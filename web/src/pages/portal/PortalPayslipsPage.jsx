import { useCallback, useEffect, useState } from 'react'
import { http, errorMessage } from '../../api/client'

const peso = (n) =>
  `₱${Number(n ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

function DeductionLines({ detail }) {
  const rows = [
    ['SSS', detail?.deduction_lines?.sss],
    ['PhilHealth', detail?.deduction_lines?.philhealth],
    ['Pag-IBIG', detail?.deduction_lines?.pagibig],
    ['Withholding tax', detail?.deduction_lines?.withholding_tax],
    ['Other', detail?.deduction_lines?.other],
  ]
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="flex justify-between border-b border-neutral-200 py-1">
          <dt className="text-neutral-700">{label}</dt>
          <dd className="tabular-nums">{peso(value)}</dd>
        </div>
      ))}
    </dl>
  )
}

export function PortalPayslipsPage() {
  const [runs, setRuns] = useState([])
  const [selected, setSelected] = useState(null)
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState(null)
  const [detailError, setDetailError] = useState(null)

  useEffect(() => {
    http
      .get('/me/payslips')
      .then(({ data }) => {
        setRuns(data.data ?? [])
        setError(null)
      })
      .catch((err) => setError(errorMessage(err, 'Unable to load your payslips.')))
      .finally(() => setLoading(false))
  }, [])

  const open = useCallback(async (run) => {
    setSelected(run)
    setDetail(null)
    setDetailLoading(true)
    try {
      const { data } = await http.get(`/me/payslips/${run.run_id}`)
      setDetail(data.data)
      setDetailError(null)
    } catch (err) {
      setDetailError(errorMessage(err, 'That payslip is not available.'))
    } finally {
      setDetailLoading(false)
    }
  }, [])

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5">
        <div className="font-heading text-[22px] leading-tight">My payslips</div>
        <div className="truncate text-[11px] text-neutral-700">Approved runs only</div>
      </div>

      <div className="flex flex-1 flex-col gap-4 px-[22px] py-4 lg:flex-row">
        <div className="w-full lg:max-w-[380px] lg:flex-none">
          {error ? <p className="py-4 text-sm text-[#75261C]">{error}</p> : null}
          {loading ? (
            <p className="py-4 text-sm text-neutral-700">Loading your payslips…</p>
          ) : (
            <ul className="divide-y divide-neutral-200 border border-neutral-300">
              {runs.map((run) => (
                <li key={run.run_id}>
                  <button
                    type="button"
                    onClick={() => open(run)}
                    className={`flex w-full items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-neutral-200/60 ${
                      selected?.run_id === run.run_id ? 'bg-primary-100' : ''
                    }`}
                  >
                    <span>
                      <span className="block font-semibold">{run.run_code}</span>
                      <span className="block text-[11px] text-neutral-700">
                        {run.period?.start} – {run.period?.end}
                      </span>
                    </span>
                    <span className="tabular-nums">{peso(run.net_pay)}</span>
                  </button>
                </li>
              ))}
              {!runs.length ? <li className="px-3 py-6 text-center text-sm text-neutral-700">No approved payslips yet.</li> : null}
            </ul>
          )}
        </div>

        <div className="min-w-0 flex-1">
          {!selected ? (
            <p className="py-4 text-sm text-neutral-700">Choose a run to see the breakdown.</p>
          ) : detailLoading ? (
            <p className="py-4 text-sm text-neutral-700">Loading the breakdown…</p>
          ) : detailError ? (
            <p className="py-4 text-sm text-[#75261C]">{detailError}</p>
          ) : detail?.detail ? (
            <div className="max-w-[560px] space-y-5">
              <div>
                <div className="font-heading text-lg">
                  {detail.run_code} · {peso(detail.net_pay)} net
                </div>
                <div className="text-xs text-neutral-700">
                  {detail.period?.start} – {detail.period?.end} · gross {peso(detail.gross_pay)} · deductions{' '}
                  {peso(detail.deductions)}
                </div>
              </div>
              <section>
                <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">Hours</div>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                  {[
                    ['Regular', detail.detail.hours?.regular],
                    ['Overtime', detail.detail.hours?.overtime],
                    ['Night differential', detail.detail.hours?.night_differential],
                    ['Rest day', detail.detail.hours?.rest_day],
                    ['Holiday', detail.detail.hours?.holiday],
                  ].map(([label, value]) => (
                    <div key={label} className="flex justify-between border-b border-neutral-200 py-1">
                      <dt className="text-neutral-700">{label}</dt>
                      <dd className="tabular-nums">{Number(value ?? 0).toFixed(2)}</dd>
                    </div>
                  ))}
                </dl>
                <p className="mt-2 text-sm">
                  Basic {peso(detail.detail.basic_pay)} · Premium {peso(detail.detail.premium_pay)}
                </p>
              </section>
              <section>
                <div className="mb-2 text-[11px] uppercase tracking-[.1em] text-primary-700">Deductions</div>
                <DeductionLines detail={detail.detail} />
                {detail.detail.tax_note ? <p className="mt-2 text-xs text-neutral-700">{detail.detail.tax_note}</p> : null}
              </section>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
