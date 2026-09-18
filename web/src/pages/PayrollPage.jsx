import { useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Payroll Run (docs/prototypes/HRIS Payroll Run.dc.html) — Phase 8, UC-08,
 * STD TC-06.
 *
 * HR computes a cut-off, reads each worker's pay, opens a payslip to see
 * every day and deduction behind it, and approves. Approval takes the rows
 * that may be paid; Blocked rows (attendance still under review or awaiting
 * recovery) stay in draft until resolved. Approved rows are final.
 *
 * The prototype's "Locks in 27 h", "+3.1% vs last run" and pay date need a
 * payroll calendar this system does not keep yet; they are left out.
 */

const READINESS = {
  ready: { label: 'Ready', ...tone.present },
  recovered: { label: 'Recovered', ...tone.flagged },
  blocked: { label: 'Blocked', ...tone.absent },
}

const RUN_STATUS = {
  not_computed: { label: 'Not computed', ...tone.neutral },
  draft: { label: 'Draft — not approved', ...tone.pending },
  partly_approved: { label: 'Partly approved', ...tone.late },
  approved: { label: 'Approved', ...tone.present },
}

const DAY_TYPE = {
  ordinary: 'Ordinary day',
  rest_day: 'Rest day',
  special: 'Special day',
  special_rest_day: 'Special day on rest day',
  regular_holiday: 'Regular holiday',
  regular_holiday_rest_day: 'Regular holiday on rest day',
  double_holiday: 'Double holiday',
  double_holiday_rest_day: 'Double holiday on rest day',
}

const LINE_KIND = {
  regular: 'Regular hours',
  overtime: 'Overtime',
  overtime_night: 'Overtime, night',
  unworked_holiday: 'Holiday pay (not worked)',
}

const inputCls = 'h-[34px] w-full border border-neutral-400 bg-canvas px-2 text-[13px] text-ink'
const btnPrimary =
  'inline-flex items-center justify-center gap-2 border border-primary-800 bg-primary-800 px-4 py-2 text-[13px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'

const money = (v) => Number(v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const peso = (v) => `₱${money(v)}`
const hrs = (v) => (Number(v) ? Number(v).toFixed(1) : '—')
const dayFmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Manila', weekday: 'short', day: '2-digit', month: 'short' })
const siteDay = (ymd) => dayFmt.format(new Date(`${ymd}T12:00:00+08:00`))

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
      <div className="mt-1.5 font-heading text-[26px] leading-tight tabular-nums" style={accent ? { color: accent } : undefined}>
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

export function PayrollPage() {
  const { user } = useAuth()
  const canRun = roleKey(user?.role?.slug) === 'hr'
  const [tab, setTab] = useState('run')

  return (
    <div className="flex flex-col pb-10">
      <div className="flex gap-1 border-b border-neutral-300 px-[22px] pt-3" role="tablist">
        {[
          ['run', 'Payroll run'],
          ['holidays', 'Holiday calendar'],
        ].map(([key, label]) => (
          <button
            key={key}
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={`-mb-px border-b-2 px-3 py-2 text-[13px] ${tab === key ? 'border-primary-800 text-ink' : 'border-transparent text-neutral-700 hover:text-ink'}`}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === 'run' ? <PayrollRun canRun={canRun} /> : <HolidayCalendar canEdit={canRun} />}
    </div>
  )
}

function PayrollRun({ canRun }) {
  const [runs, setRuns] = useState([])
  const [code, setCode] = useState(null)
  const [reload, setReload] = useState(0)
  const [result, setResult] = useState({ key: null, run: null, error: null })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [search, setSearch] = useState('')
  const [site, setSite] = useState('')
  const [readiness, setReadiness] = useState('')
  const [payslipFor, setPayslipFor] = useState(null)
  const [confirming, setConfirming] = useState(false)

  const queryKey = `${code}|${reload}`
  const run = result.key === queryKey ? result.run : null

  useEffect(() => {
    let active = true
    http
      .get('/payroll/runs')
      .then((r) => {
        if (!active) return
        setRuns(r.data.data)
        // Default to the latest closed cut-off: the one HR is paying now.
        setCode((current) => current ?? r.data.data.find((p) => p.status !== 'not_computed')?.code ?? r.data.data[1]?.code ?? r.data.data[0]?.code)
      })
      .catch(() => {})
    return () => {
      active = false
    }
  }, [reload])

  useEffect(() => {
    if (!code) return undefined
    let active = true
    http
      .get(`/payroll/runs/${code}`)
      .then((r) => active && setResult({ key: queryKey, run: r.data.data, error: null }))
      .catch((err) => active && setResult({ key: queryKey, run: null, error: errorMessage(err, 'Unable to load the payroll run.') }))
    return () => {
      active = false
    }
  }, [code, queryKey])

  const act = async (fn, message) => {
    setBusy(true)
    setNotice(null)
    try {
      await fn()
      setNotice(message)
      setReload((n) => n + 1)
    } catch (err) {
      setNotice(errorMessage(err, 'That did not work. Try again.'))
    } finally {
      setBusy(false)
    }
  }

  const compute = () => act(() => http.post(`/payroll/runs/${code}/compute`), `Payroll ${code} computed.`)
  const approve = () =>
    act(async () => {
      const r = await http.post(`/payroll/runs/${code}/approve`)
      setConfirming(false)
      return r
    }, `Approved rows in ${code}. Blocked rows stay in draft.`)

  const rows = useMemo(() => run?.rows ?? [], [run])
  const sites = [...new Set(rows.map((r) => r.employee.site).filter(Boolean))].sort()
  const visible = rows.filter((r) => {
    const q = search.trim().toLowerCase()
    return (
      (!q || r.employee.full_name?.toLowerCase().includes(q) || r.employee.employee_code?.toLowerCase().includes(q)) &&
      (!site || r.employee.site === site) &&
      (!readiness || r.readiness === readiness)
    )
  })
  const approvable = rows.filter((r) => r.status === 'draft' && r.readiness !== 'blocked')
  const status = RUN_STATUS[run?.status] ?? RUN_STATUS.not_computed
  const computed = run && run.status !== 'not_computed'

  const exportRegister = () => {
    const head = ['Code', 'Employee', 'Role', 'Site', 'Daily rate', 'Base pay', 'OT h', 'ND h', 'RD h', 'Hol h', 'Gross', 'Deductions', 'Net', 'Status', 'Readiness']
    const lines = rows.map((r) => [
      r.employee.employee_code,
      r.employee.full_name,
      r.employee.role,
      r.employee.site,
      r.employee.daily_rate,
      r.basic_pay,
      r.hours.overtime,
      r.hours.night_diff,
      r.hours.rest_day,
      r.hours.holiday,
      r.gross_pay,
      r.deductions,
      r.net_pay,
      r.status,
      r.readiness,
    ])
    const csv = [head, ...lines].map((l) => l.map((v) => `"${String(v ?? '').replaceAll('"', '""')}"`).join(',')).join('\r\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `payroll-register-${code}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <>
      <div className="flex flex-wrap items-end gap-4 border-b border-neutral-200 px-[22px] py-4">
        <div className="min-w-0 flex-1">
          <h1 className="font-heading text-2xl leading-tight">Payroll run {code ?? ''}</h1>
          <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[13px] text-neutral-700">
            {run ? `Semi-monthly · ${run.label} · ${run.working_days} working days` : '…'}
            <Tag bg={status.bg} fg={status.fg}>
              {status.label}
            </Tag>
          </div>
        </div>
        <div className="w-[280px]">
          <Field label="Cut-off">
            <select className={inputCls} value={code ?? ''} onChange={(e) => setCode(e.target.value)}>
              {runs.map((p) => (
                <option key={p.code} value={p.code}>
                  {p.code} · {p.label} · {RUN_STATUS[p.status]?.label}
                </option>
              ))}
            </select>
          </Field>
        </div>
      </div>

      {result.error ? <p className="px-[22px] py-4 text-sm text-[#75261C]">{result.error}</p> : null}

      {run && !computed ? (
        <div className="m-[22px] border border-neutral-300 bg-surface p-6 text-center">
          <p className="text-sm text-neutral-700">This cut-off has not been computed yet.</p>
          {canRun ? (
            <button className={`${btnPrimary} mt-3`} disabled={busy} onClick={compute}>
              {busy ? 'Computing…' : 'Compute payroll'}
            </button>
          ) : null}
          {notice ? <p className="mt-3 text-xs text-primary-800">{notice}</p> : null}
        </div>
      ) : null}

      {computed ? (
        <>
          <div className="grid grid-cols-2 gap-4 border-b border-neutral-200 px-[22px] py-[18px] md:grid-cols-3 xl:grid-cols-5">
            <StatCard label="Employees" value={run.summary.employees} note={`${run.summary.sites} ${run.summary.sites === 1 ? 'site' : 'sites'}`} />
            <StatCard label="Gross" value={peso(run.summary.gross)} note={`base ${peso(run.summary.basic)}`} />
            <StatCard label="Deductions" value={peso(run.summary.deductions)} note="SSS, PhilHealth, Pag-IBIG, tax" accent="#8A5211" />
            <StatCard label="Net payable" value={peso(run.summary.net)} note={`${run.summary.approved} of ${run.summary.employees} approved`} accent="#1F5334" />
            <StatCard label="Blocked rows" value={run.summary.blocked} note="attendance unresolved" accent="#75261C" />
          </div>

          <div className="flex flex-wrap items-end gap-3 border-b border-neutral-200 px-[22px] py-3.5">
            <div className="w-[230px]">
              <Field label="Search">
                <input className={inputCls} placeholder="Employee or ID" value={search} onChange={(e) => setSearch(e.target.value)} />
              </Field>
            </div>
            <div className="w-[190px]">
              <Field label="Site">
                <select className={inputCls} value={site} onChange={(e) => setSite(e.target.value)}>
                  <option value="">All sites</option>
                  {sites.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <div className="w-[160px]">
              <Field label="Row status">
                <select className={inputCls} value={readiness} onChange={(e) => setReadiness(e.target.value)}>
                  <option value="">All rows</option>
                  <option value="ready">Ready</option>
                  <option value="recovered">Recovered</option>
                  <option value="blocked">Blocked</option>
                </select>
              </Field>
            </div>
            <div className="ml-auto flex flex-wrap items-center gap-2.5">
              {notice ? <span className="border border-primary-500 px-3 py-1.5 text-xs text-primary-800">{notice}</span> : null}
              {canRun ? (
                <button className={btnSecondary} disabled={busy} onClick={compute}>
                  Recompute
                </button>
              ) : null}
              <button className={btnSecondary} onClick={exportRegister}>
                <Icon name="download" size={14} /> Export register
              </button>
              {canRun ? (
                <button className={btnPrimary} disabled={busy || !approvable.length} onClick={() => setConfirming(true)}>
                  Approve {approvable.length} {approvable.length === 1 ? 'row' : 'rows'}
                </button>
              ) : null}
            </div>
          </div>

          <div className="overflow-x-auto px-[22px] pt-2">
            <table className="w-full min-w-[1040px] border-collapse text-sm tabular-nums">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  <th className="pb-1 pr-4 font-normal" rowSpan={2}>
                    Employee
                  </th>
                  <th className="pb-1 pr-4 text-right font-normal" rowSpan={2}>
                    Base pay
                  </th>
                  <th className="pb-1 text-center font-normal" colSpan={4}>
                    Premium hours
                  </th>
                  <th className="pb-1 pr-4 text-right font-normal" rowSpan={2}>
                    Gross
                  </th>
                  <th className="pb-1 pr-4 text-right font-normal" rowSpan={2}>
                    Deductions
                  </th>
                  <th className="pb-1 pr-4 text-right font-normal" rowSpan={2}>
                    Net pay
                  </th>
                  <th className="pb-1 font-normal" rowSpan={2}>
                    Status
                  </th>
                </tr>
                <tr className="border-b border-neutral-300 text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  {['OT ×1.25', 'ND ×1.10', 'RD ×1.30', 'Hol'].map((h) => (
                    <th key={h} className="px-2 pb-2 text-right font-normal">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {visible.map((r) => {
                  const ready = READINESS[r.readiness] ?? READINESS.ready
                  return (
                    <tr
                      key={r.payroll_id}
                      className="cursor-pointer border-b border-neutral-200 hover:bg-neutral-200/60"
                      onClick={() => setPayslipFor(r.employee)}
                    >
                      <td className="py-2.5 pr-4">
                        {r.employee.full_name}
                        <div className="text-[11px] text-neutral-700">
                          {[r.employee.employee_code, r.employee.role, r.employee.site, `${peso(r.employee.daily_rate)}/day`].filter(Boolean).join(' · ')}
                        </div>
                      </td>
                      <td className="py-2.5 pr-4 text-right">{money(r.basic_pay)}</td>
                      <td className="px-2 py-2.5 text-right">{hrs(r.hours.overtime)}</td>
                      <td className="px-2 py-2.5 text-right">{hrs(r.hours.night_diff)}</td>
                      <td className="px-2 py-2.5 text-right">{hrs(r.hours.rest_day)}</td>
                      <td className="px-2 py-2.5 text-right">{hrs(r.hours.holiday + r.hours.unworked_holiday)}</td>
                      <td className="py-2.5 pr-4 text-right">{money(r.gross_pay)}</td>
                      <td className="py-2.5 pr-4 text-right">{money(r.deductions)}</td>
                      <td className="py-2.5 pr-4 text-right font-medium">{money(r.net_pay)}</td>
                      <td className="py-2.5">
                        <div className="flex flex-wrap gap-1.5">
                          <Tag bg={ready.bg} fg={ready.fg}>
                            {ready.label}
                          </Tag>
                          {r.status === 'approved' ? <Tag {...tone.neutral}>Approved</Tag> : null}
                        </div>
                      </td>
                    </tr>
                  )
                })}
                {!visible.length ? (
                  <tr>
                    <td colSpan={10} className="py-8 text-center text-sm text-neutral-700">
                      No rows match these filters.
                    </td>
                  </tr>
                ) : null}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-neutral-400 font-medium">
                  <td className="py-2.5 pr-4">Run total — {run.summary.employees}</td>
                  <td className="py-2.5 pr-4 text-right">{money(run.summary.basic)}</td>
                  <td className="px-2 py-2.5 text-right">{hrs(run.summary.hours.overtime)}</td>
                  <td className="px-2 py-2.5 text-right">{hrs(run.summary.hours.night_diff)}</td>
                  <td className="px-2 py-2.5 text-right">{hrs(run.summary.hours.rest_day)}</td>
                  <td className="px-2 py-2.5 text-right">{hrs(run.summary.hours.holiday)}</td>
                  <td className="py-2.5 pr-4 text-right">{money(run.summary.gross)}</td>
                  <td className="py-2.5 pr-4 text-right">{money(run.summary.deductions)}</td>
                  <td className="py-2.5 pr-4 text-right">{money(run.summary.net)}</td>
                  <td />
                </tr>
              </tfoot>
            </table>

            {run.summary.blocked ? (
              <div className="mt-4 flex flex-wrap items-center gap-3 border border-[#A83A2C] bg-[#F7E8E5] p-3 text-sm text-[#75261C]">
                <Icon name="alert" size={16} className="flex-none" />
                <span className="min-w-0 flex-1">
                  {run.summary.blocked} {run.summary.blocked === 1 ? 'row is' : 'rows are'} blocked: attendance still under HR review or awaiting recovery.
                  Approving the run leaves them in draft until that is resolved and the run is recomputed.
                </span>
                <a href="/overrides" className="underline">
                  Open overrides
                </a>
                <a href="/recovery" className="underline">
                  Open recovery
                </a>
              </div>
            ) : null}
          </div>
        </>
      ) : null}

      {payslipFor ? <Payslip code={code} employee={payslipFor} onClose={() => setPayslipFor(null)} /> : null}

      {confirming ? (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4" role="dialog" aria-modal="true" aria-label="Approve payroll">
          <div className="w-full max-w-[460px] border border-neutral-300 bg-surface p-5 shadow-lg">
            <h3 className="font-heading text-lg">Approve payroll {code}</h3>
            <p className="mt-2 text-sm">
              {approvable.length} {approvable.length === 1 ? 'row' : 'rows'} · gross {peso(approvable.reduce((s, r) => s + r.gross_pay, 0))} · net{' '}
              {peso(approvable.reduce((s, r) => s + r.net_pay, 0))}
            </p>
            <p className="mt-2 text-xs text-neutral-700">
              Approved rows are final: recomputing the period will not change them.
              {run?.summary.blocked ? ` ${run.summary.blocked} blocked ${run.summary.blocked === 1 ? 'row stays' : 'rows stay'} in draft.` : ''} This is logged under your name.
            </p>
            <div className="mt-4 flex justify-end gap-2.5">
              <button className={btnSecondary} disabled={busy} onClick={() => setConfirming(false)}>
                Cancel
              </button>
              <button className={btnPrimary} disabled={busy} onClick={approve}>
                {busy ? 'Approving…' : 'Approve'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </>
  )
}

function Payslip({ code, employee, onClose }) {
  const [slip, setSlip] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let active = true
    http
      .get(`/payroll/runs/${code}/employees/${employee.employee_id}`)
      .then((r) => active && setSlip(r.data.data))
      .catch((err) => active && setError(errorMessage(err, 'Unable to load the payslip.')))
    return () => {
      active = false
    }
  }, [code, employee.employee_id])

  const ready = slip ? READINESS[slip.readiness] : null

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" role="dialog" aria-modal="true" aria-label="Payslip">
      <div className="flex h-full w-full max-w-[640px] flex-col bg-canvas shadow-lg">
        <div className="flex items-start gap-3 border-b border-neutral-300 px-5 py-4">
          <div className="min-w-0 flex-1">
            <div className="text-[11px] uppercase tracking-[.1em] text-neutral-700">Payslip · {code}</div>
            <h2 className="font-heading text-2xl leading-tight">{employee.full_name}</h2>
            <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-neutral-700">
              {[employee.employee_code, employee.role, `${peso(employee.daily_rate)}/day`].filter(Boolean).join(' · ')}
              {slip?.hourly_rate ? ` · ${peso(slip.hourly_rate)}/h` : ''}
              {ready ? (
                <Tag bg={ready.bg} fg={ready.fg}>
                  {ready.label}
                </Tag>
              ) : null}
            </div>
          </div>
          <button onClick={onClose} aria-label="Close" className="text-neutral-500 hover:text-ink">
            <Icon name="close" size={18} />
          </button>
        </div>

        <div className="flex-1 overflow-auto px-5 py-4 text-sm">
          {error ? <p className="text-[#75261C]">{error}</p> : null}
          {!slip && !error ? <p className="text-neutral-700">Loading…</p> : null}
          {slip ? (
            <div className="flex flex-col gap-5">
              {slip.blocked_reasons?.length ? (
                <div className="border border-[#A83A2C] bg-[#F7E8E5] p-3 text-[#75261C]">
                  <div className="text-[11px] uppercase tracking-[.08em]">Held from approval</div>
                  <ul className="mt-1 list-disc pl-5">
                    {slip.blocked_reasons.map((b, i) => (
                      <li key={i}>
                        {b.date ? `${siteDay(b.date)}: ` : ''}
                        {b.reason}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
              {slip.warnings?.length ? (
                <ul className="list-disc border border-neutral-300 bg-surface p-3 pl-8 text-xs text-neutral-700">
                  {slip.warnings.map((w, i) => (
                    <li key={i}>{w}</li>
                  ))}
                </ul>
              ) : null}

              <section>
                <h3 className="mb-1.5 text-[11px] uppercase tracking-[.08em] text-neutral-700">Earnings</h3>
                <table className="w-full border-collapse tabular-nums">
                  <tbody>
                    {slip.lines.map((l, i) => (
                      <tr key={i} className="border-b border-neutral-200">
                        <td className="py-1.5 pr-3 whitespace-nowrap">{siteDay(l.date)}</td>
                        <td className="py-1.5 pr-3">
                          {LINE_KIND[l.kind] ?? l.kind}
                          <div className="text-[11px] text-neutral-700">{DAY_TYPE[l.day_type] ?? l.day_type}</div>
                        </td>
                        <td className="py-1.5 pr-3 text-right whitespace-nowrap">
                          {Number(l.hours).toFixed(2)} h × {Number(l.multiplier).toFixed(3).replace(/0+$/, '').replace(/\.$/, '')}
                        </td>
                        <td className="py-1.5 text-right">{money(l.amount)}</td>
                      </tr>
                    ))}
                    {!slip.lines.length ? (
                      <tr>
                        <td className="py-2 text-neutral-700">No paid days in this cut-off.</td>
                      </tr>
                    ) : null}
                  </tbody>
                  <tfoot>
                    <tr className="font-medium">
                      <td colSpan={3} className="pt-2">
                        Gross pay
                      </td>
                      <td className="pt-2 text-right">{money(slip.gross_pay)}</td>
                    </tr>
                  </tfoot>
                </table>
              </section>

              <section>
                <h3 className="mb-1.5 text-[11px] uppercase tracking-[.08em] text-neutral-700">Deductions</h3>
                <table className="w-full border-collapse tabular-nums">
                  <tbody>
                    {[
                      ['SSS', slip.deduction_lines.sss],
                      ['PhilHealth', slip.deduction_lines.philhealth],
                      ['Pag-IBIG', slip.deduction_lines.pagibig],
                      ['Withholding tax', slip.deduction_lines.withholding_tax],
                    ].map(([label, value]) => (
                      <tr key={label} className="border-b border-neutral-200">
                        <td className="py-1.5">{label}</td>
                        <td className="py-1.5 text-right">{money(value)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="font-medium">
                      <td className="pt-2">Total deductions</td>
                      <td className="pt-2 text-right">{money(slip.deductions)}</td>
                    </tr>
                  </tfoot>
                </table>
                <p className="mt-1.5 text-xs text-neutral-700">Tax: {slip.tax_note}</p>
              </section>

              <div className="flex items-baseline justify-between border-t-2 border-neutral-400 pt-3">
                <span className="font-heading text-lg">Net pay</span>
                <span className="font-heading text-2xl tabular-nums">{peso(slip.net_pay)}</span>
              </div>

              <p className="text-xs text-neutral-700">
                Employer share, not deducted from pay: SSS {peso(slip.employer_shares.sss)} · PhilHealth {peso(slip.employer_shares.philhealth)} · Pag-IBIG{' '}
                {peso(slip.employer_shares.pagibig)}. Statutory rates are pending verification against current circulars.
              </p>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}

function HolidayCalendar({ canEdit }) {
  const thisYear = new Date().getFullYear()
  const [year, setYear] = useState(thisYear)
  const [reload, setReload] = useState(0)
  const [result, setResult] = useState({ key: null, holidays: [], error: null })
  const [form, setForm] = useState({ date: '', name: '', type: 'regular' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const queryKey = `${year}|${reload}`

  useEffect(() => {
    let active = true
    http
      .get('/holidays', { params: { year } })
      .then((r) => active && setResult({ key: queryKey, holidays: r.data.data, error: null }))
      .catch((err) => active && setResult({ key: queryKey, holidays: [], error: errorMessage(err, 'Unable to load holidays.') }))
    return () => {
      active = false
    }
  }, [year, queryKey])

  const add = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await http.post('/holidays', form)
      setForm({ date: '', name: '', type: form.type })
      setReload((n) => n + 1)
    } catch (err) {
      setError(errorMessage(err, 'Unable to add the holiday.'))
    } finally {
      setBusy(false)
    }
  }

  const remove = async (holiday) => {
    if (!window.confirm(`Remove ${holiday.name} (${holiday.date})?`)) return
    try {
      await http.delete(`/holidays/${holiday.holiday_id}`)
      setReload((n) => n + 1)
    } catch (err) {
      setError(errorMessage(err, 'Unable to remove the holiday.'))
    }
  }

  return (
    <div className="flex flex-col gap-4 px-[22px] py-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-[140px]">
          <Field label="Year">
            <select className={inputCls} value={year} onChange={(e) => setYear(Number(e.target.value))}>
              {[thisYear - 1, thisYear, thisYear + 1].map((y) => (
                <option key={y} value={y}>
                  {y}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <p className="max-w-[620px] text-xs text-neutral-700">
          Regular holidays are paid when not worked and at 200% when worked; special days are unpaid when not worked and 130% when worked. Changes
          apply to draft payroll when it is recomputed; approved payroll does not change. Check each year's dates against the President's
          proclamation.
        </p>
      </div>

      {canEdit ? (
        <form onSubmit={add} className="flex flex-wrap items-end gap-3 border border-neutral-300 bg-surface p-3">
          <div className="w-[160px]">
            <Field label="Date">
              <input type="date" required className={inputCls} value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
            </Field>
          </div>
          <div className="min-w-[220px] flex-1">
            <Field label="Name">
              <input required maxLength={120} className={inputCls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
          </div>
          <div className="w-[170px]">
            <Field label="Type">
              <select className={inputCls} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="regular">Regular holiday</option>
                <option value="special">Special day</option>
              </select>
            </Field>
          </div>
          <button className={btnPrimary} disabled={busy}>
            <Icon name="plus" size={14} /> Add holiday
          </button>
          {error ? <p className="w-full text-xs text-[#75261C]">{error}</p> : null}
        </form>
      ) : null}

      {result.error ? <p className="text-sm text-[#75261C]">{result.error}</p> : null}
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
            <th className="w-[160px] py-2.5 pr-4 font-normal">Date</th>
            <th className="py-2.5 pr-4 font-normal">Holiday</th>
            <th className="w-[160px] py-2.5 pr-4 font-normal">Type</th>
            {canEdit ? <th className="w-[90px]" /> : null}
          </tr>
        </thead>
        <tbody>
          {result.holidays.map((h) => (
            <tr key={h.holiday_id} className="border-b border-neutral-200">
              <td className="py-2 pr-4 tabular-nums">{siteDay(h.date)}</td>
              <td className="py-2 pr-4">{h.name}</td>
              <td className="py-2 pr-4">
                <Tag {...(h.type === 'regular' ? tone.present : tone.pending)}>{h.type === 'regular' ? 'Regular holiday' : 'Special day'}</Tag>
              </td>
              {canEdit ? (
                <td className="py-2 text-right">
                  <button className="text-xs text-[#75261C] underline" onClick={() => remove(h)}>
                    Remove
                  </button>
                </td>
              ) : null}
            </tr>
          ))}
          {result.key !== null && !result.holidays.length ? (
            <tr>
              <td colSpan={4} className="py-8 text-center text-neutral-700">
                No holidays on the calendar for {year}.
              </td>
            </tr>
          ) : null}
        </tbody>
      </table>
    </div>
  )
}
