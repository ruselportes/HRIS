import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { errorMessage } from '../api/client'

export function ForgotPasswordPage() {
  const { requestReset } = useAuth()
  const [employeeCode, setEmployeeCode] = useState('')
  const [state, setState] = useState({ stage: 'form', error: null })

  const submit = async (event) => {
    event.preventDefault()
    setState({ stage: 'form', error: null })
    try {
      await requestReset(employeeCode.trim())
      setState({ stage: 'done', error: null })
    } catch (err) {
      setState({ stage: 'form', error: errorMessage(err, 'Unable to submit the request. Try again.') })
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-canvas px-4">
      <div className="w-full max-w-[460px] border border-neutral-300 bg-surface p-8 shadow-md">
        <div className="mb-1 text-[11px] uppercase tracking-[.1em] text-primary-700">Forgot password</div>
        <h1 className="text-[32px]">Reset request</h1>
        <p className="mt-2 text-[13px] leading-relaxed text-neutral-700">
          Enter your Employee ID. HR receives the reset request — no self-serve reset link, since most field workers
          have no company email.
        </p>

        {state.error ? (
          <div className="mt-4 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
            {state.error}
          </div>
        ) : null}

        {state.stage === 'done' ? (
          <div className="mt-5 border border-[#2F7A4D] bg-[#E6F1EA] p-4 text-[13px] text-[#1F5334]">
            If that Employee ID exists, HR has received your request. They will reach out with next steps — usually
            within one working day.
          </div>
        ) : (
          <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
            <div>
              <label htmlFor="employee_code" className="mb-1.5 block text-sm text-neutral-700">
                Employee ID
              </label>
              <input
                id="employee_code"
                type="text"
                value={employeeCode}
                onChange={(e) => setEmployeeCode(e.target.value)}
                required
                placeholder="ADC-0000"
                className="h-11 w-full border border-neutral-400 bg-canvas px-3 text-ink"
              />
            </div>
            <button
              type="submit"
              className="h-11 bg-primary px-5 font-heading font-semibold text-canvas hover:bg-primary-600 active:bg-primary-700"
            >
              Request reset
            </button>
          </form>
        )}

        <div className="mt-6 border-t border-neutral-200 pt-4 text-[13px]">
          <Link to="/login" className="text-primary-700 hover:underline">
            ← Back to sign in
          </Link>
        </div>
      </div>
    </main>
  )
}