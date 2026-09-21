import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { http, errorMessage } from '../api/client'
import { Icon } from '../components/icons'
import { ArcenasMark } from '../components/ArcenasMark'

const inputCls = 'h-[44px] w-full border border-neutral-400 bg-canvas px-3 text-ink'

export function ActivatePage() {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const [dob, setDob] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setError(null)
    if (password !== confirm) {
      setError('The new passwords do not match.')
      return
    }
    setBusy(true)
    try {
      // The birthday travels as a plain Y-m-d calendar date: sending an ISO
      // datetime would hand a UTC+8 birthday back as the previous day.
      await http.post('/auth/activate', {
        employee_code: code.trim(),
        date_of_birth: dob,
        password,
      })
      navigate('/login', { replace: true, state: { activated: true } })
    } catch (err) {
      const data = err.response?.data
      setError(
        data?.errors?.employee_code?.[0] ??
          data?.errors?.password?.[0] ??
          errorMessage(err, 'Unable to activate. Contact HR.'),
      )
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
          <h1 className="mb-1.5 text-[32px]">Activate your account</h1>
          <p className="mb-7 text-sm text-neutral-700">
            Already activated?{' '}
            <Link to="/login" className="text-primary-700 hover:underline">
              Sign in
            </Link>
          </p>

          {error ? (
            <div className="mb-5 flex items-start gap-2.5 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
              <Icon.alert className="mt-0.5 flex-none" />
              <span>{error}</span>
            </div>
          ) : null}

          <form onSubmit={submit} className="space-y-4" noValidate>
            <div>
              <label htmlFor="activate-code" className="mb-1.5 block text-sm text-neutral-700">
                Employee ID
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
              <label htmlFor="activate-dob" className="mb-1.5 block text-sm text-neutral-700">
                Birthday
              </label>
              <input
                id="activate-dob"
                type="date"
                value={dob}
                onChange={(e) => setDob(e.target.value)}
                required
                className={inputCls}
              />
            </div>

            <div>
              <label htmlFor="activate-password" className="mb-1.5 block text-sm text-neutral-700">
                New password
              </label>
              <input
                id="activate-password"
                type="password"
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                className={inputCls}
                placeholder="At least 8 characters, not your ID or birthday"
              />
            </div>

            <div>
              <label htmlFor="activate-confirm" className="mb-1.5 block text-sm text-neutral-700">
                Confirm new password
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
              {busy ? 'Activating…' : 'Activate'}
            </button>
          </form>
        </div>
      </div>
    </main>
  )
}
