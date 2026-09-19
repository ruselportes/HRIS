import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { NAV, tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Leave Requests (Phase 9 — UC-10): file, endorse and approve leave and
 * overtime.
 *
 * Two hops: a request goes to the endorser assigned when it was filed (the
 * worker's crew leader, else a site engineer), then to HR. A request filed
 * with nobody to endorse goes straight to HR. HR approves or rejects (with a
 * note), and may hand a pending request to another endorser; the filer may
 * cancel while it is still pending. Approval is final.
 *
 * Every button shown here is only a hint: the server applies the same rules
 * (who is party to a request, closed payroll periods, conflicts) and its
 * refusal is shown as it gives it.
 *
 * Overtime filed for several workers at once shares a batch key. Endorsing or
 * approving one row carries the rest of its batch; HR can also approve a batch
 * explicitly and see which rows were skipped and why.
 */

const SITE_TZ = 'Asia/Manila'

const LEAVE_TYPES = {
  sick: 'Sick',
  vacation: 'Vacation',
  personal: 'Personal',
  bereavement: 'Bereavement',
  leave_without_pay: 'Leave without pay',
}

const FILERS = ['hr', 'foreman', 'engineer']

const inputCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnPrimary =
  'inline-flex items-center justify-center gap-2 border border-primary-800 bg-primary-800 px-4 py-2 text-[13px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'
const btnReject =
  'inline-flex items-center justify-center gap-2 border border-[#A83A2C] bg-canvas px-4 py-2 text-[13px] text-[#75261C] disabled:cursor-not-allowed disabled:opacity-40'
const btnSmall = 'inline-flex items-center justify-center border px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-40'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const todayInSite = () => new Intl.DateTimeFormat('en-CA', { timeZone: SITE_TZ }).format(new Date())
const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, weekday: 'short', day: '2-digit', month: 'short' })
const stampFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

// Plain Y-m-d dates are site days; anchor them at site noon so they cannot shift a day.
const siteDay = (ymd) => (ymd ? dayFmt.format(new Date(`${ymd}T12:00:00+08:00`)) : '—')
const stamp = (iso) => (iso ? stampFmt.format(new Date(iso)) : '')
const clock = (time) => (time ? String(time).slice(0, 5) : '—')

function daysBetween(from, to) {
  return Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000) + 1
}

function windowMinutes(start, end) {
  if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end) || start === end) return null
  const [sh, sm] = start.split(':').map(Number)
  const [eh, em] = end.split(':').map(Number)
  let minutes = eh * 60 + em - (sh * 60 + sm)
  if (minutes <= 0) minutes += 24 * 60
  return minutes
}

function formatMinutes(minutes) {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h > 0 ? `${h} h${m ? ` ${m} m` : ''}` : `${m} m`
}

