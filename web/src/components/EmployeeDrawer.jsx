import { useState } from 'react'
import { Icon } from './icons'

const TABS = ['Profile', 'Attendance', 'Payroll', 'Documents', 'Audit']

const STATUS_STYLE = {
  regular: { bg: '#E6F1EA', fg: '#1F5334', dot: '#2F7A4D' },
  probationary: { bg: '#FBF0E2', fg: '#8A5211', dot: '#C9781B' },
  project_based: { bg: '#e7e7ea', fg: '#2b2b2d', dot: '#7a7a7d' },
  seasonal: { bg: '#e7e7ea', fg: '#2b2b2d', dot: '#7a7a7d' },
  separated: { bg: '#e7e7ea', fg: '#2b2b2d', dot: 'transparent' },
}

function certStatus(expiresAt) {
  if (!expiresAt) return { label: 'Not set', tone: 'neutral' }
  const days = Math.ceil((new Date(`${expiresAt}T00:00:00`) - new Date()) / 86400000)
  if (days < 0) return { label: 'Expired', tone: 'expired' }
  if (days <= 14) return { label: `Expires in ${days} d`, tone: 'soon' }
  return { label: 'Current', tone: 'valid' }
}

const CERT_TONE = {
  expired: { bg: '#F7E8E5', fg: '#75261C' },
  soon: { bg: '#FBF0E2', fg: '#8A5211' },
  valid: { bg: '#E6F1EA', fg: '#1F5334' },
  neutral: { bg: '#e7e7ea', fg: '#2b2b2d' },
}

const fmtDate = (iso) => {
  if (!iso) return '—'
  const d = new Date(`${iso}T00:00:00`)
  return d.toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' })
}

