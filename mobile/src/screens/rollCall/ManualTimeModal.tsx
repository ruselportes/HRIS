/**
 * Time entry for a time the foreman states: an arrival under "Set each time
 * myself" (Phase 7 — UC-05), or a time-out for a worker nobody tapped out.
 *
 * Plain steppers rather than a native time picker: the stack has no picker
 * library, and steppers cannot produce a time the server would refuse — they
 * stop at the start of the day (for a time-out, just after the time in) and at
 * the current minute.
 *
 * @format
 */

import React, {useState} from 'react';
import {Modal, StyleSheet, Text, TouchableOpacity, View} from 'react-native';
import {
  ShiftConfig,
  earliestTimeOut,
  formatSiteTime,
  shiftEndMs,
  shiftStartMs,
  stepManualTime,
} from '../../db/shiftRules';

/** What is being stated: an arrival, or a time-out after a known time in. */
export type ManualTimeKind = {kind: 'arrival'} | {kind: 'time_out'; timeIn: number};

type Props = {
  visible: boolean;
  workerName: string;
  status: 'present' | 'late';
  date: string;
  shift: ShiftConfig;
  purpose?: ManualTimeKind;
  onCancel: () => void;
  onConfirm: (epochMs: number) => void;
};

const ARRIVAL: ManualTimeKind = {kind: 'arrival'};

/**
 * A sensible starting point: shift start for a Present arrival, shift end for
 * a time-out ("forgot to tap out at the end of the day"), otherwise now.
 */
function initialTime(
  purpose: ManualTimeKind,
  status: 'present' | 'late',
  date: string,
  shift: ShiftConfig,
): number {
  const now = Date.now();

  if (purpose.kind === 'time_out') {
    return stepManualTime(shiftEndMs(date, shift), 0, date, now, shift, earliestTimeOut(purpose.timeIn));
  }

  const start = status === 'present' ? shiftStartMs(date, shift) : now;

  return stepManualTime(start, 0, date, now, shift);
}

export function ManualTimeModal({
  visible,
  workerName,
  status,
  date,
  shift,
  purpose = ARRIVAL,
  onCancel,
  onConfirm,
}: Props) {
  const timeOut = purpose.kind === 'time_out';
  const earliest = timeOut ? earliestTimeOut(purpose.timeIn) : null;

  // Mounted per worker by Roll Call, so the starting value is set once here.
  const [value, setValue] = useState(() => initialTime(purpose, status, date, shift));

  const clamp = (ms: number, minutes = 0) =>
    stepManualTime(ms, minutes, date, Date.now(), shift, earliest);

  const step = (minutes: number) => setValue(current => clamp(current, minutes));

  const statusLabel = status === 'present' ? 'Present' : 'Late';
  const shown = formatSiteTime(value, shift);
  // Only when the time in was this very minute: nothing after it has passed yet.
  const tooSoon = timeOut && value <= purpose.timeIn;

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onCancel}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <Text style={styles.eyebrow}>{timeOut ? 'Set time-out' : 'Set arrival time'}</Text>
          <Text style={styles.worker}>{workerName}</Text>
          <Text style={styles.meta}>
            {timeOut
              ? `${statusLabel} · in ${formatSiteTime(purpose.timeIn, shift)}`
              : `Marking ${statusLabel}`}
          </Text>

          <Text
            style={styles.value}
            accessibilityLabel={`${timeOut ? 'Time-out' : 'Arrival'} ${shown}`}>
            {shown}
          </Text>

          <View style={styles.stepRow}>
            <StepButton label="−1 h" onPress={() => step(-60)} />
            <StepButton label="+1 h" onPress={() => step(60)} />
          </View>
          <View style={styles.stepRow}>
            <StepButton label="−5 m" onPress={() => step(-5)} />
            <StepButton label="−1 m" onPress={() => step(-1)} />
            <StepButton label="+1 m" onPress={() => step(1)} />
            <StepButton label="+5 m" onPress={() => step(5)} />
          </View>
          <View style={styles.stepRow}>
            <StepButton
              label={timeOut ? shift.end : shift.start}
              onPress={() =>
                setValue(clamp(timeOut ? shiftEndMs(date, shift) : shiftStartMs(date, shift)))
              }
            />
            <StepButton label="Now" onPress={() => setValue(clamp(Date.now()))} />
          </View>

          <Text style={styles.note}>
            {timeOut
              ? 'Flagged for HR review as MANUAL_TIME_OUT. Your actual tap time is recorded beside it. For everyone still on site at the end of the day, use Close shift instead.'
              : 'Flagged for HR review as MANUAL_TIME_OVERRIDE. Your actual tap time is recorded beside it.'}
          </Text>

          <TouchableOpacity
            style={[styles.confirm, tooSoon && styles.confirmDisabled]}
            accessibilityRole="button"
            accessibilityState={{disabled: tooSoon}}
            disabled={tooSoon}
            onPress={() => onConfirm(value)}>
            <Text style={styles.confirmText}>
              {timeOut ? `Time out at ${shown}` : `Mark ${statusLabel} at ${shown}`}
            </Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.cancel} accessibilityRole="button" onPress={onCancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </TouchableOpacity>
        </View>
      </View>
    </Modal>
  );
}

function StepButton({label, onPress}: {label: string; onPress: () => void}) {
  return (
    <TouchableOpacity style={styles.step} accessibilityRole="button" onPress={onPress}>
      <Text style={styles.stepText}>{label}</Text>
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  backdrop: {flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.4)'},
  sheet: {backgroundColor: '#f2f2f3', padding: 20, paddingBottom: 32, borderTopLeftRadius: 8, borderTopRightRadius: 8},
  eyebrow: {fontSize: 12, letterSpacing: 1, textTransform: 'uppercase', color: '#5d5d60'},
  worker: {fontSize: 20, fontWeight: '700', color: '#1d1f20', marginTop: 4},
  meta: {fontSize: 14, color: '#5d5d60'},
  value: {fontSize: 56, fontWeight: '700', color: '#1d1f20', textAlign: 'center', marginVertical: 12},
  stepRow: {flexDirection: 'row', gap: 10, marginBottom: 10},
  step: {
    flex: 1,
    minHeight: 52,
    borderWidth: 1.5,
    borderColor: '#1d2d3d',
    borderRadius: 4,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#fff',
  },
  stepText: {fontSize: 16, fontWeight: '600', color: '#1d2d3d'},
  note: {fontSize: 13, lineHeight: 19, color: '#5d5d60', marginVertical: 10},
  confirm: {
    minHeight: 60,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
  },
  confirmDisabled: {opacity: 0.4},
  confirmText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  cancel: {minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6},
  cancelText: {fontSize: 16, color: '#5d5d60'},
});
