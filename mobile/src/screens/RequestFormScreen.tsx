/**
 * New request (docs/prototypes/HRIS Foreman Request Form.dc.html) — Phase 9,
 * UC-10 / PR-10.
 *
 * A foreman files overtime for their crew, or leave for a crew member or
 * themselves. The crew comes from the cached roster, so the form opens and
 * fills with no signal. Sending needs signal: offline, the request can be
 * saved as a draft on this phone and sent later. A draft is not a request —
 * nobody can see it until it is sent — and the screen says so.
 *
 * Overtime for several workers is one request each, sharing a batch key, so
 * the endorser and HR decide them together. Any the server refuses stay in
 * the draft with the server's reason, and a retry sends only those.
 *
 * Left out of the prototype: the optional photo. The API has no attachment
 * endpoint yet.
 *
 * @format
 */

import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {BackHandler, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View} from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import {useAuth} from '../auth/AuthContext';
import type {CachedCrew} from '../db/attendanceRepository';
import {clearDraft, loadDraft, saveDraft} from '../db/requestDrafts';
import {ShiftConfig, siteDateOf} from '../db/shiftRules';
import {isReachable} from '../sync/useSyncTriggers';
import {
  LEAVE_TYPES,
  LeaveDraft,
  OVERTIME_REASONS,
  OvertimeDraft,
  RequestDraft,
  addDays,
  crossesMidnight,
  endTime,
  isExpired,
  minutesOf,
  clockOf,
  overtimeEstimate,
  problemsWith,
} from '../requests/requestDraft';
import {SendOutcome, sendDraft} from '../requests/submitRequests';

type Props = {
  crew: CachedCrew | null;
  shift: ShiftConfig;
  onClose: (notice: string | null) => void;
};

type Person = {id: number; name: string; detail: string | null};

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "Monday, 21 September 2026" for a site date. */
function longDate(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  const weekday = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
  return `${weekday}, ${String(d).padStart(2, '0')} ${MONTHS[m - 1]} ${y}`;
}

function hoursText(hours: number): string {
  return Number.isInteger(hours) ? `${hours}` : hours.toFixed(1);
}

