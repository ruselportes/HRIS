/**
 * Sync Queue (docs/prototypes/HRIS Foreman Sync Queue.dc.html, Phase 6).
 *
 * Built to the prototype's four states — offline and waiting, sending, a record
 * not accepted, nothing waiting — and to the principles its own annotations
 * state:
 *
 *   "Sync Now is reassurance" — sending is automatic. The button exists so a
 *     foreman can confirm, not because anything depends on pressing it.
 *   "A failure names the person" — a rejected record shows the worker's name,
 *     never a hash.
 *   "Last successful sync always shows" — in every state, including offline.
 *   "Failed first" — refused records sort to the top.
 *
 * Deliberately NOT built: the prototype's "He was here" / "Remove record"
 * actions on a failed record. Its example failure is a business rule ("HR has
 * him on approved leave today"), which needs Phase 9's leave data to exist.
 * Buttons that do nothing would be worse than no buttons.
 *
 * @format
 */

import React, {useCallback, useEffect, useState} from 'react';
import {
  ActivityIndicator,
  FlatList,
  RefreshControl,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import {chainEpoch, clearCredentials, loadCredentials} from '../crypto/deviceCredentials';
import {SyncQueueRow, SyncSummary, getSyncSummary} from '../db/attendanceRepository';
import {SchedulerState, syncScheduler} from '../sync/syncScheduler';
import {isReachable} from '../sync/useSyncTriggers';

/**
 * Halts only a fresh setup can fix: the server no longer accepts this phone's
 * binding. The copy used to say "set this phone up again" with no way to do
 * it; clearing the binding sends the foreman to setup (Phase 7).
 */
const NEEDS_SETUP = new Set(['device_not_bound', 'unbound', 'device_revoked']);

/** Plain-language copy for every halt, in the prototype's register. */
const HALT_COPY: Record<string, {title: string; body: string}> = {
  chain_broken: {
    title: 'Sending has stopped',
    body: 'Some records were not accepted, so nothing more can be sent from this phone. Your records are still saved here. Tell HR — they need to check this before you continue.',
  },
  device_revoked: {
    title: 'This phone was removed',
    body: 'HR has removed this phone from the system. Your records are still saved here. Ask HR to set it up again.',
  },
  device_not_bound: {
    title: 'This phone needs setting up again',
    body: 'Your records are still saved here. Set this phone up again to keep sending.',
  },
  unbound: {
    title: 'This phone needs setting up again',
    body: 'Your records are still saved here. Set this phone up again to keep sending.',
  },
  auth_expired: {
    title: 'Please sign in again',
    body: 'Your sign-in has expired. Your records are still saved here and will send once you sign in.',
  },
  invalid_payload: {
    title: 'Something went wrong sending',
    body: 'Your records are still saved here. Tell HR so they can look into it.',
  },
};

function formatTime(epochMs: number | null): string {
  if (epochMs === null) {
    return '—';
  }
  const d = new Date(epochMs);
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

function describeLastSync(epochMs: number | null): string {
  if (epochMs === null) {
    return 'Not yet';
  }
  const d = new Date(epochMs);
  const sameDay = d.toDateString() === new Date().toDateString();
  return `${sameDay ? 'Today' : d.toLocaleDateString()} ${formatTime(epochMs)}`;
}

export function SyncQueueScreen() {
  const [summary, setSummary] = useState<SyncSummary | null>(null);
  const [scheduler, setScheduler] = useState<SchedulerState>(syncScheduler.getState());
  const [online, setOnline] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadSummary = useCallback(async () => {
    const credentials = await loadCredentials();
    if (credentials === null) {
      setSummary(null);
      return;
    }
    setSummary(await getSyncSummary(chainEpoch(credentials)));
  }, []);

  useEffect(() => {
    loadSummary();

    // Refresh the list whenever a run finishes, so statuses update without the
    // foreman having to pull.
    const unsubscribeScheduler = syncScheduler.subscribe(state => {
      setScheduler(state);
      if (!state.running) {
        loadSummary();
      }
    });

    const unsubscribeNet = NetInfo.addEventListener(state => setOnline(isReachable(state)));

    return () => {
      unsubscribeScheduler();
      unsubscribeNet();
    };
  }, [loadSummary]);

  const onRefresh = async () => {
    setRefreshing(true);
    await loadSummary();
    setRefreshing(false);
  };

  if (summary === null) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  const halted = scheduler.lastResult?.kind === 'halted' ? scheduler.lastResult.reason : null;
  const haltCopy = halted ? HALT_COPY[halted] : null;
  const waiting = summary.pending;

  return (
    <View style={styles.container}>
      <FlatList
        data={summary.rows}
        keyExtractor={row => String(row.eventId)}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
        contentContainerStyle={styles.content}
        ListHeaderComponent={
          <Header
            summary={summary}
            scheduler={scheduler}
            online={online}
            waiting={waiting}
            haltCopy={haltCopy}
            onSetUpAgain={halted && NEEDS_SETUP.has(halted) ? () => clearCredentials() : null}
          />
        }
        renderItem={({item}) => <QueueRow row={item} />}
        ListEmptyComponent={
          <Text style={styles.empty}>
            Nothing recorded yet today. Tomorrow's roll call works the same way, with or without
            signal.
          </Text>
        }
        ListFooterComponent={
          <Footer
            online={online}
            running={scheduler.running}
            halted={halted !== null}
            waiting={waiting}
          />
        }
      />
    </View>
  );
}

function Header({
  summary,
  scheduler,
  online,
  waiting,
  haltCopy,
  onSetUpAgain,
}: {
  summary: SyncSummary;
  scheduler: SchedulerState;
  online: boolean;
  waiting: number;
  haltCopy: {title: string; body: string} | null;
  onSetUpAgain: (() => void) | null;
}) {
  let pill: {label: string; tone: 'online' | 'offline' | 'busy'};
  if (scheduler.running) {
    pill = {label: `Sending — ${waiting} left`, tone: 'busy'};
  } else if (!online) {
    pill = {label: waiting > 0 ? `Offline — ${waiting} pending` : 'Offline', tone: 'offline'};
  } else {
    pill = {label: waiting > 0 ? `${waiting} waiting` : 'Online — synced', tone: 'online'};
  }

  return (
    <View>
      <Text style={styles.title}>Sync</Text>

      <View style={[styles.pill, styles[`pill_${pill.tone}`]]}>
        <Text style={styles.pillText}>{pill.label}</Text>
      </View>

      {haltCopy ? (
        <View style={styles.haltBox}>
          <Text style={styles.haltTitle}>{haltCopy.title}</Text>
          <Text style={styles.haltBody}>{haltCopy.body}</Text>
          {onSetUpAgain && (
            <TouchableOpacity
              style={styles.haltAction}
              accessibilityRole="button"
              onPress={onSetUpAgain}>
              <Text style={styles.haltActionText}>Set up this phone again</Text>
            </TouchableOpacity>
          )}
        </View>
      ) : waiting === 0 && summary.rows.length > 0 ? (
        <View style={styles.okBox}>
          <Text style={styles.okTitle}>Everything is sent</Text>
          <Text style={styles.okBody}>All of today's attendance is with HR. Nothing is waiting on this phone.</Text>
        </View>
      ) : (
        <View style={styles.infoBox}>
          <Text style={styles.infoTitle}>Your work is saved</Text>
          <Text style={styles.infoBody}>
            Records are saved securely on this phone and send automatically once you're back online.
          </Text>
        </View>
      )}

      <View style={styles.statsRow}>
        <Stat label="Waiting" value={summary.pending} />
        <Stat label="Sent" value={summary.synced} />
        <Stat label="For review" value={summary.flagged} />
        {/* The foreman does not need the refused/rejected distinction to act —
            neither record counts. The row detail says which kind it was. */}
        <Stat
          label="Not accepted"
          value={summary.rejected + summary.refused}
          warn={summary.rejected + summary.refused > 0}
        />
      </View>

      {/* "Last successful sync always shows." */}
      <View style={styles.lastSyncRow}>
        <Text style={styles.lastSyncLabel}>Last successful sync</Text>
        <Text style={styles.lastSyncValue}>{describeLastSync(summary.lastSuccessfulSync)}</Text>
      </View>

      {summary.rows.length > 0 && (
        <Text style={styles.sectionLabel}>
          {summary.rejected + summary.refused > 0
            ? 'Not accepted first, then oldest first'
            : 'Oldest first'}
        </Text>
      )}
    </View>
  );
}

function Stat({label, value, warn}: {label: string; value: number; warn?: boolean}) {
  return (
    <View style={styles.stat}>
      <Text style={[styles.statValue, warn && styles.statValueWarn]}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

const TAG: Record<string, {label: string; style: object; textStyle: object}> = {
  pending: {label: 'Queued', style: {backgroundColor: '#e7e7ea'}, textStyle: {color: '#424244'}},
  synced: {label: 'Sent', style: {backgroundColor: '#e6f1ea'}, textStyle: {color: '#1f5334'}},
  flagged: {label: 'For review', style: {backgroundColor: '#efe9f4'}, textStyle: {color: '#4a3260'}},
  refused: {label: 'Not accepted', style: {backgroundColor: '#f6e1de'}, textStyle: {color: '#75261c'}},
  rejected: {label: 'Not accepted', style: {backgroundColor: '#f6e1de'}, textStyle: {color: '#75261c'}},
};

/** Plain words for the credited time, so the foreman sees what was assumed. */
function overrideNote(row: SyncQueueRow): string {
  if (row.overrideType === 'shift_credit') {
    return ' · credited from shift start';
  }
  if (row.overrideType === 'manual_time') {
    return ' · time set by you';
  }
  return '';
}

function QueueRow({row}: {row: SyncQueueRow}) {
  const tag = TAG[row.syncStatus] ?? TAG.pending;
  const statusWord = row.status.charAt(0).toUpperCase() + row.status.slice(1);

  // "A failure names the person" — the worker's name leads, and the detail line
  // says plainly what happened rather than showing a reason code.
  const detail =
    row.syncStatus === 'flagged'
      ? `${statusWord} · ${formatTime(row.timeIn)} · sent for HR review`
      : row.syncStatus === 'rejected'
      ? `${statusWord} · ${formatTime(row.timeIn)} · not accepted — HR needs to check this`
      : row.syncStatus === 'refused'
      ? // Authentic but not permitted — most often the crew was reassigned.
        // Said plainly, and distinct from rejected, which suggests tampering.
        `${statusWord} · not accepted — this record wasn't allowed (the crew may have a different foreman now)`
      : `${statusWord}${row.timeIn ? ` · ${formatTime(row.timeIn)}` : ''}${overrideNote(row)}`;

  const failed = row.syncStatus === 'rejected' || row.syncStatus === 'refused';

  return (
    <View style={[styles.row, failed && styles.rowRejected]}>
      <View style={styles.rowText}>
        <Text style={styles.rowName}>{row.employeeName}</Text>
        <Text style={styles.rowDetail}>{detail}</Text>
      </View>
      <View style={[styles.tag, tag.style]}>
        <Text style={[styles.tagText, tag.textStyle]}>{tag.label}</Text>
      </View>
    </View>
  );
}

function Footer({
  online,
  running,
  halted,
  waiting,
}: {
  online: boolean;
  running: boolean;
  halted: boolean;
  waiting: number;
}) {
  // A halted chain will not send however many times the button is pressed, so
  // offering it would only suggest the foreman can fix this themselves.
  if (halted) {
    return null;
  }

  return (
    <View style={styles.footer}>
      {waiting > 0 && <Text style={styles.signedNote}>All saved and signed on this phone</Text>}

      <TouchableOpacity
        style={[styles.button, (!online || running) && styles.buttonDisabled]}
        disabled={!online || running}
        onPress={() => syncScheduler.syncNow()}>
        {running ? (
          <ActivityIndicator color="#f2f2f3" />
        ) : (
          <Text style={styles.buttonText}>{online ? 'Sync Now' : 'Sync Now — needs signal'}</Text>
        )}
      </TouchableOpacity>

      {/* "Sync Now is reassurance." */}
      <Text style={styles.reassurance}>
        {running
          ? 'Keep the app open until this finishes.'
          : online
          ? 'Checks for anything new. Safe to press any time.'
          : 'You do not need to do anything. Sending starts by itself.'}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3'},
  content: {padding: 20, paddingBottom: 40},
  centered: {flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f2f2f3'},
  title: {fontSize: 26, fontWeight: '700', color: '#1d1f20'},
  pill: {alignSelf: 'flex-start', borderRadius: 12, paddingVertical: 5, paddingHorizontal: 12, marginTop: 10},
  pill_online: {backgroundColor: '#e6f1ea'},
  pill_offline: {backgroundColor: '#fcece0'},
  pill_busy: {backgroundColor: '#d6ebff'},
  pillText: {fontSize: 13, fontWeight: '600', color: '#1d1f20'},
  infoBox: {marginTop: 16, padding: 14, borderRadius: 4, backgroundColor: '#fff'},
  infoTitle: {fontSize: 16, fontWeight: '700', color: '#1d1f20'},
  infoBody: {fontSize: 14, color: '#5d5d60', marginTop: 4, lineHeight: 20},
  okBox: {marginTop: 16, padding: 14, borderRadius: 4, backgroundColor: '#e6f1ea'},
  okTitle: {fontSize: 16, fontWeight: '700', color: '#1f5334'},
  okBody: {fontSize: 14, color: '#1d1f20', marginTop: 4, lineHeight: 20},
  haltBox: {marginTop: 16, padding: 14, borderRadius: 4, backgroundColor: '#f6e1de'},
  haltTitle: {fontSize: 16, fontWeight: '700', color: '#75261c'},
  haltBody: {fontSize: 14, color: '#1d1f20', marginTop: 4, lineHeight: 20},
  haltAction: {
    minHeight: 48,
    marginTop: 12,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
  },
  haltActionText: {color: '#f2f2f3', fontSize: 15, fontWeight: '600'},
  statsRow: {flexDirection: 'row', gap: 8, marginTop: 16},
  stat: {flex: 1, backgroundColor: '#fff', borderRadius: 4, paddingVertical: 10, alignItems: 'center'},
  statValue: {fontSize: 20, fontWeight: '700', color: '#1d1f20'},
  statValueWarn: {color: '#a83a2c'},
  statLabel: {fontSize: 11, color: '#5d5d60', marginTop: 2, textAlign: 'center'},
  lastSyncRow: {flexDirection: 'row', justifyContent: 'space-between', marginTop: 14},
  lastSyncLabel: {fontSize: 13, color: '#5d5d60'},
  lastSyncValue: {fontSize: 13, fontWeight: '600', color: '#1d1f20'},
  sectionLabel: {
    fontSize: 12,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: '#5d5d60',
    marginTop: 20,
    marginBottom: 10,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 4,
    padding: 12,
    marginBottom: 8,
  },
  rowRejected: {borderLeftWidth: 3, borderLeftColor: '#a83a2c'},
  rowText: {flex: 1},
  rowName: {fontSize: 16, fontWeight: '600', color: '#1d1f20'},
  rowDetail: {fontSize: 13, color: '#5d5d60', marginTop: 2},
  tag: {borderRadius: 12, paddingVertical: 4, paddingHorizontal: 10, marginLeft: 8},
  tagText: {fontSize: 12, fontWeight: '600'},
  empty: {fontSize: 14, color: '#5d5d60', textAlign: 'center', marginTop: 24, lineHeight: 20},
  footer: {marginTop: 20},
  signedNote: {fontSize: 13, color: '#1f5334', textAlign: 'center', marginBottom: 10},
  button: {
    minHeight: 56,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonDisabled: {opacity: 0.5},
  buttonText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  reassurance: {fontSize: 13, color: '#7a7a7d', textAlign: 'center', marginTop: 10},
});
