/**
 * Foreman Home (docs/prototypes/HRIS Foreman Home.dc.html) — roll-call stat
 * summary + entry point to the checklist. "File Overtime Request" (Phase 9)
 * is shown per the prototype but disabled. "Report Absent Foreman" stays
 * disabled too: in Phase 7 an absent foreman is covered by the Site Engineer
 * assigning an acting foreman on the web (UC-06), not from this phone.
 *
 * An acting foreman sees whose crew they are covering and until when; a
 * foreman whose crew was handed over has the roster removed (TC-05).
 *
 * @format
 */

import React, {useCallback, useEffect, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import {useNavigation} from '@react-navigation/native';
import {apiClient} from '../api/client';
import {useAuth} from '../auth/AuthContext';
import {
  CachedCrew,
  clearRosterCache,
  getCachedCrew,
  getShiftConfig,
  listTodayAttendance,
  saveRosterCache,
  saveShiftConfig,
} from '../db/attendanceRepository';
import {
  DEFAULT_SHIFT,
  ShiftConfig,
  formatSiteTime,
  formatSiteWeekday,
  siteDateOf,
} from '../db/shiftRules';

export function ForemanHomeScreen() {
  const {user, signOut} = useAuth();
  const navigation = useNavigation<any>();
  const [crew, setCrew] = useState<CachedCrew | null>(null);
  const [counts, setCounts] = useState({present: 0, late: 0, absent: 0, pending: 0});
  const [online, setOnline] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [shift, setShift] = useState<ShiftConfig>(DEFAULT_SHIFT);

  const refreshCounts = useCallback(async (rosterSize: number) => {
    const attendance = await listTodayAttendance();
    let present = 0;
    let late = 0;
    let absent = 0;

    attendance.forEach(record => {
      if (record.status === 'present') present++;
      else if (record.status === 'late') late++;
      else if (record.status === 'absent') absent++;
    });

    setCounts({
      present,
      late,
      absent,
      pending: Math.max(rosterSize - present - late - absent, 0),
    });
  }, []);

  const sync = useCallback(async () => {
    const net = await NetInfo.fetch();
    setOnline(!!net.isConnected);

    if (net.isConnected) {
      try {
        const {data} = await apiClient.get('/me/crew');
        if (data.shift) {
          // Cached so Roll Call can detect a late start with no signal.
          await saveShiftConfig(data.shift);
        }
        if (data.crew) {
          await saveRosterCache(
            data.crew.crew_id,
            data.crew.crew_name,
            data.crew.site?.site_name ?? null,
            data.crew.members.map((m: any) => ({
              employeeId: m.employee_id,
              employeeCode: m.employee_code,
              firstName: m.first_name,
              lastName: m.last_name,
              tradeSkill: m.trade_skill,
            })),
            data.crew.acting
              ? {
                  until: data.crew.acting.until,
                  regularForemanName: data.crew.acting.regular_foreman?.full_name ?? null,
                }
              : null,
          );
        } else if (data.crew === null) {
          // The server answered: no crew. It was handed to an acting foreman,
          // or a cover ended (Phase 7, TC-05) — don't keep marking it.
          await clearRosterCache();
        }
      } catch {
        // Fetch failed (server down, token expired, etc.) — fall through to
        // whatever's already cached locally. This is the whole point of the
        // offline-first design: a failed fetch is not a failed screen.
      }
    }

    const cached = await getCachedCrew();
    setCrew(cached);
    setShift(await getShiftConfig());
    await refreshCounts(cached?.members.length ?? 0);
  }, [refreshCounts]);

  useEffect(() => {
    (async () => {
      setLoading(true);
      await sync();
      setLoading(false);
    })();

    const unsubscribe = NetInfo.addEventListener(state => setOnline(!!state.isConnected));
    return unsubscribe;
  }, [sync]);

  const onRefresh = async () => {
    setRefreshing(true);
    await sync();
    setRefreshing(false);
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
      <View style={[styles.syncBar, online ? styles.syncOnline : styles.syncOffline]}>
        <Text style={styles.syncText}>{online ? 'Online' : 'Offline — showing cached roster'}</Text>
      </View>

      <Text style={styles.greeting}>
        {user ? `${user.first_name} ${user.last_name}` : 'Foreman'}
      </Text>
      <Text style={styles.crewLine}>
        {crew ? `${crew.crewName} · ${crew.siteName ?? 'Unassigned site'}` : 'No crew assigned to you'}
      </Text>

      {crew?.acting && (
        <View style={styles.actingBanner}>
          <Text style={styles.actingTitle}>Acting foreman</Text>
          <Text style={styles.actingBody}>
            Covering for {crew.acting.regularForemanName ?? 'the regular foreman'} until{' '}
            {describeUntil(Date.parse(crew.acting.until), shift)}. The crew goes back to them
            automatically.
          </Text>
        </View>
      )}

      <Text style={styles.sectionLabel}>Roll call today</Text>
      <View style={styles.statsRow}>
        <Stat label="Present" value={counts.present} />
        <Stat label="Late" value={counts.late} />
        <Stat label="Absent" value={counts.absent} />
        <Stat label="Pending" value={counts.pending} />
      </View>

      <TouchableOpacity
        style={styles.primaryAction}
        onPress={() => navigation.navigate('RollCall')}
        disabled={!crew}>
        <Text style={styles.primaryActionText}>View Crew / Roll Call</Text>
      </TouchableOpacity>

      <Text style={styles.sectionLabel}>Quick actions</Text>
      <DisabledAction
        label="File Overtime Request"
        note="Phase 9 — Leave & Overtime Filing"
      />
      <DisabledAction
        label="Report Absent Foreman"
        note="Your Site Engineer assigns an acting foreman from the web portal"
      />

      <TouchableOpacity style={styles.signOut} onPress={() => signOut()}>
        <Text style={styles.signOutText}>Sign out</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

/** "23:59" when the cover ends today, "Sun 23:59" when it runs longer. */
function describeUntil(untilMs: number, shift: ShiftConfig): string {
  const time = formatSiteTime(untilMs, shift);
  const today = siteDateOf(Date.now(), shift) === siteDateOf(untilMs, shift);

  return today ? time : `${formatSiteWeekday(untilMs, shift)} ${time}`;
}

function Stat({label, value}: {label: string; value: number}) {
  return (
    <View style={styles.statCard}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

function DisabledAction({label, note}: {label: string; note: string}) {
  return (
    <TouchableOpacity
      style={styles.disabledAction}
      onPress={() => Alert.alert(label, `Not built yet — ${note}.`)}>
      <Text style={styles.disabledActionText}>{label}</Text>
      <Text style={styles.disabledActionNote}>{note}</Text>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3', padding: 20},
  centered: {flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f2f2f3'},
  syncBar: {padding: 10, borderRadius: 4, marginBottom: 16, alignItems: 'center'},
  syncOnline: {backgroundColor: '#e6f1ea'},
  syncOffline: {backgroundColor: '#fcece0'},
  syncText: {fontSize: 13, fontWeight: '600', color: '#1d1f20'},
  greeting: {fontSize: 28, fontWeight: '700', color: '#1d1f20'},
  crewLine: {fontSize: 15, color: '#5d5d60', marginTop: 4, marginBottom: 20},
  actingBanner: {backgroundColor: '#efe9f4', borderRadius: 4, padding: 14, marginBottom: 4},
  actingTitle: {fontSize: 14, fontWeight: '700', color: '#4a3260'},
  actingBody: {fontSize: 14, lineHeight: 20, color: '#3a3a3d', marginTop: 2},
  sectionLabel: {
    fontSize: 12,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: '#5d5d60',
    marginTop: 20,
    marginBottom: 10,
  },
  statsRow: {flexDirection: 'row', gap: 10},
  statCard: {
    flex: 1,
    backgroundColor: '#fff',
    borderRadius: 4,
    paddingVertical: 14,
    alignItems: 'center',
  },
  statValue: {fontSize: 24, fontWeight: '700', color: '#1d1f20'},
  statLabel: {fontSize: 12, color: '#5d5d60', marginTop: 2},
  primaryAction: {
    minHeight: 64,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 20,
  },
  primaryActionText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  disabledAction: {
    minHeight: 60,
    borderWidth: 1,
    borderColor: '#d4d4d7',
    borderRadius: 4,
    justifyContent: 'center',
    paddingHorizontal: 16,
    marginBottom: 10,
    opacity: 0.6,
  },
  disabledActionText: {fontSize: 16, color: '#1d1f20'},
  disabledActionNote: {fontSize: 12, color: '#7a7a7d', marginTop: 2},
  signOut: {alignItems: 'center', marginTop: 24, marginBottom: 40},
  signOutText: {color: '#a83a2c', fontSize: 15},
});
