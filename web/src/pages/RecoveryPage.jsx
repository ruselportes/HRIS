import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Attendance Recovery (docs/prototypes/HRIS Attendance Recovery
 * Signoff.dc.html) — Phase 7, UC-07.
 *
 * A deployed crew's working day with no roll call at all is a gap. The Site
 * Engineer reconstructs it from the roster with a cause and a note (first
 * signature); HR signs it off or returns it (second signature). Reconstructed
 * records are paid only after both.
 *
 * The prototype's "Evidence" column rates gate biometrics, which this system
 * does not collect. In its place is the one check that is available: whether
 * the foreman's phone could still be holding that day's roll call.
 */

const SITE_TZ = 'Asia/Manila'

const CAUSES = {
  foreman_absent: 'Foreman absent',
  phone_problem: 'Phone lost or broken',
  records_rejected: 'Records rejected on sync',
  no_work: 'No work that day',
  other: 'Other',
}

const STAGES = {
  awaiting_engineer: { label: 'Awaiting engineer', ...tone.pending },
  returned: { label: 'Returned to engineer', ...tone.absent },
  awaiting_hr: { label: 'Awaiting HR sign-off', ...tone.late },
  closed: { label: 'Closed', ...tone.present },
}

const PHONE = {
  nothing_waiting: { label: 'Nothing waiting on phone', ...tone.present },
  may_be_on_phone: { label: 'May still be on phone', ...tone.late },
  no_phone: { label: 'No phone set up', ...tone.neutral },
}

const STATUSES = [
  ['present', 'Present'],
  ['late', 'Late'],
  ['absent', 'Absent'],
  ['not_on_crew', 'Not on crew'],
]

const STATUS_LABEL = Object.fromEntries([...STATUSES, ['no_work', 'No work']])

const STEP_LABEL = {
  RECOVERY_SUBMITTED: 'Reconstructed and signed',
  RECOVERY_RETURNED: 'Returned to engineer',
  RECOVERY_SIGNED_OFF: 'Signed off',
}

const selectCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnPrimary =
  'inline-flex items-center justify-center gap-2 border border-primary-800 bg-primary-800 px-4 py-2 text-[13px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40'
const btnReject =
  'inline-flex items-center justify-center gap-2 border border-[#A83A2C] bg-canvas px-4 py-2 text-[13px] text-[#75261C] disabled:cursor-not-allowed disabled:opacity-40'

const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
const stampFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

const siteDay = (ymd) => (ymd ? dayFmt.format(new Date(`${ymd}T12:00:00+08:00`)) : '—')
const stamp = (iso) => (iso ? stampFmt.format(new Date(iso)) : '')
const hours = (v) => Number(v ?? 0).toFixed(1)
const peso = (v) => `₱${Number(v ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 0 })}`

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

