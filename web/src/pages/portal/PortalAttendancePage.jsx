import { useEffect, useState } from 'react'
import { http, errorMessage } from '../../api/client'

const inputCls = 'h-[38px] border border-neutral-400 bg-canvas px-2.5 text-[13px] text-ink'

function isoDaysAgo(days) {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

export function PortalAttendancePage() {
  const [from, setFrom] = useState(() => isoDaysAgo(30))
  const [to, setTo] = useState(() => todayIso())
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // The fetch lives in the effect with an active flag (no setState call in
  // the effect body itself); the inputs set loading alongside the dates.
  useEffect(() => {
    let active = true
    http
      .get('/me/attendance', { params: { from, to } })
      .then(({ data }) => {
        if (!active) return
        setRows(data.data ?? [])
        setError(null)
      })
      .catch((err) => {
        if (!active) return
        setError(errorMessage(err, 'Unable to load your attendance.'))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [from, to])

  const changeFrom = (value) => {
    setFrom(value)
    setLoading(true)
  }

  const changeTo = (value) => {
    setTo(value)
    setLoading(true)
  }

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5">
        <div className="font-heading text-[22px] leading-tight">My attendance</div>
        <div className="truncate text-[11px] text-neutral-700">Your daily time records, credited times included</div>
      </div>

      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-300 px-[22px] py-3.5">
        <div>
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">From</label>
          <input type="date" className={inputCls} value={from} onChange={(e) => changeFrom(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">To</label>
          <input type="date" className={inputCls} value={to} onChange={(e) => changeTo(e.target.value)} />
        </div>
        <span className="pb-2 text-xs text-neutral-700">At most 62 days at a time.</span>
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
                  <td className="py-2.5">{row.review}</td>
                </tr>
              ))}
              {!rows.length ? (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-sm text-neutral-700">
                    No records in this window.
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
