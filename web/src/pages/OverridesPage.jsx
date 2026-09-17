import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { NAV, tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Overrides & Audit (docs/prototypes/HRIS Late Override Audit.dc.html) —
 * Phase 7, UC-05, STD TC-04 step 5.
 *
 * One card per override event: a foreman's late-start credit or manual times
 * for one crew on one day. Overridden records are held out of payroll until HR
 * decides. Approving pays the credited time; rejecting pays each worker from
 * their actual tap. Only HR decides — everyone else with access reads.
 *
 * Not built from the prototype, because nothing records them yet: the
 * foreman's reason, device state, gate-log corroboration, leave conflicts,
 * per-record selection, "Ask foreman" and the payroll lock countdown.
 */

const SITE_TZ = 'Asia/Manila'

const TYPE_LABEL = {
  FOREMAN_LATE_OVERRIDE: 'Late-start shift credit',
  MANUAL_TIME_OVERRIDE: 'Manual time in',
}

const STATUS_TAG = {
  pending: { label: 'Awaiting review', ...tone.late },
  approved: { label: 'Approved', ...tone.present },
  rejected: { label: 'Rejected', ...tone.absent },
}

const OVERRIDE_INK = '#6B4B8A'

const selectCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnPrimary =
  'inline-flex items-center justify-center gap-2 border border-primary-800 bg-primary-800 px-4 py-2 text-[13px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'
const btnReject =
  'inline-flex items-center justify-center gap-2 border border-[#A83A2C] bg-canvas px-4 py-2 text-[13px] text-[#75261C] disabled:cursor-not-allowed disabled:opacity-40'
const btnGhost = 'inline-flex items-center justify-center gap-1.5 border border-neutral-400 px-2.5 py-1 text-xs text-ink'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const timeFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
const shortDayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short' })

const siteTime = (iso) => (iso ? timeFmt.format(new Date(iso)) : '—')
// subject_date is a plain Y-m-d in site time; anchor it there so it cannot shift a day.
const siteDay = (ymd) => (ymd ? dayFmt.format(new Date(`${ymd}T12:00:00+08:00`)) : '—')
const shortDay = (iso) => (iso ? shortDayFmt.format(new Date(iso)) : '')

function formatGap(minutes) {
  const m = Math.max(0, Math.round(minutes))
  const h = Math.floor(m / 60)
  return h > 0 ? `${h} h ${m % 60} m` : `${m} m`
}

const peso = (amount) =>
  `₱${Number(amount ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const hours = (value) => Number(value ?? 0).toFixed(2)

function StatCard({ label, value, note, accent }) {
  return (
    <div className="relative border border-neutral-300 bg-surface p-3.5">
      <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      <div className="mt-1.5 font-heading text-3xl tabular-nums" style={accent ? { color: accent } : undefined}>
        {value}
      </div>
      <div className="mt-0.5 text-[11px] text-neutral-700">{note}</div>
    </div>
  )
}

function Tag({ bg, fg, children, className = '' }) {
  return (
    <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 text-[11px] ${className}`} style={{ background: bg, color: fg }}>
      {children}
    </span>
  )
}

function TypePill({ type, solid }) {
  return (
    <span
      className="inline-flex items-center gap-2 px-2.5 py-1"
      style={solid ? { background: OVERRIDE_INK, color: '#fff' } : { border: `1px solid ${OVERRIDE_INK}`, color: '#4A3260' }}
    >
      <Icon name="flag" size={12} />
      <span className="font-mono text-xs tracking-[.04em]">{type}</span>
    </span>
  )
}

function Field({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</label>
      {children}
    </div>
  )
}

function Meta({ label, value, sub }) {
  return (
    <div className="min-w-0">
      <div className="text-[11px] text-neutral-700">{label}</div>
      <div className="truncate text-sm">{value}</div>
      {sub ? <div className="truncate text-[11px] text-neutral-700">{sub}</div> : null}
    </div>
  )
}

/** What approving or rejecting means for pay, in the event's own terms. */
function payrollEffect(event) {
  if (event.review_status === 'approved') return { value: 'Paid from credited time', sub: 'approved by HR' }
  if (event.review_status === 'rejected') return { value: 'Paid from actual taps', sub: 'credited time discarded' }
  return { value: 'Held from payroll', sub: 'until HR decides' }
}

