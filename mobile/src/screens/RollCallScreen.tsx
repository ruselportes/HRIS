/**
 * Roll Call (docs/prototypes/HRIS Foreman Attendance Mobile.dc.html) — the
 * offline-capable tap checklist itself. Every write here goes straight to
 * local SQLite (attendanceRepository) with no network call in the path,
 * which is the actual "offline-capable" requirement (UC-04).
 *
 * Late start (Phase 7 — UC-05, STD TC-04): opened past the grace window with
 * nobody marked, the roster is replaced by LateStartPrompt until the foreman
 * picks how time in is recorded. Under the shift credit, Present is credited
 * from shift start; under "Set each time myself", Present and Late ask for an
 * arrival time. Late and Absent under the credit, and Absent always, stay
 * ordinary taps — neither is an arrival the credit can speak for.
 *
 * @format
 */

import React, {useCallback, useState} from 'react';
import {FlatList, StyleSheet, Text, TouchableOpacity, View} from 'react-native';
import {useFocusEffect} from '@react-navigation/native';
import {useAuth} from '../auth/AuthContext';
import {AttendanceStatus, todayLocalDate} from '../db/attendanceLogic';
import {
  CachedCrew,
  DeviceNotBoundError,
  LateStartChoice,
  LateStartMode,
  TodayRecord,
  getCachedCrew,
  getLateStartChoice,
  getShiftConfig,
  hasRollCallStarted,
  listTodayAttendance,
  recordManualTime,
  recordShiftCredit,
  recordStatus,
  saveLateStartChoice,
  undoAttendance,
} from '../db/attendanceRepository';
import {DEFAULT_SHIFT, ShiftConfig, formatSiteTime, isLateStart} from '../db/shiftRules';
import {LateStartPrompt} from './rollCall/LateStartPrompt';
import {ManualTimeModal} from './rollCall/ManualTimeModal';

type RowState = {
  employeeId: number;
  name: string;
  tradeSkill: string | null;
  record: TodayRecord;
};

type Choice = 'present' | 'late' | 'absent';

