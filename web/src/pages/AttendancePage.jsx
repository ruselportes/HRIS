import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { tone } from '../config/nav'

/*
 * Attendance & DTR (the web portal's Fig 20.0 read side) — Phase 10, nav
 * `attendance` matrix: HR full, Site Foreman full, Site Engineer full, Admin
 * and Executive view. Data comes from GET /attendance/records, which scopes
 * per role before any filter: a foreman sees only the crews they led at the
 * instant each tap was captured, an engineer is clamped to their own home site
 * (their filter gets ignored, never applied wider), and HR/Admin/Executive see
 * everything the filters allow. Nothing on this page writes — the same records
 * that sync from the field device are read straight back.
 *
 * Left out rather than faked: the prototype's bulk approve / export actions.
 * Approval lives in the overrides recovery flows; there is no "approve a whole
 * DTR batch" backend action yet.
 */

const SITE_TZ = 'Asia/Manila'

const STATUS_TONE = {
  present: tone.present,
  late: tone.late,
  absent: tone.absent,
  pending: tone.pending,
}
const SYNC_LABEL = {
  synced: 'Synced',
  reconstructed: 'Rebuilt',
}

const inputCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const ymd = (date) => new Intl.DateTimeFormat('en-CA', { timeZone: SITE_TZ }).format(date)
const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', year: 'numeric' })
const siteDay = (d) => (d ? dayFmt.format(new Date(`${d}T12:00:00+08:00`)) : '—')
const daysAgo = (n, from) => {
  const t = new Date(Date.parse(`${from ?? ymd(new Date())}T12:00:00+08:00`))
  return ymd(new Date(t.getTime() - n * 86_400_000))
}

/** The request window for a period choice, in site days. */
function periodWindow(period, custom) {
  const today = ymd(new Date())
  const [y, m] = today.split('-').map(Number)

  if (period === 'last_7') return { from: daysAgo(6, today), to: today }
  if (period === 'last_14') return { from: daysAgo(13, today), to: today }
  if (period === 'this_month') return { from: `${today.slice(0, 7)}-01`, to: today }
  if (period === 'last_month') {
    const first = new Date(Date.UTC(m === 1 ? y - 1 : y, m === 1 ? 11 : m - 2, 1))
    const last = new Date(Date.UTC(y, m - 1, 0))
    const iso = (d) => d.toISOString().slice(0, 10)
    return { from: iso(first), to: iso(last) }
  }
  if (period === 'custom') return custom.from && custom.to ? { ...custom } : null
  return null
}

function customProblem(custom) {
  if (!custom.from || !custom.to) return 'Choose both dates.'
  if (custom.to < custom.from) return 'The end date is before the start date.'
  if ((Date.parse(custom.to) - Date.parse(custom.from)) / 86_400_000 > 62) return 'A window can be at most 62 days.'
  return null
}

function Field({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</label>
      {children}
    </div>
  )
}

function Tag({ bg, fg, children }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap px-2 py-0.5 text-[11px]" style={{ background: bg, color: fg }}>
      {children}
    </span>
  )
}

function StatusTag({ status }) {
  const t = STATUS_TONE[status] ?? tone.neutral
  return <Tag bg={t.bg} fg={t.fg}>{status ?? '—'}</Tag>
}

function Card({ label, value, children, dotBg }) {
  return (
    <div className="border border-neutral-300 bg-surface p-4">
      <div className="flex items-center gap-2">
        {dotBg ? <span className="h-2.5 w-2.5 rounded-full" style={{ background: dotBg }} /> : null}
        <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      </div>
      <div className="font-heading text-[30px] leading-tight tabular-nums">{value}</div>
      <div className="mt-1.5 text-[11px] text-neutral-700">{children}</div>
    </div>
  )
}

