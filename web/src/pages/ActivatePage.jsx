import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { http, errorMessage } from '../api/client'
import { Icon } from '../components/icons'
import { ArcenasMark } from '../components/ArcenasMark'

const STRINGS = {
  title: 'Activate your account',
  signInInstead: 'Already activated?',
  signIn: 'Sign in',
  code: 'Employee ID',
  birthday: 'Birthday',
  password: 'New password',
  passwordHint: 'At least 8 characters, not your ID or birthday',
  confirm: 'Confirm new password',
  mismatch: 'The new passwords do not match.',
  activate: 'Activate',
  activating: 'Activating…',
  fallback: 'Unable to activate. Contact HR.',
  rulesTitle: 'Your password must have:',
  rules: ['At least 8 characters', 'Something other than your employee ID', 'Nothing spelling your birthday'],
}

const inputCls = 'h-[44px] w-full border border-neutral-400 bg-canvas px-3 text-ink'

const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

const thisYear = new Date().getFullYear()
const YEARS = Array.from({ length: 56 }, (_, i) => thisYear - 15 - i).reverse()

// Month lengths without a Date object: February's extra day is leap-year
// arithmetic, so no timezone can shift the birthday a day.
const isLeap = (y) => y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0)
const daysIn = (y, m) => [31, isLeap(y) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1]

const pad = (n) => String(n).padStart(2, '0')