function newBatchKey() {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `ot-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

/** Where a request stands, in the words of whoever has to act next. */
function statusTag(req) {
  switch (req.status) {
    case 'pending':
      return req.assigned_endorser
        ? { label: 'With endorser', ...tone.pending }
        : { label: 'Awaiting HR', ...tone.late }
    case 'endorsed':
      return { label: 'Endorsed · awaiting HR', ...tone.late }
    case 'approved':
      return { label: 'Approved', ...tone.present }
    case 'rejected':
      return { label: 'Rejected', ...tone.absent }
    case 'cancelled':
      return { label: 'Cancelled', ...tone.neutral }
    default:
      return { label: req.status, ...tone.neutral }
  }
}

/** The actions this viewer could take — a mirror of the server's rules, which decide. */
function actionsFor(req, me, role) {
  const hr = role === 'hr'
  const open = req.status === 'pending' || req.status === 'endorsed'
  const parties = [req.employee?.employee_id, req.filed_by?.employee_id, req.endorsed_by?.employee_id]

  return {
    endorse: req.status === 'pending' && req.assigned_endorser?.employee_id === me && req.employee?.employee_id !== me,
    approve:
      hr && (req.status === 'endorsed' || (req.status === 'pending' && !req.assigned_endorser)) && !parties.includes(me),
    reject: hr && open,
    cancel: req.status === 'pending' && req.filed_by?.employee_id === me,
    reassign: hr && req.status === 'pending',
  }
}

const needsMe = (a) => a.endorse || a.approve

function Tag({ bg, fg, children }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 py-0.5 text-[11px]" style={{ background: bg, color: fg }}>
      {children}
    </span>
  )
}

function Field({ label, hint, children }) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</label>
      {children}
      {hint ? <span className="text-[11px] text-neutral-700">{hint}</span> : null}
    </div>
  )
}

function StatCard({ label, value, note, accent }) {
  return (
    <div className="border border-neutral-300 bg-surface p-3.5">
      <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      <div className="mt-1.5 font-heading text-3xl tabular-nums" style={accent ? { color: accent } : undefined}>
        {value}
      </div>
      <div className="mt-0.5 text-[11px] text-neutral-700">{note}</div>
    </div>
  )
}

function Person({ person, sub }) {
  if (!person) return <span className="text-neutral-700">—</span>
  return (
    <>
      {person.full_name}
      <div className="text-[11px] text-neutral-700">{sub ?? [person.employee_code, person.role, person.site].filter(Boolean).join(' · ')}</div>
    </>
  )
}

function Dialog({ title, onClose, children, wide }) {
  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className={`max-h-[90vh] w-full overflow-y-auto border border-neutral-300 bg-surface p-5 shadow-lg ${wide ? 'max-w-[640px]' : 'max-w-[460px]'}`}>
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-heading text-lg">{title}</h3>
          <button className="text-neutral-500 hover:text-ink" onClick={onClose} aria-label="Close">
            <Icon name="close" size={16} />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

/** What happened to a request, in order — who filed, endorsed and decided it, and when. */
function Timeline({ req }) {
  const steps = [
    { label: 'Filed', who: req.filed_by, at: req.created_at },
    req.endorsed_by ? { label: 'Endorsed', who: req.endorsed_by, at: req.endorsed_at } : null,
    req.approved_by ? { label: 'Approved', who: req.approved_by, at: req.approved_at } : null,
    req.rejected_by ? { label: 'Rejected', who: req.rejected_by, at: req.rejected_at } : null,
  ].filter(Boolean)

  return (
    <div className="grid gap-4 bg-canvas px-5 py-4 text-[13px] md:grid-cols-[1fr_1fr]">
      <div>
        <div className="mb-1 text-[11px] uppercase tracking-[.08em] text-neutral-700">Reason</div>
        <p className="whitespace-pre-wrap">{req.reason || <span className="text-neutral-700">None given</span>}</p>
        {req.rejection_note ? (
          <>
            <div className="mb-1 mt-3 text-[11px] uppercase tracking-[.08em] text-[#75261C]">Rejection note</div>
            <p className="whitespace-pre-wrap text-[#75261C]">{req.rejection_note}</p>
          </>
        ) : null}
      </div>
      <div>
        <div className="mb-1 text-[11px] uppercase tracking-[.08em] text-neutral-700">History</div>
        <ol className="flex flex-col gap-1.5">
          {steps.map((s) => (
            <li key={s.label} className="flex gap-2">
              <span className="w-[72px] flex-none text-neutral-700">{s.label}</span>
              <span>
                {s.who?.full_name ?? '—'} <span className="text-neutral-700">· {stamp(s.at)}</span>
              </span>
            </li>
          ))}
          {req.status === 'pending' ? (
            <li className="flex gap-2">
              <span className="w-[72px] flex-none text-neutral-700">Next</span>
              <span>{req.assigned_endorser ? `Endorsement by ${req.assigned_endorser.full_name}` : 'HR decides directly (no endorser on site)'}</span>
            </li>
          ) : null}
          {req.status === 'endorsed' ? (
            <li className="flex gap-2">
              <span className="w-[72px] flex-none text-neutral-700">Next</span>
              <span>HR approves or rejects</span>
            </li>
          ) : null}
        </ol>
      </div>
    </div>
  )
}

function RowActions({ actions, busy, onAct }) {
  if (!Object.values(actions).some(Boolean)) return null
  return (
    <div className="flex flex-wrap justify-end gap-1.5">
      {actions.endorse ? (
        <button className={`${btnSmall} border-primary-800 bg-primary-800 text-white`} disabled={busy} onClick={() => onAct('endorse')}>
          Endorse
        </button>
      ) : null}
      {actions.approve ? (
        <button className={`${btnSmall} border-primary-800 bg-primary-800 text-white`} disabled={busy} onClick={() => onAct('approve')}>
          Approve
        </button>
      ) : null}
      {actions.reject ? (
        <button className={`${btnSmall} border-[#A83A2C] text-[#75261C]`} disabled={busy} onClick={() => onAct('reject')}>
          Reject
        </button>
      ) : null}
      {actions.reassign ? (
        <button className={`${btnSmall} border-neutral-400 text-ink`} disabled={busy} onClick={() => onAct('reassign')}>
          Reassign
        </button>
      ) : null}
      {actions.cancel ? (
        <button className={`${btnSmall} border-neutral-400 text-ink`} disabled={busy} onClick={() => onAct('cancel')}>
          Cancel
        </button>
      ) : null}
    </div>
  )
}

function EndorserCell({ req }) {
  if (req.endorsed_by) return <Person person={req.endorsed_by} sub={`endorsed ${stamp(req.endorsed_at)}`} />
  if (req.assigned_endorser) return <Person person={req.assigned_endorser} sub="assigned" />
  return <span className="text-[12px] text-neutral-700">None on site · HR decides</span>
}

