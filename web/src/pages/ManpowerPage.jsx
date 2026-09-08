import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { NAV } from '../config/nav'
import { Icon } from '../components/icons'
import { tone } from '../config/nav'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

function initials(name) {
  return String(name ?? '')
    .split(/\s+/)
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

function yearsSince(date) {
  if (!date) return ''
  const ms = new Date() - new Date(`${date}T00:00:00`)
  const yrs = Math.max(0, ms / (365.24 * 86400000))
  return yrs < 1 ? `${Math.max(1, Math.round(yrs * 12))} mo` : `${Math.round(yrs)} yr`
}

const CERT_TONE = {
  valid: { bg: '#E6F1EA', fg: '#1F5334' },
  expiring_soon: { bg: '#FBF0E2', fg: '#8A5211' },
  expired: { bg: '#F7E8E5', fg: '#75261C' },
  none: { bg: '#e7e7ea', fg: '#7a7a7d' },
}

function certInfo(cert) {
  const counts = cert?.cert_counts ?? {}
  if (cert?.cert_status === 'expired') return { label: `${counts.expired} expired`, hint: 'Cannot select — certs expired', disabled: true }
  if (cert?.cert_status === 'expiring_soon') return { label: 'Expiring soon', hint: '', disabled: false }
  if (cert?.cert_status === 'valid') return { label: 'Valid', hint: '', disabled: false }
  return { label: 'No certs', hint: '', disabled: false }
}

const inputCls = 'h-[38px] w-full border border-neutral-400 bg-canvas px-2.5 text-[13px] text-ink'

const btnPrimary =
  'inline-flex items-center justify-center gap-2 border border-primary-800 bg-primary-800 px-4 py-2 text-[13px] font-medium text-white disabled:cursor-not-allowed disabled:opacity-40'
const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'
const btnGhost = 'inline-flex items-center justify-center gap-1.5 border border-neutral-400 px-2.5 py-1 text-xs text-ink disabled:cursor-not-allowed disabled:opacity-40'

function StatCard({ label, value, note, accent }) {
  return (
    <div className="blueprint relative border border-neutral-300 bg-surface p-3.5">
      <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      <div className="mt-1.5 font-heading text-3xl tabular-nums" style={accent ? { color: accent } : undefined}>
        {value}
      </div>
      <div className="mt-0.5 text-[11px] text-neutral-700">{note}</div>
    </div>
  )
}

function Tag({ bg, fg, children, className = '' }) {
  return (
    <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 text-[11px] ${className}`} style={{ background: bg, color: fg }}>
      {children}
    </span>
  )
}

export function ManpowerPage() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'manpower').access[roleKey(user?.role?.slug)]
  const canManage = access === 'full'

  const [crews, setCrews] = useState([])
  const [pool, setPool] = useState([])
  const [deployment, setDeployment] = useState(null)
  const [sites, setSites] = useState([])
  const [foremen, setForemen] = useState([])

  const [selectedCrewId, setSelectedCrewId] = useState(null)
  const [poolSel, setPoolSel] = useState({})
  const [rosterSel, setRosterSel] = useState({})
  const [search, setSearch] = useState('')
  const [trade, setTrade] = useState('')
  const [newCrewOpen, setNewCrewOpen] = useState(false)
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)

  const selected = crews.find((c) => Number(c.crew_id) === Number(selectedCrewId)) ?? crews[0] ?? null

  const refresh = async (preferredCrewId = null) => {
    const [c, p, d] = await Promise.all([
      http.get('/crews'),
      http.get('/crews/pool'),
      canManage ? http.get('/deployment') : http.get('/deployment'),
    ])
    setCrews(c.data.data)
    setPool(p.data.pool)
    setDeployment(d.data)
    const keep = preferredCrewId ?? selectedCrewId ?? c.data.data[0]?.crew_id ?? null
    if (keep) setSelectedCrewId(keep)
  }

  useEffect(() => {
    let active = true
    Promise.all([
      http.get('/crews'),
      http.get('/crews/pool'),
      http.get('/deployment'),
      http.get('/sites'),
    ])
      .then(([c, p, d, s]) => {
        if (!active) return
        setCrews(c.data.data)
        setPool(p.data.pool)
        setDeployment(d.data)
        setSites(s.data.sites)
        const draft = c.data.data.find((x) => x.status === 'draft') ?? c.data.data[0]
        if (draft) setSelectedCrewId(draft.crew_id)
      })
      .catch(() => {})
    if (canManage) {
      http.get('/employees', { params: { role: 'foreman', per_page: 100 } })
        .then((r) => {
          if (active) setForemen(r.data.data)
        })
        .catch(() => {})
    }
    return () => {
      active = false
    }
  }, [canManage])

  const poolFiltered = pool.filter((w) => {
    const q = search.trim().toLowerCase()
    const matchesSearch =
      !q || w.full_name.toLowerCase().includes(q) || w.employee_code.toLowerCase().includes(q) || (w.trade_skill ?? '').toLowerCase().includes(q)
    const matchesTrade = !trade || w.trade_skill === trade
    return matchesSearch && matchesTrade
  })

  const trades = [...new Set(pool.map((w) => w.trade_skill).filter(Boolean))].sort()

  const countPoolSel = Object.keys(poolSel).filter((id) => poolSel[id]).length
  const countRosterSel = Object.keys(rosterSel).filter((id) => rosterSel[id]).length

  const readyToDeploy = Boolean(selected?.foreman)

  const flash = (msg) => {
    setNotice(msg)
    clearTimeout(flash._t)
    flash._t = setTimeout(() => setNotice(null), 4000)
  }

  const runMutation = async (fn, successMsg) => {
    setBusy(true)
    try {
      await fn()
      flash(successMsg)
    } catch (err) {
      flash(errorMessage(err, 'Unable to update the crew.'))
    } finally {
      setBusy(false)
    }
  }

  const assignSelected = () => {
    const ids = Object.keys(poolSel).filter((id) => poolSel[id]).map(Number)
    if (!selected || !ids.length) return
    runMutation(async () => {
      await http.post(`/crews/${selected.crew_id}/members`, { employee_ids: ids })
      await refresh(selected.crew_id)
      setPoolSel({})
    }, 'Assigned to crew.')
  }

  const removeMember = (employeeId) => {
    if (!selected) return
    runMutation(async () => {
      await http.delete(`/crews/${selected.crew_id}/members/${employeeId}`)
      const next = { ...rosterSel }
      delete next[employeeId]
      setRosterSel(next)
      await refresh(selected.crew_id)
    }, 'Removed from crew.')
  }

  const removeSelected = () => {
    const ids = Object.keys(rosterSel).filter((id) => rosterSel[id]).map(Number)
    if (!ids.length) return
    Promise.all(ids.map((id) => http.delete(`/crews/${selected.crew_id}/members/${id}`)))
      .then(() => flash('Removed from crew.'))
      .catch((err) => flash(errorMessage(err)))
      .finally(() => refresh(selected.crew_id))
    setRosterSel({})
  }

  const setForeman = (foremanId) => {
    if (!selected) return
    runMutation(async () => {
      await http.put(`/crews/${selected.crew_id}/foreman`, { foreman_id: Number(foremanId) })
      await refresh(selected.crew_id)
    }, 'Foreman set.')
  }

  const deployCrew = () => {
    if (!selected?.foreman) return
    runMutation(async () => {
      await http.post(`/crews/${selected.crew_id}/deploy`)
      await refresh(selected.crew_id)
    }, 'Crew deployed.')
  }

  const createCrew = (payload) => {
    runMutation(async () => {
      const r = await http.post('/crews', payload)
      await refresh(r.data.data.crew_id)
    }, 'Draft crew created.')
  }

  const crewOptions = [...crews].sort((a, b) => (a.status === b.status ? 0 : a.status === 'draft' ? -1 : 1))

  return (
    <div className="flex flex-col gap-10 px-[22px] pb-10 pt-5">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="font-heading text-2xl">Crew builder &amp; deployment</h1>
        {!canManage ? <Tag bg={tone.neutral.bg} fg={tone.neutral.fg}>View only</Tag> : null}
        <span className="text-xs text-neutral-700">
          {canManage ? 'Site Engineer — build a crew from the pool, then deploy it.' : 'Read-only. Crews are managed by the Site Engineer.'}
        </span>
        {notice ? <span className="ml-auto border border-primary-500 px-3 py-1.5 text-xs text-primary-800">{notice}</span> : null}
      </div>

      {/* 01 — Crew builder */}
      <section className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-3">
          <h2 className="font-heading text-[13px] uppercase tracking-[.12em]">01 — Crew builder</h2>
          <div className="ml-auto flex items-center gap-2">
            <label className="text-xs text-neutral-700">Building crew</label>
            <select
              className="h-[34px] border border-neutral-400 bg-canvas px-2 text-[13px] text-ink"
              value={selected?.crew_id ?? ''}
              onChange={(e) => setSelectedCrewId(Number(e.target.value))}
            >
              {crewOptions.map((c) => (
                <option key={c.crew_id} value={c.crew_id}>
                  {c.crew_name} · {c.site?.site_name} ({c.status})
                </option>
              ))}
            </select>
            {canManage ? (
              <button className={btnGhost} onClick={() => setNewCrewOpen(true)}>
                <Icon name="plus" size={14} /> New crew
              </button>
            ) : null}
          </div>
        </div>

        <div className="grid grid-cols-[1fr_54px_1.15fr] items-start gap-0">
          {/* POOL */}
          <div className="flex min-w-0 flex-col border border-neutral-300 bg-surface">
            <div className="border-b border-neutral-300 px-4 pb-3 pt-4">
              <div className="flex items-baseline gap-2">
                <span className="font-heading text-lg">Available pool</span>
                <span className="text-xs text-neutral-700">{pool.length} workers unassigned today</span>
              </div>
              <div className="mt-3 flex gap-2">
                <div className="flex h-[34px] flex-1 items-center gap-2 border border-neutral-400 bg-canvas px-2.5 text-[13px] text-neutral-600">
                  <Icon name="search" size={15} />
                  <input
                    className="w-full bg-transparent text-[13px] text-ink outline-none"
                    placeholder="Search name or trade"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                </div>
                <select className="h-[34px] w-[130px] border border-neutral-400 bg-canvas px-2 text-[13px] text-ink" value={trade} onChange={(e) => setTrade(e.target.value)}>
                  <option value="">All trades</option>
                  {trades.map((t) => (
                    <option key={t} value={t}>
                      {t}
                    </option>
                  ))}
                </select>
              </div>
              <div className="mt-2.5 flex flex-wrap items-center gap-2">
                {trade ? (
                  <Tag bg="#D7E5F2" fg="#2F5A85">
                    Trade: {trade}
                    <button className="cursor-pointer" aria-label="Clear trade filter" onClick={() => setTrade('')}>
                      <Icon name="close" size={11} />
                    </button>
                  </Tag>
                ) : null}
                <span className="ml-auto text-[11px] text-neutral-700">{poolFiltered.length} shown</span>
              </div>
            </div>

            <div className="flex flex-col gap-2 p-3">
              {poolFiltered.map((w) => {
                const cert = certInfo(w)
                const checked = Boolean(poolSel[w.employee_id])
                return (
                  <div
                    key={w.employee_id}
                    className={`flex items-center gap-2.5 border bg-canvas px-3 py-2.5 ${cert.disabled || !canManage ? 'opacity-50' : ''} ${checked ? 'border-primary-500' : 'border-neutral-300'}`}
                  >
                    <button
                      type="button"
                      className={`grid h-4 w-4 flex-none place-items-center border text-white ${
                        checked ? 'border-primary-800 bg-primary-800' : 'border-primary-600 bg-canvas'
                      }`}
                      disabled={cert.disabled || !canManage}
                      aria-pressed={checked}
                      onClick={() => setPoolSel((s) => ({ ...s, [w.employee_id]: !checked }))}
                    >
                      {checked ? <Icon name="check" size={11} /> : null}
                    </button>
                    <Icon name="grip" size={14} className="flex-none" />
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm text-ink">{w.full_name}</div>
                      <div className="truncate text-[11px] text-neutral-700">
                        {w.employee_code} · {w.trade_skill ?? 'No trade'} · {yearsSince(w.date_hired)}
                      </div>
                    </div>
                    <Tag bg={CERT_TONE[cert.disabled ? 'expired' : certInfo(w).hint ? 'expiring' : w.cert_status].bg} fg={CERT_TONE[w.cert_status].fg}>
                      {cert.label}
                    </Tag>
                  </div>
                )
              })}
              {!poolFiltered.length ? <p className="px-3 py-6 text-center text-xs text-neutral-700">No unassigned workers match the filters.</p> : null}
            </div>

            <div className="mt-auto flex items-center gap-3 border-t border-neutral-300 px-4 py-3">
              <span className="text-xs text-neutral-700">{countPoolSel} selected</span>
              <span className="text-[11px] text-neutral-700">Workers with expired certs cannot be selected.</span>
            </div>
          </div>

          {/* TRANSFER */}
          <div className="flex flex-col items-center gap-2 pt-10">
            <button
              className={`grid h-11 w-11 flex-none place-items-center border border-primary-800 bg-primary-800 text-white disabled:cursor-not-allowed disabled:opacity-40`}
              disabled={!canManage || !countPoolSel || busy || !selected}
              onClick={assignSelected}
              aria-label="Assign to crew"
              title="Assign selected workers"
            >
              <Icon name="arrowRight" size={18} />
            </button>
            <span className="text-[10px] uppercase tracking-[.08em] text-neutral-700">Assign</span>
            <button
              className="mt-1.5 grid h-11 w-11 flex-none place-items-center border border-neutral-400 bg-canvas text-ink disabled:cursor-not-allowed disabled:opacity-40"
              disabled={!canManage || !countRosterSel || busy || !selected}
              onClick={removeSelected}
              aria-label="Remove from crew"
              title="Remove selected roster members"
            >
              <Icon name="arrowLeft" size={18} />
            </button>
            <span className="text-[10px] uppercase tracking-[.08em] text-neutral-700">Remove</span>
          </div>

          {/* ROSTER */}
          <div className="flex min-w-0 flex-col border border-neutral-300 bg-surface">
            <div className="border-b border-neutral-300 px-4 pb-3 pt-4">
              <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline gap-2">
                    <span className="font-heading text-lg">{selected?.crew_name ?? 'Select a crew'}</span>
                    <Tag bg={selected?.status === 'deployed' ? tone.present.bg : tone.pending.bg} fg={selected?.status === 'deployed' ? tone.present.fg : tone.pending.fg}>
                      {selected?.status === 'deployed' ? 'Deployed' : 'Draft'}
                    </Tag>
                  </div>
                  <div className="mt-0.5 text-xs text-neutral-700">
                    {selected?.site?.site_name ?? ''} · effective {new Date().toLocaleDateString('en-PH', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })}
                  </div>
                </div>
                <div className="flex-none text-right">
                  <div className="font-heading text-3xl tabular-nums leading-none">
                    {selected?.members_count ?? 0}
                  </div>
                  <div className="text-[11px] text-neutral-700">members</div>
                </div>
              </div>
              <div className="mt-3 flex flex-wrap gap-2">
                {selected?.foreman ? (
                  <Tag bg="#E6F1EA" fg="#1F5334" className="gap-1.5">
                    <Icon name="people" size={11} /> Foreman assigned
                  </Tag>
                ) : (
                  <Tag bg="#FBF0E2" fg="#8A5211" className="gap-1.5">
                    <Icon name="alert" size={11} /> Foreman needed
                  </Tag>
                )}
                {selected?.members_count ? <Tag bg="#E6F1EA" fg="#1F5334">{selected.members_count} members</Tag> : null}
              </div>
            </div>

            {/* FOREMAN SLOT */}
            <div className="border-b border-neutral-300 bg-neutral-50 px-4 py-3">
              <div className="mb-2 text-[10px] uppercase tracking-[.1em] text-neutral-700">Foreman</div>
              {selected?.foreman ? (
                <div className="flex items-center gap-3 border border-primary-600 bg-canvas px-3 py-2.5">
                  <span className="grid h-8 w-8 flex-none place-items-center border border-neutral-300 bg-canvas font-heading text-[13px]">
                    {initials(selected.foreman.full_name)}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm">{selected.foreman.full_name}</div>
                    <div className="truncate text-[11px] text-neutral-700">
                      {selected.foreman.employee_code}
                      {leadingOtherCrew(selected) ? ` · currently leads ${leadingOtherCrew(selected)}` : ' · leads this crew'}
                    </div>
                  </div>
                  <Tag bg={tone.present.bg} fg={tone.present.fg} className="flex-none">
                    Foreman
                  </Tag>
                  {canManage ? (
                    <button className={btnGhost} onClick={() => setForeman(null)}>
                      Clear
                    </button>
                  ) : null}
                </div>
              ) : (
                <div className="flex flex-wrap items-center gap-3 border border-neutral-300 bg-canvas px-3 py-2.5">
                  <span className="grid h-8 w-8 flex-none place-items-center border border-neutral-300 bg-neutral-100 font-heading text-[13px] text-neutral-600">
                    {initials(selected?.crew_name)}
                  </span>
                  <span className="text-sm text-neutral-700">No foreman assigned</span>
                  {canManage ? (
                    <span className="ml-auto flex items-center gap-2">
                      <select
                        className="h-[30px] border border-neutral-400 bg-canvas px-2 text-[13px] text-ink"
                        defaultValue=""
                        onChange={(e) => e.target.value && setForeman(e.target.value)}
                      >
                        <option value="" disabled>
                          Assign a foreman…
                        </option>
                        {foremen.map((f) => (
                          <option key={f.employee_id} value={f.employee_id}>
                            {f.full_name} · {f.employee_code}
                          </option>
                        ))}
                      </select>
                    </span>
                  ) : null}
                </div>
              )}
              {selected && !selected.foreman && selected.members_count > 0 ? (
                <p className="mt-2 flex items-start gap-1.5 text-[11px] text-amber-800">
                  <Icon name="info" size={14} className="mt-0.5 flex-none" />
                  A crew without a foreman cannot be deployed — the roster below can still be drafted.
                </p>
              ) : null}
            </div>

            {/* ROSTER LIST */}
            <div className="px-3 pt-1">
              <div className="flex items-baseline gap-2 px-1.5 py-2">
                <span className="text-[10px] uppercase tracking-[.1em] text-neutral-700">Roster — {selected?.members_count ?? 0} workers</span>
                <span className="ml-auto text-[11px] text-neutral-700">{countRosterSel} selected</span>
              </div>
              <div className="flex flex-col gap-2 pb-2">
                {(selected?.members ?? []).map((m) => {
                  const checked = Boolean(rosterSel[m.employee_id])
                  return (
                    <div key={m.employee_id} className={`flex items-center gap-2.5 border px-3 py-2 ${checked ? 'border-primary-500 bg-primary-50' : 'border-neutral-300 bg-canvas'}`}>
                      <button
                        type="button"
                        className={`grid h-4 w-4 flex-none place-items-center border text-white ${
                          checked ? 'border-primary-800 bg-primary-800' : 'border-neutral-400 bg-canvas'
                        }`}
                        disabled={!canManage}
                        aria-pressed={checked}
                        onClick={() => setRosterSel((s) => ({ ...s, [m.employee_id]: !checked }))}
                      >
                        {checked ? <Icon name="check" size={11} /> : null}
                      </button>
                      <Icon name="grip" size={14} className="flex-none" />
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm">{m.full_name}</div>
                        <div className="truncate text-[11px] text-neutral-700">
                          {m.employee_code} · {m.trade_skill ?? 'No trade'}
                        </div>
                      </div>
                      <Tag bg={CERT_TONE[m.cert_status].bg} fg={CERT_TONE[m.cert_status].fg}>
                        {certInfo(m).label}
                      </Tag>
                      {canManage ? (
                        <button className="text-neutral-500 hover:text-ink" aria-label={`Remove ${m.full_name}`} onClick={() => removeMember(m.employee_id)} disabled={busy}>
                          <Icon name="close" size={15} />
                        </button>
                      ) : null}
                    </div>
                  )
                })}
                {!selected?.members?.length ? (
                  <div className="flex min-h-[68px] flex-col items-center justify-center gap-1.5 border border-dashed border-primary-500 bg-primary-50 px-4 py-4 text-center">
                    <Icon name="plus" size={17} className="text-primary-700" />
                    <span className="text-sm text-primary-800">
                      {selected ? `Select workers above and press Assign to fill ${selected.crew_name}` : 'Select or create a crew to start building'}
                    </span>
                  </div>
                ) : null}
              </div>
            </div>

            <div className="mt-auto flex items-center gap-2 border-t border-neutral-300 px-4 py-3">
              <span className="text-[11px] text-neutral-700">Changes apply immediately to the draft.</span>
              <div className="ml-auto flex gap-2">
                <button className={btnSecondary} disabled>
                  Save draft
                </button>
                <button
                  className={btnPrimary}
                  disabled={!canManage || !readyToDeploy || busy}
                  title={selected && !selected.foreman ? 'Assign a foreman before deploying' : 'Deploy this crew'}
                  onClick={deployCrew}
                >
                  Deploy crew
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* 02 — Site deployment today */}
      <section className="flex flex-col gap-4">
        <div className="flex items-baseline gap-3">
          <h2 className="font-heading text-[13px] uppercase tracking-[.12em]">02 — Site deployment today</h2>
          <span className="text-xs text-neutral-700">
            {new Date().toLocaleDateString('en-PH', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })} · every crew in scope
          </span>
        </div>

        <div className="grid grid-cols-4 gap-4">
          <StatCard label="Deployed today" value={deployment?.deployed_workers ?? '–'} note={`of ${deployment?.required_workers ?? '–'} required`} />
          <StatCard label="Active crews" value={deployment?.sites?.flatMap((s) => s.crews).length ?? '–'} note="across all sites" />
          <StatCard label="Without foreman" value={deployment?.crews_without_foreman ?? '–'} note="cannot start work" accent="#75261C" />
          <StatCard label="Unassigned pool" value={deployment?.pool_available ?? '–'} note="available to deploy" accent="#96420E" />
        </div>

        <div className="grid grid-cols-3 items-start gap-6">
          {(deployment?.sites ?? []).map((site) => (
            <div key={site.site.site_id} className="flex flex-col border border-neutral-300 bg-surface">
              <div className="border-b border-neutral-300 p-4">
                <div className="flex items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="font-heading text-xl">{site.site.site_name}</div>
                    <div className="text-xs text-neutral-700">{site.site.location}</div>
                  </div>
                  <Tag
                    bg={site.needs_foreman ? tone.absent.bg : tone.present.bg}
                    fg={site.needs_foreman ? tone.absent.fg : tone.present.fg}
                    className="flex-none"
                  >
                    {site.needs_foreman ? <Icon name="alert" size={11} /> : <Icon name="check" size={11} />}
                    {site.needs_foreman ? 'No foreman' : 'Manned'}
                  </Tag>
                </div>
                <div className="mt-3 flex items-baseline gap-2">
                  <span className="font-heading text-2xl tabular-nums">{site.workers}</span>
                  <span className="text-xs text-neutral-700">workers · {site.crews.length} {site.crews.length === 1 ? 'crew' : 'crews'}</span>
                </div>
              </div>
              <div className="flex flex-col gap-2 p-3">
                {site.crews.map((crew) => (
                  <div key={crew.crew_id} className={`border p-3 ${crew.foreman ? 'border-neutral-300' : 'border-l-[3px] border-l-red-800 border-r-neutral-300 border-t-neutral-300 border-b-neutral-300'}`}>
                    <div className="flex items-baseline gap-2">
                      <span className="text-sm">{crew.crew_name}</span>
                      {crew.status === 'draft' ? (
                        <Tag bg={tone.pending.bg} fg={tone.pending.fg} className="flex-none">
                          Draft
                        </Tag>
                      ) : null}
                      <span className="ml-auto font-heading text-base tabular-nums">{crew.members_count}</span>
                    </div>
                    <div className="mt-1.5 flex items-center gap-1.5 text-xs text-neutral-700">
                      {crew.foreman ? (
                        <>
                          <Icon name="people" size={13} />
                          <span className="text-primary-800">{crew.foreman.full_name}</span>
                        </>
                      ) : (
                        <>
                          <Icon name="alert" size={13} className="text-red-800" />
                          <span className="text-red-800">No foreman assigned</span>
                        </>
                      )}
                    </div>
                  </div>
                ))}
                {canManage ? (
                  <button
                    className="flex items-center justify-center gap-2 border border-dashed border-neutral-400 px-3 py-3 text-[13px] text-neutral-700 hover:text-ink"
                    onClick={() => setNewCrewOpen(site.site.site_id)}
                  >
                    <Icon name="plus" size={15} /> New crew
                  </button>
                ) : null}
              </div>
            </div>
          ))}
        </div>
      </section>

      {newCrewOpen !== false ? (
        <NewCrewModal
          open={newCrewOpen !== false}
          defaultSite={typeof newCrewOpen === 'number' ? newCrewOpen : undefined}
          sites={sites}
          onCreate={createCrew}
          onClose={() => setNewCrewOpen(false)}
        />
      ) : null}
    </div>
  )
}

function leadingOtherCrew(selected) {
  if (!selected?.foreman) return null
  return selected.foreman_other_crew ?? null
}

function NewCrewModal({ defaultSite, sites, onCreate, onClose }) {
  const [siteId, setSiteId] = useState(typeof defaultSite === 'number' ? defaultSite : '')
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = () => {
    if (!siteId || !name.trim()) return
    setBusy(true)
    onCreate({ site_id: Number(siteId), crew_name: name.trim() })
    onClose()
  }

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30" role="dialog" aria-label="New crew">
      <div className="w-full max-w-[420px] border border-neutral-300 bg-surface p-5 shadow-lg">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="font-heading text-lg">New crew</h3>
          <button className="text-neutral-500 hover:text-ink" onClick={onClose} aria-label="Close">
            <Icon name="close" size={16} />
          </button>
        </div>
        <div className="flex flex-col gap-3">
          <div>
            <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Site</label>
            <select className={inputCls} value={siteId} onChange={(e) => setSiteId(e.target.value)}>
              <option value="">Select site…</option>
              {sites.map((s) => (
                <option key={s.site_id} value={s.site_id}>
                  {s.site_name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Crew name</label>
            <input className={inputCls} placeholder="e.g. Rebar crew D" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
        </div>
        <div className="mt-5 flex justify-end gap-2">
          <button className={btnSecondary} onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button className={btnPrimary} onClick={submit} disabled={busy || !siteId || !name.trim()}>
            Create draft
          </button>
        </div>
      </div>
    </div>
  )
}