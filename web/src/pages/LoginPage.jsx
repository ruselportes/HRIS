import { useState } from 'react'
import { Link, useNavigate, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { errorMessage } from '../api/client'
import { Icon } from '../components/icons'

export function LoginPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = location.state?.from?.pathname ?? '/'

  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [keep, setKeep] = useState(false)
  const [show, setShow] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await signIn(identifier.trim(), password, keep)
      navigate(from, { replace: true })
    } catch (err) {
      setError(errorMessage(err, 'Unable to sign in. Check your connection and try again.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="grid min-h-screen grid-cols-1 lg:grid-cols-2">
      <div className="flex flex-col justify-between bg-primary-900 p-12 text-canvas">
        <div className="flex items-center gap-3.5">
          <span className="grid h-10 w-10 place-items-center border border-canvas/50 font-heading text-lg">
            A
          </span>
          <div>
            <div className="font-heading text-[19px] tracking-[.04em]">ARCENAS</div>
            <div className="text-[11px] uppercase tracking-[.16em] opacity-60">Development Corporation</div>
          </div>
        </div>

        <div className="max-w-md">
          <div className="mb-3.5 text-[11px] uppercase tracking-[.16em] opacity-60">
            Human Resource Information System
          </div>
          <div className="font-heading text-[46px] leading-[1.06]">One record of every worker, on every site.</div>
          <p className="mt-5 text-sm leading-relaxed opacity-75">
            Attendance, payroll and compliance for eleven project sites in Cebu and Bohol. The field app keeps working
            when the signal does not.
          </p>
        </div>

        <div className="flex items-center gap-3 text-xs opacity-70">
          <span className="h-[7px] w-[7px] rounded-full bg-[#6FBF8E]" />
          <span>Server reachable · v2.4.1</span>
          <span className="ml-auto">© 2026 ADC</span>
        </div>
      </div>

      <div className="flex flex-col justify-center bg-canvas px-6 py-12 sm:px-12 lg:px-16">
        <div className="w-full max-w-[400px]">
          <h1 className="mb-1.5 text-[32px]">Sign in</h1>
          <p className="mb-7 text-sm text-neutral-700">
            Use the credentials issued by HR. Accounts are created by an administrator.
          </p>

          {error ? (
            <div className="mb-5 flex items-start gap-2.5 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
              <Icon.alert className="mt-0.5 flex-none" />
              <span>{error}</span>
            </div>
          ) : null}

          <form onSubmit={submit} className="space-y-4" noValidate>
            <div>
              <label htmlFor="identifier" className="mb-1.5 block text-sm text-neutral-700">
                Email or Employee ID
              </label>
              <input
                id="identifier"
                type="text"
                autoComplete="username"
                value={identifier}
                onChange={(e) => setIdentifier(e.target.value)}
                required
                className={`h-[44px] w-full border bg-canvas px-3 text-ink ${
                  error ? 'border-[#A83A2C]' : 'border-neutral-400'
                }`}
                placeholder="e.g. mreyes@arcenasdev.ph or ADC-0002"
              />
            </div>

            <div>
              <label htmlFor="password" className="mb-1.5 block text-sm text-neutral-700">
                Password
              </label>
              <div className="flex h-[44px] items-center gap-2.5 border border-neutral-400 bg-canvas px-3">
                <input
                  id="password"
                  type={show ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  className="min-w-0 flex-1 bg-transparent text-ink outline-none"
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShow((s) => !s)}
                  className="border border-neutral-300 px-2 py-1 text-xs text-ink hover:bg-neutral-200"
                >
                  {show ? 'Hide' : 'Show'}
                </button>
              </div>
            </div>

            <div className="flex items-center justify-between pb-1.5">
              <label className="flex cursor-pointer select-none items-center gap-2 text-sm text-ink">
                <input
                  type="checkbox"
                  checked={keep}
                  onChange={(e) => setKeep(e.target.checked)}
                  className="h-4 w-4 accent-primary"
                />
                Keep me signed in
              </label>
              <Link to="/forgot-password" className="text-[13px] text-primary-700 hover:underline">
                Forgot password
              </Link>
            </div>

            <button
              type="submit"
              disabled={busy}
              className="h-12 w-full bg-primary px-4 font-heading text-base font-semibold text-canvas hover:bg-primary-600 active:bg-primary-700 disabled:opacity-50"
            >
              {busy ? 'Signing in…' : 'Sign in'}
            </button>
          </form>

          <div className="mt-6 flex items-start gap-3 border-t border-neutral-200 pt-4">
            <Icon.info className="mt-0.5 flex-none text-primary-700" size={17} />
            <p className="text-xs text-neutral-700">
              No account? HRIS access is provisioned by an administrator. Contact HR at hr@arcenasdev.ph or local
              214.
            </p>
          </div>
        </div>
      </div>
    </main>
  )
}