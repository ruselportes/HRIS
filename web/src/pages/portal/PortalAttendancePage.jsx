import { useEffect, useState } from 'react'
import { http, errorMessage } from '../../api/client'

// Worker-facing copy lives here, in one object, so a Bisaya version can be
// added later without hunting through markup.
const STRINGS = {
  title: 'My attendance',
  subtitle: 'Your daily time records for the pay period',
  empty: 'No attendance recorded for this period.',
  loadError: 'Unable to load your attendance.',
  previous: '‹ Previous',
  next: 'Next ›',
}

const REVIEW_PILL = {
  'Counted for pay': 'bg-[#E6F1EA] text-[#1F5334]',
  'Under HR review': 'bg-[#F7EFDC] text-[#7A5B16]',
  'On hold — ask HR': 'bg-[#F7E8E5] text-[#75261C]',
}

function reviewClass(review) {
  if (review?.startsWith('Not accepted')) return 'bg-[#F7E8E5] text-[#75261C]'
  return REVIEW_PILL[review] ?? 'bg-neutral-200 text-neutral-700'
}

export function PortalAttendancePage() {
  // win is null for the default read: the server answers with the current
  // pay period and the response's period block drives the steppers, so no
  // cutoff rule ever lives in this file.
  const [win, setWin] = useState(null)
  const [period, setPeriod] = useState(null)
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    const params = win ? { from: win.start, to: win.end } : {}
    http
      .get('/me/attendance', { params })
      .then(({ data }) => {
        if (cancelled) return
        setPeriod(data.period ?? null)
        setRows(data.data ?? [])
        setError(null)
      })
      .catch((err) => {
        if (cancelled) return
        setError(errorMessage(err, STRINGS.loadError))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [win])

  const step = (next) => {
    setWin(next)
    setLoading(true)
  }

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5">
        <div className="font-heading text-[22px] leading-tight">{STRINGS.title}</div>
        <div className="truncate text-[11px] text-neutral-700">{STRINGS.subtitle}</div>
      </div>

      <div className="flex flex-wrap items-center gap-3 border-b border-neutral-300 px-[22px] py-3.5">
        <button
          type="button"
          onClick={() => period && step(period.previous)}
          disabled={loading || !period}
          className="border border-neutral-400 px-3 py-2 text-[13px] disabled:opacity-40"
        >
          {STRINGS.previous}
        </button>
        <div className="text-sm font-semibold tabular-nums">
          {period ? `${period.label} · ${period.code}` : '…'}
        </div>
        <button
          type="button"
          onClick={() => period?.next && step(period.next)}
          disabled={loading || !period?.next}
          className="border border-neutral-400 px-3 py-2 text-[13px] disabled:opacity-40"
        >
          {STRINGS.next}
        </button>
      </div>

      <div className="flex-1 px-[22px]">
        {error ? <p className="py-6 text-sm text-[#75261C]">{error}</p> : null}
        {loading ? (
          <p className="py-6 text-sm text-neutral-700">Loading your records…</p>
        ) : (
          <table className="w-full border-collapse text-sm tabular-nums">
            <thead>
              <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                <th className="py-2.5 pr-4 font-normal">Date</th>
                <th className="py-2.5 pr-4 font-normal">Status</th>
                <th className="py-2.5 pr-4 font-normal">Time in</th>
                <th className="py-2.5 pr-4 font-normal">Time out</th>
                <th className="py-2.5 pr-4 font-normal">How the time out was recorded</th>
                <th className="py-2.5 font-normal">Review</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.attendance_id} className="border-b border-neutral-200">
                  <td className="py-2.5 pr-4">{row.date}</td>
                  <td className="py-2.5 pr-4 capitalize">{row.status}</td>
                  <td className="py-2.5 pr-4">{row.time_in ?? '—'}</td>
                  <td className="py-2.5 pr-4">{row.time_out ?? '—'}</td>
                  <td className="py-2.5 pr-4">{row.time_out_source}</td>
                  <td className="py-2.5">
                    <span className={`inline-block whitespace-nowrap px-2 py-0.5 text-[11px] ${reviewClass(row.review)}`}>
                      {row.review}
                    </span>
                  </td>
                </tr>
              ))}
              {!rows.length ? (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-sm text-neutral-700">
                    {STRINGS.empty}
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