export function RequestFormScreen({crew, shift, onClose}: Props) {
  const {user} = useAuth();
  const ownerId = user?.employee_id ?? 0;
  const today = siteDateOf(Date.now(), shift);

  const people: Person[] = useMemo(
    () => [
      {id: ownerId, name: user ? `${user.first_name} ${user.last_name}` : 'You', detail: 'You'},
      ...(crew?.members ?? []).map(m => ({
        id: m.employeeId,
        name: `${m.firstName} ${m.lastName}`,
        detail: m.tradeSkill,
      })),
    ],
    [crew, ownerId, user],
  );
  const nameOf = (id: number) => people.find(p => p.id === id)?.name ?? `Employee #${id}`;

  const [kind, setKind] = useState<'overtime' | 'leave'>('overtime');
  const [overtime, setOvertime] = useState<OvertimeDraft>({
    kind: 'overtime',
    workerIds: (crew?.members ?? []).map(m => m.employeeId),
    date: today,
    start: shift.end,
    hours: 3,
    reason: '',
    batchKey: null,
  });
  const [leave, setLeave] = useState<LeaveDraft>({
    kind: 'leave',
    workerId: null,
    leaveType: 'vacation',
    dateFrom: today,
    days: 1,
    reason: '',
  });
  const [step, setStep] = useState<'edit' | 'review' | 'result'>('edit');
  const [online, setOnline] = useState<boolean | null>(null);
  const [problems, setProblems] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [outcomes, setOutcomes] = useState<SendOutcome[] | null>(null);
  const [showWorkers, setShowWorkers] = useState(false);
  const [otherReason, setOtherReason] = useState(false);

  const draft: RequestDraft = kind === 'overtime' ? overtime : leave;

  // Pick up this foreman's saved draft, unless its date has gone.
  useEffect(() => {
    let active = true;
    loadDraft(ownerId).then(saved => {
      if (!active || !saved) {
        return;
      }
      if (isExpired(saved.draft, today)) {
        clearDraft(ownerId);
        setNotice('Your saved request was dropped: its date has passed, so it can no longer be filed.');
        return;
      }
      setKind(saved.draft.kind);
      if (saved.draft.kind === 'overtime') {
        setOvertime(saved.draft);
        setOtherReason(!!saved.draft.reason && !OVERTIME_REASONS.includes(saved.draft.reason));
      } else {
        setLeave(saved.draft);
      }
      setNotice('This is your saved draft. It has not been sent — nobody can see it yet.');
    });
    return () => {
      active = false;
    };
  }, [ownerId, today]);

  useEffect(() => {
    NetInfo.fetch().then(state => setOnline(state ? isReachable(state) : null));
    return NetInfo.addEventListener(state => setOnline(isReachable(state)));
  }, []);

  const back = useCallback(() => {
    if (step === 'review') {
      setStep('edit');
    } else {
      onClose(null);
    }
    return true;
  }, [step, onClose]);

  useEffect(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', back);
    return () => subscription.remove();
  }, [back]);

  const review = () => {
    const found = problemsWith(draft, today, shift);
    setProblems(found);
    if (found.length === 0) {
      setStep('review');
    }
  };

  const keepAsDraft = async () => {
    await saveDraft(ownerId, draft);
    onClose('Saved on this phone, not sent. Nobody can see it until you send it.');
  };

  const send = async () => {
    setSending(true);
    const result = await sendDraft(draft);
    setSending(false);
    setOutcomes(result.outcomes);

    if (result.remaining === null) {
      await clearDraft(ownerId);
      const count = result.outcomes.length;
      onClose(
        kind === 'overtime'
          ? `Overtime filed for ${count} ${count === 1 ? 'worker' : 'workers'}. It goes to the endorser, then HR.`
          : 'Leave filed. It goes to the endorser, then HR.',
      );
      return;
    }

    // Keep only what did not go through, so a retry never files the rest twice.
    if (result.remaining.kind === 'overtime') {
      setOvertime(result.remaining);
    }
    await saveDraft(ownerId, result.remaining);
    setStep('result');
  };

  /* ------------------------------------------------------------------ */

  if (step === 'result' && outcomes) {
    const failed = outcomes.filter(o => !o.ok);
    return (
      <ScrollView style={styles.container} contentContainerStyle={styles.content}>
        <Text style={styles.title}>Not everything was filed</Text>
        <Text style={styles.subtitle}>
          {outcomes.length - failed.length} of {outcomes.length} went through. The rest are kept as a draft on this phone.
        </Text>
        {outcomes.map(o => (
          <View key={o.employeeId} style={[styles.outcome, o.ok ? styles.outcomeOk : styles.outcomeFailed]}>
            <Text style={styles.outcomeName}>{nameOf(o.employeeId)}</Text>
            <Text style={styles.outcomeText}>{o.ok ? 'Filed' : o.message}</Text>
          </View>
        ))}
        <TouchableOpacity
          style={[styles.primary, !online && styles.disabled]}
          accessibilityRole="button"
          disabled={!online || sending}
          onPress={send}>
          <Text style={styles.primaryText}>{online ? `Try again for ${failed.length}` : 'Needs signal to send'}</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.secondary} accessibilityRole="button" onPress={() => setStep('edit')}>
          <Text style={styles.secondaryText}>Change the draft</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.linkButton} accessibilityRole="button" onPress={() => onClose('The unsent part is saved as a draft.')}>
          <Text style={styles.linkText}>Close — keep the draft</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  if (step === 'review') {
    return (
      <ScrollView style={styles.container} contentContainerStyle={styles.content}>
        <Text style={styles.title}>Check before sending</Text>
        <OnlineBar online={online} />
        <View style={styles.card}>
          {draft.kind === 'overtime' ? (
            <>
              <Row label="Overtime for" value={`${draft.workerIds.length} ${draft.workerIds.length === 1 ? 'worker' : 'workers'}`} />
              <Text style={styles.cardNote}>{draft.workerIds.map(nameOf).join(', ')}</Text>
              <Row label="Date" value={longDate(draft.date)} />
              <Row
                label="Window"
                value={`${draft.start} – ${endTime(draft.start, draft.hours)}${crossesMidnight(draft.start, draft.hours) ? ' (next day)' : ''}`}
              />
              <Row label="Reason" value={draft.reason.trim() || 'None given'} />
              <Text style={styles.cardNote}>{estimateText(draft, shift)}</Text>
            </>
          ) : (
            <>
              <Row label="Leave for" value={draft.workerId === null ? '—' : nameOf(draft.workerId)} />
              <Row label="Type" value={LEAVE_TYPES.find(t => t.value === draft.leaveType)?.label ?? draft.leaveType} />
              <Row label="From" value={longDate(draft.dateFrom)} />
              <Row label="To" value={`${longDate(addDays(draft.dateFrom, draft.days - 1))} · ${draft.days} ${draft.days === 1 ? 'day' : 'days'}`} />
              <Row label="Reason" value={draft.reason.trim()} />
            </>
          )}
        </View>
        <Text style={styles.hint}>It goes to the endorser first, then HR. You can cancel it while it is still pending.</Text>

        <TouchableOpacity
          style={[styles.primary, (!online || sending) && styles.disabled]}
          accessibilityRole="button"
          disabled={!online || sending}
          onPress={send}>
          <Text style={styles.primaryText}>{sending ? 'Sending…' : online ? 'Send now' : 'Needs signal to send'}</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.secondary} accessibilityRole="button" onPress={keepAsDraft}>
          <Text style={styles.secondaryText}>Save as draft — send later</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.linkButton} accessibilityRole="button" onPress={() => setStep('edit')}>
          <Text style={styles.linkText}>Back to edit</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <View style={styles.headerRow}>
        <Text style={styles.title}>New request</Text>
        <TouchableOpacity accessibilityRole="button" onPress={() => onClose(null)} style={styles.closeButton}>
          <Text style={styles.linkText}>Close</Text>
        </TouchableOpacity>
      </View>
      <Text style={styles.subtitle}>{crew ? `${crew.siteName ?? 'Site'} · ${crew.crewName}` : 'No crew cached on this phone'}</Text>
      <OnlineBar online={online} />
      {notice ? <Text style={styles.notice}>{notice}</Text> : null}

      <Section label="What are you filing?" />
      <View style={styles.chipRow}>
        <Chip label="Overtime" active={kind === 'overtime'} onPress={() => setKind('overtime')} big />
        <Chip label="Leave" active={kind === 'leave'} onPress={() => setKind('leave')} big />
      </View>

      {kind === 'overtime' ? (
        <>
          <Section label="Who is it for?" />
          <TouchableOpacity style={styles.whoCard} accessibilityRole="button" onPress={() => setShowWorkers(v => !v)}>
            <View style={styles.flex}>
              <Text style={styles.whoTitle}>
                {overtime.workerIds.length} {overtime.workerIds.length === 1 ? 'worker' : 'workers'}
              </Text>
              <Text style={styles.whoNote}>{crew ? crew.crewName : 'No crew cached'} · tap to {showWorkers ? 'hide' : 'choose'}</Text>
            </View>
          </TouchableOpacity>
          {showWorkers ? (
            <PeopleList
              people={people}
              selected={overtime.workerIds}
              onToggle={id =>
                setOvertime(o => ({
                  ...o,
                  workerIds: o.workerIds.includes(id) ? o.workerIds.filter(w => w !== id) : [...o.workerIds, id],
                }))
              }
            />
          ) : null}

          <Section label="Date" />
          <DateChoice
            value={overtime.date}
            today={today}
            earliest={today}
            labels={['Tonight', 'Tomorrow']}
            onChange={date => setOvertime(o => ({...o, date}))}
          />

          <Section label="Hours" />
          <Stepper
            label="Starts"
            value={overtime.start}
            onMinus={() => setOvertime(o => ({...o, start: clockOf(minutesOf(o.start) - 30)}))}
            onPlus={() => setOvertime(o => ({...o, start: clockOf(minutesOf(o.start) + 30)}))}
          />
          <Stepper
            label="Hours each"
            value={hoursText(overtime.hours)}
            onMinus={() => setOvertime(o => ({...o, hours: Math.max(0.5, o.hours - 0.5)}))}
            onPlus={() => setOvertime(o => ({...o, hours: Math.min(12, o.hours + 0.5)}))}
          />
          <Text style={styles.windowText}>
            {overtime.start} – {endTime(overtime.start, overtime.hours)}
            {crossesMidnight(overtime.start, overtime.hours) ? ' (next day)' : ''}
          </Text>
          <Text style={styles.hint}>{estimateText(overtime, shift)}</Text>

          <Section label="Reason — tap one" />
          {OVERTIME_REASONS.map(reason => (
            <Choice
              key={reason}
              label={reason}
              active={!otherReason && overtime.reason === reason}
              onPress={() => {
                setOtherReason(false);
                setOvertime(o => ({...o, reason}));
              }}
            />
          ))}
          <Choice
            label="Other — type a reason"
            active={otherReason}
            onPress={() => {
              setOtherReason(true);
              setOvertime(o => ({...o, reason: OVERTIME_REASONS.includes(o.reason) ? '' : o.reason}));
            }}
          />
          {otherReason ? (
            <TextInput
              style={styles.textArea}
              multiline
              maxLength={500}
              value={overtime.reason}
              placeholder="Why is overtime needed?"
              onChangeText={reason => setOvertime(o => ({...o, reason}))}
            />
          ) : null}
        </>
      ) : (
        <>
          <Section label="Who is it for?" />
          <PeopleList
            people={people}
            selected={leave.workerId === null ? [] : [leave.workerId]}
            onToggle={id => setLeave(l => ({...l, workerId: l.workerId === id ? null : id}))}
          />

          <Section label="Type" />
          <View style={styles.chipWrap}>
            {LEAVE_TYPES.map(t => (
              <Chip key={t.value} label={t.label} active={leave.leaveType === t.value} onPress={() => setLeave(l => ({...l, leaveType: t.value}))} />
            ))}
          </View>

          <Section label="First day" />
          <DateChoice
            value={leave.dateFrom}
            today={today}
            earliest={addDays(today, -14)}
            labels={['Today', 'Tomorrow']}
            onChange={dateFrom => setLeave(l => ({...l, dateFrom}))}
          />
          <Stepper
            label="Days"
            value={String(leave.days)}
            onMinus={() => setLeave(l => ({...l, days: Math.max(1, l.days - 1)}))}
            onPlus={() => setLeave(l => ({...l, days: Math.min(30, l.days + 1)}))}
          />
          <Text style={styles.hint}>
            Until {longDate(addDays(leave.dateFrom, leave.days - 1))}. Days already past can only be sick leave.
          </Text>

          <Section label="Reason" />
          <TextInput
            style={styles.textArea}
            multiline
            maxLength={500}
            value={leave.reason}
            placeholder="Required"
            onChangeText={reason => setLeave(l => ({...l, reason}))}
          />
        </>
      )}

      {problems.length ? (
        <View style={styles.problems}>
          {problems.map(p => (
            <Text key={p} style={styles.problemText}>
              {p}
            </Text>
          ))}
        </View>
      ) : null}

      <TouchableOpacity style={styles.primary} accessibilityRole="button" onPress={review}>
        <Text style={styles.primaryText}>Check before sending</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

