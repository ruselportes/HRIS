/**
 * Late start prompt (Phase 7 — UC-05, STD TC-04 step 2).
 *
 * Shown full-screen, in place of the roster, when roll call opens past the
 * grace window with nobody marked yet. Workers who were on site before the
 * foreman have no tap to record, so the foreman has to say how their time in
 * is recorded before marking anyone — an override chosen after the fact would
 * leave the first few records inconsistent with the rest.
 *
 * Choosing only sets a mode. No record is written here: each worker's event
 * carries its own signed override_type when marked, and the server raises the
 * audit event from those.
 *
 * @format
 */

import React, {useState} from 'react';
import {ScrollView, StyleSheet, Text, TouchableOpacity, View} from 'react-native';
import type {LateStartMode} from '../../db/attendanceRepository';
import {
  ShiftConfig,
  formatGap,
  formatSiteTime,
  minutesSinceShiftStart,
} from '../../db/shiftRules';

type Props = {
  crewName: string;
  foremanName: string;
  shift: ShiftConfig;
  date: string;
  openedAt: number;
  onChoose: (mode: LateStartMode) => void;
};

export function LateStartPrompt({crewName, foremanName, shift, date, openedAt, onChoose}: Props) {
  // The confirm step shows the time it was reached, not when the screen opened.
  const [confirmAt, setConfirmAt] = useState<number | null>(null);

  if (confirmAt !== null) {
    return (
      <ScrollView style={styles.container} contentContainerStyle={styles.content}>
        <Text style={styles.eyebrow}>Late start</Text>
        <Text style={styles.title}>Confirm shift credit</Text>

        <View style={styles.summary}>
          <SummaryRow label="Crew" value={crewName} />
          <SummaryRow label="Time credited" value={shift.start} />
          <SummaryRow label="Actual time now" value={formatSiteTime(confirmAt, shift)} />
          <SummaryRow
            label="Gap"
            value={formatGap(minutesSinceShiftStart(confirmAt, date, shift))}
          />
          <SummaryRow label="Logged as" value={foremanName} />
          <SummaryRow label="Audit flag" value="FOREMAN_LATE_OVERRIDE" mono />
        </View>

        <Text style={styles.body}>
          Everyone you mark Present is credited from {shift.start}. Your actual tap time is
          recorded beside it. Credited records are held out of payroll until HR approves
          them; if HR rejects, workers are paid from your tap time instead.
        </Text>

        <TouchableOpacity
          style={styles.primary}
          accessibilityRole="button"
          onPress={() => onChoose('credit')}>
          <Text style={styles.primaryText}>Apply {shift.start} shift credit</Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={styles.secondary}
          accessibilityRole="button"
          onPress={() => setConfirmAt(null)}>
          <Text style={styles.secondaryText}>Back</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.eyebrow}>Late start</Text>
      <Text style={styles.title}>Roll call is starting late</Text>

      <Text style={styles.clock}>{formatSiteTime(openedAt, shift)}</Text>
      <Text style={styles.clockMeta}>
        Shift started {shift.start} · {formatGap(minutesSinceShiftStart(openedAt, date, shift))}{' '}
        ago
      </Text>

      <Text style={styles.body}>
        Workers who were on site before you have no tap to record. Choose how their time in is
        recorded. Either override is flagged for HR review before it reaches payroll.
      </Text>

      <TouchableOpacity
        style={styles.optionPrimary}
        accessibilityRole="button"
        onPress={() => setConfirmAt(Date.now())}>
        <Text style={styles.optionPrimaryTitle}>Apply {shift.start} shift credit</Text>
        <Text style={styles.optionPrimaryText}>
          Everyone you mark Present is credited from {shift.start}. Mark Late anyone who arrived
          after it.
        </Text>
      </TouchableOpacity>

      <TouchableOpacity
        style={styles.option}
        accessibilityRole="button"
        onPress={() => onChoose('manual')}>
        <Text style={styles.optionTitle}>Set each time myself</Text>
        <Text style={styles.optionText}>Enter each worker's arrival time as you mark them.</Text>
      </TouchableOpacity>

      <TouchableOpacity
        style={styles.tertiary}
        accessibilityRole="button"
        onPress={() => onChoose('none')}>
        <Text style={styles.tertiaryText}>No override — use actual tap times</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

function SummaryRow({label, value, mono}: {label: string; value: string; mono?: boolean}) {
  return (
    <View style={styles.summaryRow}>
      <Text style={styles.summaryLabel}>{label}</Text>
      <Text style={[styles.summaryValue, mono && styles.mono]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3'},
  content: {padding: 20, paddingBottom: 40},
  eyebrow: {
    fontSize: 12,
    letterSpacing: 1,
    textTransform: 'uppercase',
    color: '#96420e',
    fontWeight: '700',
    marginTop: 8,
  },
  title: {fontSize: 26, fontWeight: '700', color: '#1d1f20', marginTop: 4},
  clock: {fontSize: 56, fontWeight: '700', color: '#1d1f20', marginTop: 20},
  clockMeta: {fontSize: 15, color: '#5d5d60'},
  body: {fontSize: 15, lineHeight: 22, color: '#3a3a3d', marginTop: 20, marginBottom: 20},
  optionPrimary: {
    backgroundColor: '#1d2d3d',
    borderRadius: 4,
    padding: 18,
    marginBottom: 12,
    minHeight: 64,
  },
  optionPrimaryTitle: {fontSize: 18, fontWeight: '700', color: '#f2f2f3'},
  optionPrimaryText: {fontSize: 14, lineHeight: 20, color: '#c9d1d9', marginTop: 4},
  option: {
    borderWidth: 1.5,
    borderColor: '#1d2d3d',
    borderRadius: 4,
    padding: 18,
    marginBottom: 12,
    minHeight: 64,
  },
  optionTitle: {fontSize: 18, fontWeight: '700', color: '#1d2d3d'},
  optionText: {fontSize: 14, lineHeight: 20, color: '#5d5d60', marginTop: 4},
  tertiary: {minHeight: 48, alignItems: 'center', justifyContent: 'center'},
  tertiaryText: {fontSize: 15, color: '#5d5d60', textDecorationLine: 'underline'},
  summary: {backgroundColor: '#fff', borderRadius: 4, paddingHorizontal: 16, marginTop: 20},
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#d4d4d7',
    gap: 12,
  },
  summaryLabel: {fontSize: 14, color: '#5d5d60'},
  summaryValue: {fontSize: 15, fontWeight: '600', color: '#1d1f20', flexShrink: 1, textAlign: 'right'},
  mono: {fontFamily: 'monospace', fontSize: 13},
  primary: {
    minHeight: 60,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  secondary: {minHeight: 52, alignItems: 'center', justifyContent: 'center', marginTop: 8},
  secondaryText: {fontSize: 16, color: '#1d2d3d'},
});
