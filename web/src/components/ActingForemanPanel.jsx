import { useEffect, useState } from 'react'
import { http, errorMessage } from '../api/client'
import { Icon } from './icons'

/*
 * Acting Foreman Reassignment (docs/prototypes/HRIS Acting Foreman
 * Reassignment.dc.html) — Phase 7, UC-06, STD TC-05 steps 1-2.
 *
 * The Site Engineer picks a free Site Foreman and a duration; one click hands
 * the crew over. The regular foreman loses the crew until the cover ends, and
 * it ends on its own — nobody has to remember to give it back.
 *
 * The prototype's "On site 06:44" needs gate data the system does not collect,
 * and "He is notified immediately" needs notifications it does not send; both
 * are left out rather than faked.
 */

function initials(name) {
  return String(name ?? '')
    .split(/[\s,]+/)
    .filter(Boolean)
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

function yearsSince(date) {
  if (!date) return null
  const years = (Date.now() - new Date(`${date}T00:00:00`)) / (365.24 * 86400000)
  return years < 1 ? `${Math.max(1, Math.round(years * 12))} mo` : `${Math.floor(years)} yr`
}

function Pill({ tone, children }) {
  const tones = {
    ok: { background: '#E6F1EA', color: '#1F5334' },
    warn: { background: '#FBF0E2', color: '#8A5211' },
    bad: { background: '#F7E8E5', color: '#75261C' },
    info: { background: 'var(--color-canvas)', color: 'var(--color-primary-800)', border: '1px solid var(--color-primary-400)' },
  }
  return (
    <span className="inline-flex items-center px-2.5 py-1 text-xs" style={tones[tone]}>
      {children}
    </span>
  )
}

export function ActingForemanPanel({ crew, onClose, onAssigned }) {
  const [candidates, setCandidates] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [selectedId, setSelectedId] = useState(null)
  const [duration, setDuration] = useState('today')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    let active = true
    http
      .get(`/crews/${crew.crew_id}/acting-candidates`)
      .then((r) => {
        if (!active) return
        setCandidates(r.data.candidates)
        setSelectedId(r.data.candidates.find((c) => c.available)?.employee_id ?? null)
      })
      .catch((err) => active && setLoadError(errorMessage(err, 'Unable to load foremen.')))
    return () => {
      active = false
    }
  }, [crew.crew_id])

  const selected = candidates?.find((c) => c.employee_id === selectedId) ?? null
  const eligible = candidates?.filter((c) => c.available).length ?? 0

  const assign = async () => {
    if (!selected) return
    setBusy(true)
    setError(null)
    try {
      await http.post(`/crews/${crew.crew_id}/acting-foreman`, { foreman_id: selected.employee_id, duration })
      onAssigned(`${selected.full_name} is acting foreman of ${crew.crew_name}.`)
    } catch (err) {
      setError(errorMessage(err, 'Unable to assign the acting foreman.'))
    } finally {
      setBusy(false)
    }
  }

  const shortName = selected ? `${selected.full_name.split(',')[1]?.trim()?.[0] ?? ''}. ${selected.full_name.split(',')[0]}` : ''

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-black/30" role="dialog" aria-modal="true" aria-label="Acting foreman">
      <div className="flex h-full w-full max-w-[420px] flex-col bg-canvas shadow-lg">
        <div className="flex items-center gap-3.5 bg-primary-900 px-[18px] pb-4 pt-4 text-canvas">
          <button onClick={onClose} aria-label="Close" className="flex-none">
            <Icon name="close" size={20} />
          </button>
          <div className="min-w-0 flex-1">
            <div className="font-heading text-[22px] leading-tight">Acting foreman</div>
            <div className="truncate text-[13px] opacity-75">
              {crew.crew_name} · {duration === 'week' ? 'this week' : 'today only'}
            </div>
          </div>
        </div>

        <div className="border-b border-neutral-200 px-[18px] pb-3 pt-4">
          <p className="text-[15px] leading-snug text-neutral-800">
            {candidates === null
              ? 'Loading Site Foremen…'
              : `${eligible} eligible — Site Foremen who are free and can sign in.`}
          </p>
          {crew.foreman ? (
            <p className="mt-1 text-xs text-neutral-700">Covering for {crew.foreman.full_name}, who loses access to this crew until the cover ends.</p>
          ) : null}
        </div>

        <div className="flex-1 overflow-auto p-3">
          {loadError ? <p className="p-3 text-sm text-[#75261C]">{loadError}</p> : null}
          <div className="flex flex-col gap-3" role="radiogroup" aria-label="Site Foremen">
            {(candidates ?? []).map((c) => {
              const checked = c.employee_id === selectedId
              const years = yearsSince(c.date_hired)
              return (
                <button
                  key={c.employee_id}
                  type="button"
                  role="radio"
                  aria-checked={checked}
                  disabled={!c.available}
                  onClick={() => setSelectedId(c.employee_id)}
                  className={`p-3.5 text-left disabled:cursor-not-allowed disabled:opacity-50 ${
                    checked ? 'border-2 border-primary-600 bg-primary-100' : 'border border-neutral-300'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <span
                      className={`grid h-6 w-6 flex-none place-items-center rounded-full border-2 ${
                        checked ? 'border-primary-600 bg-primary-600 text-white' : 'border-neutral-400'
                      }`}
                    >
                      {checked ? <Icon name="check" size={13} /> : null}
                    </span>
                    <span className="grid h-11 w-11 flex-none place-items-center border border-neutral-300 font-heading text-[15px]">
                      {initials(c.full_name)}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-lg leading-tight">{c.full_name}</span>
                      <span className="block truncate text-sm text-neutral-700">
                        {[c.employee_code, c.trade_skill, years].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {c.foreman_certificate ? <Pill tone="ok">{c.foreman_certificate}</Pill> : null}
                    {c.available ? (
                      <Pill tone={c.same_site ? 'ok' : 'warn'}>{c.same_site ? 'Same site' : c.site?.site_name ?? 'No site'}</Pill>
                    ) : (
                      <Pill tone="bad">{c.unavailable_reason}</Pill>
                    )}
                    {c.acted_before > 0 ? <Pill tone="info">Acted {c.acted_before}× before</Pill> : null}
                  </div>
                </button>
              )
            })}
          </div>
        </div>

        <div className="border-t border-neutral-300 px-[18px] pb-5 pt-4">
          <div className="mb-3">
            <div className="mb-1.5 text-[11px] uppercase tracking-[.08em] text-neutral-700">Duration</div>
            <div className="grid grid-cols-2 border border-neutral-400">
              {[
                ['today', 'Today'],
                ['week', 'This week'],
              ].map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  aria-pressed={duration === value}
                  onClick={() => setDuration(value)}
                  className={`min-h-[44px] text-sm ${duration === value ? 'bg-primary-800 text-white' : 'bg-canvas text-ink'}`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
          {error ? <p className="mb-2 text-xs text-[#75261C]">{error}</p> : null}
          <button
            className="flex min-h-[52px] w-full items-center justify-center border border-primary-800 bg-primary-800 text-[15px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40"
            disabled={!selected || busy}
            onClick={assign}
          >
            {busy ? 'Assigning…' : selected ? `Assign ${shortName}` : 'Choose a foreman'}
          </button>
          <p className="mt-2 text-center text-xs text-neutral-700">
            Takes effect now and ends on its own {duration === 'week' ? 'on Sunday night' : 'at midnight'}. Logged to the audit trail.
          </p>
        </div>
      </div>
    </div>
  )
}
