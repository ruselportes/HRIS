import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { http } from '../api/client'
import { NAV } from '../config/nav'
import { Icon } from '../components/icons'
import { EmployeeDrawer } from '../components/EmployeeDrawer'
import { EmployeeForm } from '../components/EmployeeForm'

function roleKey(slug) {
  return slug === 'executive' ? 'exec' : slug
}

const STATUSES = ['probationary', 'regular', 'project_based', 'seasonal', 'separated']

const EMP_STATUS = {
  regular: { bg: '#E6F1EA', fg: '#1F5334', dot: '#2F7A4D' },
  probationary: { bg: '#FBF0E2', fg: '#8A5211', dot: '#C9781B' },
  project_based: { bg: '#e7e7ea', fg: '#2b2b2d', dot: '#7a7a7d' },
  seasonal: { bg: '#e7e7ea', fg: '#2b2b2d', dot: '#7a7a7d' },
  separated: { bg: '#e7e7ea', fg: '#2b2b2d', dot: 'none' },
}

const CERT_SUMMARY_TONE = {
  expired: { bg: '#F7E8E5', fg: '#75261C' },
  soon: { bg: '#FBF0E2', fg: '#8A5211' },
  current: { bg: '#E6F1EA', fg: '#1F5334' },
  neutral: { bg: '#e7e7ea', fg: '#7a7a7d' },
}

const EMPTY_FILTERS = { search: '', role: '', site_id: '', employment_status: '' }

function certSummary(employee) {
  if (employee.employment_status === 'separated') {
    return { label: 'Not tracked', tone: 'neutral' }
  }
  const certs = employee.certification ?? []
  if (!certs.length) return null
  const expiring = certs
    .filter((c) => c.expires_at)
    .map((c) => Math.ceil((new Date(`${c.expires_at}T00:00:00`) - new Date()) / 86400000))
  if (!expiring.length) return null
  const min = Math.min(...expiring)
  if (min < 0) {
    const expired = expiring.filter((d) => d < 0).length
    return { label: `${expired} expired`, tone: 'expired' }
  }
  if (min <= 14) return { label: `Expires in ${min} d`, tone: 'soon' }
  return { label: 'Current', tone: 'current' }
}