/** What the window is likely to pay, in plain words. The server's figure is the one filed. */
function estimateText(draft: OvertimeDraft, shift: ShiftConfig): string {
  const {paidHours, inShiftHours, nightHours} = overtimeEstimate(draft.start, draft.hours, shift);
  const parts = [`About ${hoursText(paidHours)} h of overtime each`];
  if (nightHours > 0) {
    parts.push(`${hoursText(nightHours)} h after 22:00 also earn night differential`);
  }
  if (inShiftHours > 0) {
    parts.push(`${hoursText(inShiftHours)} h fall inside the ${shift.start}–${shift.end} shift and are not overtime`);
  }
  return `${parts.join('; ')}. The server works out the exact paid hours when it is filed.`;
}

function OnlineBar({online}: {online: boolean | null}) {
  const up = online !== false;
  return (
    <View style={[styles.onlineBar, up ? styles.onlineUp : styles.onlineDown]}>
      <Text style={styles.onlineText}>
        {online === null ? 'Checking signal…' : up ? 'Online — ready to send' : 'Offline — you can save a draft and send it later'}
      </Text>
    </View>
  );
}

function Section({label}: {label: string}) {
  return <Text style={styles.section}>{label}</Text>;
}

function Chip({label, active, onPress, big}: {label: string; active: boolean; onPress: () => void; big?: boolean}) {
  return (
    <TouchableOpacity
      style={[styles.chip, big && styles.chipBig, active && styles.chipActive]}
      accessibilityRole="button"
      accessibilityState={{selected: active}}
      onPress={onPress}>
      <Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
    </TouchableOpacity>
  );
}

