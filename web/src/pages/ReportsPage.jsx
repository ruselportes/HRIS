import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Reports & Analytics (docs/prototypes/HRIS Executive Dashboard.dc.html) —
 * Phase 9, UC-09 / FR-09.
 *
 * Headline figures, labour cost and compliance by site, and the flagged audit
 * feed, for a window of site days (default: the last 30). Every figure comes
 * from GET /reports/overview, which defines them:
 *
 *   score per site = 100 − 2×expired certifications − overrides
 *                    − 5×integrity incidents, clamped 0–100;
 *                    good ≥ 85, fair ≥ 80, watch below.
 *   company score  = the headcount-weighted average of the site scores.
 *   labour cost    = approved payroll rows only, by payslip line date.
 *
 * A Site Engineer only ever sees their own site; the server enforces that, and
 * the site filter here just says so.
 *
 * Left out rather than faked: the prototype's "compare with" month, its trend
 * sparklines, the attendance target marker and the manning counts. The system
 * records none of them yet.
 */

const SITE_TZ = 'Asia/Manila'

const INTEGRITY = ['ATTENDANCE_CLOCK_FLAGGED', 'ATTENDANCE_VERIFICATION_FAILED']
const OVERRIDES = ['FOREMAN_LATE_OVERRIDE', 'MANUAL_TIME_OVERRIDE', 'MANUAL_TIME_OUT']

// The audit log's action types, for the full log's filter (ReportsQueryRequest::ACTION_TYPES).
const ACTION_TYPES = [
  'FOREMAN_LATE_OVERRIDE',
  'MANUAL_TIME_OVERRIDE',
  'MANUAL_TIME_OUT',
  'ATTENDANCE_CLOCK_FLAGGED',
  'ATTENDANCE_VERIFICATION_FAILED',
  'ATTENDANCE_REFUSED',
  'ACTING_FOREMAN_ASSIGNED',
  'ACTING_FOREMAN_ENDED',
  'RETROACTIVE_RECOVERY',
  'RECOVERY_SUBMITTED',
  'RECOVERY_RETURNED',
  'RECOVERY_SIGNED_OFF',
  'PAYROLL_APPROVED',
  'REQUEST_SUBMITTED',
  'REQUEST_ENDORSED',
  'REQUEST_APPROVED',
  'REQUEST_REJECTED',
  'REQUEST_CANCELLED',
  'REQUEST_ENDORSER_REASSIGNED',
]

const BAND = {
  good: { label: 'Good', ...tone.present, ink: '#1F5334' },
  fair: { label: 'Fair', ...tone.late, ink: '#8A5211' },
  watch: { label: 'Watch', ...tone.absent, ink: '#75261C' },
}

const REGULAR_INK = 'var(--color-primary-700)'
const OVERTIME_INK = '#C9781B'

const inputCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'
const chip = (active) =>
  `border px-2.5 py-1 text-[11px] ${active ? 'border-primary-800 bg-primary-800 text-white' : 'border-neutral-400 bg-canvas text-ink'}`

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const ymd = (date) => new Intl.DateTimeFormat('en-CA', { timeZone: SITE_TZ }).format(date)
const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', year: 'numeric' })
const stampFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const siteDay = (d) => (d ? dayFmt.format(new Date(`${d}T12:00:00+08:00`)) : '—')
const stamp = (iso) => (iso ? stampFmt.format(new Date(iso)) : '')

const pesoFull = (v) => `₱${Number(v ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 0 })}`
const pesoShort = (v) =>
  `₱${new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 2 }).format(Number(v ?? 0))}`