function LeaveTable({ rows, me, role, busyId, expanded, onToggle, onAct }) {
  return (
    <div className="overflow-x-auto border border-neutral-300 bg-surface">
      <table className="w-full min-w-[980px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
            <th className="py-2.5 pl-5 pr-4 font-normal">Employee</th>
            <th className="w-[150px] py-2.5 pr-4 font-normal">Leave</th>
            <th className="w-[190px] py-2.5 pr-4 font-normal">Dates</th>
            <th className="w-[170px] py-2.5 pr-4 font-normal">Status</th>
            <th className="w-[190px] py-2.5 pr-4 font-normal">Endorser</th>
            <th className="w-[220px] py-2.5 pr-5 text-right font-normal">Actions</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((req) => {
            const actions = actionsFor(req, me, role)
            const tag = statusTag(req)
            const open = expanded === `leave-${req.leave_id}`
            return (
              <FragmentRow
                key={req.leave_id}
                open={open}
                colSpan={6}
                detail={<Timeline req={req} />}
                highlight={needsMe(actions)}
              >
                <td className="py-2.5 pl-5 pr-4 align-top">
                  <button className="text-left hover:underline" onClick={() => onToggle(`leave-${req.leave_id}`)} aria-expanded={open}>
                    <Person person={req.employee} />
                  </button>
                </td>
                <td className="py-2.5 pr-4 align-top">
                  {LEAVE_TYPES[req.leave_type] ?? req.leave_type}
                  {req.filed_by && req.filed_by.employee_id !== req.employee?.employee_id ? (
                    <div className="text-[11px] text-neutral-700">filed by {req.filed_by.full_name}</div>
                  ) : null}
                </td>
                <td className="py-2.5 pr-4 align-top tabular-nums">
                  {req.date_from === req.date_to ? siteDay(req.date_from) : `${siteDay(req.date_from)} – ${siteDay(req.date_to)}`}
                  <div className="text-[11px] text-neutral-700">
                    {daysBetween(req.date_from, req.date_to)} {daysBetween(req.date_from, req.date_to) === 1 ? 'day' : 'days'}
                  </div>
                </td>
                <td className="py-2.5 pr-4 align-top">
                  <Tag bg={tag.bg} fg={tag.fg}>{tag.label}</Tag>
                </td>
                <td className="py-2.5 pr-4 align-top">
                  <EndorserCell req={req} />
                </td>
                <td className="py-2.5 pr-5 align-top">
                  <RowActions actions={actions} busy={busyId !== null} onAct={(action) => onAct(action, 'leave', req)} />
                </td>
              </FragmentRow>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function FragmentRow({ children, open, detail, colSpan, highlight }) {
  return (
    <>
      <tr className="border-b border-neutral-200" style={highlight ? { boxShadow: 'inset 3px 0 0 #96420E' } : undefined}>
        {children}
      </tr>
      {open ? (
        <tr className="border-b border-neutral-200">
          <td colSpan={colSpan} className="p-0">
            {detail}
          </td>
        </tr>
      ) : null}
    </>
  )
}

/**
 * A batch is overtime filed together: several workers on one night, one worker
 * over several nights, or both — so it counts requests and workers apart.
 */
function batchSummary(rows) {
  const workers = new Set(rows.map((r) => r.employee?.employee_id)).size
  const dates = rows.map((r) => r.ot_date).sort()
  const first = dates[0]
  const last = dates[dates.length - 1]
  return [
    `${rows.length} requests`,
    `${workers} ${workers === 1 ? 'worker' : 'workers'}`,
    first === last ? siteDay(first) : `${siteDay(first)} – ${siteDay(last)}`,
  ].join(' · ')
}

/** Overtime rows, with a header for each batch filed together. */
function OvertimeTable({ rows, me, role, busyId, expanded, onToggle, onAct, onApproveBatch }) {
  const groups = useMemo(() => {
    const out = []
    for (const req of rows) {
      const last = out[out.length - 1]
      if (req.batch_key && last && last.key === req.batch_key) last.rows.push(req)
      else out.push({ key: req.batch_key ?? `single-${req.ot_id}`, batch: Boolean(req.batch_key), rows: [req] })
    }
    return out
  }, [rows])

  return (
    <div className="overflow-x-auto border border-neutral-300 bg-surface">
      <table className="w-full min-w-[980px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
            <th className="py-2.5 pl-5 pr-4 font-normal">Employee</th>
            <th className="w-[190px] py-2.5 pr-4 font-normal">Date and window</th>
            <th className="w-[80px] py-2.5 pr-4 font-normal">Hours</th>
            <th className="w-[170px] py-2.5 pr-4 font-normal">Status</th>
            <th className="w-[190px] py-2.5 pr-4 font-normal">Endorser</th>
            <th className="w-[220px] py-2.5 pr-5 text-right font-normal">Actions</th>
          </tr>
        </thead>
        <tbody>
          {groups.map((group) => {
            const approvable = group.rows.filter((r) => actionsFor(r, me, role).approve)
            return [
              group.batch && group.rows.length > 1 ? (
                <tr key={`batch-${group.key}`} className="border-b border-neutral-200 bg-canvas">
                  <td colSpan={5} className="py-2 pl-5 pr-4 text-xs text-neutral-700">
                    <span className="mr-2 font-mono text-[11px] text-ink">Batch</span>
                    {batchSummary(group.rows)} · filed by {group.rows[0].filed_by?.full_name ?? '—'}
                    <span className="ml-2">— endorsing or approving one row carries the rest</span>
                  </td>
                  <td className="py-2 pr-5 text-right">
                    {approvable.length > 1 ? (
                      <button
                        className={`${btnSmall} border-primary-800 text-primary-800`}
                        disabled={busyId !== null}
                        onClick={() => onApproveBatch(approvable)}
                      >
                        Approve batch ({approvable.length})
                      </button>
                    ) : null}
                  </td>
                </tr>
              ) : null,
              ...group.rows.map((req) => {
                const actions = actionsFor(req, me, role)
                const tag = statusTag(req)
                const open = expanded === `ot-${req.ot_id}`
                return (
                  <FragmentRow key={req.ot_id} open={open} colSpan={6} detail={<Timeline req={req} />} highlight={needsMe(actions)}>
                    <td className="py-2.5 pl-5 pr-4 align-top">
                      <button className="text-left hover:underline" onClick={() => onToggle(`ot-${req.ot_id}`)} aria-expanded={open}>
                        <Person person={req.employee} />
                      </button>
                    </td>
                    <td className="py-2.5 pr-4 align-top tabular-nums">
                      {siteDay(req.ot_date)}
                      <div className="text-[11px] text-neutral-700">
                        {req.start_time ? `${clock(req.start_time)}–${clock(req.end_time)}` : 'no window'}
                      </div>
                    </td>
                    <td className="py-2.5 pr-4 align-top tabular-nums">{req.hours_requested != null ? Number(req.hours_requested).toFixed(2) : '—'}</td>
                    <td className="py-2.5 pr-4 align-top">
                      <Tag bg={tag.bg} fg={tag.fg}>{tag.label}</Tag>
                    </td>
                    <td className="py-2.5 pr-4 align-top">
                      <EndorserCell req={req} />
                    </td>
                    <td className="py-2.5 pr-5 align-top">
                      <RowActions actions={actions} busy={busyId !== null} onAct={(action) => onAct(action, 'overtime', req)} />
                    </td>
                  </FragmentRow>
                )
              }),
            ]
          })}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Who this viewer may file for: HR anyone (searched), an engineer their site,
 * a foreman their crew — each always including themselves.
 */
function useFileableEmployees(user, role, search) {
  const [state, setState] = useState({ key: null, people: [], error: null })
  const key = role === 'hr' ? `hr|${search}` : role

  useEffect(() => {
    let active = true
    const self = {
      employee_id: user.employee_id,
      full_name: user.full_name,
      employee_code: user.employee_code,
      detail: 'You',
    }

    const load = async () => {
      if (role === 'foreman') {
        const r = await http.get('/me/crew')
        const crew = r.data.crew
        return (crew?.members ?? []).map((m) => ({
          employee_id: m.employee_id,
          full_name: `${m.last_name}, ${m.first_name}`,
          employee_code: m.employee_code,
          detail: [m.trade_skill, crew.crew_name].filter(Boolean).join(' · '),
        }))
      }

      const params = role === 'engineer' ? { site_id: user.site_id, per_page: 100 } : { search: search || undefined, per_page: 30 }
      const r = await http.get('/employees', { params })
      return (r.data.data ?? [])
        .filter((e) => e.employment_status !== 'separated')
        .map((e) => ({
          employee_id: e.employee_id,
          full_name: e.full_name,
          employee_code: e.employee_code,
          detail: [e.role?.role_name, e.site?.site_name].filter(Boolean).join(' · '),
        }))
    }

    const timer = setTimeout(
      () => {
        load()
          .then((people) => {
            if (!active) return
            const others = people.filter((p) => p.employee_id !== user.employee_id)
            setState({ key, people: [self, ...others], error: null })
          })
          .catch((err) => {
            if (active) setState({ key, people: [self], error: errorMessage(err, 'Could not load employees.') })
          })
      },
      role === 'hr' ? 250 : 0,
    )

    return () => {
      active = false
      clearTimeout(timer)
    }
  }, [key, role, search, user])

  return { ...state, loading: state.key !== key }
}

function NewRequestDialog({ user, role, initialKind, onClose, onFiled }) {
  const today = todayInSite()
  const [kind, setKind] = useState(initialKind)
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState([])
  const [leave, setLeave] = useState({ leave_type: 'vacation', date_from: today, date_to: today, reason: '' })
  const [ot, setOt] = useState({ ot_date: today, start_time: '16:00', end_time: '19:00', reason: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [results, setResults] = useState(null)

  const { people, loading, error: peopleError } = useFileableEmployees(user, role, search)
  const multiple = kind === 'overtime'
  const minutes = windowMinutes(ot.start_time, ot.end_time)

  const visiblePeople =
    role === 'hr' || !search.trim()
      ? people
      : people.filter((p) => `${p.full_name} ${p.employee_code ?? ''}`.toLowerCase().includes(search.trim().toLowerCase()))

  const toggle = (person) => {
    setSelected((prev) => {
      const has = prev.some((p) => p.employee_id === person.employee_id)
      if (!multiple) return has ? [] : [person]
      return has ? prev.filter((p) => p.employee_id !== person.employee_id) : [...prev, person]
    })
  }

  const switchKind = (next) => {
    setKind(next)
    setResults(null)
    setError(null)
    if (next === 'leave') setSelected((prev) => prev.slice(0, 1))
  }

  const leaveInvalid = !leave.reason.trim() || !leave.date_from || !leave.date_to || leave.date_to < leave.date_from
  const otInvalid = !ot.ot_date || ot.ot_date < today || minutes === null
  const invalid = selected.length === 0 || (kind === 'leave' ? leaveInvalid : otInvalid)

  const submit = async () => {
    setBusy(true)
    setError(null)
    setResults(null)

    if (kind === 'leave') {
      try {
        await http.post('/leaves', { employee_id: selected[0].employee_id, ...leave, reason: leave.reason.trim() })
        onFiled('Leave request filed.')
      } catch (err) {
        setError(errorMessage(err, 'Could not file the leave request.'))
      } finally {
        setBusy(false)
      }
      return
    }

    // One request per worker; several filed together share a batch key so
    // they are endorsed and approved as one.
    const batchKey = selected.length > 1 ? newBatchKey() : undefined
    const outcome = []
    for (const person of selected) {
      try {
        await http.post('/overtimes', {
          employee_id: person.employee_id,
          ot_date: ot.ot_date,
          start_time: ot.start_time,
          end_time: ot.end_time,
          reason: ot.reason.trim() || undefined,
          batch_key: batchKey,
        })
        outcome.push({ person, ok: true })
      } catch (err) {
        outcome.push({ person, ok: false, message: errorMessage(err, 'Could not file this request.') })
      }
    }
    setBusy(false)

    const failed = outcome.filter((o) => !o.ok)
    if (failed.length === 0) {
      onFiled(outcome.length === 1 ? 'Overtime request filed.' : `Overtime filed for ${outcome.length} workers as one batch.`)
      return
    }
    // Keep the dialog open on the ones that failed, so they can be fixed and
    // retried without filing the others twice.
    setResults(outcome)
    setSelected(failed.map((o) => o.person))
    if (outcome.length > failed.length) onFiled(null)
  }

  return (
    <Dialog title="New request" onClose={onClose} wide>
      <div className="mb-4 flex gap-2">
        {[
          ['leave', 'Leave'],
          ['overtime', 'Overtime'],
        ].map(([value, label]) => (
          <button
            key={value}
            className={value === kind ? `${btnSmall} border-primary-800 bg-primary-800 text-white` : `${btnSmall} border-neutral-400 text-ink`}
            onClick={() => switchKind(value)}
            aria-pressed={value === kind}
          >
            {label}
          </button>
        ))}
      </div>

      <Field
        label={multiple ? 'Workers' : 'Employee'}
        hint={
          role === 'foreman'
            ? 'You, or a worker on your crew.'
            : role === 'engineer'
              ? 'You, or anyone homed at your site.'
              : 'Search by name or employee code.'
        }
      >
        <input className={inputCls} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search" aria-label="Search employees" />
      </Field>
      <div className="mt-2 max-h-[180px] overflow-y-auto border border-neutral-300 bg-canvas">
        {loading ? <p className="px-3 py-2 text-xs text-neutral-700">Loading…</p> : null}
        {peopleError ? <p className="px-3 py-2 text-xs text-[#75261C]">{peopleError}</p> : null}
        {!loading && visiblePeople.length === 0 ? <p className="px-3 py-2 text-xs text-neutral-700">No one matches.</p> : null}
        {visiblePeople.map((person) => {
          const checked = selected.some((p) => p.employee_id === person.employee_id)
          return (
            <label key={person.employee_id} className="flex cursor-pointer items-center gap-2.5 border-b border-neutral-200 px-3 py-1.5 text-[13px] last:border-b-0">
              <input type={multiple ? 'checkbox' : 'radio'} name="subject" checked={checked} onChange={() => toggle(person)} />
              <span className="min-w-0 flex-1 truncate">
                {person.full_name}
                <span className="ml-2 text-[11px] text-neutral-700">{[person.employee_code, person.detail].filter(Boolean).join(' · ')}</span>
              </span>
            </label>
          )
        })}
      </div>
      {multiple && selected.length > 1 ? (
        <p className="mt-1.5 text-[11px] text-neutral-700">{selected.length} selected — filed as one batch.</p>
      ) : null}

      {kind === 'leave' ? (
        <div className="mt-4 grid grid-cols-2 gap-3">
          <Field label="Type">
            <select className={inputCls} value={leave.leave_type} onChange={(e) => setLeave({ ...leave, leave_type: e.target.value })}>
              {Object.entries(LEAVE_TYPES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <div />
          <Field label="From">
            <input type="date" className={inputCls} value={leave.date_from} onChange={(e) => setLeave({ ...leave, date_from: e.target.value })} />
          </Field>
          <Field label="To">
            <input type="date" className={inputCls} value={leave.date_to} min={leave.date_from} onChange={(e) => setLeave({ ...leave, date_to: e.target.value })} />
          </Field>
          <div className="col-span-2">
            <Field label="Reason (required)" hint="Days before today may only be filed as sick leave, and never on a day the worker clocked in.">
              <textarea
                className="min-h-[72px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
                value={leave.reason}
                maxLength={500}
                onChange={(e) => setLeave({ ...leave, reason: e.target.value })}
              />
            </Field>
          </div>
        </div>
      ) : (
        <div className="mt-4 grid grid-cols-3 gap-3">
          <Field label="Date">
            <input type="date" className={inputCls} value={ot.ot_date} min={today} onChange={(e) => setOt({ ...ot, ot_date: e.target.value })} />
          </Field>
          <Field label="From">
            <input type="time" className={inputCls} value={ot.start_time} onChange={(e) => setOt({ ...ot, start_time: e.target.value })} />
          </Field>
          <Field label="To">
            <input type="time" className={inputCls} value={ot.end_time} onChange={(e) => setOt({ ...ot, end_time: e.target.value })} />
          </Field>
          <p className="col-span-3 text-[11px] text-neutral-700">
            {minutes === null
              ? 'Set a start and an end time.'
              : `A ${formatMinutes(minutes)} window${ot.end_time <= ot.start_time ? ', running past midnight' : ''}. Only the part outside the regular shift is overtime; the request shows the paid hours once filed.`}
          </p>
          <div className="col-span-3">
            <Field label="Reason (optional)">
              <textarea
                className="min-h-[60px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
                value={ot.reason}
                maxLength={500}
                onChange={(e) => setOt({ ...ot, reason: e.target.value })}
              />
            </Field>
          </div>
        </div>
      )}

      {results ? (
        <ul className="mt-3 border border-neutral-300 bg-canvas px-3 py-2 text-xs">
          {results.map((r) => (
            <li key={r.person.employee_id} className={r.ok ? 'text-[#1F5334]' : 'text-[#75261C]'}>
              {r.person.full_name}: {r.ok ? 'filed' : r.message}
            </li>
          ))}
        </ul>
      ) : null}
      {error ? <p className="mt-3 text-xs text-[#75261C]">{error}</p> : null}

      <div className="mt-4 flex justify-end gap-2.5">
        <button className={btnSecondary} onClick={onClose} disabled={busy}>
          Close
        </button>
        <button className={btnPrimary} disabled={busy || invalid} onClick={submit}>
          {busy ? 'Filing…' : kind === 'overtime' && selected.length > 1 ? `File for ${selected.length} workers` : 'File request'}
        </button>
      </div>
    </Dialog>
  )
}

function RejectDialog({ target, busy, error, onCancel, onSubmit }) {
  const [note, setNote] = useState('')
  return (
    <Dialog title="Reject request" onClose={onCancel}>
      <p className="text-sm">
        {target.req.employee?.full_name} · {target.kind === 'leave' ? LEAVE_TYPES[target.req.leave_type] ?? 'Leave' : 'Overtime'}
      </p>
      <p className="mt-2 text-xs text-neutral-700">The filer sees your note. Rejection is final for this request.</p>
      <div className="mt-4">
        <Field label="Note (required)">
          <textarea
            className="min-h-[88px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
            value={note}
            maxLength={2000}
            onChange={(e) => setNote(e.target.value)}
          />
        </Field>
      </div>
      {error ? <p className="mt-2 text-xs text-[#75261C]">{error}</p> : null}
      <div className="mt-4 flex justify-end gap-2.5">
        <button className={btnSecondary} onClick={onCancel} disabled={busy}>
          Cancel
        </button>
        <button className={btnReject} disabled={busy || !note.trim()} onClick={() => onSubmit({ rejection_note: note.trim() })}>
          {busy ? 'Saving…' : 'Reject request'}
        </button>
      </div>
    </Dialog>
  )
}

/** HR hands a pending request to another foreman or site engineer. */
function ReassignDialog({ target, busy, error, onCancel, onSubmit }) {
  const [candidates, setCandidates] = useState(null)
  const [choice, setChoice] = useState('')

  useEffect(() => {
    let active = true
    Promise.all(['foreman', 'engineer'].map((slug) => http.get('/employees', { params: { role: slug, per_page: 100 } })))
      .then((responses) => {
        if (!active) return
        const exclude = [target.req.employee?.employee_id, target.req.filed_by?.employee_id, target.req.assigned_endorser?.employee_id]
        setCandidates(
          responses
            .flatMap((r) => r.data.data ?? [])
            .filter((e) => e.employment_status !== 'separated' && !exclude.includes(e.employee_id))
            .sort((a, b) => a.full_name.localeCompare(b.full_name)),
        )
      })
      .catch(() => active && setCandidates([]))
    return () => {
      active = false
    }
  }, [target])

  return (
    <Dialog title="Reassign endorser" onClose={onCancel}>
      <p className="text-sm">
        {target.req.employee?.full_name} · now with {target.req.assigned_endorser?.full_name ?? 'no endorser'}
      </p>
      <div className="mt-4">
        <Field label="New endorser" hint="A foreman or site engineer with an HRIS sign-in; never the worker or the filer.">
          <select className={inputCls} value={choice} onChange={(e) => setChoice(e.target.value)} disabled={!candidates}>
            <option value="">{candidates ? 'Choose…' : 'Loading…'}</option>
            {(candidates ?? []).map((e) => (
              <option key={e.employee_id} value={e.employee_id}>
                {e.full_name} — {[e.role?.role_name, e.site?.site_name].filter(Boolean).join(', ')}
              </option>
            ))}
          </select>
        </Field>
      </div>
      {error ? <p className="mt-2 text-xs text-[#75261C]">{error}</p> : null}
      <div className="mt-4 flex justify-end gap-2.5">
        <button className={btnSecondary} onClick={onCancel} disabled={busy}>
          Cancel
        </button>
        <button className={btnPrimary} disabled={busy || !choice} onClick={() => onSubmit({ employee_id: Number(choice) })}>
          {busy ? 'Saving…' : 'Reassign'}
        </button>
      </div>
    </Dialog>
  )
}

const STATUS_FILTERS = [
  ['open', 'Open (pending or endorsed)'],
  ['mine', 'Needs my action'],
  ['approved', 'Approved'],
  ['rejected', 'Rejected'],
  ['cancelled', 'Cancelled'],
  ['all', 'All'],
]

export function LeavePage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)
  const access = NAV.find((n) => n.key === 'leave').access[role]
  const me = user?.employee_id
  const canFile = access === 'full' && FILERS.includes(role)

  const [kind, setKind] = useState('leave')
  const [status, setStatus] = useState('open')
  const [search, setSearch] = useState('')
  const [reload, setReload] = useState(0)
  const [data, setData] = useState({ key: null, leave: [], overtime: [], error: null })
  const [expanded, setExpanded] = useState(null)
  const [notice, setNotice] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [filing, setFiling] = useState(false)

  const loading = data.key !== reload

  useEffect(() => {
    let active = true
    Promise.all([http.get('/leaves'), http.get('/overtimes')])
      .then(([l, o]) => {
        if (active) setData({ key: reload, leave: l.data.data, overtime: o.data.data, error: null })
      })
      .catch((err) => {
        if (active) setData({ key: reload, leave: [], overtime: [], error: errorMessage(err, 'Unable to load requests.') })
      })
    return () => {
      active = false
    }
  }, [reload])

  const refresh = useCallback(() => setReload((n) => n + 1), [])

  const all = kind === 'leave' ? data.leave : data.overtime

  const rows = all.filter((req) => {
    if (status === 'open' && !['pending', 'endorsed'].includes(req.status)) return false
    if (status === 'mine' && !needsMe(actionsFor(req, me, role))) return false
    if (!['open', 'mine', 'all'].includes(status) && req.status !== status) return false
    const q = search.trim().toLowerCase()
    return !q || `${req.employee?.full_name ?? ''} ${req.employee?.employee_code ?? ''}`.toLowerCase().includes(q)
  })

  const countMine = (list) => list.filter((req) => needsMe(actionsFor(req, me, role))).length
  const countOpen = (list) => list.filter((req) => ['pending', 'endorsed'].includes(req.status)).length
  const withEndorser = all.filter((req) => req.status === 'pending' && req.assigned_endorser).length
  const awaitingHr = all.filter((req) => req.status === 'endorsed' || (req.status === 'pending' && !req.assigned_endorser)).length
  const thisMonth = todayInSite().slice(0, 7)
  const approvedThisMonth = all.filter((req) => req.status === 'approved' && (req.approved_at ?? '').slice(0, 7) === thisMonth).length

  const post = async (url, body, success) => {
    setNotice(null)
    try {
      await http.post(url, body ?? {})
      setDialog(null)
      setNotice(success)
      refresh()
    } catch (err) {
      const message = errorMessage(err, 'The request could not be updated.')
      setDialog((d) => (d ? { ...d, error: message } : d))
      if (!dialog) setNotice(message)
    }
  }

  const act = async (action, reqKind, req) => {
    const base = reqKind === 'leave' ? `/leaves/${req.leave_id}` : `/overtimes/${req.ot_id}`
    const who = req.employee?.full_name ?? 'the request'

    if (action === 'reject' || action === 'reassign') {
      setDialog({ action, target: { kind: reqKind, req }, error: null })
      return
    }

    setBusyId(`${reqKind}-${reqKind === 'leave' ? req.leave_id : req.ot_id}`)
    const batchNote = reqKind === 'overtime' && req.batch_key ? ' The rest of its batch was carried with it where allowed.' : ''
    const messages = {
      endorse: `Endorsed ${who}'s request; it now goes to HR.${batchNote}`,
      approve: `Approved ${who}'s request.${batchNote}`,
      cancel: `Cancelled ${who}'s request.`,
    }
    await post(`${base}/${action}`, null, messages[action])
    setBusyId(null)
  }

  const submitDialog = async (body) => {
    const { action, target } = dialog
    const base = target.kind === 'leave' ? `/leaves/${target.req.leave_id}` : `/overtimes/${target.req.ot_id}`
    setBusyId('dialog')
    await post(
      `${base}/${action === 'reassign' ? 'reassign-endorser' : 'reject'}`,
      body,
      action === 'reassign'
        ? 'Endorser reassigned.'
        : `Rejected ${target.req.employee?.full_name ? `${target.req.employee.full_name}'s` : 'the'} request.`,
    )
    setBusyId(null)
  }

  const approveBatch = async (batchRows) => {
    setBusyId('batch')
    setNotice(null)
    try {
      const r = await http.post('/overtimes/batch-approve', { ot_ids: batchRows.map((b) => b.ot_id) })
      const { approved, skipped } = r.data.data
      setNotice(
        skipped.length
          ? `Approved ${approved.length}; skipped ${skipped.length}: ${skipped.map((s) => `#${s.id} ${s.reason}`).join(' ')}`
          : `Approved all ${approved.length} requests in the batch.`,
      )
      refresh()
    } catch (err) {
      const skipped = err.response?.data?.data?.skipped
      setNotice(
        skipped?.length
          ? `Nothing approved: ${skipped.map((s) => `#${s.id} ${s.reason}`).join(' ')}`
          : errorMessage(err, 'The batch could not be approved.'),
      )
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="flex flex-col pb-10">
      <div className="grid grid-cols-2 gap-4 border-b border-neutral-200 px-[22px] py-[18px] lg:grid-cols-4">
        <StatCard
          label="Needs your action"
          value={loading ? '—' : countMine(data.leave) + countMine(data.overtime)}
          note={`${countMine(data.leave)} leave · ${countMine(data.overtime)} overtime`}
          accent="#96420E"
        />
        <StatCard label="With endorser" value={loading ? '—' : withEndorser} note={`${kind} requests pending endorsement`} />
        <StatCard label="Awaiting HR" value={loading ? '—' : awaitingHr} note="endorsed, or no endorser on site" accent="#8A5211" />
        <StatCard label="Approved this month" value={loading ? '—' : approvedThisMonth} note={`${kind} requests`} />
      </div>

      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
        <div className="flex" role="tablist">
          {[
            ['leave', 'Leave', data.leave],
            ['overtime', 'Overtime', data.overtime],
          ].map(([value, label, list]) => (
            <button
              key={value}
              role="tab"
              aria-selected={kind === value}
              className={`border px-4 py-2 text-[13px] ${kind === value ? 'border-primary-800 bg-primary-800 text-white' : 'border-neutral-400 bg-canvas text-ink'}`}
              onClick={() => {
                setKind(value)
                setExpanded(null)
              }}
            >
              {label} <span className="opacity-70">({countOpen(list)} open)</span>
            </button>
          ))}
        </div>
        <div className="w-[220px]">
          <Field label="Status">
            <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value)}>
              {STATUS_FILTERS.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="w-[230px]">
          <Field label="Employee">
            <input className={inputCls} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or code" />
          </Field>
        </div>
        <div className="ml-auto flex items-center gap-3">
          {!canFile ? <Tag bg={tone.neutral.bg} fg={tone.neutral.fg}>View only</Tag> : null}
          {canFile ? (
            <button className={btnPrimary} onClick={() => setFiling(true)}>
              <Icon name="plus" size={14} /> New request
            </button>
          ) : null}
        </div>
      </div>

      <div className="flex flex-col gap-3 px-[22px] pt-[18px]">
        {notice ? (
          <div className="flex items-start justify-between gap-3 border border-primary-500 bg-canvas px-3 py-2 text-xs text-primary-800">
            <span>{notice}</span>
            <button onClick={() => setNotice(null)} aria-label="Dismiss">
              <Icon name="close" size={12} />
            </button>
          </div>
        ) : null}
        {data.error ? <p className="text-sm text-[#75261C]">{data.error}</p> : null}
        {loading && data.key === null ? <p className="text-sm text-neutral-700">Loading requests…</p> : null}
        {!loading && !data.error && rows.length === 0 ? (
          <p className="border border-neutral-300 bg-surface px-5 py-8 text-center text-sm text-neutral-700">
            {status === 'mine' ? 'Nothing is waiting on you.' : `No ${kind} requests match these filters.`}
          </p>
        ) : null}

        {rows.length > 0 && kind === 'leave' ? (
          <LeaveTable rows={rows} me={me} role={role} busyId={busyId} expanded={expanded} onToggle={(k) => setExpanded((e) => (e === k ? null : k))} onAct={act} />
        ) : null}
        {rows.length > 0 && kind === 'overtime' ? (
          <OvertimeTable
            rows={rows}
            me={me}
            role={role}
            busyId={busyId}
            expanded={expanded}
            onToggle={(k) => setExpanded((e) => (e === k ? null : k))}
            onAct={act}
            onApproveBatch={approveBatch}
          />
        ) : null}
        {rows.length > 0 ? (
          <p className="text-[11px] text-neutral-700">
            {rows.length} of {all.length} {kind} requests · select a name for its reason and history
            {rows.some((r) => needsMe(actionsFor(r, me, role))) ? ' · rows marked on the left need your action' : ''}
          </p>
        ) : null}
      </div>

      {filing ? (
        <NewRequestDialog
          user={user}
          role={role}
          initialKind={kind}
          onClose={() => setFiling(false)}
          onFiled={(message) => {
            refresh()
            if (message) {
              setFiling(false)
              setNotice(message)
            }
          }}
        />
      ) : null}

      {dialog?.action === 'reject' ? (
        <RejectDialog target={dialog.target} busy={busyId !== null} error={dialog.error} onCancel={() => setDialog(null)} onSubmit={submitDialog} />
      ) : null}
      {dialog?.action === 'reassign' ? (
        <ReassignDialog target={dialog.target} busy={busyId !== null} error={dialog.error} onCancel={() => setDialog(null)} onSubmit={submitDialog} />
      ) : null}
    </div>
  )
}
