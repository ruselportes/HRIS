/**
 * @format
 */

import {isReachable} from '../useSyncTriggers';

describe('isReachable', () => {
  test('connected with confirmed internet is reachable', () => {
    expect(isReachable({isConnected: true, isInternetReachable: true})).toBe(true);
  });

  test('connected to a network with no internet is NOT reachable', () => {
    // A site hotspot or captive portal: syncing here would only fail and back off.
    expect(isReachable({isConnected: true, isInternetReachable: false})).toBe(false);
  });

  test('connected but not yet probed counts as reachable', () => {
    // NetInfo reports null until its first probe. Treating that as "no" would
    // delay the first sync behind a slow probe.
    expect(isReachable({isConnected: true, isInternetReachable: null})).toBe(true);
  });

  test('disconnected is not reachable regardless of the probe', () => {
    expect(isReachable({isConnected: false, isInternetReachable: null})).toBe(false);
    expect(isReachable({isConnected: false, isInternetReachable: true})).toBe(false);
  });

  test('unknown connectivity is not reachable', () => {
    expect(isReachable({isConnected: null, isInternetReachable: null})).toBe(false);
  });
});