const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`)

/** The request window for a period choice, in site days. Null = the server's default (last 30 days). */
function periodWindow(period, custom) {
  const today = ymd(new Date())
  const [y, m] = today.split('-').map(Number)

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
  if ((Date.parse(custom.to) - Date.parse(custom.from)) / 86_400_000 > 366) return 'A window can be at most 366 days.'
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

function ScoreTag({ score }) {
  const band = BAND[score?.band] ?? BAND.watch
  return (
    <Tag bg={band.bg} fg={band.fg}>
      {score?.value ?? '—'} · {band.label}
    </Tag>
  )
}

function Card({ label, value, children }) {
  return (
    <div className="border border-neutral-300 bg-surface p-4">
      <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      <div className="font-heading text-[34px] leading-tight tabular-nums">{value}</div>
      <div className="mt-1.5 text-[11px] text-neutral-700">{children}</div>
    </div>
  )
}

/** One site's bar: regular and overtime cost (scaled to the costliest site), with the score as a dot on the same track. */
function SiteBar({ site, maxCost }) {
  const band = BAND[site.score.band] ?? BAND.watch
  const regular = maxCost > 0 ? (site.labour_cost.regular / maxCost) * 100 : 0
  const overtime = maxCost > 0 ? (site.labour_cost.overtime / maxCost) * 100 : 0

  return (
    <div className="grid grid-cols-[168px_1fr_88px_88px] items-center gap-3.5">
      <div className="min-w-0">
        <div className="truncate text-[13px]">{site.site_name}</div>
        <div className="text-[11px] text-neutral-700">
          {site.workers} {site.workers === 1 ? 'worker' : 'workers'}
        </div>
      </div>
      <div className="relative flex h-[22px] bg-neutral-200" aria-hidden="true">
        <span style={{ width: `${regular}%`, background: REGULAR_INK }} />
        <span style={{ width: `${overtime}%`, background: OVERTIME_INK }} />
        <span
          className="absolute top-1/2 h-[11px] w-[11px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-canvas"
          style={{ left: `${site.score.value}%`, border: `2px solid ${band.ink}` }}
        />
      </div>
      <div className="text-right text-[13px] tabular-nums" title={pesoFull(site.labour_cost.total)}>
        {pesoShort(site.labour_cost.total)}
      </div>
      <div className="text-right">
        <ScoreTag score={site.score} />
      </div>
    </div>
  )
}

function feedInk(action) {
  if (INTEGRITY.includes(action)) return { border: '#A83A2C', text: '#75261C' }
  if (OVERRIDES.includes(action)) return { border: '#6B4B8A', text: '#4A3260' }
  return { border: 'var(--color-primary-700)', text: 'var(--color-primary-800)' }
}

function FeedItem({ row }) {
  const ink = feedInk(row.action)
  return (
    <div className="border border-neutral-300 bg-canvas p-3" style={{ borderLeft: `3px solid ${ink.border}` }}>
      <div className="mb-1.5 flex flex-wrap items-center gap-2">
        <span className="font-mono text-[11px]" style={{ color: ink.text }}>
          {row.action}
        </span>
        <span className="ml-auto text-[11px] tabular-nums text-neutral-700">{stamp(row.timestamp)}</span>
      </div>
      <div className="text-[13px] leading-snug">{row.description}</div>
      <div className="mt-1.5 text-[11px] text-neutral-700">
        {[row.actor, row.subject_date ? `for ${siteDay(row.subject_date)}` : null, row.review_status ? `review: ${row.review_status}` : null]
          .filter(Boolean)
          .join(' · ')}
      </div>
    </div>
  )
}

/** The whole audit log for the window, paged, filterable by action. */
function AuditLogDialog({ range, siteId, onClose }) {
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const [state, setState] = useState({ key: null, rows: [], meta: null, error: null })
  const key = `${action}|${page}`

  useEffect(() => {
    let active = true
    http
      .get('/reports/audit', {
        params: { from: range.from, to: range.to, site_id: siteId || undefined, action: action || undefined, page, per_page: 25 },
      })
      .then((r) => active && setState({ key, rows: r.data.data, meta: r.data.meta, error: null }))
      .catch((err) => active && setState({ key, rows: [], meta: null, error: errorMessage(err, 'Unable to load the audit log.') }))
    return () => {
      active = false
    }
  }, [key, action, page, range, siteId])

  const loading = state.key !== key

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4" role="dialog" aria-modal="true" aria-label="Audit log">
      <div className="flex max-h-[90vh] w-full max-w-[860px] flex-col border border-neutral-300 bg-surface shadow-lg">
        <div className="flex items-center justify-between border-b border-neutral-300 px-5 py-3.5">
          <div>
            <h3 className="font-heading text-lg">Audit log</h3>
            <div className="text-xs text-neutral-700">
              {siteDay(range.from)} – {siteDay(range.to)} · newest first · read-only
            </div>
          </div>
          <button className="text-neutral-500 hover:text-ink" onClick={onClose} aria-label="Close">
            <Icon name="close" size={16} />
          </button>
        </div>
        <div className="flex items-end gap-3 border-b border-neutral-200 px-5 py-3">
          <div className="w-[300px]">
            <Field label="Action">
              <select
                className={inputCls}
                value={action}
                onChange={(e) => {
                  setAction(e.target.value)
                  setPage(1)
                }}
              >
                <option value="">All actions</option>
                {ACTION_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </select>
            </Field>
          </div>
          <div className="ml-auto text-xs text-neutral-700">{state.meta ? `${state.meta.total} events` : ''}</div>
        </div>
        <div className="flex flex-col gap-2.5 overflow-y-auto px-5 py-4">
          {state.error ? <p className="text-sm text-[#75261C]">{state.error}</p> : null}
          {loading && !state.rows.length ? <p className="text-sm text-neutral-700">Loading…</p> : null}
          {!loading && !state.error && !state.rows.length ? <p className="text-sm text-neutral-700">No events in this window.</p> : null}
          {state.rows.map((row) => (
            <FeedItem key={row.audit_id} row={row} />
          ))}
        </div>
        <div className="flex items-center justify-end gap-2.5 border-t border-neutral-300 px-5 py-3">
          <span className="mr-auto text-xs text-neutral-700">
            {state.meta ? `Page ${state.meta.page} of ${state.meta.pages}` : ''}
          </span>
          <button className={btnSecondary} disabled={loading || page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </button>
          <button className={btnSecondary} disabled={loading || !state.meta || page >= state.meta.pages} onClick={() => setPage((p) => p + 1)}>
            Next
          </button>
        </div>
      </div>
    </div>
  )
}

export function ReportsPage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)
  const engineer = role === 'engineer'

  const [period, setPeriod] = useState('last_30')
  const [custom, setCustom] = useState({ from: '', to: '' })
  const [siteId, setSiteId] = useState('')
  const [sites, setSites] = useState([])
  const [result, setResult] = useState({ key: null, data: null, error: null })
  const [feed, setFeed] = useState('flagged')
  const [allEvents, setAllEvents] = useState({ key: null, rows: [], total: 0 })
  const [logOpen, setLogOpen] = useState(false)

  const problem = period === 'custom' ? customProblem(custom) : null
  const requested = problem ? null : periodWindow(period, custom)
  const queryKey = problem ? null : `${requested?.from ?? ''}|${requested?.to ?? ''}|${engineer ? '' : siteId}`
  const loading = queryKey !== null && result.key !== queryKey
  const data = result.data

  useEffect(() => {
    if (engineer) return
    http
      .get('/sites')
      .then((r) => setSites(r.data.sites ?? []))
      .catch(() => {})
  }, [engineer])

  useEffect(() => {
    if (queryKey === null) return
    let active = true
    const [from, to, site] = queryKey.split('|')
    http
      .get('/reports/overview', { params: { from: from || undefined, to: to || undefined, site_id: site || undefined } })
      .then((r) => active && setResult({ key: queryKey, data: r.data.data, error: null }))
      .catch((err) => active && setResult({ key: queryKey, data: null, error: errorMessage(err, 'Unable to load the report.') }))
    return () => {
      active = false
    }
  }, [queryKey])

  // "All events" reads the audit log for the same window the overview answered for.
  const allKey = feed === 'all' && data ? `${data.window.from}|${data.window.to}|${data.site?.site_id ?? ''}` : null
  useEffect(() => {
    if (allKey === null) return
    let active = true
    const [from, to, site] = allKey.split('|')
    http
      .get('/reports/audit', { params: { from, to, site_id: site || undefined, per_page: 8 } })
      .then((r) => active && setAllEvents({ key: allKey, rows: r.data.data, total: r.data.meta.total }))
      .catch(() => active && setAllEvents({ key: allKey, rows: [], total: 0 }))
    return () => {
      active = false
    }
  }, [allKey])

  const siteRows = data?.sites ?? []
  const maxCost = Math.max(0, ...siteRows.map((s) => s.labour_cost.total))
  const sortedSites = [...siteRows].sort((a, b) => b.labour_cost.total - a.labour_cost.total || a.site_name.localeCompare(b.site_name))
  const flagged = data?.flagged_audits ?? []
  const feedRows = feed === 'integrity' ? flagged.filter((r) => INTEGRITY.includes(r.action)) : feed === 'all' ? allEvents.rows : flagged
  const incidents = data?.audit.integrity_incidents ?? 0
  const score = data?.score
  const scoreBand = BAND[score?.band] ?? BAND.good

  return (
    <div className="flex flex-col pb-10">
      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
        <div className="w-[180px]">
          <Field label="Period">
            <select className={inputCls} value={period} onChange={(e) => setPeriod(e.target.value)}>
              <option value="last_30">Last 30 days</option>
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
                <input type="date" className={inputCls} value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} />
              </Field>
            </div>
            <div className="w-[150px]">
              <Field label="To">
                <input type="date" className={inputCls} value={custom.to} min={custom.from} onChange={(e) => setCustom({ ...custom, to: e.target.value })} />
              </Field>
            </div>
          </>
        ) : null}
        <div className="w-[230px]">
          <Field label="Site">
            {engineer ? (
              <div className={`${inputCls} flex items-center`} title="Site Engineers see their own site only">
                {user?.site?.site_name ?? 'Your site'}
              </div>
            ) : (
              <select className={inputCls} value={siteId} onChange={(e) => setSiteId(e.target.value)}>
                <option value="">All work sites</option>
                {sites.map((s) => (
                  <option key={s.site_id} value={s.site_id}>
                    {s.site_name}
                  </option>
                ))}
              </select>
            )}
          </Field>
        </div>
        <div className="ml-auto flex items-center gap-3 self-center text-xs text-neutral-700">
          {data ? (
            <span>
              {siteDay(data.window.from)} – {siteDay(data.window.to)} · as of {stamp(data.generated_at)}
            </span>
          ) : null}
          <button className={btnSecondary} disabled={!data} onClick={() => setLogOpen(true)}>
            Open full audit log
          </button>
        </div>
      </div>

      {problem ? <p className="px-[22px] pt-4 text-sm text-[#75261C]">{problem}</p> : null}
      {result.error ? <p className="px-[22px] pt-4 text-sm text-[#75261C]">{result.error}</p> : null}
      {loading && !data ? <p className="px-[22px] pt-4 text-sm text-neutral-700">Loading report…</p> : null}

      {data ? (
        <div className={loading ? 'opacity-60 transition-opacity' : undefined}>
          <div className="grid grid-cols-1 gap-4 px-[22px] pt-5 sm:grid-cols-2 xl:grid-cols-4">
            <Card label="Headcount" value={data.kpis.headcount.toLocaleString('en-PH')}>
              {data.kpis.sites_active} {data.kpis.sites_active === 1 ? 'work site' : 'work sites'} in this window
            </Card>
            <Card label="Attendance rate" value={pct(data.kpis.attendance_rate)}>
              absence {pct(data.kpis.absence_rate)} · late {pct(data.kpis.late_rate)}
              <div className="mt-2.5 h-2 bg-neutral-200" aria-hidden="true">
                <div className="h-2 bg-[#2F7A4D]" style={{ width: `${data.kpis.attendance_rate ?? 0}%` }} />
              </div>
              <div className="mt-1.5">rest-day and holiday absences not counted</div>
            </Card>
            <Card label="Overtime cost" value={pesoShort(data.labour_cost.overtime)}>
              {data.kpis.overtime_share === null
                ? 'no approved payroll in this window'
                : `${pct(data.kpis.overtime_share)} of ${pesoShort(data.labour_cost.total)} labour cost`}
              <div className="mt-1.5">approved payroll only, by payslip line date</div>
            </Card>
            <Card label="Compliance score" value={score?.value ?? '—'}>
              <Tag bg={scoreBand.bg} fg={scoreBand.fg}>{scoreBand.label}</Tag>
              <div className="mt-1.5">{score?.basis}</div>
            </Card>
          </div>

          <div className="mx-[22px] mt-4 flex flex-wrap gap-x-8 gap-y-2 border border-neutral-300 bg-surface px-4 py-3 text-[13px]">
            <span>
              <span className="text-neutral-700">Roll call · </span>
              {data.attendance.present} present, {data.attendance.late} late, {data.attendance.absent} absent
            </span>
            <span>
              <span className="text-neutral-700">Approved leave · </span>
              {data.leaves.requests} {data.leaves.requests === 1 ? 'request' : 'requests'}, {data.leaves.days} days in window
            </span>
            <span>
              <span className="text-neutral-700">Approved overtime · </span>
              {data.overtime.requests} {data.overtime.requests === 1 ? 'request' : 'requests'}, {Number(data.overtime.hours).toFixed(1)} h
            </span>
            <span>
              <span className="text-neutral-700">Audit · </span>
              {data.audit.overrides} overrides, {incidents} integrity {incidents === 1 ? 'incident' : 'incidents'}
            </span>
          </div>

          <div className="grid grid-cols-1 gap-[22px] px-[22px] pt-5 xl:grid-cols-[1.5fr_1fr]">
            <section className="border border-neutral-300 bg-surface p-[18px]">
              <h2 className="font-heading text-[19px]">Labour cost and compliance by site</h2>
              <p className="mb-4 text-xs text-neutral-700">
                Bars are approved labour cost, scaled to the costliest site and split regular and overtime · the dot is the site&apos;s
                compliance score on a 0–100 scale
              </p>
              <div className="mb-3.5 flex flex-wrap gap-5 text-[11px] text-neutral-700">
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2.5 w-3.5" style={{ background: REGULAR_INK }} />
                  Regular
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2.5 w-3.5" style={{ background: OVERTIME_INK }} />
                  Overtime
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2.5 w-2.5 rounded-full border-2 border-ink" />
                  Compliance score
                </span>
              </div>
              {sortedSites.length ? (
                <div className="flex flex-col gap-3.5">
                  {sortedSites.map((site) => (
                    <SiteBar key={site.site_id} site={site} maxCost={maxCost} />
                  ))}
                </div>
              ) : (
                <p className="text-sm text-neutral-700">No work sites have staff or activity in this window.</p>
              )}
              {sortedSites.length && maxCost === 0 ? (
                <p className="mt-3.5 text-xs text-neutral-700">
                  No approved payroll falls in this window yet, so there are no cost bars; the scores still apply.
                </p>
              ) : null}
            </section>

            <section className="flex flex-col border border-neutral-300 bg-surface p-[18px]">
              <div className="flex items-baseline gap-3">
                <h2 className="font-heading text-[19px]">Audit feed</h2>
                {incidents > 0 ? (
                  <Tag bg={tone.absent.bg} fg={tone.absent.fg}>
                    {incidents} integrity {incidents === 1 ? 'incident' : 'incidents'}
                  </Tag>
                ) : null}
              </div>
              <p className="mb-3.5 text-xs text-neutral-700">
                {feed === 'all' ? `All events in this window, newest first · ${allEvents.total} in all` : 'Overrides, integrity flags and recovery in this window'}
              </p>
              <div className="mb-3.5 flex flex-wrap gap-2">
                {[
                  ['flagged', 'Flagged'],
                  ['integrity', 'Integrity'],
                  ['all', 'All events'],
                ].map(([value, label]) => (
                  <button key={value} className={chip(feed === value)} onClick={() => setFeed(value)} aria-pressed={feed === value}>
                    {label}
                  </button>
                ))}
              </div>
              <div className="flex flex-col gap-3">
                {feed === 'all' && allEvents.key !== allKey ? <p className="text-sm text-neutral-700">Loading…</p> : null}
                {feedRows.map((row) => (
                  <FeedItem key={row.audit_id} row={row} />
                ))}
                {!feedRows.length && !(feed === 'all' && allEvents.key !== allKey) ? (
                  <p className="text-sm text-neutral-700">Nothing in this window.</p>
                ) : null}
              </div>
              <p className="mt-4 text-[11px] text-neutral-700">Read-only here. HR acts on overrides and recovery from their own screens.</p>
            </section>
          </div>

          <section className="mx-[22px] mt-5 overflow-x-auto border border-neutral-300 bg-surface">
            <div className="px-[18px] pt-[18px]">
              <h2 className="font-heading text-[19px]">Scorecard by site</h2>
              <p className="text-xs text-neutral-700">
                Each site starts at 100 and loses 2 per expired certification, 1 per override raised and 5 per integrity incident. Staff
                figures follow the employee&apos;s home site; overrides and incidents follow the crew&apos;s site.
              </p>
            </div>
            <table className="mt-3 w-full min-w-[900px] border-collapse text-sm tabular-nums">
              <thead>
                <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  <th className="py-2.5 pl-[18px] pr-4 font-normal">Site</th>
                  <th className="py-2.5 pr-4 font-normal">Workers</th>
                  <th className="py-2.5 pr-4 font-normal">Attendance</th>
                  <th className="py-2.5 pr-4 font-normal">Late</th>
                  <th className="py-2.5 pr-4 font-normal">Leave days</th>
                  <th className="py-2.5 pr-4 font-normal">Expired certs</th>
                  <th className="py-2.5 pr-4 font-normal">Overrides</th>
                  <th className="py-2.5 pr-4 font-normal">Incidents</th>
                  <th className="py-2.5 pr-[18px] text-right font-normal">Score</th>
                </tr>
              </thead>
              <tbody>
                {sortedSites.map((site) => (
                  <tr key={site.site_id} className="border-b border-neutral-200">
                    <td className="py-2.5 pl-[18px] pr-4">{site.site_name}</td>
                    <td className="py-2.5 pr-4">{site.workers}</td>
                    <td className="py-2.5 pr-4">{pct(site.attendance_rate)}</td>
                    <td className="py-2.5 pr-4">{pct(site.late_rate)}</td>
                    <td className="py-2.5 pr-4">{site.leaves.days}</td>
                    <td className="py-2.5 pr-4">
                      {site.certifications_expired}
                      {site.score.deductions.certifications ? <span className="text-neutral-700"> (−{site.score.deductions.certifications})</span> : null}
                    </td>
                    <td className="py-2.5 pr-4">
                      {site.overrides}
                      {site.score.deductions.overrides ? <span className="text-neutral-700"> (−{site.score.deductions.overrides})</span> : null}
                    </td>
                    <td className="py-2.5 pr-4">
                      {site.integrity_incidents}
                      {site.score.deductions.integrity ? <span className="text-neutral-700"> (−{site.score.deductions.integrity})</span> : null}
                    </td>
                    <td className="py-2.5 pr-[18px] text-right">
                      <ScoreTag score={site.score} />
                    </td>
                  </tr>
                ))}
                {!sortedSites.length ? (
                  <tr>
                    <td colSpan={9} className="px-[18px] py-4 text-neutral-700">
                      No work sites in this window.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </section>
        </div>
      ) : null}

      {logOpen && data ? <AuditLogDialog range={data.window} siteId={data.site?.site_id ?? ''} onClose={() => setLogOpen(false)} /> : null}
    </div>
  )
}
