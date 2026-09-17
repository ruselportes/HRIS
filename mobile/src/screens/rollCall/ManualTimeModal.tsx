/**
 * Arrival time entry for "Set each time myself" (Phase 7 — UC-05).
 *
 * Plain steppers rather than a native time picker: the stack has no picker
 * library, and steppers cannot produce a time the server would refuse — they
 * stop at the start of the day and at the current minute.
 *
 * @format
 */

import React, {useState} from 'react';
import {Modal, StyleSheet, Text, TouchableOpacity, View} from 'react-native';
import {
  ShiftConfig,
  formatSiteTime,
  shiftStartMs,
  stepManualTime,
} from '../../db/shiftRules';

type Props = {
  visible: boolean;
  workerName: string;
  status: 'present' | 'late';
  date: string;
  shift: ShiftConfig;
  onCancel: () => void;
  onConfirm: (timeInMs: number) => void;
};

/** A sensible starting point: shift start for Present, now for Late. */
function initialTime(status: 'present' | 'late', date: string, shift: ShiftConfig): number {
  const now = Date.now();
  const start = status === 'present' ? shiftStartMs(date, shift) : now;

  return stepManualTime(start, 0, date, now, shift);
}

export function ManualTimeModal({
  visible,
  workerName,
  status,
  date,
  shift,
  onCancel,
  onConfirm,
}: Props) {
  // Mounted per worker by Roll Call, so the starting value is set once here.
  const [value, setValue] = useState(() => initialTime(status, date, shift));

  const step = (minutes: number) =>
    setValue(current => stepManualTime(current, minutes, date, Date.now(), shift));

  const statusLabel = status === 'present' ? 'Present' : 'Late';

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onCancel}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <Text style={styles.eyebrow}>Set arrival time</Text>
          <Text style={styles.worker}>{workerName}</Text>
          <Text style={styles.meta}>Marking {statusLabel}</Text>

          <Text style={styles.value} accessibilityLabel={`Arrival ${formatSiteTime(value, shift)}`}>
            {formatSiteTime(value, shift)}
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
              label={shift.start}
              onPress={() =>
                setValue(stepManualTime(shiftStartMs(date, shift), 0, date, Date.now(), shift))
              }
            />
            <StepButton
              label="Now"
              onPress={() => setValue(stepManualTime(Date.now(), 0, date, Date.now(), shift))}
            />
          </View>

          <Text style={styles.note}>
            Flagged for HR review as MANUAL_TIME_OVERRIDE. Your actual tap time is recorded beside
            it.
          </Text>

          <TouchableOpacity
            style={styles.confirm}
            accessibilityRole="button"
            onPress={() => onConfirm(value)}>
            <Text style={styles.confirmText}>
              Mark {statusLabel} at {formatSiteTime(value, shift)}
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
  confirmText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  cancel: {minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6},
  cancelText: {fontSize: 16, color: '#5d5d60'},
});
