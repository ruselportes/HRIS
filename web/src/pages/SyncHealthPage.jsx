import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http, errorMessage } from '../api/client'
import { tone } from '../config/nav'
import { Icon } from '../components/icons'

/*
 * Device & Sync Health (Phase 10), nav `synchealth` matrix: HR view, Admin
 * full. Reading GET /devices shows the whole registered field-device fleet —
 * owner, security level, bound time, last sync, chain presence, integrity
 * incidents — and nothing that is verification material (no HMAC keys, no
 * public keys, no hash digests; those never leave the server).
 *
 * Revoking is the deliberate gap this page closes. The foreman's own revoke
 * endpoint only works from the phone being revoked, so a lost or stolen device
 * was previously unreachable from the portal. Here an Admin can cut it out of
 * the system, with the reason required so the DEVICE_REVOKED audit row says
 * why. The reason is required for that same reason. A revoked phone can no
 * longer sync; its unsynced local records never reach the server, and the
 * device must be re-bound to be used again — the confirm dialog says so.
 */

const SITE_TZ = 'Asia/Manila'

const OFFLINE_MS = 4 * 60 * 60 * 1000

// Captured at module load, not during render, so the clock state starts from a
// stable value the React Compiler can trust (it flags Date.now() in render).
const LOADED_AT = Date.now()

const SECURITY_LABEL = {
  STRONGBOX: 'StrongBox',
  TRUSTED_ENVIRONMENT: 'Trusted Environment',
  SOFTWARE: 'Software',
}
const SECURITY_TONE = {
  STRONGBOX: tone.present,
  TRUSTED_ENVIRONMENT: tone.present,
  SOFTWARE: tone.absent,
}

const btnSecondary =
  'inline-flex items-center justify-center gap-2 border border-neutral-400 bg-canvas px-4 py-2 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-40'