function Tag({ bg, fg, children }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 py-0.5 text-[11px]" style={{ background: bg, color: fg }}>
      {children}
    </span>
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

function Field({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</label>
      {children}
    </div>
  )
}

export function RecoveryPage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)

  const [siteId, setSiteId] = useState('')
  const [stage, setStage] = useState('open')
  const [cause, setCause] = useState('')
  const [sites, setSites] = useState([])
  const [reload, setReload] = useState(0)
  const [result, setResult] = useState({ key: null, items: [], summary: null, error: null })
  const [open, setOpen] = useState(null)
  const [notice, setNotice] = useState(null)

  const queryKey = `${siteId}|${reload}`
  const { items, summary, error } = result

  useEffect(() => {
    let active = true
    http
      .get('/recovery', { params: { site_id: siteId || undefined } })
      .then((r) => active && setResult({ key: queryKey, items: r.data.data, summary: r.data.summary, error: null }))
      .catch((err) => active && setResult({ key: queryKey, items: [], summary: null, error: errorMessage(err, 'Unable to load recovery.') }))
    return () => {
      active = false
    }
  }, [queryKey, siteId])

  useEffect(() => {
    http
      .get('/sites')
      .then((r) => setSites(r.data.sites ?? []))
      .catch(() => {})
  }, [])

  const visible = items.filter(
    (i) => (stage === 'all' || (stage === 'open' ? i.stage !== 'closed' : i.stage === stage)) && (!cause || i.cause === cause),
  )

  const done = (message) => {
    setNotice(message)
    setReload((n) => n + 1)
  }

  return (
    <div className="flex flex-col pb-10">
      <div className="grid grid-cols-2 gap-4 border-b border-neutral-200 px-[22px] py-[18px] lg:grid-cols-4">
        <StatCard label="Crew-days to recover" value={summary?.to_recover ?? '—'} note={`${summary?.to_recover_records ?? 0} worker records`} />
        <StatCard label="Awaiting HR" value={summary?.awaiting_hr ?? '—'} note="engineer already signed" accent="#8A5211" />
        <StatCard label="Hours at risk" value={hours(summary?.hours_at_risk)} note={`≈ ${peso(summary?.amount_at_risk)} unpaid if not recovered`} accent="#96420E" />
        <StatCard label="Recovered" value={summary?.recovered ?? '—'} note="signed off by both, in this window" accent="#1F5334" />
      </div>

      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
        <div className="w-[200px]">
          <Field label="Cause">
            <select className={selectCls} value={cause} onChange={(e) => setCause(e.target.value)}>
              <option value="">All causes</option>
              {Object.entries(CAUSES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="w-[200px]">
          <Field label="Site">
            <select className={selectCls} value={siteId} onChange={(e) => setSiteId(e.target.value)}>
              <option value="">All sites</option>
              {sites.map((s) => (
                <option key={s.site_id} value={s.site_id}>
                  {s.site_name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="w-[200px]">
          <Field label="Stage">
            <select className={selectCls} value={stage} onChange={(e) => setStage(e.target.value)}>
              <option value="open">Not yet signed off</option>
              <option value="awaiting_engineer">Awaiting engineer</option>
              <option value="returned">Returned to engineer</option>
              <option value="awaiting_hr">Awaiting HR sign-off</option>
              <option value="closed">Closed</option>
              <option value="all">All stages</option>
            </select>
          </Field>
        </div>
        <div className="ml-auto flex items-center gap-3 self-center">
          {role === 'admin' ? <Tag {...tone.neutral}>View only</Tag> : null}
          {notice ? <span className="border border-primary-500 px-3 py-1.5 text-xs text-primary-800">{notice}</span> : null}
        </div>
      </div>

      <div className="overflow-x-auto px-[22px] pt-3">
        {error ? <p className="py-4 text-sm text-[#75261C]">{error}</p> : null}
        <table className="w-full min-w-[860px] border-collapse text-sm tabular-nums">
          <thead>
            <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
              <th className="py-2.5 pr-4 font-normal">Crew-day</th>
              <th className="w-[190px] py-2.5 pr-4 font-normal">Cause</th>
              <th className="w-[80px] py-2.5 pr-4 font-normal">Records</th>
              <th className="w-[80px] py-2.5 pr-4 font-normal">Hours</th>
              <th className="w-[190px] py-2.5 pr-4 font-normal">Phone check</th>
              <th className="w-[190px] py-2.5 font-normal">Sign-off stage</th>
            </tr>
          </thead>
          <tbody>
            {visible.map((item) => {
              const st = STAGES[item.stage]
              const phone = PHONE[item.phone?.status]
              return (
                <tr
                  key={`${item.crew_id}-${item.date}`}
                  className="cursor-pointer border-b border-neutral-200 hover:bg-neutral-200/60"
                  onClick={() => setOpen({ crewId: item.crew_id, date: item.date })}
                >
                  <td className="py-2.5 pr-4">
                    {[item.site?.site_name, item.crew_name].filter(Boolean).join(' · ')}
                    <div className="text-[11px] text-neutral-700">{siteDay(item.date)}</div>
                  </td>
                  <td className="py-2.5 pr-4">{item.cause ? CAUSES[item.cause] : <span className="text-neutral-600">Not stated yet</span>}</td>
                  <td className="py-2.5 pr-4">{item.records}</td>
                  <td className="py-2.5 pr-4">{hours(item.hours)}</td>
                  <td className="py-2.5 pr-4">{phone ? <Tag bg={phone.bg} fg={phone.fg}>{phone.label}</Tag> : '—'}</td>
                  <td className="py-2.5">
                    <Tag bg={st.bg} fg={st.fg}>
                      {st.label}
                    </Tag>
                  </td>
                </tr>
              )
            })}
            {result.key !== null && !visible.length ? (
              <tr>
                <td colSpan={6} className="py-8 text-center text-sm text-neutral-700">
                  {stage === 'open' ? 'No crew-days waiting to be recovered.' : 'No crew-days match these filters.'}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
        <p className="mt-3 text-[11px] text-neutral-700">
          A gap is a deployed crew's working day (Mon–Sat) with no roll call for anyone
          {summary?.window ? `, from ${siteDay(summary.window.from)} to ${siteDay(summary.window.to)}` : ''}.
          Phone check: the foreman's phone sends records strictly in order, so once it has sent roll call from a later day, nothing from this day
          is still waiting on it.
        </p>
      </div>

      {open ? (
        <RecoveryPanel
          key={`${open.crewId}-${open.date}`}
          crewId={open.crewId}
          date={open.date}
          role={role}
          onClose={() => setOpen(null)}
          onDone={(message) => {
            setOpen(null)
            done(message)
          }}
        />
      ) : null}
    </div>
  )
}

function RecoveryPanel({ crewId, date, role, onClose, onDone }) {
  const [detail, setDetail] = useState(null)
  const [loadError, setLoadError] = useState(null)

  useEffect(() => {
    let active = true
    http
      .get(`/recovery/${crewId}/${date}`)
      .then((r) => active && setDetail(r.data.data))
      .catch((err) => active && setLoadError(errorMessage(err, 'Unable to load this crew-day.')))
    return () => {
      active = false
    }
  }, [crewId, date])

  const editable = detail && role === 'engineer' && ['awaiting_engineer', 'returned'].includes(detail.stage)
  const reviewable = detail && role === 'hr' && detail.stage === 'awaiting_hr'
  const st = detail ? STAGES[detail.stage] : null
  const phone = detail ? PHONE[detail.phone?.status] : null

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" role="dialog" aria-modal="true" aria-label="Recover crew-day">
      <div className="flex h-full w-full max-w-[720px] flex-col bg-canvas shadow-lg">
        <div className="flex items-start gap-3 border-b border-neutral-300 px-5 py-4">
          <div className="min-w-0 flex-1">
            <div className="text-[11px] uppercase tracking-[.1em] text-neutral-700">Attendance recovery{detail?.code ? ` · ${detail.code}` : ''}</div>
            <h2 className="font-heading text-2xl leading-tight">
              {detail ? [detail.crew_name, detail.site?.site_name].filter(Boolean).join(' · ') : 'Loading…'}
            </h2>
            <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-neutral-700">
              {siteDay(date)}
              {st ? <Tag bg={st.bg} fg={st.fg}>{st.label}</Tag> : null}
            </div>
          </div>
          <button onClick={onClose} aria-label="Close" className="text-neutral-500 hover:text-ink">
            <Icon name="close" size={18} />
          </button>
        </div>

        <div className="flex-1 overflow-auto px-5 py-4">
          {loadError ? <p className="text-sm text-[#75261C]">{loadError}</p> : null}
          {detail ? (
            <div className="flex flex-col gap-4">
              {phone ? (
                <div className="flex gap-2.5 border border-neutral-300 bg-surface p-3">
                  <Tag bg={phone.bg} fg={phone.fg}>{phone.label}</Tag>
                  <p className="text-xs text-neutral-700">{detail.phone.detail}</p>
                </div>
              ) : null}

              {detail.stage === 'returned' && detail.hr_note ? (
                <div className="border border-[#A83A2C] bg-[#F7E8E5] p-3 text-sm text-[#75261C]">
                  <div className="text-[11px] uppercase tracking-[.08em]">Returned by {detail.hr?.full_name}</div>
                  {detail.hr_note}
                </div>
              ) : null}

              {editable ? (
                <ReconstructForm detail={detail} onDone={onDone} />
              ) : (
                <ReadOnly detail={detail} role={role} />
              )}

              {reviewable ? <ReviewActions detail={detail} onDone={onDone} /> : null}

              {detail.history?.length ? (
                <div>
                  <div className="mb-1.5 text-[11px] uppercase tracking-[.08em] text-neutral-700">History</div>
                  <ol className="flex flex-col gap-2">
                    {detail.history.map((h, i) => (
                      <li key={i} className="border-l-2 border-neutral-300 pl-3 text-xs">
                        <span className="text-ink">{STEP_LABEL[h.action] ?? h.action}</span>
                        <span className="text-neutral-700">
                          {' '}
                          · {h.actor?.full_name} · {stamp(h.at)}
                        </span>
                        {h.note ? <div className="mt-0.5 text-neutral-700">{h.note}</div> : null}
                      </li>
                    ))}
                  </ol>
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}

function ReconstructForm({ detail, onDone }) {
  const initial = Object.fromEntries(
    detail.roster.map((r) => [
      r.employee.employee_id,
      {
        status: r.proposed && r.proposed.status !== 'no_work' ? r.proposed.status : 'present',
        time_in: r.proposed?.time_in ?? detail.shift_start,
      },
    ]),
  )
  const [cause, setCause] = useState(detail.cause ?? '')
  const [note, setNote] = useState(detail.engineer_note ?? '')
  const [rows, setRows] = useState(initial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const set = (id, patch) => setRows((prev) => ({ ...prev, [id]: { ...prev[id], ...patch } }))
  const noWork = cause === 'no_work'

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await http.post(`/recovery/${detail.crew_id}/${detail.date}`, {
        cause,
        note,
        records: noWork
          ? []
          : Object.entries(rows).map(([id, r]) => ({
              employee_id: Number(id),
              status: r.status,
              time_in: ['present', 'late'].includes(r.status) ? r.time_in : null,
            })),
      })
      onDone(`${detail.crew_name}, ${siteDay(detail.date)} sent to HR for sign-off.`)
    } catch (err) {
      const first = err.response?.data?.errors ? Object.values(err.response.data.errors)[0]?.[0] : null
      setError(first ?? errorMessage(err, 'Unable to submit the reconstruction.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Field label="Why was there no roll call?">
        <select className={selectCls} value={cause} onChange={(e) => setCause(e.target.value)}>
          <option value="">Choose a cause…</option>
          {Object.entries(CAUSES).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </Field>

      {noWork ? (
        <p className="border border-neutral-300 bg-surface p-3 text-xs text-neutral-700">
          No attendance is written for a day with no work. HR still signs it off, since it means nobody on the crew is paid for the day.
        </p>
      ) : (
        <div>
          <div className="mb-1.5 text-[11px] uppercase tracking-[.08em] text-neutral-700">Crew that day</div>
          <table className="w-full border-collapse text-sm">
            <tbody>
              {detail.roster.map((r) => {
                const row = rows[r.employee.employee_id]
                const worked = ['present', 'late'].includes(row.status)
                return (
                  <tr key={r.employee.employee_id} className="border-b border-neutral-200">
                    <td className="py-2 pr-3">
                      {r.employee.full_name}
                      <div className="text-[11px] text-neutral-700">{[r.employee.employee_code, r.employee.trade_skill].filter(Boolean).join(' · ')}</div>
                    </td>
                    <td className="py-2 pr-3">
                      <div className="flex border border-neutral-400" role="radiogroup" aria-label={`Status for ${r.employee.full_name}`}>
                        {STATUSES.map(([value, label]) => (
                          <button
                            key={value}
                            type="button"
                            role="radio"
                            aria-checked={row.status === value}
                            onClick={() => set(r.employee.employee_id, { status: value })}
                            className={`min-h-[32px] flex-1 whitespace-nowrap px-2 text-xs ${row.status === value ? 'bg-primary-800 text-white' : 'bg-canvas text-ink'}`}
                          >
                            {label}
                          </button>
                        ))}
                      </div>
                    </td>
                    <td className="w-[110px] py-2">
                      <input
                        type="time"
                        aria-label={`Arrival for ${r.employee.full_name}`}
                        disabled={!worked}
                        value={worked ? row.time_in : ''}
                        onChange={(e) => set(r.employee.employee_id, { time_in: e.target.value })}
                        className="h-[32px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] disabled:opacity-40"
                      />
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <Field label="What happened, and what is this based on?">
        <textarea
          className="min-h-[88px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
          value={note}
          maxLength={2000}
          onChange={(e) => setNote(e.target.value)}
          placeholder="e.g. Foreman absent with no acting cover. Rebuilt from the site logbook and the gate guard's list."
        />
      </Field>

      {error ? <p className="text-xs text-[#75261C]">{error}</p> : null}
      <div className="flex items-center justify-end gap-3">
        <span className="text-xs text-neutral-700">Your signature is the first of two. HR signs it off before payroll can use it.</span>
        <button className={btnPrimary} disabled={busy || !cause || note.trim().length < 10} onClick={submit}>
          {busy ? 'Signing…' : 'Sign as Site Engineer'}
        </button>
      </div>
    </div>
  )
}

function ReadOnly({ detail, role }) {
  if (detail.stage === 'awaiting_engineer') {
    return (
      <p className="border border-neutral-300 bg-surface p-3 text-sm text-neutral-700">
        {role === 'engineer' ? 'Nothing to edit.' : 'Waiting for a Site Engineer to reconstruct this day.'} {detail.records} workers on the crew ·{' '}
        {hours(detail.hours)} hours unpaid until it is recovered.
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-4 border border-neutral-300 bg-surface p-3 text-sm">
        <div>
          <div className="text-[11px] text-neutral-700">Cause</div>
          {CAUSES[detail.cause] ?? '—'}
        </div>
        <div>
          <div className="text-[11px] text-neutral-700">Signatures</div>
          <div>
            Engineer: {detail.engineer?.full_name ?? '—'} <span className="text-neutral-700">{stamp(detail.engineer_signed_at)}</span>
          </div>
          <div>
            HR:{' '}
            {detail.stage === 'closed' ? (
              <>
                {detail.hr?.full_name} <span className="text-neutral-700">{stamp(detail.hr_signed_at)}</span>
              </>
            ) : (
              <span className="text-neutral-700">not yet</span>
            )}
          </div>
        </div>
        <div className="col-span-2">
          <div className="text-[11px] text-neutral-700">Engineer's note</div>
          {detail.engineer_note}
        </div>
      </div>

      <table className="w-full border-collapse text-sm tabular-nums">
        <thead>
          <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
            <th className="py-2 pr-3 font-normal">Worker</th>
            <th className="w-[140px] py-2 pr-3 font-normal">Status</th>
            <th className="w-[90px] py-2 font-normal">Time in</th>
          </tr>
        </thead>
        <tbody>
          {detail.roster.map((r) => (
            <tr key={r.employee.employee_id} className="border-b border-neutral-200">
              <td className="py-2 pr-3">
                {r.employee.full_name}
                <div className="text-[11px] text-neutral-700">{r.employee.employee_code}</div>
              </td>
              <td className="py-2 pr-3">{STATUS_LABEL[r.proposed?.status] ?? '—'}</td>
              <td className="py-2">{r.proposed?.time_in ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="text-[11px] text-neutral-700">Reconstructed, not captured on a phone. Payroll uses these only once HR has signed off.</p>
    </div>
  )
}

function ReviewActions({ detail, onDone }) {
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const decide = async (action) => {
    setBusy(true)
    setError(null)
    try {
      await http.post(`/recovery/cases/${detail.case_id}/${action}`, note.trim() ? { note: note.trim() } : {})
      onDone(action === 'sign-off' ? `${detail.code} signed off.` : `${detail.code} returned to the engineer.`)
    } catch (err) {
      setError(errorMessage(err, 'Unable to record the decision.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex flex-col gap-2 border-t border-neutral-300 pt-4">
      <Field label="Note (required to return)">
        <textarea
          className="min-h-[64px] w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-[13px] text-ink"
          value={note}
          maxLength={2000}
          onChange={(e) => setNote(e.target.value)}
          placeholder="e.g. Villamor was on approved leave that day."
        />
      </Field>
      {error ? <p className="text-xs text-[#75261C]">{error}</p> : null}
      <div className="flex items-center justify-end gap-2.5">
        <button className={btnReject} disabled={busy || !note.trim()} onClick={() => decide('return')}>
          Return to engineer
        </button>
        <button className={btnPrimary} disabled={busy} onClick={() => decide('sign-off')}>
          Sign off (second signature)
        </button>
      </div>
    </div>
  )
}
