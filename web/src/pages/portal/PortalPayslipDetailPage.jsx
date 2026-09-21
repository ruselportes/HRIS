import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { http, errorMessage } from '../../api/client'
import { peso, approvedDate, siteDay } from './portalFormat'

const STRINGS = {
  back: '‹ All payslips',
  notFound: 'That payslip is not available.',
  loadError: 'Unable to load this payslip.',
  gross: 'Gross pay',
  deductions: 'Deductions',
  net: 'Net pay',
  hours: 'Hours worked',
  basic: 'Basic',
  premium: 'Premium',
  payLines: 'Pay lines',
  notes: 'Notes',
  employerShares: 'Paid by the company, not deducted from you',
  print: 'Print',
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

function groupByDate(lines) {
  const groups = new Map()
  for (const line of lines ?? []) {
    if (!groups.has(line.date)) groups.set(line.date, [])
    groups.get(line.date).push(line)
  }
  return [...groups.entries()]
}

export function PortalPayslipDetailPage() {
  const { run } = useParams()
  const [detail, setDetail] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    http
      .get(`/me/payslips/${run}`)
      .then(({ data }) => {
        if (cancelled) return
        setDetail(data.data)
        setError(null)
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.response?.status === 404 ? STRINGS.notFound : errorMessage(err, STRINGS.loadError))
      })
    return () => {
      cancelled = true
    }
  }, [run])

  // The requested run's detail, not the previous screen's: while a new run
  // loads, the stale detail must not render as the answer.
  const showing = detail && String(detail.run_id) === String(run) ? detail : null
  const d = showing?.detail

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5 print:hidden">
        <Link to="/portal/payslips" className="text-[13px] text-primary-700 hover:underline">
          {STRINGS.back}
        </Link>
      </div>

      <div className="max-w-[560px] space-y-5 px-[22px] py-4">
        {error ? <p className="py-4 text-sm text-[#75261C]">{error}</p> : null}
        {!error && !showing ? <p className="py-4 text-sm text-neutral-700">Loading the breakdown…</p> : null}
        {!error && showing ? (
          <>
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-heading text-lg">
                  {showing.run_code} · {peso(showing.net_pay)} net
                </div>
                <div className="text-xs text-neutral-700">
                  {showing.period?.start} – {showing.period?.end} · Approved {approvedDate(showing.approved_at)}
                </div>
              </div>
              <button
                type="button"
                onClick={() => window.print()}
                className="flex-none border border-neutral-400 px-3 py-2 text-[13px] print:hidden"
              >
                {STRINGS.print}
              </button>
            </div>

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.gross}</div>
              <div className="text-sm tabular-nums">{peso(showing.gross_pay)}</div>
            </section>

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.deductions}</div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                {[
                  ['SSS', d?.deduction_lines?.sss],
                  ['PhilHealth', d?.deduction_lines?.philhealth],
                  ['Pag-IBIG', d?.deduction_lines?.pagibig],
                  ['Withholding tax', d?.deduction_lines?.withholding_tax],
                  ['Other', d?.deduction_lines?.other],
                ].map(([label, value]) => (
                  <div key={label} className="flex justify-between border-b border-neutral-200 py-1">
                    <dt className="text-neutral-700">{label}</dt>
                    <dd className="tabular-nums">{peso(value)}</dd>
                  </div>
                ))}
              </dl>
              {d?.tax_note ? <p className="mt-2 text-xs text-neutral-700">{d.tax_note}</p> : null}
            </section>

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.net}</div>
              <div className="font-heading text-[26px] tabular-nums">{peso(showing.net_pay)}</div>
            </section>

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.hours}</div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                {[
                  ['Regular', d?.hours?.regular],
                  ['Overtime', d?.hours?.overtime],
                  ['Night differential', d?.hours?.night_differential],
                  ['Rest day', d?.hours?.rest_day],
                  ['Holiday', d?.hours?.holiday],
                ].map(([label, value]) => (
                  <div key={label} className="flex justify-between border-b border-neutral-200 py-1">
                    <dt className="text-neutral-700">{label}</dt>
                    <dd className="tabular-nums">{Number(value ?? 0).toFixed(2)}</dd>
                  </div>
                ))}
              </dl>
              <p className="mt-2 text-sm">
                {STRINGS.basic} {peso(d?.basic_pay)} · {STRINGS.premium} {peso(d?.premium_pay)}
              </p>
            </section>

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.payLines}</div>
              {groupByDate(d?.lines).map(([date, lines]) => (
                <div key={date} className="mb-3">
                  <div className="text-[13px] font-semibold">{siteDay(date)}</div>
                  <ul className="text-sm">
                    {lines.map((line, i) => (
                      <li key={i} className="flex justify-between gap-3 border-b border-neutral-200 py-1">
                        <span className="text-neutral-700">
                          {DAY_TYPE[line.day_type] ?? line.day_type} · {LINE_KIND[line.kind] ?? line.kind} ×
                          {Number(line.multiplier).toFixed(2)} · {Number(line.hours).toFixed(2)}h
                        </span>
                        <span className="flex-none tabular-nums">{peso(line.amount)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
              {!d?.lines?.length ? <p className="text-sm text-neutral-700">—</p> : null}
            </section>

            {d?.warnings?.length ? (
              <section>
                <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">{STRINGS.notes}</div>
                <ul className="space-y-1 text-[13px] text-neutral-700">
                  {d.warnings.map((w, i) => (
                    <li key={i}>· {w}</li>
                  ))}
                </ul>
              </section>
            ) : null}

            <section>
              <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">
                {STRINGS.employerShares}
              </div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                {[
                  ['SSS', d?.employer_shares?.sss],
                  ['PhilHealth', d?.employer_shares?.philhealth],
                  ['Pag-IBIG', d?.employer_shares?.pagibig],
                ].map(([label, value]) => (
                  <div key={label} className="flex justify-between border-b border-neutral-200 py-1">
                    <dt className="text-neutral-700">{label}</dt>
                    <dd className="tabular-nums">{peso(value)}</dd>
                  </div>
                ))}
              </dl>
            </section>
          </>
        ) : null}
      </div>
    </div>
  )
}