function downloadCsv(rows) {
  const head = ['Employee code', 'Full name', 'Role', 'Site', 'Employment status', 'Daily rate']
  const lines = rows.map((e) =>
    [e.employee_code, e.full_name, e.role?.role_name ?? '', e.site?.site_name ?? '', e.employment_status, e.daily_rate]
      .map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`)
      .join(','),
  )
  const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'employees.csv'
  a.click()
  URL.revokeObjectURL(url)
}

const inputCls = 'h-[38px] w-full border border-neutral-400 bg-canvas px-2.5 text-[13px] text-ink'

export function EmployeesPage() {
  const { user } = useAuth()
  const access = NAV.find((n) => n.key === 'employees').access[roleKey(user?.role?.slug)]
  const canEdit = access === 'full'

  const [roles, setRoles] = useState([])
  const [sites, setSites] = useState([])
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [selected, setSelected] = useState({})
  const [drawer, setDrawer] = useState(null)
  const [form, setForm] = useState(null)

  useEffect(() => {
    http.get('/roles').then((r) => setRoles(r.data.roles)).catch(() => {})
    http.get('/sites').then((r) => setSites(r.data.sites)).catch(() => {})
  }, [])

  const load = useCallback(async () => {
    try {
      const params = {
        page,
        per_page: 24,
        ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
      }
      const { data } = await http.get('/employees', { params })
      setResult(data)
      setSelected({})
      setError(null)
    } catch (err) {
      setError(err.response?.data?.message ?? 'Unable to load employees.')
    } finally {
      setLoading(false)
    }
  }, [page, filters])

  useEffect(() => {
    let active = true
    const params = {
      page,
      per_page: 24,
      ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')),
    }
    http
      .get('/employees', { params })
      .then(({ data }) => {
        if (!active) return
        setResult(data)
        setSelected({})
        setError(null)
      })
      .catch((err) => {
        if (!active) return
        setError(err.response?.data?.message ?? 'Unable to load employees.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [page, filters])

  const setFilter = (key) => (valueOrEvent) => {
    const value = typeof valueOrEvent === 'object' ? valueOrEvent.target.value : valueOrEvent
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
  }

  const clearAll = () => {
    setFilters(EMPTY_FILTERS)
    setPage(1)
  }

  const activeChips = [
    filters.role && roles.find((r) => r.role_id === Number(filters.role)),
    filters.site_id && sites.find((s) => s.site_id === Number(filters.site_id)),
    filters.employment_status && { employment_status: filters.employment_status },
  ].filter(Boolean)

  const rows = result?.data ?? []
  const meta = result?.meta ?? {}
  const total = meta.total ?? 0

  const toggle = (id) => {
    setSelected((prev) => {
      const next = { ...prev }
      if (next[id]) delete next[id]
      else next[id] = true
      return next
    })
  }
  const countSelected = Object.keys(selected).filter((id) => selected[id]).length

  const saveDone = useCallback(() => {
    setForm(null)
    load()
  }, [load])

  return (
    <div className="flex min-h-full flex-col">
      <div className="flex flex-wrap items-end gap-3 border-b border-neutral-300 px-[22px] py-3.5">
        <div className="w-[220px]">
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Search</label>
          <input
            className={`${inputCls} flex items-center gap-2`}
            placeholder="Name or employee ID"
            value={filters.search}
            onChange={setFilter('search')}
          />
        </div>
        <div className="w-[190px]">
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Role</label>
          <select className={inputCls} value={filters.role} onChange={setFilter('role')}>
            <option value="">All roles</option>
            {roles.map((r) => (
              <option key={r.role_id} value={r.role_id}>
                {r.role_name}
              </option>
            ))}
          </select>
        </div>
        <div className="w-[210px]">
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Project site</label>
          <select className={inputCls} value={filters.site_id} onChange={setFilter('site_id')}>
            <option value="">All sites</option>
            {sites.map((s) => (
              <option key={s.site_id} value={s.site_id}>
                {s.site_name}
              </option>
            ))}
          </select>
        </div>
        <div className="w-[170px]">
          <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">Employment status</label>
          <select className={inputCls} value={filters.employment_status} onChange={setFilter('employment_status')}>
            <option value="">All statuses</option>
            {STATUSES.map((s) => (
              <option key={s} value={s} className="capitalize">
                {s}
              </option>
            ))}
          </select>
        </div>
        <div className="ml-auto flex gap-2.5 self-end">
          <button
            type="button"
            className="flex h-[38px] items-center gap-2 border border-neutral-300 bg-surface px-4 text-[13px] text-ink hover:bg-neutral-200"
            onClick={() => downloadCsv(rows)}
          >
            <Icon.download size={15} />
            Export
          </button>
          {canEdit ? (
            <button
              type="button"
              className="flex h-[38px] items-center gap-2 bg-primary px-4 text-[13px] font-semibold text-canvas hover:bg-primary-600"
              onClick={() => setForm({ mode: 'create' })}
            >
              <Icon.plus size={15} />
              Add employee
            </button>
          ) : null}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2.5 border-b border-neutral-300 px-[22px] py-3">
        <span className="text-[11px] uppercase tracking-[.1em] text-neutral-700">
          Filtered set — {rows.length} of {total}
        </span>
        {activeChips.map((chip) => {
          const label = chip.role_name || chip.site_name || chip.employment_status
          const key = chip.role_id ?? chip.site_id ?? chip.employment_status
          return (
            <span key={String(key)} className="inline-flex items-center gap-2 bg-primary-100 px-2 py-1 text-xs text-primary-800">
              {label}
              <button type="button" aria-label={`Remove ${label}`} onClick={() => setFilterByChip(chip)}>
                <Icon.close size={11} />
              </button>
            </span>
          )
        })}
        {activeChips.length ? (
          <button type="button" className="text-xs text-neutral-700 underline hover:text-ink" onClick={clearAll}>
            Clear all
          </button>
        ) : null}
        <div className="ml-auto flex items-center gap-3 text-xs text-neutral-700">
          <span>{countSelected} selected</span>
          <button type="button" disabled className="h-[30px] border border-neutral-300 px-2.5 text-[13px] opacity-50">
            Assign to crew
          </button>
          <button type="button" disabled className="h-[30px] border border-neutral-300 px-2.5 text-[13px] opacity-50">
            Flag for renewal
          </button>
        </div>
      </div>

      <div className="flex-1 px-[22px]">
        {error ? <p className="py-6 text-sm text-[#75261C]">{error}</p> : null}
        {loading ? (
          <p className="py-6 text-sm text-neutral-700">Loading employees…</p>
        ) : (
          <table className="w-full border-collapse text-sm tabular-nums">
            <thead>
              <tr className="border-b border-neutral-300 text-left text-[11px] uppercase tracking-[.08em] text-neutral-700">
                <th className="w-9 py-2.5">
                  <input
                    type="checkbox"
                    aria-label="Select all"
                    checked={rows.length > 0 && countSelected === rows.length}
                    onChange={() => {
                      if (countSelected === rows.length) setSelected({})
                      else setSelected(Object.fromEntries(rows.map((r) => [r.employee_id, true])))
                    }}
                    className="h-[14px] w-[14px] accent-primary"
                  />
                </th>
                <th className="py-2.5 pr-4 font-normal">Employee</th>
                <th className="w-[130px] py-2.5 pr-4 font-normal">Role</th>
                <th className="w-[180px] py-2.5 pr-4 font-normal">Site</th>
                <th className="w-[150px] py-2.5 pr-4 font-normal">Employment</th>
                <th className="w-[190px] py-2.5 pr-4 font-normal">Certification</th>
                <th className="w-11" />
              </tr>
            </thead>
            <tbody>
              {rows.map((employee) => {
                const statusStyle = EMP_STATUS[employee.employment_status] ?? EMP_STATUS.project_based
                const cert = certSummary(employee)
                const certTone = cert ? CERT_SUMMARY_TONE[cert.tone] : null
                return (
                  <tr
                    key={employee.employee_id}
                    className="cursor-pointer border-b border-neutral-200 hover:bg-neutral-200/60"
                    onClick={() => setDrawer(employee)}
                  >
                    <td className="py-2.5" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        aria-label={`Select ${employee.full_name}`}
                        checked={Boolean(selected[employee.employee_id])}
                        onChange={() => toggle(employee.employee_id)}
                        className="h-[14px] w-[14px] accent-primary"
                      />
                    </td>
                    <td className="py-2.5 pr-4">
                      {employee.full_name}
                      <div className="text-[11px] text-neutral-700">
                        {employee.employee_code}
                        {employee.trade_skill ? ` · ${employee.trade_skill}` : ''}
                        {employee.date_hired ? ` · hired ${new Date(`${employee.date_hired}T00:00:00`).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' })}` : ''}
                      </div>
                    </td>
                    <td className="py-2.5 pr-4">{employee.role?.role_name ?? '—'}</td>
                    <td className="py-2.5 pr-4">{employee.site?.site_name ?? '—'}</td>
                    <td className="py-2.5 pr-4">
                      <span className="inline-flex items-center gap-1.5 px-2 py-1 text-xs capitalize" style={{ background: statusStyle.bg, color: statusStyle.fg }}>
                        <span
                          className="h-[6px] w-[6px] rounded-full"
                          style={statusStyle.dot === 'none' ? { border: '1.5px solid #7a7a7d', borderRadius: '50%' } : { background: statusStyle.dot }}
                        />
                        {employee.employment_status}
                      </span>
                    </td>
                    <td className="py-2.5 pr-4">
                      {certTone ? (
                        <span className="px-2 py-1 text-xs" style={{ background: certTone.bg, color: certTone.fg }}>
                          {cert.label}
                        </span>
                      ) : (
                        <span className="px-2 py-1 text-xs text-neutral-700">—</span>
                      )}
                    </td>
                    <td className="py-2.5 text-right text-neutral-500">
                      <Icon.caretRight size={15} />
                    </td>
                  </tr>
                )
              })}
              {!rows.length && !loading ? (
                <tr>
                  <td colSpan={7} className="py-8 text-center text-sm text-neutral-700">
                    No employees match those filters.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        )}
      </div>

      <div className="flex items-center gap-4 px-[22px] pb-5 pt-3 text-xs text-neutral-700">
        <span>
          {meta.from && meta.to ? `Showing ${meta.from}–${meta.to} of ${total}` : `0 of ${total}`}
          {meta.last_page > 1 ? ` · page ${meta.current_page} of ${meta.last_page}` : ''}
        </span>
        <div className="ml-auto flex gap-2">
          <button
            type="button"
            disabled={!meta.prev_page_url}
            onClick={() => setPage((p) => p - 1)}
            className="h-8 border border-neutral-300 bg-surface px-3 text-[13px] text-ink hover:bg-neutral-200 disabled:opacity-50"
          >
            Previous
          </button>
          <button
            type="button"
            disabled={!meta.next_page_url}
            onClick={() => setPage((p) => p + 1)}
            className="h-8 border border-neutral-300 bg-surface px-3 text-[13px] text-ink hover:bg-neutral-200 disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>

      {drawer ? <EmployeeDrawer employee={drawer} canEdit={canEdit} onClose={() => setDrawer(null)} onEdit={() => setForm({ mode: 'edit', seed: drawer })} /> : null}
      {form ? (
        <EmployeeForm
          roles={roles}
          sites={sites}
          seed={form.seed}
          onCancel={() => setForm(null)}
          onDone={saveDone}
        />
      ) : null}
    </div>
  )

  function setFilterByChip(chip) {
    if (chip.employment_status) setFilter('employment_status')(chip.employment_status === filters.employment_status ? '' : chip.employment_status)
    else if (chip.role_id) {
      const roleId = String(chip.role_id)
      setFilter('role')(roleId === filters.role ? '' : roleId)
    } else if (chip.site_id) {
      const siteId = String(chip.site_id)
      setFilter('site_id')(siteId === filters.site_id ? '' : siteId)
    }
  }
}