const initials = (name = '') =>
  name
    .split(/[\s,]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

function Row({ label, value }) {
  return (
    <div>
      <div className="text-[11px] text-neutral-700">{label}</div>
      <div className="text-sm">{value || '—'}</div>
    </div>
  )
}

const Tag = ({ style, children }) => (
  <span className="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs" style={{ background: style.bg, color: style.fg }}>
    {children}
  </span>
)

export function EmployeeDrawer({ employee, onClose, onEdit, canEdit }) {
  const [tab, setTab] = useState('Profile')
  const status = STATUS_STYLE[employee.employment_status] ?? STATUS_STYLE.project_based
  const certs = employee.certification ?? []

  return (
    <div className="fixed inset-0 z-30 flex justify-end bg-black/30" role="dialog" aria-label={`${employee.full_name} — profile`}>
      <div className="flex h-full w-full max-w-[560px] flex-col border-l border-neutral-300 bg-surface shadow-lg">
        <div className="border-b border-neutral-300 px-5 py-4">
          <div className="flex items-start gap-3.5">
            <span className="grid h-11 w-11 flex-none place-items-center border border-neutral-300 bg-canvas font-heading text-sm">
              {initials(employee.full_name)}
            </span>
            <div className="min-w-0 flex-1">
              <h2 className="truncate font-heading text-xl">{employee.full_name}</h2>
              <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                <span className="tabular-nums text-neutral-700">{employee.employee_code}</span>
                {employee.role ? (
                  <span className="inline border border-primary-500 px-2 py-0.5 text-xs text-primary-800">
                    {employee.role.role_name}
                  </span>
                ) : null}
                {employee.employment_status ? (
                  <Tag style={status}>
                    <span className="h-[6px] w-[6px] rounded-full" style={{ background: status.dot, border: status.dot === 'transparent' ? '1.5px solid #7a7a7d' : 'none' }} />
                    <span className="capitalize">{employee.employment_status}</span>
                  </Tag>
                ) : null}
              </div>
            </div>
            <button type="button" onClick={onClose} aria-label="Close drawer" className="p-1 text-neutral-600 hover:text-ink">
              <Icon.close />
            </button>
          </div>
          <div className="mt-3 flex items-center gap-2 text-xs text-neutral-700">
            <Icon.site size={14} />
            <span>{employee.site?.site_name ?? 'No site assigned'}</span>
            <span className="ml-auto">
              {employee.daily_rate ? `₱${Number(employee.daily_rate).toFixed(2)}/day` : 'Rate not set'}
            </span>
          </div>
          {canEdit ? (
            <button
              type="button"
              onClick={onEdit}
              className="mt-3 h-10 w-full border border-primary-700 text-sm font-semibold text-primary-800 hover:bg-primary-100"
            >
              Edit profile
            </button>
          ) : null}
        </div>

        <div className="flex border-b border-neutral-300">
          {TABS.map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTab(t)}
              className={`px-4 py-2.5 font-heading text-sm text-neutral-700 ${
                tab === t ? 'shadow-[inset_0_-2px_0_#5980a6] text-primary-800' : 'hover:text-ink'
              }`}
            >
              {t}
            </button>
          ))}
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-5">
          {tab === 'Profile' ? (
            <div className="space-y-6">
              <section>
                <div className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Identity</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                  <Row label="Full name" value={employee.full_name} />
                  <Row label="Date of birth" value={fmtDate(employee.date_of_birth)} />
                  <Row label="Civil status" value={`${employee.civil_status ?? '—'}${employee.dependents ? ` · ${employee.dependents} dependents` : ''}`} />
                  <Row label="Blood type" value={employee.blood_type} />
                  <Row label="Trade / skill" value={employee.trade_skill} />
                  <Row label="Mobile" value={employee.mobile} />
                  <Row label="Address" value={employee.address} />
                  <Row label="Date hired" value={fmtDate(employee.date_hired)} />
                </div>
              </section>

              <section>
                <div className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Government IDs</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                  <Row label="TIN" value={employee.tin} />
                  <Row label="SSS" value={employee.sss} />
                  <Row label="PhilHealth" value={employee.philhealth} />
                  <Row label="Pag-IBIG" value={employee.pag_ibig} />
                </div>
              </section>

              <section>
                <div className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Certifications</div>
                {certs.length ? (
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[440px] border-collapse text-sm">
                      <thead>
                        <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                          <th className="py-1.5 pr-3 font-normal">Certification</th>
                          <th className="py-1.5 pr-3 font-normal">Issuer</th>
                          <th className="py-1.5 pr-3 font-normal">Issued</th>
                          <th className="py-1.5 pr-3 font-normal">Expires</th>
                          <th className="py-1.5 font-normal">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {certs.map((cert) => {
                          const state = certStatus(cert.expires_at)
                          const colors = CERT_TONE[state.tone]
                          return (
                            <tr key={`${cert.certificate_no}-${cert.name}`} className="border-b border-neutral-200 align-top">
                              <td className="py-2 pr-3">
                                {cert.name || '—'}
                                {cert.certificate_no ? <div className="text-[11px] text-neutral-700">{cert.certificate_no}</div> : null}
                              </td>
                              <td className="py-2 pr-3">{cert.issuer || '—'}</td>
                              <td className="py-2 pr-3">{fmtDate(cert.issued_at)}</td>
                              <td className="py-2 pr-3">{fmtDate(cert.expires_at)}</td>
                              <td className="py-2">
                                <span className="px-2 py-0.5 text-xs" style={{ background: colors.bg, color: colors.fg }}>
                                  {state.label}
                                </span>
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <p className="text-[13px] text-neutral-700">No certifications on file.</p>
                )}
              </section>

              <section>
                <div className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Emergency contact</div>
                {employee.emergency_contact ? (
                  <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                    <Row label="Name" value={employee.emergency_contact.name} />
                    <Row label="Mobile" value={employee.emergency_contact.mobile} />
                    <Row label="Alternate contact" value={employee.emergency_contact.alternate} />
                    <Row label="Hospital / clinic" value={employee.emergency_contact.hospital} />
                  </div>
                ) : (
                  <p className="text-[13px] text-neutral-700">No emergency contact on file.</p>
                )}
              </section>
            </div>
          ) : (
            <div className="py-8 text-center">
              <p className="text-sm text-neutral-700">{tab} records arrive in a later capstone phase.</p>
              <p className="mt-1 text-xs text-neutral-700">Attendance, payroll, documents and audit history all reuse this Profile drawer when their modules land.</p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}