export function ActivatePage() {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const [day, setDay] = useState('')
  const [month, setMonth] = useState('')
  const [year, setYear] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [banner, setBanner] = useState(null)
  const [passwordError, setPasswordError] = useState(null)
  const [busy, setBusy] = useState(false)

  const maxDay = day && month && year ? daysIn(Number(year), Number(month)) : 31
  const days = Array.from({ length: maxDay }, (_, i) => i + 1)

  const submit = async (event) => {
    event.preventDefault()
    setBanner(null)
    setPasswordError(null)
    if (password !== confirm) {
      setPasswordError(STRINGS.mismatch)
      return
    }
    // Joined with zero-padding, never through a Date: sending an ISO
    // datetime would hand a UTC+8 birthday back as the previous day.
    const dateOfBirth = `${year}-${pad(month)}-${pad(day)}`
    setBusy(true)
    try {
      await http.post('/auth/activate', {
        employee_code: code.trim(),
        date_of_birth: dateOfBirth,
        password,
      })
      navigate('/login', { replace: true, state: { activated: true, employeeCode: code.trim() } })
    } catch (err) {
      const data = err.response?.data
      // The backend answers password-policy failures on the password field
      // and everything identity-shaped as the one generic message: the
      // banner is the "contact HR" case, the field note is the fixable one.
      if (data?.errors?.password?.[0]) {
        setPasswordError(data.errors.password[0])
      } else {
        setBanner(data?.errors?.employee_code?.[0] ?? errorMessage(err, STRINGS.fallback))
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="grid min-h-screen grid-cols-1 lg:grid-cols-2">
      <div className="flex flex-col justify-between bg-primary-900 p-12 text-canvas">
        <div className="flex items-center gap-3.5">
          <ArcenasMark className="h-10 w-auto flex-none" />
          <div>
            <div className="font-heading text-[19px] tracking-[.04em]">ARCENAS</div>
            <div className="text-[11px] uppercase tracking-[.16em] opacity-60">Development Corporation</div>
          </div>
        </div>

        <div className="max-w-md">
          <div className="mb-3.5 text-[11px] uppercase tracking-[.16em] opacity-60">Worker portal</div>
          <div className="font-heading text-[46px] leading-[1.06]">Your payslips, on any device.</div>
          <p className="mt-5 text-sm leading-relaxed opacity-75">
            Activate with the employee ID HR gave you and your birthday, then choose a password. Use a device only you
            control.
          </p>
        </div>

        <div className="flex items-center gap-3 text-xs opacity-70">
          <span>© 2026 ADC</span>
        </div>
      </div>

      <div className="flex flex-col justify-center bg-canvas px-6 py-12 sm:px-12 lg:px-16">
        <div className="w-full max-w-[400px]">
          <h1 className="mb-1.5 text-[32px]">{STRINGS.title}</h1>
          <p className="mb-7 text-sm text-neutral-700">
            {STRINGS.signInInstead}{' '}
            <Link to="/login" className="text-primary-700 hover:underline">
              {STRINGS.signIn}
            </Link>
          </p>

          {banner ? (
            <div className="mb-5 flex items-start gap-2.5 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
              <Icon.alert className="mt-0.5 flex-none" />
              <span>{banner}</span>
            </div>
          ) : null}

          <form onSubmit={submit} className="space-y-4" noValidate>
            <div>
              <label htmlFor="activate-code" className="mb-1.5 block text-sm text-neutral-700">
                {STRINGS.code}
              </label>
              <input
                id="activate-code"
                type="text"
                autoComplete="username"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
                maxLength={8}
                className={inputCls}
                placeholder="e.g. ADC-0742"
              />
            </div>

            <div>
              <span id="activate-dob-label" className="mb-1.5 block text-sm text-neutral-700">
                {STRINGS.birthday}
              </span>
              <div className="grid grid-cols-3 gap-2" role="group" aria-labelledby="activate-dob-label">
                <select
                  aria-label="Day"
                  value={day}
                  onChange={(e) => setDay(e.target.value)}
                  required
                  className="h-[44px] border border-neutral-400 bg-canvas px-2 text-ink"
                >
                  <option value="">Day</option>
                  {days.map((d) => (
                    <option key={d} value={d}>
                      {d}
                    </option>
                  ))}
                </select>
                <select
                  aria-label="Month"
                  value={month}
                  onChange={(e) => setMonth(e.target.value)}
                  required
                  className="h-[44px] border border-neutral-400 bg-canvas px-2 text-ink"
                >
                  <option value="">Month</option>
                  {MONTHS.map((m, i) => (
                    <option key={m} value={i + 1}>
                      {m}
                    </option>
                  ))}
                </select>
                <select
                  aria-label="Year"
                  value={year}
                  onChange={(e) => setYear(e.target.value)}
                  required
                  className="h-[44px] border border-neutral-400 bg-canvas px-2 text-ink"
                >
                  <option value="">Year</option>
                  {YEARS.map((y) => (
                    <option key={y} value={y}>
                      {y}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="border border-neutral-300 bg-surface p-3 text-[13px] text-neutral-700">
              <div className="mb-1 text-[11px] uppercase tracking-[.1em]">{STRINGS.rulesTitle}</div>
              <ul className="list-disc pl-5">
                {STRINGS.rules.map((rule) => (
                  <li key={rule}>{rule}</li>
                ))}
              </ul>
            </div>

            <div>
              <label htmlFor="activate-password" className="mb-1.5 block text-sm text-neutral-700">
                {STRINGS.password}
              </label>
              <input
                id="activate-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                className={inputCls}
                placeholder={STRINGS.passwordHint}
              />
              {passwordError ? <p className="mt-1.5 text-[13px] text-[#75261C]">{passwordError}</p> : null}
            </div>

            <div>
              <label htmlFor="activate-confirm" className="mb-1.5 block text-sm text-neutral-700">
                {STRINGS.confirm}
              </label>
              <input
                id="activate-confirm"
                type="password"
                autoComplete="new-password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
                className={inputCls}
              />
            </div>

            <button
              type="submit"
              disabled={busy}
              className="h-12 w-full bg-primary px-4 font-heading text-base font-semibold text-canvas hover:bg-primary-600 active:bg-primary-700 disabled:opacity-50"
            >
              {busy ? STRINGS.activating : STRINGS.activate}
            </button>
          </form>
        </div>
      </div>
    </main>
  )
}