function headline(event, records) {
  const where = [event.crew?.crew_name, event.site?.site_name].filter(Boolean).join(' · ')
  const count = `${event.record_count} ${event.record_count === 1 ? 'record' : 'records'}`

  if (event.action_type === 'FOREMAN_LATE_OVERRIDE') {
    const credited = records?.[0] ? siteTime(records[0].credited_time_in) : 'shift start'
    return `${count} credited ${credited} · ${where}`
  }

  return `${count} with arrival times set by the foreman · ${where}`
}

function RecordsTable({ event, records }) {
  if (!records) {
    return <p className="px-5 py-4 text-xs text-neutral-700">Loading records…</p>
  }

  const statusTag =
    event.review_status === 'approved'
      ? { label: 'Approved', ...tone.present }
      : event.review_status === 'rejected'
        ? { label: 'Paid from tap', ...tone.absent }
        : { label: 'Override flagged', ...tone.flagged }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[820px] border-collapse whitespace-nowrap text-sm tabular-nums">
        <thead>
          <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
            <th className="py-2.5 pl-5 pr-4 font-normal">Employee</th>
            <th className="w-[110px] py-2.5 pr-4 font-normal">Credited in</th>
            <th className="w-[110px] py-2.5 pr-4 font-normal">Actual tap</th>
            <th className="w-[100px] py-2.5 pr-4 font-normal">Gap</th>
            <th className="w-[80px] py-2.5 pr-4 font-normal">Hrs</th>
            <th className="w-[120px] py-2.5 pr-4 font-normal">At stake</th>
            <th className="w-[150px] py-2.5 pr-5 font-normal">Record status</th>
          </tr>
        </thead>
        <tbody>
          {records.map((record) => (
            <tr key={record.attendance_id} className="border-b border-neutral-200" style={{ boxShadow: `inset 3px 0 0 ${OVERRIDE_INK}` }}>
              <td className="py-2.5 pl-5 pr-4">
                {record.employee.full_name}
                <div className="text-[11px] text-neutral-700">
                  {[record.employee.employee_code, record.employee.trade_skill].filter(Boolean).join(' · ')}
                </div>
              </td>
              <td className="py-2.5 pr-4">{siteTime(record.credited_time_in)}</td>
              <td className="py-2.5 pr-4">{siteTime(record.tapped_at)}</td>
              <td className="py-2.5 pr-4" style={record.credited_minutes > 0 ? { color: '#96420E' } : undefined}>
                {formatGap(record.credited_minutes)}
              </td>
              <td className="py-2.5 pr-4">{hours(record.credited_hours)}</td>
              <td className="py-2.5 pr-4">{peso(record.amount_at_stake)}</td>
              <td className="py-2.5 pr-5">
                <Tag bg={statusTag.bg} fg={statusTag.fg}>
                  <Icon name="flag" size={11} />
                  {statusTag.label}
                </Tag>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function PendingEventCard({ event, records, canDecide, onDecide }) {
  const first = records?.[0]
  const gap = first ? (new Date(event.logged_at) - new Date(first.credited_time_in)) / 60000 : null
  const effect = payrollEffect(event)

  return (
    <article className="border border-neutral-300 bg-surface" style={{ boxShadow: `inset 4px 0 0 ${OVERRIDE_INK}` }}>
      <div className="border-b border-neutral-300 px-5 py-4">
        <div className="flex items-start gap-4">
          <div className="min-w-0 flex-1">
            <div className="mb-2 flex flex-wrap items-center gap-2.5">
              <TypePill type={event.action_type} solid />
              <Tag bg={STATUS_TAG.pending.bg} fg={STATUS_TAG.pending.fg}>
                {STATUS_TAG.pending.label}
              </Tag>
              <span className="text-xs text-neutral-700">Event #{event.code}</span>
            </div>
            <h3 className="font-heading text-2xl leading-tight">{headline(event, records)}</h3>
            <div className="mt-1 text-[13px] tabular-nums text-neutral-700">
              {siteDay(event.date)} · first tap {siteTime(event.logged_at)}
              {event.action_type === 'FOREMAN_LATE_OVERRIDE' && gap !== null ? `, ${formatGap(gap)} after shift start` : ''}
            </div>
          </div>
          <div className="flex-none text-right">
            <div className="font-heading text-3xl leading-none tabular-nums" style={{ color: '#96420E' }}>
              {hours(event.hours_at_stake)}
            </div>
            <div className="text-[11px] text-neutral-700">hours at stake · {peso(event.amount_at_stake)}</div>
          </div>
        </div>

        <div className="mt-4 grid grid-cols-2 gap-5 border-t border-neutral-200 pt-4 md:grid-cols-4">
          <Meta label="Raised by" value={event.actor?.full_name ?? '—'} sub="Site Foreman" />
          <Meta label="Override" value={TYPE_LABEL[event.action_type] ?? event.action_type} sub="signed on the foreman's device" />
          <Meta label="Crew" value={event.crew?.crew_name ?? '—'} sub={event.site?.site_name} />
          <Meta label="Payroll" value={effect.value} sub={effect.sub} />
        </div>

        {event.description?.includes('Reopened') ? (
          <p className="mt-3 flex items-start gap-2 text-xs text-[#8A5211]">
            <Icon name="alert" size={14} className="mt-px flex-none" />
            Reopened: a record synced after an earlier decision, so this event needs review again.
          </p>
        ) : null}
      </div>

      <RecordsTable event={event} records={records} />

      <div className="flex flex-wrap items-center gap-3.5 border-t border-neutral-300 px-5 py-4">
        <p className="max-w-[520px] text-xs text-neutral-700">
          {canDecide
            ? event.action_type === 'FOREMAN_LATE_OVERRIDE'
              ? `Approving pays these ${event.record_count} records from the credited time. Rejecting pays each worker from their actual tap.`
              : `Approving pays the times the foreman set. Rejecting pays each worker from their actual tap.`
            : 'Only HR Personnel can approve or reject an override.'}
        </p>
        {canDecide ? (
          <div className="ml-auto flex gap-2.5">
            <button className={btnReject} onClick={() => onDecide(event, 'reject')}>
              Reject
            </button>
            <button className={btnPrimary} onClick={() => onDecide(event, 'approve')}>
              Approve {event.record_count} {event.record_count === 1 ? 'record' : 'records'}
            </button>
          </div>
        ) : null}
      </div>
    </article>
  )
}

function DecidedEventCard({ event, records, expanded, onToggle }) {
  const status = STATUS_TAG[event.review_status] ?? STATUS_TAG.pending
  const where = [event.crew?.crew_name, event.site?.site_name].filter(Boolean).join(' · ')
  const verb = event.review_status === 'approved' ? 'Approved' : 'Rejected'

  return (
    <article className="border border-neutral-300 bg-surface" style={{ boxShadow: 'inset 4px 0 0 var(--color-neutral-400)' }}>
      <div className="flex items-center gap-4 px-5 py-4">
        <div className="min-w-0 flex-1">
          <div className="mb-1.5 flex flex-wrap items-center gap-2.5">
            <TypePill type={event.action_type} />
            <Tag bg={status.bg} fg={status.fg}>
              {verb} {shortDay(event.reviewed_at)}
            </Tag>
            <span className="text-xs text-neutral-700">Event #{event.code}</span>
          </div>
          <div className="text-[15px]">
            {event.record_count} {event.record_count === 1 ? 'record' : 'records'} · {where} · {siteDay(event.date)}
          </div>
          <div className="text-xs text-neutral-700">
            {verb} by {event.reviewer?.full_name ?? '—'}
            {event.review_note ? ` · ${event.review_note}` : ''}
          </div>
        </div>
        <button className={btnGhost} onClick={onToggle} aria-expanded={expanded}>
          {expanded ? 'Hide records' : 'View records'}
        </button>
      </div>
      {expanded ? (
        <div className="border-t border-neutral-300">
          <RecordsTable event={event} records={records} />
        </div>
      ) : null}
    </article>
  )
}

function DecisionDialog({ event, decision, busy, error, onCancel, onSubmit }) {
  const [note, setNote] = useState('')
  const rejecting = decision === 'reject'
  const noteMissing = rejecting && !note.trim()

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4" role="dialog" aria-modal="true" aria-label={rejecting ? 'Reject override' : 'Approve override'}>
      <div className="w-full max-w-[460px] border border-neutral-300 bg-surface p-5 shadow-lg">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-heading text-lg">{rejecting ? 'Reject override' : 'Approve override'}</h3>
          <button className="text-neutral-500 hover:text-ink" onClick={onCancel} aria-label="Close">
            <Icon name="close" size={16} />
          </button>
        </div>
        <p className="text-sm">
          Event #{event.code} · {event.record_count} {event.record_count === 1 ? 'record' : 'records'} · {hours(event.hours_at_stake)} h ·{' '}
          {peso(event.amount_at_stake)}
        </p>
        <p className="mt-2 text-xs text-neutral-700">
          {rejecting
            ? 'Each worker is paid from their actual tap time instead. The foreman and workers will see your reason.'
            : 'The credited times are released to payroll. This is logged under your name.'}
        </p>
        <div className="mt-4">
          <Field label={rejecting ? 'Reason (required)' : 'Note (optional)'}>
            <textarea
              className="min-h-[88px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
              value={note}
              maxLength={1000}
              onChange={(e) => setNote(e.target.value)}
              placeholder={rejecting ? 'e.g. gate log shows crew arrival at 08:11' : ''}
            />
          </Field>
        </div>
        {error ? <p className="mt-2 text-xs text-[#75261C]">{error}</p> : null}
        <div className="mt-4 flex justify-end gap-2.5">
          <button className={btnSecondary} onClick={onCancel} disabled={busy}>
            Cancel
          </button>
          <button
            className={rejecting ? btnReject : btnPrimary}
            disabled={busy || noteMissing}
            onClick={() => onSubmit(note.trim() || null)}
          >
            {busy ? 'Saving…' : rejecting ? 'Reject override' : 'Approve override'}
          </button>
        </div>
      </div>
    </div>
  )
}

export function OverridesPage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)
  const access = NAV.find((n) => n.key === 'overrides').access[role]
  const canDecide = access === 'full' && role === 'hr'

  const [result, setResult] = useState({ key: null, events: [], summary: null, error: null })
  const [records, setRecords] = useState({})
  const [expanded, setExpanded] = useState({})
  const [sites, setSites] = useState([])
  const [type, setType] = useState('')
  const [siteId, setSiteId] = useState('')
  const [status, setStatus] = useState('pending')
  const [reload, setReload] = useState(0)
  const [notice, setNotice] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)

  // Loading is derived: the last response answered a different query.
  const queryKey = `${type}|${siteId}|${reload}`
  const loading = result.key !== queryKey
  const { events, summary, error } = result

  const loadRecords = useCallback((auditId) => {
    http
      .get(`/overrides/${auditId}`)
      .then((r) => setRecords((prev) => ({ ...prev, [auditId]: r.data.data.records })))
      .catch(() => setRecords((prev) => ({ ...prev, [auditId]: [] })))
  }, [])

  /*
   * Type and site filter server-side; status filters here. The summary counts
   * what is still pending within the chosen type and site, so it stays
   * meaningful while browsing decided events.
   */
  useEffect(() => {
    let active = true

    http
      .get('/overrides', { params: { type: type || undefined, site_id: siteId || undefined } })
      .then((r) => {
        if (!active) return
        setResult({ key: queryKey, events: r.data.data, summary: r.data.summary, error: null })
        setRecords({})
        setExpanded({})
        r.data.data.filter((e) => e.review_status === 'pending').forEach((e) => loadRecords(e.audit_id))
      })
      .catch((err) => {
        if (active) setResult({ key: queryKey, events: [], summary: null, error: errorMessage(err, 'Unable to load overrides.') })
      })

    return () => {
      active = false
    }
  }, [queryKey, type, siteId, loadRecords])

  useEffect(() => {
    http
      .get('/sites')
      .then((r) => setSites(r.data.sites ?? []))
      .catch(() => {})
  }, [])

  const toggle = (event) => {
    const open = !expanded[event.audit_id]
    setExpanded((prev) => ({ ...prev, [event.audit_id]: open }))
    if (open && !records[event.audit_id]) loadRecords(event.audit_id)
  }

  const decide = async (note) => {
    const { event, decision } = dialog
    setBusy(true)
    try {
      await http.post(`/overrides/${event.audit_id}/${decision}`, note ? { note } : {})
      setDialog(null)
      setNotice(`Event #${event.code} ${decision === 'approve' ? 'approved' : 'rejected'}.`)
      setReload((n) => n + 1)
    } catch (err) {
      setDialog((d) => d && { ...d, error: errorMessage(err, 'Unable to record the decision.') })
    } finally {
      setBusy(false)
    }
  }

  const visible = events.filter((e) => !status || e.review_status === status)
  const pending = events.filter((e) => e.review_status === 'pending')
  const pendingOf = (actionType) =>
    pending.filter((e) => e.action_type === actionType).reduce((sum, e) => sum + e.record_count, 0)

  return (
    <div className="flex flex-col pb-10">
      <div className="grid grid-cols-2 gap-4 border-b border-neutral-200 px-[22px] py-[18px] lg:grid-cols-4">
        <StatCard
          label="Flagged records"
          value={summary?.pending_records ?? '—'}
          note={`in ${summary?.pending_events ?? 0} ${summary?.pending_events === 1 ? 'event' : 'events'} awaiting review`}
        />
        <StatCard label="Late-start credits" value={pendingOf('FOREMAN_LATE_OVERRIDE')} note="records, awaiting review" accent="#4A3260" />
        <StatCard label="Hours at stake" value={hours(summary?.hours_at_stake)} note={`≈ ${peso(summary?.amount_at_stake)}`} accent="#96420E" />
        <StatCard label="Manual times" value={pendingOf('MANUAL_TIME_OVERRIDE')} note="records, awaiting review" accent="#4A3260" />
      </div>

      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
        <div className="w-[230px]">
          <Field label="Flag type">
            <select className={selectCls} value={type} onChange={(e) => setType(e.target.value)}>
              <option value="">All types</option>
              <option value="FOREMAN_LATE_OVERRIDE">FOREMAN_LATE_OVERRIDE</option>
              <option value="MANUAL_TIME_OVERRIDE">MANUAL_TIME_OVERRIDE</option>
            </select>
          </Field>
        </div>
        <div className="w-[190px]">
          <Field label="Site">
            <select className={selectCls} value={siteId} onChange={(e) => setSiteId(e.target.value)}>
              <option value="">All sites</option>
              {sites.map((site) => (
                <option key={site.site_id} value={site.site_id}>
                  {site.site_name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="w-[170px]">
          <Field label="Status">
            <select className={selectCls} value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="pending">Awaiting review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="">All statuses</option>
            </select>
          </Field>
        </div>
        <div className="ml-auto flex items-center gap-3 self-center">
          {!canDecide ? <Tag bg={tone.neutral.bg} fg={tone.neutral.fg}>View only</Tag> : null}
          {notice ? <span className="border border-primary-500 px-3 py-1.5 text-xs text-primary-800">{notice}</span> : null}
        </div>
      </div>

      <div className="flex flex-col gap-[18px] px-[22px] pt-[18px]">
        {error ? <p className="text-sm text-[#75261C]">{error}</p> : null}
        {loading && result.key === null ? <p className="text-sm text-neutral-700">Loading overrides…</p> : null}
        {!loading && !error && !visible.length ? (
          <p className="border border-neutral-300 bg-surface px-5 py-8 text-center text-sm text-neutral-700">
            {status === 'pending' ? 'Nothing awaiting review.' : 'No override events match these filters.'}
          </p>
        ) : null}

        {result.key !== null
          ? visible.map((event) =>
              event.review_status === 'pending' ? (
                <PendingEventCard
                  key={event.audit_id}
                  event={event}
                  records={records[event.audit_id]}
                  canDecide={canDecide}
                  onDecide={(e, decision) => setDialog({ event: e, decision })}
                />
              ) : (
                <DecidedEventCard
                  key={event.audit_id}
                  event={event}
                  records={records[event.audit_id]}
                  expanded={Boolean(expanded[event.audit_id])}
                  onToggle={() => toggle(event)}
                />
              ),
            )
          : null}
      </div>

      {dialog ? (
        <DecisionDialog
          key={`${dialog.event.audit_id}-${dialog.decision}`}
          event={dialog.event}
          decision={dialog.decision}
          busy={busy}
          error={dialog.error}
          onCancel={() => setDialog(null)}
          onSubmit={decide}
        />
      ) : null}
    </div>
  )
}