function InTime({ row }) {
  if (!row.time_in) {
    return <span className="text-neutral-500">—</span>
  }
  const credited = row.time_in
  const shown = row.captured_at && row.captured_at !== row.time_in ? (
    <span>
      {credited}
      <span className="ml-1 text-[11px] text-neutral-700" title="Actual device tap time">
        tap {row.captured_at}
      </span>
    </span>
  ) : (
    credited
  )
  return shown
}

function OutTime({ row }) {
  if (!row.time_out) {
    return <span className="text-neutral-500">—</span>
  }
  const label = row.time_out_type === 'shift_end' ? ' · shift end' : row.time_out_type === 'manual_time' ? ' · manual' : ''
  return (
    <span>
      {row.time_out}
      <span className="ml-1 text-[11px] text-neutral-700">{label}</span>
    </span>
  )
}

function Notices({ row }) {
  const items = []
  if (row.reconstructed) items.push('no roll call, rebuilt')
  if (row.has_pending_override_review) items.push('override flagged')
  if (row.has_pending_time_out_review) items.push('time-out flagged')
  if (row.override_flag && !row.has_pending_override_review) items.push('override')
  if (!items.length) return null
  return (
    <span className="flex flex-wrap gap-1.5">
      {items.map((n) => (
        <Tag key={n} bg={tone.flagged.bg} fg={tone.flagged.fg}>{n}</Tag>
      ))}
    </span>
  )
}