export function RollCallScreen() {
  const {user} = useAuth();
  const [crew, setCrew] = useState<CachedCrew | null>(null);
  const [rows, setRows] = useState<RowState[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [shift, setShift] = useState<ShiftConfig>(DEFAULT_SHIFT);
  const [date, setDate] = useState(todayLocalDate());
  const [lateStart, setLateStart] = useState<LateStartChoice | null>(null);
  const [prompt, setPrompt] = useState<{openedAt: number} | null>(null);
  const [manual, setManual] = useState<{row: RowState; status: 'present' | 'late'} | null>(
    null,
  );

  /*
   * On focus rather than on mount: Roll Call is a tab, so it stays mounted,
   * and a foreman who looked at it at 06:50 and returns at 09:30 is starting
   * late now.
   */
  const load = useCallback(async () => {
    const cached = await getCachedCrew();
    setCrew(cached);

    if (!cached) {
      return;
    }

    const today = todayLocalDate();
    const [attendance, shiftConfig, choice, started] = await Promise.all([
      listTodayAttendance(),
      getShiftConfig(),
      getLateStartChoice(cached.crewId, today),
      hasRollCallStarted(cached.crewId, today),
    ]);

    setDate(today);
    setShift(shiftConfig);
    setLateStart(choice);
    setRows(
      cached.members.map(member => ({
        employeeId: member.employeeId,
        name: `${member.firstName} ${member.lastName}`,
        tradeSkill: member.tradeSkill,
        record: attendance.get(member.employeeId) ?? {
          employeeId: member.employeeId,
          date: today,
          status: 'pending' as AttendanceStatus,
          timeIn: null,
          overrideType: null,
          capturedAt: null,
        },
      })),
    );

    const now = Date.now();
    setPrompt(
      choice === null && !started && isLateStart(now, today, shiftConfig) ? {openedAt: now} : null,
    );
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  /**
   * Surface a capture failure instead of leaving the row silently unchanged.
   * The likely cause is an unbound device, and a tap that appears to do
   * nothing would have the foreman marking the same worker repeatedly.
   */
  const applyCapture = async (employeeId: number, capture: () => Promise<TodayRecord>) => {
    setError(null);
    try {
      const updated = await capture();
      setRows(prev =>
        prev.map(row => (row.employeeId === employeeId ? {...row, record: updated} : row)),
      );
    } catch (err) {
      setError(
        err instanceof DeviceNotBoundError
          ? 'This device is not bound yet — bind it before recording attendance.'
          : 'Could not record that tap. It was not saved; try again.',
      );
    }
  };

  const mark = (row: RowState, status: Choice) => {
    if (!crew) {
      return;
    }

    const {employeeId} = row;
    const mode = lateStart?.mode;

    if (mode === 'manual' && status !== 'absent') {
      setManual({row, status});
      return;
    }

    if (mode === 'credit' && status === 'present') {
      applyCapture(employeeId, () => recordShiftCredit(employeeId, crew.crewId));
      return;
    }

    applyCapture(employeeId, () => recordStatus(employeeId, crew.crewId, status));
  };

  const undo = (employeeId: number) =>
    crew && applyCapture(employeeId, () => undoAttendance(employeeId, crew.crewId));

  const choose = async (mode: LateStartMode) => {
    if (!crew) {
      return;
    }

    setLateStart(await saveLateStartChoice(crew.crewId, mode, date));
    setPrompt(null);
  };

  if (!crew) {
    return (
      <View style={styles.centered}>
        <Text style={styles.emptyText}>No cached roster yet — connect once to fetch it.</Text>
      </View>
    );
  }

  if (prompt) {
    return (
      <LateStartPrompt
        crewName={crew.crewName}
        foremanName={user ? `${user.first_name} ${user.last_name}` : 'You'}
        shift={shift}
        date={date}
        openedAt={prompt.openedAt}
        onChoose={choose}
      />
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Roll Call</Text>
      <Text style={styles.subtitle}>{crew.crewName}</Text>

      {lateStart && (
        <LateStartBanner
          choice={lateStart}
          shift={shift}
          onChange={() => setPrompt({openedAt: Date.now()})}
        />
      )}

      {error && <Text style={styles.error}>{error}</Text>}

      <FlatList
        data={rows}
        keyExtractor={item => String(item.employeeId)}
        renderItem={({item}) => (
          <RollCallRow
            row={item}
            shift={shift}
            mode={lateStart?.mode ?? null}
            onMark={mark}
            onUndo={undo}
          />
        )}
        contentContainerStyle={styles.list}
      />

      {manual && (
        <ManualTimeModal
          visible
          workerName={manual.row.name}
          status={manual.status}
          date={date}
          shift={shift}
          onCancel={() => setManual(null)}
          onConfirm={timeInMs => {
            const {row, status} = manual;
            setManual(null);
            applyCapture(row.employeeId, () =>
              recordManualTime(row.employeeId, crew.crewId, status, timeInMs),
            );
          }}
        />
      )}
    </View>
  );
}

function LateStartBanner({
  choice,
  shift,
  onChange,
}: {
  choice: LateStartChoice;
  shift: ShiftConfig;
  onChange: () => void;
}) {
  const override = choice.mode !== 'none';
  const title =
    choice.mode === 'credit'
      ? `${shift.start} shift credit applied`
      : choice.mode === 'manual'
        ? 'Setting arrival times yourself'
        : 'Late start · recording actual tap times';

  return (
    <View style={[styles.banner, override ? styles.bannerOverride : styles.bannerPlain]}>
      <View style={styles.bannerBody}>
        <Text style={styles.bannerTitle}>{title}</Text>
        <Text style={styles.bannerMeta}>
          {override ? 'Flagged for HR review · ' : ''}
          {formatSiteTime(choice.decidedAt, shift)}
        </Text>
      </View>
      <TouchableOpacity style={styles.bannerAction} accessibilityRole="button" onPress={onChange}>
        <Text style={styles.bannerActionText}>Change</Text>
      </TouchableOpacity>
    </View>
  );
}

function RollCallRow({
  row,
  shift,
  mode,
  onMark,
  onUndo,
}: {
  row: RowState;
  shift: ShiftConfig;
  mode: LateStartMode | null;
  onMark: (row: RowState, status: Choice) => void;
  onUndo: (employeeId: number) => void;
}) {
  const {status} = row.record;
  const marked = status !== 'pending';
  const detail = timeDetail(row.record, shift);

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
      {detail && (
        <Text style={[styles.rowDetail, row.record.overrideType && styles.rowDetailOverride]}>
          {detail}
        </Text>
      )}

      <View style={styles.buttonsRow}>
        {(['present', 'late', 'absent'] as const)
          // Under manual times the current status stays tappable, to correct the time.
          .filter(option => option !== status || (mode === 'manual' && option !== 'absent'))
          .map(option => {
            const hint = buttonHint(option, mode, shift);

            return (
              <TouchableOpacity
                key={option}
                style={[styles.choiceButton, choiceButtonStyleFor(option)]}
                accessibilityRole="button"
                onPress={() => onMark(row, option)}>
                <Text style={[styles.choiceButtonText, choiceTextStyleFor(option)]}>
                  {statusLabel(option)}
                </Text>
                {hint && <Text style={styles.choiceButtonHint}>{hint}</Text>}
              </TouchableOpacity>
            );
          })}
        {marked && (
          <TouchableOpacity style={styles.undoButton} onPress={() => onUndo(row.employeeId)}>
            <Text style={styles.undoButtonText}>Undo</Text>
          </TouchableOpacity>
        )}
      </View>
    </View>
  );
}

/**
 * What was credited against when it was tapped. An override shows both, so the
 * foreman sees exactly what HR will be asked to approve.
 */
function timeDetail(record: TodayRecord, shift: ShiftConfig): string | null {
  if (record.timeIn === null) {
    return null;
  }

  const credited = formatSiteTime(record.timeIn, shift);
  const tapped = record.capturedAt === null ? null : formatSiteTime(record.capturedAt, shift);
  const tappedSuffix = tapped ? ` · tapped ${tapped}` : '';

  if (record.overrideType === 'shift_credit') {
    return `Credited ${credited}${tappedSuffix}`;
  }

  if (record.overrideType === 'manual_time') {
    return `Set to ${credited}${tappedSuffix}`;
  }

  return `Tapped ${credited}`;
}

function buttonHint(option: Choice, mode: LateStartMode | null, shift: ShiftConfig): string | null {
  if (mode === 'credit' && option === 'present') {
    return `from ${shift.start}`;
  }

  if (mode === 'manual' && option !== 'absent') {
    return 'set time';
  }

  return null;
}

function statusLabel(status: AttendanceStatus | Choice): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

function tagStyleFor(status: AttendanceStatus) {
  if (status === 'present') return {backgroundColor: '#e6f1ea'};
  if (status === 'late') return {backgroundColor: '#fcece0'};
  if (status === 'absent') return {backgroundColor: '#f6e1de'};
  return {};
}

function choiceButtonStyleFor(option: Choice) {
  if (option === 'present') return {borderColor: '#2F7A4D'};
  if (option === 'late') return {borderColor: '#C9781B'};
  return {borderColor: '#A83A2C'};
}

function choiceTextStyleFor(option: Choice) {
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
  error: {
    fontSize: 14,
    color: '#75261c',
    backgroundColor: '#f6e1de',
    marginHorizontal: 20,
    marginBottom: 12,
    padding: 10,
    borderRadius: 4,
  },
  banner: {
    flexDirection: 'row',
    alignItems: 'center',
    marginHorizontal: 20,
    marginBottom: 12,
    paddingLeft: 12,
    borderRadius: 4,
  },
  bannerOverride: {backgroundColor: '#fcece0'},
  bannerPlain: {backgroundColor: '#e4e4e7'},
  bannerBody: {flex: 1, paddingVertical: 10},
  bannerTitle: {fontSize: 14, fontWeight: '700', color: '#1d1f20'},
  bannerMeta: {fontSize: 13, color: '#5d5d60', marginTop: 2},
  bannerAction: {minHeight: 48, justifyContent: 'center', paddingHorizontal: 14},
  bannerActionText: {fontSize: 14, fontWeight: '600', color: '#1d2d3d'},
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
  rowDetail: {fontSize: 13, color: '#5d5d60', marginTop: 4},
  rowDetailOverride: {color: '#96420e', fontWeight: '600'},
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
  choiceButtonHint: {fontSize: 11, color: '#5d5d60', marginTop: 1},
  undoButton: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: 12,
  },
  undoButtonText: {fontSize: 14, color: '#5d5d60'},
});
