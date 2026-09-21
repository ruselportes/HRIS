import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { http, errorMessage } from '../../api/client'
import { peso, approvedDate } from './portalFormat'

const STRINGS = {
  title: 'My payslips',
  subtitle: 'Approved runs only',
  empty: 'No approved payslips yet.',
  loadError: 'Unable to load your payslips.',
  approved: 'Approved',
}

export function PortalPayslipsPage() {
  const [runs, setRuns] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    http
      .get('/me/payslips')
      .then(({ data }) => {
        setRuns(data.data ?? [])
        setError(null)
      })
      .catch((err) => setError(errorMessage(err, STRINGS.loadError)))
      .finally(() => setLoading(false))
  }, [])

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5">
        <div className="font-heading text-[22px] leading-tight">{STRINGS.title}</div>
        <div className="truncate text-[11px] text-neutral-700">{STRINGS.subtitle}</div>
      </div>

      <div className="px-[22px] py-4">
        {error ? <p className="py-4 text-sm text-[#75261C]">{error}</p> : null}
        {loading ? (
          <p className="py-4 text-sm text-neutral-700">Loading your payslips…</p>
        ) : (
          <ul className="divide-y divide-neutral-200 border border-neutral-300">
            {runs.map((run) => (
              <li key={run.run_id}>
                <Link
                  to={`/portal/payslips/${run.run_id}`}
                  className="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm hover:bg-neutral-200/60"
                >
                  <span>
                    <span className="block font-semibold">{run.run_code}</span>
                    <span className="block text-[11px] text-neutral-700">
                      {run.period?.start} – {run.period?.end}
                    </span>
                    <span className="block text-[11px] text-neutral-700">
                      {STRINGS.approved} {approvedDate(run.approved_at)}
                    </span>
                  </span>
                  <span className="tabular-nums">{peso(run.net_pay)}</span>
                </Link>
              </li>
            ))}
            {!runs.length ? (
              <li className="px-3 py-6 text-center text-sm text-neutral-700">{STRINGS.empty}</li>
            ) : null}
          </ul>
        )}
      </div>
    </div>
  )
}