export function AttendancePage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)
  const singleSite = role === 'engineer' || role === 'foreman'
  // Foremen cannot list the employee registry (EmployeePolicy::viewAny), so the
  // employee picker is for the roles that can; everyone else narrows by crew.
  const canListEmployees = role !== 'foreman'

  const [period, setPeriod] = useState('last_14')
  const [custom, setCustom] = useState({ from: '', to: '' })
  const [siteId, setSiteId] = useState('')
  const [crewId, setCrewId] = useState('')
  const [employeeId, setEmployeeId] = useState('')
  const [page, setPage] = useState(1)
  const [sites, setSites] = useState([])
  const [people, setPeople] = useState([])
  const [state, setState] = useState({ key: null, rows: [], meta: null, summary: null, crewOptions: [], error: null })

  const problem = period === 'custom' ? customProblem(custom) : null
  const win = problem ? null : periodWindow(period, custom)
  const queryKey = problem
    ? null
    : `${win?.from ?? ''}|${win?.to ?? ''}|${singleSite ? '' : siteId}|${crewId}|${employeeId}|${page}`
  const loading = queryKey !== null && state.key !== queryKey

  // A filter change restarts the listing from page one, so the user is not
  // dropped onto a page of a different result set.
  const resetPage = (fn) => {
    setPage(1)
    fn()
  }

  useEffect(() => {
    if (singleSite) return
    http
      .get('/sites')
      .then((r) => setSites(r.data.sites ?? []))
      .catch(() => {})
  }, [singleSite])

  useEffect(() => {
    if (!canListEmployees) return
    let active = true
    const load = async () => {
      const people = []
      let p = 1
      for (;;) {
        const { data } = await http.get('/employees', { params: { per_page: 100, page: p } })
        people.push(...(data.data ?? []))
        if (p >= (data.meta?.last_page ?? 1)) break
        p += 1
      }
      if (active) setPeople(people)
    }
    load().catch(() => {})
    return () => {
      active = false
    }
  }, [canListEmployees])

  useEffect(() => {
    if (queryKey === null) return
    let active = true
    const [from, to, site, crew, employee, pg] = queryKey.split('|')
    http
      .get('/attendance/records', {
        params: {
          from,
          to,
          site_id: site || undefined,
          crew_id: crew || undefined,
          employee_id: employee || undefined,
          per_page: 50,
          page: Number(pg),
        },
      })
      .then((r) =>
        active &&
        setState({
          key: queryKey,
          rows: r.data.data,
          meta: r.data.meta,
          summary: r.data.summary,
          crewOptions: r.data.crew_options ?? [],
          error: null,
        }),
      )
      .catch((err) =>
        active &&
        setState({ key: queryKey, rows: [], meta: null, summary: null, crewOptions: [], error: errorMessage(err, 'Unable to load attendance records.') }),
      )
    return () => {
      active = false
    }
  }, [queryKey])

  const changeSite = (e) => {
    const v = e.target.value
    resetPage(() => {
      setSiteId(v)
      setCrewId('')
      setEmployeeId('')
    })
  }
  const changeCrew = (e) => resetPage(() => setCrewId(e.target.value))
  const changeEmployee = (e) => resetPage(() => setEmployeeId(e.target.value))

  const s = state.summary ?? {}

  return (
    <div className="flex flex-col pb-10">
      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
        <div className="w-[170px]">
          <Field label="Period">
            <select className={inputCls} value={period} onChange={(e) => resetPage(() => setPeriod(e.target.value))}>
              <option value="last_7">Last 7 days</option>
              <option value="last_14">Last 14 days</option>
              <option value="this_month">This month</option>
              <option value="last_month">Last month</option>
              <option value="custom">Custom…</option>
            </select>
          </Field>
        </div>
        {period === 'custom' ? (
          <>
            <div className="w-[150px]">
              <Field label="From">
                <input type="date" className={inputCls} value={custom.from} onChange={(e) => resetPage(() => setCustom({ ...custom, from: e.target.value }))} />
              </Field>
            </div>
            <div className="w-[150px]">
              <Field label="To">
                <input type="date" className={inputCls} value={custom.to} min={custom.from} onChange={(e) => resetPage(() => setCustom({ ...custom, to: e.target.value }))} />
              </Field>
            </div>
          </>
        ) : null}
        <div className="w-[210px]">
          <Field label="Site">
            {singleSite ? (
              <div className={`${inputCls} flex items-center`} title={role === 'engineer' ? 'Site Engineers see their own site only' : 'Foremen see only the crews they led'}>
                {user?.site?.site_name ?? 'Your site'}
              </div>
            ) : (
              <select className={inputCls} value={siteId} onChange={changeSite}>
                <option value="">All work sites</option>
                {sites.map((site) => (
                  <option key={site.site_id} value={site.site_id}>
                    {site.site_name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        </div>
        <div className="w-[230px]">
          <Field label="Crew">
            <select className={inputCls} value={crewId} onChange={changeCrew} disabled={!state.crewOptions.length}>
              <option value="">All crews</option>
              {state.crewOptions.map((c) => (
                <option key={c.crew_id} value={c.crew_id}>
                  {c.crew_name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        {canListEmployees ? (
          <div className="w-[230px]">
            <Field label="Employee">
              <select className={inputCls} value={employeeId} onChange={changeEmployee} disabled={!people.length}>
                <option value="">All employees</option>
                {people.map((p) => (
                  <option key={p.employee_id} value={p.employee_id}>
                    {p.full_name} · {p.employee_code}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        ) : null}
        <div className="ml-auto self-center text-xs text-neutral-700">
          {win ? (
            <span>
              {siteDay(win.from)} – {siteDay(win.to)}
              {state.meta ? ` · ${state.meta.total} ${state.meta.total === 1 ? 'record' : 'records'}` : ''}
            </span>
          ) : null}
        </div>
      </div>

      {problem ? <p className="px-[22px] pt-4 text-sm text-[#75261C]">{problem}</p> : null}
      {state.error ? <p className="px-[22px] pt-4 text-sm text-[#75261C]">{state.error}</p> : null}
      {loading && !state.rows.length ? <p className="px-[22px] pt-4 text-sm text-neutral-700">Loading records…</p> : null}
      {!loading && !state.error && queryKey !== null && !state.rows.length ? (
        <p className="px-[22px] pt-4 text-sm text-neutral-700">No attendance records in this window. Try a wider period.</p>
      ) : null}

      {state.summary && state.rows.length ? (
        <div className={loading ? 'opacity-60 transition-opacity' : 'transition-opacity'}>
          <div className="grid grid-cols-2 gap-4 px-[22px] pt-5 lg:grid-cols-5">
            <Card label="Present" value={s.present} dotBg={tone.present.fg}>credited on time</Card>
            <Card label="Late" value={s.late} dotBg={tone.late.fg}>tap after shift start</Card>
            <Card label="Absent" value={s.absent} dotBg={tone.absent.fg}>no roll call</Card>
            <Card label="Pending" value={s.pending} dotBg={tone.pending.fg}>roll call not closed</Card>
            <Card label="Flagged" value={s.flagged} dotBg={tone.flagged.fg}>awaiting HR review</Card>
          </div>

          <section className="mx-[22px] mt-5 overflow-x-auto border border-neutral-300 bg-surface">
            <div className="px-[18px] pt-[18px]">
              <h2 className="font-heading text-[19px]">Daily time records</h2>
              <p className="text-xs text-neutral-700">
                Time in is the credited time; the device tap time is shown underneath when it differs. Reads are server-scoped to what your role may see.
              </p>
            </div>
            <table className="mt-3 w-full min-w-[980px] border-collapse text-sm tabular-nums">
              <thead>
                <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  <th className="py-2.5 pl-[18px] pr-4 font-normal">Date</th>
                  <th className="py-2.5 pr-4 font-normal">Employee</th>
                  <th className="py-2.5 pr-4 font-normal">Site / crew</th>
                  <th className="py-2.5 pr-4 font-normal">Status</th>
                  <th className="py-2.5 pr-4 font-normal">In</th>
                  <th className="py-2.5 pr-4 font-normal">Out</th>
                  <th className="py-2.5 pr-4 font-normal">Sync</th>
                  <th className="py-2.5 pr-[18px] font-normal">Notices</th>
                </tr>
              </thead>
              <tbody>
                {state.rows.map((r) => (
                  <tr key={r.attendance_id} className="border-b border-neutral-200">
                    <td className="py-2.5 pl-[18px] pr-4 whitespace-nowrap">{siteDay(r.date)}</td>
                    <td className="py-2.5 pr-4">
                      <div>{r.employee?.full_name}</div>
                      <div className="text-[11px] text-neutral-700">
                        {r.employee?.employee_code}
                        {r.employee?.trade_skill ? ` · ${r.employee.trade_skill}` : ''}
                      </div>
                    </td>
                    <td className="py-2.5 pr-4">
                      <div>{r.site?.site_name ?? '—'}</div>
                      <div className="text-[11px] text-neutral-700">{r.crew?.crew_name ?? '—'}</div>
                    </td>
                    <td className="py-2.5 pr-4"><StatusTag status={r.status} /></td>
                    <td className="py-2.5 pr-4 whitespace-nowrap"><InTime row={r} /></td>
                    <td className="py-2.5 pr-4 whitespace-nowrap"><OutTime row={r} /></td>
                    <td className="py-2.5 pr-4">
                      <span>{SYNC_LABEL[r.sync_status] ?? 'Unsynced'}</span>
                    </td>
                    <td className="py-2.5 pr-[18px]"><Notices row={r} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            {state.meta && state.meta.pages > 1 ? (
              <div className="flex items-center justify-end gap-2.5 border-t border-neutral-300 px-5 py-3">
                <span className="mr-auto text-xs text-neutral-700">
                  Page {state.meta.page} of {state.meta.pages}
                </span>
                <button className={btnSecondary} disabled={loading || page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Previous
                </button>
                <button className={btnSecondary} disabled={loading || page >= state.meta.pages} onClick={() => setPage((p) => p + 1)}>
                  Next
                </button>
              </div>
            ) : null}
          </section>
        </div>
      ) : null}
    </div>
  )
}