function Choice({label, active, onPress}: {label: string; active: boolean; onPress: () => void}) {
  return (
    <TouchableOpacity
      style={[styles.choice, active && styles.chipActive]}
      accessibilityRole="button"
      accessibilityState={{selected: active}}
      onPress={onPress}>
      <Text style={[styles.choiceText, active && styles.chipTextActive]}>{label}</Text>
    </TouchableOpacity>
  );
}

function Stepper({label, value, onMinus, onPlus}: {label: string; value: string; onMinus: () => void; onPlus: () => void}) {
  return (
    <View style={styles.stepper}>
      <Text style={styles.stepperLabel}>{label}</Text>
      <TouchableOpacity style={styles.stepButton} accessibilityRole="button" accessibilityLabel={`${label} down`} onPress={onMinus}>
        <Text style={styles.stepButtonText}>−</Text>
      </TouchableOpacity>
      <Text style={styles.stepValue}>{value}</Text>
      <TouchableOpacity style={styles.stepButton} accessibilityRole="button" accessibilityLabel={`${label} up`} onPress={onPlus}>
        <Text style={styles.stepButtonText}>+</Text>
      </TouchableOpacity>
    </View>
  );
}

/** Two quick days and "Pick date", which steps a day at a time — the stack has no date picker. */
function DateChoice({
  value,
  today,
  earliest,
  labels,
  onChange,
}: {
  value: string;
  today: string;
  earliest: string;
  labels: [string, string];
  onChange: (date: string) => void;
}) {
  const tomorrow = addDays(today, 1);
  const picking = value !== today && value !== tomorrow;
  const [open, setOpen] = useState(picking);
  const latest = addDays(today, 60);

  const choose = (date: string) => {
    setOpen(false);
    onChange(date);
  };

  return (
    <>
      <View style={styles.chipRow}>
        <Chip label={labels[0]} active={!open && value === today} onPress={() => choose(today)} big />
        <Chip label={labels[1]} active={!open && value === tomorrow} onPress={() => choose(tomorrow)} big />
        <Chip label="Pick date" active={open} onPress={() => setOpen(true)} big />
      </View>
      {open ? (
        <View style={styles.stepper}>
          <TouchableOpacity
            style={styles.stepButton}
            accessibilityRole="button"
            accessibilityLabel="Previous day"
            disabled={value <= earliest}
            onPress={() => onChange(addDays(value, -1))}>
            <Text style={styles.stepButtonText}>‹</Text>
          </TouchableOpacity>
          <Text style={[styles.stepValue, styles.flex]}>{longDate(value)}</Text>
          <TouchableOpacity
            style={styles.stepButton}
            accessibilityRole="button"
            accessibilityLabel="Next day"
            disabled={value >= latest}
            onPress={() => onChange(addDays(value, 1))}>
            <Text style={styles.stepButtonText}>›</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <Text style={styles.hint}>{longDate(value)}</Text>
      )}
    </>
  );
}

