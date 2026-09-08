/**
 * Foreman Home (docs/prototypes/HRIS Foreman Home.dc.html) — roll-call stat
 * summary + entry point to the checklist. "File Overtime Request" (Phase 9)
 * and "Report Absent Foreman" (Phase 7) are shown per the prototype but
 * disabled — out of scope here, not silently omitted.
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
  getCachedCrew,
  listTodayAttendance,
  saveRosterCache,
} from '../db/attendanceRepository';

export function ForemanHomeScreen() {
  const {user, signOut} = useAuth();
  const navigation = useNavigation<any>();
  const [crew, setCrew] = useState<CachedCrew | null>(null);
  const [counts, setCounts] = useState({present: 0, late: 0, absent: 0, pending: 0});
  const [online, setOnline] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);

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
          );
        }
      } catch {
        // Fetch failed (server down, token expired, etc.) — fall through to
        // whatever's already cached locally. This is the whole point of the
        // offline-first design: a failed fetch is not a failed screen.
      }
    }

    const cached = await getCachedCrew();
    setCrew(cached);
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
        {crew ? `${crew.crewName} · ${crew.siteName ?? 'Unassigned site'}` : 'No crew cached yet'}
      </Text>

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
        note="Phase 7 — Foreman Edge Case Handling"
      />

      <TouchableOpacity style={styles.signOut} onPress={() => signOut()}>
        <Text style={styles.signOutText}>Sign out</Text>
      </TouchableOpacity>
    </ScrollView>
  );
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