const btnDanger =
  'inline-flex items-center justify-center gap-2 border border-[#A83A2C] bg-[#A83A2C] px-4 py-2 text-[13px] text-white disabled:cursor-not-allowed disabled:opacity-40'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const stampFmt = new Intl.DateTimeFormat('en-GB', { timeZone: SITE_TZ, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
const stamp = (iso) => (iso ? stampFmt.format(new Date(iso)) : '')

function ago(iso, now) {
  if (!iso) return null
  const ms = now - new Date(iso).getTime()
  const minutes = Math.floor(ms / 60_000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} h ago`
  const days = Math.floor(hours / 24)
  return `${days} d ago`
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

function Card({ label, value, children }) {
  return (
    <div className="border border-neutral-300 bg-surface p-4">
      <div className="text-[10px] uppercase tracking-[.1em] text-neutral-700">{label}</div>
      <div className="font-heading text-[30px] leading-tight tabular-nums">{value}</div>
      <div className="mt-1.5 text-[11px] text-neutral-700">{children}</div>
    </div>
  )
}

function SyncCell({ device, now }) {
  if (device.revoked_at) {
    return (
      <div>
        <div className="text-[#75261C]">Revoked</div>
        <div className="text-[11px] text-neutral-700" title={stamp(device.revoked_at)}>{ago(device.revoked_at, now)}</div>
      </div>
    )
  }
  if (!device.last_synced_at) {
    return (
      <div>
        <div className="text-[#75261C]">Never synced</div>
        <div className="text-[11px] text-neutral-700">no contact since binding</div>
      </div>
    )
  }
  const offline = now - new Date(device.last_synced_at).getTime() > OFFLINE_MS
  return (
    <div>
      <div style={{ color: offline ? '#75261C' : '#1F5334' }}>{offline ? 'Offline' : 'Online'}</div>
      <div className="text-[11px] text-neutral-700" title={stamp(device.last_synced_at)}>{ago(device.last_synced_at, now)}</div>
    </div>
  )
}

function RevokeDialog({ device, onClose, onRevoked }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await http.delete(`/devices/${device.device_key_id}`, { data: { reason: reason.trim() } })
      onRevoked(device.device_key_id)
    } catch (err) {
      setError(errorMessage(err, 'Unable to revoke the device. Try again.'))
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 px-4" role="dialog" aria-modal="true" aria-label="Revoke device">
      <div className="flex w-full max-w-[520px] flex-col border border-neutral-300 bg-surface shadow-lg">
        <div className="flex items-center justify-between border-b border-neutral-300 px-5 py-3.5">
          <div>
            <h3 className="font-heading text-lg">Revoke device</h3>
            <div className="font-mono text-xs text-neutral-700">{device.device_id}</div>
          </div>
          <button className="text-neutral-500 hover:text-ink" onClick={onClose} aria-label="Close">
            <Icon name="close" size={16} />
          </button>
        </div>
        <div className="flex flex-col gap-4 px-5 py-4">
          <p className="text-sm leading-snug">
            Revoking <span className="font-medium">{device.owner.full_name}</span>&apos;s phone cuts it out of the system immediately. Any attendance still
            held on the device will <span className="font-medium">never reach the server</span>, and the device must be <span className="font-medium">re-bound</span> to
            sync again.
          </p>
          <Field label="Reason (required)">
            <textarea
              className="w-full border border-neutral-400 bg-canvas px-2 py-1.5 text-[13px] text-ink"
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Lost during site relocation on 21 Sep. Replacement bound to the worker."
              autoFocus
            />
          </Field>
          {error ? <p className="text-sm text-[#75261C]">{error}</p> : null}
        </div>
        <div className="flex items-center justify-end gap-2.5 border-t border-neutral-300 px-5 py-3">
          <button className={btnSecondary} disabled={busy} onClick={onClose}>
            Cancel
          </button>
          <button className={btnDanger} disabled={busy || !reason.trim()} onClick={submit}>
            {busy ? 'Revoking…' : 'Revoke device'}
          </button>
        </div>
      </div>
    </div>
  )
}

export function SyncHealthPage() {
  const { user } = useAuth()
  const role = roleKey(user?.role?.slug)
  const canRevoke = role === 'admin'

  const [state, setState] = useState({ key: null, devices: [], error: null })
  const [now, setNow] = useState(LOADED_AT)
  const [revoking, setRevoking] = useState(null)

  useEffect(() => {
    let active = true
    http
      .get('/devices')
      .then((r) => active && setState({ key: 'devices', devices: r.data.devices ?? [], error: null }))
      .catch((err) => active && setState({ key: 'devices', devices: [], error: errorMessage(err, 'Unable to load devices.') }))
    return () => {
      active = false
    }
  }, [])

  // Keep the relative "last synced" times live on screen.
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 60_000)
    return () => clearInterval(id)
  }, [])

  const afterRevoke = (deviceKeyId) => {
    setRevoking(null)
    setState((prev) => ({
      ...prev,
      devices: prev.devices.map((d) => (d.device_key_id === deviceKeyId ? { ...d, revoked_at: new Date().toISOString() } : d)),
    }))
  }

  const active = state.devices.filter((d) => !d.revoked_at)
  const offline = active.filter((d) => d.last_synced_at && now - new Date(d.last_synced_at).getTime() > OFFLINE_MS)
  const incidentOwners = new Set(state.devices.filter((d) => d.integrity_incidents > 0).map((d) => d.owner.employee_id))

  return (
    <div className="flex flex-col pb-10">
      <div className="border-b border-neutral-200 px-[22px] py-3.5 text-xs text-neutral-700">
        Registered field devices · read-only for HR, revocation is an admin action
      </div>

      {state.error ? <p className="px-[22px] pt-4 text-sm text-[#75261C]">{state.error}</p> : null}
      {state.key !== 'devices' ? <p className="px-[22px] pt-4 text-sm text-neutral-700">Loading devices…</p> : null}

      {state.key === 'devices' && !state.error ? (
        <div>
          <div className="grid grid-cols-2 gap-4 px-[22px] pt-5 lg:grid-cols-4">
            <Card label="Registered devices" value={state.devices.length}>
              {active.length} {active.length === 1 ? 'device' : 'devices'} bound and active
            </Card>
            <Card label="Online now" value={active.length - offline.length}>
              synced within {OFFLINE_MS / 3_600_000} hours · offline {offline.length}
            </Card>
            <Card label="Hardware backed" value={state.devices.filter((d) => d.hardware_backed && !d.revoked_at).length}>
              TEE or StrongBox protection
            </Card>
            <Card label="Integrity incidents" value={incidentOwners.size}>
              {incidentOwners.size === 1 ? 'owner' : 'owners'} with flagged attendance
            </Card>
          </div>

          <section className="mx-[22px] mt-5 overflow-x-auto border border-neutral-300 bg-surface">
            <div className="px-[18px] pt-[18px]">
              <h2 className="font-heading text-[19px]">Field device registry</h2>
              <p className="text-xs text-neutral-700">
                Each device is bound to one employee and carries its own HMAC chain; the chain hash is verification material and is never shown here.
                Incidents are counted per owner and follow the crew&apos;s site.
              </p>
            </div>
            <table className="mt-3 w-full min-w-[1000px] border-collapse text-sm tabular-nums">
              <thead>
                <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                  <th className="py-2.5 pl-[18px] pr-4 font-normal">Device / owner</th>
                  <th className="py-2.5 pr-4 font-normal">Security</th>
                  <th className="py-2.5 pr-4 font-normal">Bound</th>
                  <th className="py-2.5 pr-4 font-normal">Sync</th>
                  <th className="py-2.5 pr-4 font-normal">Chain</th>
                  <th className="py-2.5 pr-4 font-normal">Incidents</th>
                  <th className="py-2.5 pr-[18px] text-right font-normal">Action</th>
                </tr>
              </thead>
              <tbody>
                {state.devices.map((d) => {
                  const sec = SECURITY_TONE[d.security_level] ?? tone.neutral
                  return (
                    <tr key={d.device_key_id} className={d.revoked_at ? 'border-b border-neutral-200 opacity-60' : 'border-b border-neutral-200'}>
                      <td className="py-2.5 pl-[18px] pr-4">
                        <div className="font-mono text-[12px]">{d.device_id}</div>
                        <div className="text-[12px]">{d.owner.full_name}</div>
                        <div className="text-[11px] text-neutral-700">
                          {d.owner.employee_code} · {d.owner.role} · {d.owner.site ?? 'no site'}
                        </div>
                      </td>
                      <td className="py-2.5 pr-4">
                        <Tag bg={sec.bg} fg={sec.fg}>
                          {SECURITY_LABEL[d.security_level] ?? d.security_level}
                          {d.hardware_backed ? ' · HW' : ''}
                        </Tag>
                      </td>
                      <td className="py-2.5 pr-4 whitespace-nowrap" title={stamp(d.bound_at)}>{ago(d.bound_at, now)}</td>
                      <td className="py-2.5 pr-4 whitespace-nowrap"><SyncCell device={d} now={now} /></td>
                      <td className="py-2.5 pr-4">
                        {d.revoked_at ? (
                          <span className="text-neutral-500">—</span>
                        ) : d.has_chain_history ? (
                          <span className="inline-flex items-center gap-1 text-[#1F5334]">
                            <Icon name="check" size={13} /> preserved
                          </span>
                        ) : (
                          <span className="text-neutral-500">first sync pending</span>
                        )}
                      </td>
                      <td className="py-2.5 pr-4">
                        {d.integrity_incidents > 0 ? (
                          <Tag bg={tone.absent.bg} fg={tone.absent.fg}>{d.integrity_incidents} {d.integrity_incidents === 1 ? 'incident' : 'incidents'}</Tag>
                        ) : (
                          <span className="text-neutral-500">none</span>
                        )}
                      </td>
                      <td className="py-2.5 pr-[18px] text-right">
                        {d.revoked_at ? (
                          <span className="text-[11px] text-neutral-700">revoked {ago(d.revoked_at, now)}</span>
                        ) : canRevoke ? (
                          <button className={btnSecondary} onClick={() => setRevoking(d)}>
                            Revoke
                          </button>
                        ) : (
                          <span className="text-[11px] text-neutral-700">admin only</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {!state.devices.length ? (
                  <tr>
                    <td colSpan={7} className="px-[18px] py-4 text-neutral-700">
                      No devices are registered yet. A foreman&apos;s first field app sign-in binds their phone.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </section>
        </div>
      ) : null}

      {revoking ? <RevokeDialog device={revoking} onClose={() => setRevoking(null)} onRevoked={afterRevoke} /> : null}
    </div>
  )
}