function PeopleList({people, selected, onToggle}: {people: Person[]; selected: number[]; onToggle: (id: number) => void}) {
  return (
    <View style={styles.people}>
      {people.map(person => {
        const on = selected.includes(person.id);
        return (
          <TouchableOpacity
            key={person.id}
            style={[styles.person, on && styles.personOn]}
            accessibilityRole="checkbox"
            accessibilityState={{checked: on}}
            onPress={() => onToggle(person.id)}>
            <Text style={[styles.personMark, on && styles.personMarkOn]}>{on ? '✓' : ''}</Text>
            <View style={styles.flex}>
              <Text style={styles.personName}>{person.name}</Text>
              {person.detail ? <Text style={styles.personDetail}>{person.detail}</Text> : null}
            </View>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

function Row({label, value}: {label: string; value: string}) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3'},
  content: {padding: 20, paddingBottom: 48},
  flex: {flex: 1},
  headerRow: {flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between'},
  closeButton: {minHeight: 44, justifyContent: 'center', paddingHorizontal: 8},
  title: {fontSize: 26, fontWeight: '700', color: '#1d1f20'},
  subtitle: {fontSize: 15, color: '#5d5d60', marginTop: 4, marginBottom: 12},
  onlineBar: {padding: 10, borderRadius: 4, marginBottom: 12, alignItems: 'center'},
  onlineUp: {backgroundColor: '#e6f1ea'},
  onlineDown: {backgroundColor: '#fcece0'},
  onlineText: {fontSize: 13, fontWeight: '600', color: '#1d1f20'},
  notice: {fontSize: 14, lineHeight: 20, color: '#4a3260', backgroundColor: '#efe9f4', padding: 12, borderRadius: 4, marginBottom: 8},
  section: {fontSize: 12, letterSpacing: 1, textTransform: 'uppercase', color: '#5d5d60', marginTop: 22, marginBottom: 10},
  chipRow: {flexDirection: 'row', gap: 10},
  chipWrap: {flexDirection: 'row', flexWrap: 'wrap', gap: 8},
  chip: {
    minHeight: 44,
    paddingHorizontal: 14,
    borderWidth: 1,
    borderColor: '#d4d4d7',
    borderRadius: 4,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#fff',
  },
  chipBig: {flex: 1, minHeight: 56},
  chipActive: {borderColor: '#1d2d3d', backgroundColor: '#1d2d3d'},
  chipText: {fontSize: 16, color: '#1d1f20'},
  chipTextActive: {color: '#f2f2f3'},
  whoCard: {
    minHeight: 68,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#1d2d3d',
    borderRadius: 4,
    paddingHorizontal: 16,
    backgroundColor: '#fff',
  },
  whoTitle: {fontSize: 17, color: '#1d1f20'},
  whoNote: {fontSize: 13, color: '#5d5d60'},
  people: {marginTop: 10, borderWidth: 1, borderColor: '#d4d4d7', borderRadius: 4, backgroundColor: '#fff'},
  person: {minHeight: 52, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, borderBottomWidth: 1, borderBottomColor: '#e7e7ea'},
  personOn: {backgroundColor: '#eef6ff'},
  personMark: {
    width: 24,
    height: 24,
    borderWidth: 1.5,
    borderColor: '#98989b',
    borderRadius: 3,
    marginRight: 12,
    textAlign: 'center',
    lineHeight: 21,
    fontSize: 15,
    color: '#f2f2f3',
  },
  personMarkOn: {backgroundColor: '#1d2d3d', borderColor: '#1d2d3d'},
  personName: {fontSize: 16, color: '#1d1f20'},
  personDetail: {fontSize: 12, color: '#7a7a7d'},
  stepper: {flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 10},
  stepperLabel: {flex: 1, fontSize: 15, color: '#1d1f20'},
  stepButton: {
    width: 56,
    height: 56,
    borderWidth: 1.5,
    borderColor: '#1d2d3d',
    borderRadius: 4,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#fff',
  },
  stepButtonText: {fontSize: 24, color: '#1d2d3d'},
  stepValue: {minWidth: 64, textAlign: 'center', fontSize: 22, fontWeight: '700', color: '#1d1f20'},
  windowText: {fontSize: 18, fontWeight: '600', color: '#1d1f20', marginTop: 12},
  hint: {fontSize: 13, lineHeight: 19, color: '#5d5d60', marginTop: 8},
  choice: {
    minHeight: 52,
    borderWidth: 1,
    borderColor: '#d4d4d7',
    borderRadius: 4,
    justifyContent: 'center',
    paddingHorizontal: 16,
    marginBottom: 8,
    backgroundColor: '#fff',
  },
  choiceText: {fontSize: 16, color: '#1d1f20'},
  textArea: {
    minHeight: 88,
    borderWidth: 1,
    borderColor: '#b7b7ba',
    borderRadius: 4,
    padding: 12,
    fontSize: 16,
    color: '#1d1f20',
    backgroundColor: '#fff',
    textAlignVertical: 'top',
  },
  problems: {marginTop: 16, backgroundColor: '#f6e1de', borderRadius: 4, padding: 12, gap: 4},
  problemText: {fontSize: 14, color: '#75261c'},
  primary: {
    minHeight: 64,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 22,
  },
  primaryText: {color: '#f2f2f3', fontSize: 18, fontWeight: '600'},
  disabled: {opacity: 0.45},
  secondary: {
    minHeight: 56,
    borderRadius: 4,
    borderWidth: 1.5,
    borderColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 10,
  },
  secondaryText: {color: '#1d2d3d', fontSize: 16, fontWeight: '600'},
  linkButton: {minHeight: 48, alignItems: 'center', justifyContent: 'center', marginTop: 6},
  linkText: {fontSize: 15, color: '#5d5d60'},
  card: {backgroundColor: '#fff', borderRadius: 4, padding: 16, gap: 10},
  cardNote: {fontSize: 13, lineHeight: 19, color: '#5d5d60'},
  row: {flexDirection: 'row', gap: 12},
  rowLabel: {width: 96, fontSize: 14, color: '#5d5d60'},
  rowValue: {flex: 1, fontSize: 15, color: '#1d1f20'},
  outcome: {borderRadius: 4, padding: 12, marginTop: 8},
  outcomeOk: {backgroundColor: '#e6f1ea'},
  outcomeFailed: {backgroundColor: '#f6e1de'},
  outcomeName: {fontSize: 15, fontWeight: '600', color: '#1d1f20'},
  outcomeText: {fontSize: 14, color: '#3a3a3d', marginTop: 2},
});
