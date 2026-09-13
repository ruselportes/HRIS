/**
 * Wires the scheduler to the device (Phase 6): connectivity, app foreground,
 * and first mount. Mounted once, inside the signed-in and bound part of the
 * app, so nothing tries to sync before there is anything able to.
 *
 * @format
 */

import {useEffect, useRef} from 'react';
import {AppState, AppStateStatus} from 'react-native';
import NetInfo, {NetInfoState} from '@react-native-community/netinfo';
import {syncScheduler} from './syncScheduler';

/**
 * Whether the server is plausibly reachable.
 *
 * isConnected alone is not enough: a site hotspot or captive portal reports
 * connected while having no route to the internet, and syncing on that would
 * just produce a failure and a backoff. isInternetReachable is null until
 * NetInfo has probed, and null is treated as "maybe" rather than "no" so a
 * slow first probe does not delay the first sync.
 */
export function isReachable(state: Pick<NetInfoState, 'isConnected' | 'isInternetReachable'>): boolean {
  return state.isConnected === true && state.isInternetReachable !== false;
}

export function useSyncTriggers(): void {
  const wasReachable = useRef<boolean | null>(null);

  useEffect(() => {
    const unsubscribeNet = NetInfo.addEventListener(state => {
      const reachable = isReachable(state);
      const previously = wasReachable.current;
      wasReachable.current = reachable;

      /*
       * Edge-triggered: only the offline -> online transition. NetInfo also
       * fires on Wi-Fi <-> cellular handovers while already connected, and
       * treating those as "signal regained" would reset the backoff and sync
       * on every handover.
       */
      if (reachable && previously === false) {
        syncScheduler.onConnectivityRegained();
      }
    });

    // Initial state: if we open the app already online, sync now rather than
    // waiting for a connectivity change that will never come.
    NetInfo.fetch().then(state => {
      const reachable = isReachable(state);
      wasReachable.current = reachable;
      if (reachable) {
        syncScheduler.syncNow();
      }
    });

    // Coming back to the foreground is a natural moment to catch up: the
    // foreman may have regained signal while the app was backgrounded, where
    // the connectivity listener may not have fired.
    const subscription = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'active') {
        NetInfo.fetch().then(state => {
          if (isReachable(state)) {
            syncScheduler.syncNow();
          }
        });
      }
    });

    return () => {
      unsubscribeNet();
      subscription.remove();
      // Signing out: nothing should keep retrying on behalf of a session that
      // no longer exists.
      syncScheduler.stop();
    };
  }, []);
}
