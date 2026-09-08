/**
 * Roll Call (docs/prototypes/HRIS Foreman Attendance Mobile.dc.html) — the
 * offline-capable tap checklist itself. Every write here goes straight to
 * local SQLite (attendanceRepository) with no network call in the path,
 * which is the actual "offline-capable" requirement (UC-04).
 *
 * The prototype's "Set time manually" modal (with reason codes like "Late
 * gate opening") is NOT built here — deliberately deferred, not silently
 * dropped. It's a manual-correction/override path, not the primary tap
 * flow, and doesn't block "offline read/write verified."
 *
 * @format
 */

import React, {useEffect, useState} from 'react';
import {FlatList, StyleSheet, Text, TouchableOpacity, View} from 'react-native';
import {getOrCreateDeviceId} from '../db/deviceId';
import {AttendanceRecord, AttendanceStatus} from '../db/attendanceLogic';
import {
  CachedCrew,
  getCachedCrew,
  listTodayAttendance,
  recordStatus,
  undoAttendance,
} from '../db/attendanceRepository';

type RowState = {
  employeeId: number;
  name: string;
  tradeSkill: string | null;
  record: AttendanceRecord;
};

export function RollCallScreen() {
  const [crew, setCrew] = useState<CachedCrew | null>(null);
  const [rows, setRows] = useState<RowState[]>([]);
  const [deviceId, setDeviceId] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      const id = await getOrCreateDeviceId();
      setDeviceId(id);

      const cached = await getCachedCrew();
      setCrew(cached);

      if (cached) {
        const attendance = await listTodayAttendance();

        setRows(
          cached.members.map(member => ({
            employeeId: member.employeeId,
            name: `${member.firstName} ${member.lastName}`,
            tradeSkill: member.tradeSkill,
            record:
              attendance.get(member.employeeId) ?? {
                employeeId: member.employeeId,
                date: '',
                status: 'pending' as AttendanceStatus,
                timeIn: null,
                overrideFlag: false,
              },
          })),
        );
      }
    })();
  }, []);

  const mark = async (employeeId: number, status: 'present' | 'late' | 'absent') => {
    if (!crew || !deviceId) return;

    const updated = await recordStatus(employeeId, crew.crewId, status, deviceId);
    setRows(prev =>
      prev.map(row => (row.employeeId === employeeId ? {...row, record: updated} : row)),
    );
  };

  const undo = async (employeeId: number) => {
    if (!crew || !deviceId) return;

    const updated = await undoAttendance(employeeId, crew.crewId, deviceId);
    setRows(prev =>
      prev.map(row => (row.employeeId === employeeId ? {...row, record: updated} : row)),
    );
  };

  if (!crew) {
    return (
      <View style={styles.centered}>
        <Text style={styles.emptyText}>No cached roster yet — connect once to fetch it.</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Roll Call</Text>
      <Text style={styles.subtitle}>{crew.crewName}</Text>

      <FlatList
        data={rows}
        keyExtractor={item => String(item.employeeId)}
        renderItem={({item}) => (
          <RollCallRow row={item} onMark={mark} onUndo={undo} />
        )}
        contentContainerStyle={styles.list}
      />
    </View>
  );
}

function RollCallRow({
  row,
  onMark,
  onUndo,
}: {
  row: RowState;
  onMark: (employeeId: number, status: 'present' | 'late' | 'absent') => void;
  onUndo: (employeeId: number) => void;
}) {
  const {status} = row.record;
  const marked = status !== 'pending';

  return (
    <View style={styles.row}>
      <View style={styles.rowHeader}>
        <Text style={styles.rowName}>{row.name}</Text>
        {marked ? (
          <View style={[styles.tag, tagStyleFor(status)]}>
            <Text style={styles.tagText}>{statusLabel(status)}</Text>
          </View>
        ) : (
          <View style={styles.tagPending}>
            <Text style={styles.tagPendingText}>Pending</Text>
          </View>
        )}
      </View>
      {row.tradeSkill && <Text style={styles.rowMeta}>{row.tradeSkill}</Text>}

      <View style={styles.buttonsRow}>
        {(['present', 'late', 'absent'] as const)
          .filter(option => option !== status)
          .map(option => (
            <TouchableOpacity
              key={option}
              style={[styles.choiceButton, choiceButtonStyleFor(option)]}
              onPress={() => onMark(row.employeeId, option)}>
              <Text style={[styles.choiceButtonText, choiceTextStyleFor(option)]}>
                {statusLabel(option)}
              </Text>
            </TouchableOpacity>
          ))}
        {marked && (
          <TouchableOpacity style={styles.undoButton} onPress={() => onUndo(row.employeeId)}>
            <Text style={styles.undoButtonText}>Undo</Text>
          </TouchableOpacity>
        )}
      </View>
    </View>
  );
}

function statusLabel(status: AttendanceStatus | 'present' | 'late' | 'absent'): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

function tagStyleFor(status: AttendanceStatus) {
  if (status === 'present') return {backgroundColor: '#e6f1ea'};
  if (status === 'late') return {backgroundColor: '#fcece0'};
  if (status === 'absent') return {backgroundColor: '#f6e1de'};
  return {};
}

function choiceButtonStyleFor(option: 'present' | 'late' | 'absent') {
  if (option === 'present') return {borderColor: '#2F7A4D'};
  if (option === 'late') return {borderColor: '#C9781B'};
  return {borderColor: '#A83A2C'};
}

function choiceTextStyleFor(option: 'present' | 'late' | 'absent') {
  if (option === 'present') return {color: '#1F5334'};
  if (option === 'late') return {color: '#8A5211'};
  return {color: '#75261C'};
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3', paddingTop: 20},
  centered: {flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24},
  emptyText: {fontSize: 15, color: '#5d5d60', textAlign: 'center'},
  title: {fontSize: 24, fontWeight: '700', color: '#1d1f20', paddingHorizontal: 20},
  subtitle: {fontSize: 14, color: '#5d5d60', paddingHorizontal: 20, marginBottom: 12},
  list: {paddingHorizontal: 20, paddingBottom: 40},
  row: {
    backgroundColor: '#fff',
    borderRadius: 4,
    padding: 14,
    marginBottom: 12,
  },
  rowHeader: {flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center'},
  rowName: {fontSize: 17, fontWeight: '600', color: '#1d1f20'},
  rowMeta: {fontSize: 13, color: '#7a7a7d', marginTop: 2},
  tag: {borderRadius: 12, paddingVertical: 4, paddingHorizontal: 10},
  tagText: {fontSize: 12, fontWeight: '600', color: '#1d1f20'},
  tagPending: {borderRadius: 12, paddingVertical: 4, paddingHorizontal: 10, backgroundColor: '#fcece0'},
  tagPendingText: {fontSize: 12, fontWeight: '600', color: '#96420e'},
  buttonsRow: {flexDirection: 'row', gap: 10, marginTop: 12},
  choiceButton: {
    flex: 1,
    minHeight: 56,
    borderWidth: 1.5,
    borderRadius: 4,
    alignItems: 'center',
    justifyContent: 'center',
  },
  choiceButtonText: {fontSize: 15, fontWeight: '600'},
  undoButton: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: 12,
  },
  undoButtonText: {fontSize: 14, color: '#5d5d60'},
});
