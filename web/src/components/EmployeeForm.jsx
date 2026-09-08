import { useEffect, useMemo, useState } from 'react'
import { errorMessage, http } from '../api/client'
import { Icon } from './icons'

const LOGIN_SLUGS = ['hr', 'foreman', 'engineer', 'admin', 'executive']
const COST_CENTRES = ['CO-01', 'CO-02', 'CO-03', 'CO-04']
const STATUSES = ['probationary', 'regular', 'project_based', 'seasonal', 'separated']
const CIVIL_STATUSES = ['Single', 'Married', 'Widowed']
const BLOOD_TYPES = ['A+', 'B+', 'O+', 'AB+', 'O-']
const TRADE_SKILLS = ['Formwork', 'Steelwork', 'Rebar', 'Masonry', 'Heavy equipment', 'Welding']

const Label = ({ children, required }) => (
  <label className="mb-1 block text-[13px] text-neutral-700">
    {children}
    {required ? <span className="text-[#A83A2C]"> *</span> : null}
  </label>
)

const inputCls =
  'h-10 w-full border border-neutral-400 bg-canvas px-2.5 text-[14px] text-ink outline-none focus:border-primary-500'

const Field = ({ label, required, error, children, hint }) => (
  <div>
    <Label required={required}>{label}</Label>
    {children}
    {error ? <div className="mt-1 text-xs text-[#75261C]">{error}</div> : null}
    {!error && hint ? <div className="mt-1 text-[11px] text-neutral-700">{hint}</div> : null}
  </div>
)

const Select = ({ children, className = inputCls, ...rest }) => (
  <select className={`${className} appearance-none bg-[right_0.6rem_center] bg-no-repeat pr-7`} {...rest}>
    {children}
  </select>
)

function emptyCert() {
  return { name: '', issuer: '', certificate_no: '', issued_at: '', expires_at: '' }
}

function emptyContact() {
  return { name: '', mobile: '', alternate: '', hospital: '' }
}

export function EmployeeForm({ roles, sites, seed, onDone, onCancel }) {
  const isEdit = Boolean(seed?.employee_id)

  const [form, setForm] = useState(() => {
    const s = seed ?? {}
    return {
      employee_code: s.employee_code ?? '',
      first_name: s.first_name ?? '',
      middle_name: s.middle_name ?? '',
      last_name: s.last_name ?? '',
      date_of_birth: s.date_of_birth ?? '',
      civil_status: s.civil_status ?? 'Single',
      dependents: s.dependents ?? 0,
      blood_type: s.blood_type ?? '',
      role_id: s.role_id ?? '',
      site_id: s.site_id ?? '',
      trade_skill: s.trade_skill ?? '',
      date_hired: s.date_hired ?? '',
      employment_status: s.employment_status ?? 'probationary',
      daily_rate: s.daily_rate ?? '',
      cost_centre: s.cost_centre ?? '',
      mobile: s.mobile ?? '',
      email: s.email ?? '',
      address: s.address ?? '',
      tin: s.tin ?? '',
      sss: s.sss ?? '',
      philhealth: s.philhealth ?? '',
      pag_ibig: s.pag_ibig ?? '',
      certification: s.certification?.length ? s.certification.map((c) => ({ ...emptyCert(), ...c })) : [emptyCert()],
      emergency_contact: s.emergency_contact ? { ...emptyContact(), ...s.emergency_contact } : { ...emptyContact(), mobile: '' },
      login_request: Boolean(s.email),
      email_login: s.email ?? '',
      password: '',
    }
  })

  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const [submitError, setSubmitError] = useState(null)

  const selectedRole = useMemo(() => roles.find((r) => r.role_id === Number(form.role_id)) ?? null, [roles, form.role_id])
  const canLogIn = selectedRole ? LOGIN_SLUGS.includes(selectedRole.slug) : false

  useEffect(() => {
    if (isEdit || form.employee_code) return
    http
      .get('/employees/next-code')
      .then((res) => setForm((f) => ({ ...f, employee_code: res.data.employee_code })))
      .catch(() => {})
  }, [isEdit, form.employee_code])

  const set = (field) => (event) => {
    setForm((f) => ({ ...f, [field]: event.target.value }))
  }

  const setCert = (index, field) => (event) => {
    const value = event.target.value
    setForm((f) => ({
      ...f,
      certification: f.certification.map((c, i) => (i === index ? { ...c, [field]: value } : c)),
    }))
  }

  const setContact = (field) => (event) => {
    setForm((f) => ({ ...f, emergency_contact: { ...f.emergency_contact, [field]: event.target.value } }))
  }

  const payload = () => {
    const { login_request: _loginReq, email_login, password, ...rest } = form
    const body = {
      ...rest,
      password: canLogIn && _loginReq ? password || null : null,
      email: canLogIn && _loginReq ? email_login || null : null,
    }
    if (isEdit) {
      delete body.employee_code
      delete body.password
      if (email_login) body.email = email_login
      else if (_loginReq) body.email = null
    }
    return body
  }

  const submit = async (event) => {
    event.preventDefault()
    setBusy(true)
    setSubmitError(null)
    setErrors({})
    try {
      if (isEdit) {
        await http.put(`/employees/${seed.employee_id}`, payload())
      } else {
        await http.post('/employees', payload())
      }
      onDone()
    } catch (err) {
      const data = err.response?.data
      if (data?.errors) {
        setErrors(Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0]])))
      } else {
        setSubmitError(errorMessage(err, 'Unable to save. Check the highlighted fields.'))
      }
    } finally {
      setBusy(false)
    }
  }

  const roleOptions = roles.map((role) => (
    <option key={role.role_id} value={role.role_id}>
      {role.role_name}
    </option>
  ))
  const siteOptions = sites.map((site) => (
    <option key={site.site_id} value={site.site_id}>
      {site.site_name}
    </option>
  ))

  return (
    <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 py-10">
      <div className="w-full max-w-3xl border border-neutral-300 bg-surface shadow-lg">
        <div className="flex items-center gap-3 border-b border-neutral-300 px-5 py-4">
          <h2 className="font-heading text-xl">{isEdit ? 'Edit employee' : 'Add employee'}</h2>
          <span className="text-xs text-neutral-700">{isEdit ? seed.employee_code : 'ADC-#### · auto-assigned'}</span>
          <button type="button" onClick={onCancel} aria-label="Close" className="ml-auto p-1 text-neutral-600 hover:text-ink">
            <Icon.close />
          </button>
        </div>

        <form onSubmit={submit} noValidate className="px-5 pb-6">
          {submitError ? (
            <div className="mt-4 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
              {submitError}
            </div>
          ) : null}

          <section className="mt-4">
            <h3 className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Identity</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Employee ID" required hint="Next available — editable">
                <input className={inputCls} value={form.employee_code} onChange={set('employee_code')} disabled={isEdit} />
              </Field>
              <Field label="First name" required error={errors.first_name}>
                <input className={inputCls} value={form.first_name} onChange={set('first_name')} />
              </Field>
              <Field label="Middle name">
                <input className={inputCls} value={form.middle_name} onChange={set('middle_name')} />
              </Field>
              <Field label="Last name" required error={errors.last_name}>
                <input className={inputCls} value={form.last_name} onChange={set('last_name')} />
              </Field>
              <Field label="Date of birth" required error={errors.date_of_birth}>
                <input type="date" className={inputCls} value={form.date_of_birth} onChange={set('date_of_birth')} />
              </Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Civil status">
                  <Select value={form.civil_status} onChange={set('civil_status')}>
                    {CIVIL_STATUSES.map((s) => (
                      <option key={s}>{s}</option>
                    ))}
                  </Select>
                </Field>
                <Field label="Dependents">
                  <input type="number" min="0" max="20" className={inputCls} value={form.dependents} onChange={set('dependents')} />
                </Field>
              </div>
              <Field label="Blood type">
                <Select value={form.blood_type} onChange={set('blood_type')}>
                  <option value="">—</option>
                  {BLOOD_TYPES.map((t) => (
                    <option key={t}>{t}</option>
                  ))}
                </Select>
              </Field>
            </div>
          </section>

          <section className="mt-5">
            <h3 className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Employment</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Role" required error={errors.role_id}>
                <Select value={form.role_id} onChange={set('role_id')}>
                  <option value="">Select role</option>
                  {roleOptions}
                </Select>
              </Field>
              <Field label="Project site" required error={errors.site_id}>
                <Select value={form.site_id} onChange={set('site_id')}>
                  <option value="">Select site</option>
                  {siteOptions}
                </Select>
              </Field>
              <Field label="Trade / skill">
                <Select value={form.trade_skill} onChange={set('trade_skill')}>
                  <option value="">—</option>
                  {TRADE_SKILLS.map((t) => (
                    <option key={t}>{t}</option>
                  ))}
                </Select>
              </Field>
              <Field label="Date hired" required error={errors.date_hired}>
                <input type="date" className={inputCls} value={form.date_hired} onChange={set('date_hired')} />
              </Field>
              <Field label="Employment status" required>
                <Select value={form.employment_status} onChange={set('employment_status')}>
                  {STATUSES.map((s) => (
                    <option key={s}>{s}</option>
                  ))}
                </Select>
              </Field>
              <Field label="Cost centre" required error={errors.cost_centre} hint="Required before this worker can appear in payroll">
                <Select value={form.cost_centre} onChange={set('cost_centre')}>
                  <option value="">Select cost centre</option>
                  {COST_CENTRES.map((c) => (
                    <option key={c}>{c}</option>
                  ))}
                </Select>
              </Field>
              <Field
                label="Daily rate (PHP)"
                required
                error={errors.daily_rate}
                hint="Region VII minimum is ₱501.00"
              >
                <input type="number" min="501" step="0.01" className={inputCls} value={form.daily_rate} onChange={set('daily_rate')} />
              </Field>
            </div>
          </section>

          <section className="mt-5">
            <h3 className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Contact & government IDs</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Mobile" required error={errors.mobile}>
                <input className={inputCls} value={form.mobile} onChange={set('mobile')} placeholder="+63 9xx xxx xxxx" />
              </Field>
              <Field label="Email">
                <input type="email" className={inputCls} value={form.email} onChange={set('email')} />
              </Field>
              <Field label="Address">
                <input className={inputCls} value={form.address} onChange={set('address')} />
              </Field>
              <Field label="TIN">
                <input className={inputCls} value={form.tin} onChange={set('tin')} placeholder="###-###-###" />
              </Field>
              <Field label="SSS">
                <input className={inputCls} value={form.sss} onChange={set('sss')} />
              </Field>
              <Field label="PhilHealth">
                <input className={inputCls} value={form.philhealth} onChange={set('philhealth')} />
              </Field>
              <Field label="Pag-IBIG">
                <input className={inputCls} value={form.pag_ibig} onChange={set('pag_ibig')} />
              </Field>
            </div>
          </section>

          <section className="mt-5">
            <div className="mb-2.5 flex items-baseline justify-between">
              <h3 className="text-[11px] uppercase tracking-[.1em] text-primary-700">Certifications</h3>
              <button
                type="button"
                onClick={() => setForm((f) => ({ ...f, certification: [...f.certification, emptyCert()] }))}
                className="flex items-center gap-1 text-xs text-primary-700 hover:underline"
              >
                <Icon.plus size={13} />
                Add certification
              </button>
            </div>
            <div className="space-y-2">
              {form.certification.map((cert, index) => (
                <div key={index} className="grid grid-cols-2 gap-2 border border-neutral-300 p-2 sm:grid-cols-5">
                  <Field label="Name">
                    <input className={inputCls} value={cert.name} onChange={setCert(index, 'name')} placeholder="e.g. Scaffolding Erector II" />
                  </Field>
                  <Field label="Issuer">
                    <input className={inputCls} value={cert.issuer} onChange={setCert(index, 'issuer')} placeholder="TESDA" />
                  </Field>
                  <Field label="Cert no.">
                    <input className={inputCls} value={cert.certificate_no} onChange={setCert(index, 'certificate_no')} />
                  </Field>
                  <Field label="Issued">
                    <input type="date" className={inputCls} value={cert.issued_at} onChange={setCert(index, 'issued_at')} />
                  </Field>
                  <div>
                    <div className="flex h-5 justify-between">
                      <span className="text-[13px] text-neutral-700">Expires</span>
                      <button
                        type="button"
                        aria-label="Remove certification"
                        onClick={() =>
                          setForm((f) => ({
                            ...f,
                            certification: f.certification.filter((_, i) => i !== index),
                          }))
                        }
                        className="text-neutral-500 hover:text-[#A83A2C]"
                      >
                        <Icon.close size={13} />
                      </button>
                    </div>
                    <input type="date" className={inputCls} value={cert.expires_at} onChange={setCert(index, 'expires_at')} />
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className="mt-5">
            <h3 className="mb-2.5 text-[11px] uppercase tracking-[.1em] text-primary-700">Emergency contact</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Name">
                <input className={inputCls} value={form.emergency_contact.name} onChange={setContact('name')} />
              </Field>
              <Field label="Mobile">
                <input className={inputCls} value={form.emergency_contact.mobile} onChange={setContact('mobile')} />
              </Field>
              <Field label="Alternate contact">
                <input className={inputCls} value={form.emergency_contact.alternate} onChange={setContact('alternate')} />
              </Field>
              <Field label="Hospital / nearest rel. clinic">
                <input className={inputCls} value={form.emergency_contact.hospital} onChange={setContact('hospital')} />
              </Field>
            </div>
          </section>

          <section className="mt-5 border border-neutral-300 p-3">
            <label className="flex cursor-pointer items-center gap-2.5">
              <input
                type="checkbox"
                checked={form.login_request}
                onChange={(e) => setForm((f) => ({ ...f, login_request: e.target.checked }))}
                disabled={!canLogIn}
                className="h-4 w-4 accent-primary"
              />
              <span className="text-[13px] text-neutral-800">
                Request HRIS login{!canLogIn ? ' — only HR, foremen, engineers, admin and executives sign in.' : ''}
              </span>
            </label>
            {canLogIn && form.login_request ? (
              <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Login email" error={errors.email}>
                  <input type="email" className={inputCls} value={form.email_login} onChange={set('email_login')} placeholder="name@arcenasdev.ph" />
                </Field>
                <Field label="Initial password" hint={isEdit ? 'Leave blank to keep the current password.' : 'Employee sets it at first sign-in.'}>
                  <input type="password" className={inputCls} value={form.password} onChange={set('password')} autoComplete="new-password" />
                </Field>
              </div>
            ) : null}
          </section>

          <div className="mt-5 flex items-center gap-3 border-t border-neutral-300 pt-4">
            <button
              type="submit"
              disabled={busy}
              className="h-11 bg-primary px-6 font-heading font-semibold text-canvas hover:bg-primary-600 disabled:opacity-50"
            >
              {busy ? 'Saving…' : isEdit ? 'Save changes' : 'Add employee'}
            </button>
            <button type="button" onClick={onCancel} className="h-11 border border-neutral-300 px-5 text-ink hover:bg-neutral-200">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}