import { useState } from 'react'
import { http, errorMessage } from '../../api/client'
import { Icon } from '../../components/icons'

const inputCls = 'h-[44px] w-full border border-neutral-400 bg-canvas px-3 text-ink'

export function PortalAccountPage() {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState(null)
  const [done, setDone] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async (event) => {
    event.preventDefault()
    setError(null)
    setDone(false)
    setBusy(true)
    try {
      await http.post('/auth/password', {
        current_password: current,
        new_password: next,
        new_password_confirmation: confirm,
      })
      setDone(true)
      setCurrent('')
      setNext('')
      setConfirm('')
    } catch (err) {
      const data = err.response?.data
      setError(
        data?.errors?.current_password?.[0] ??
          data?.errors?.new_password?.[0] ??
          errorMessage(err, 'Unable to change your password.'),
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full flex-col">
      <div className="border-b border-neutral-300 px-[22px] py-3.5">
        <div className="font-heading text-[22px] leading-tight">Change password</div>
        <div className="truncate text-[11px] text-neutral-700">Every other session signs out when you change it</div>
      </div>

      <div className="w-full max-w-[400px] px-[22px] py-5">
        {error ? (
          <div className="mb-5 flex items-start gap-2.5 border-l-[3px] border-[#A83A2C] bg-[#F7E8E5] p-2.5 text-[13px] text-[#75261C]">
            <Icon.alert className="mt-0.5 flex-none" />
            <span>{error}</span>
          </div>
        ) : null}
        {done ? (
          <div className="mb-5 border-l-[3px] border-[#2F7A4D] bg-[#E6F1EA] p-2.5 text-[13px] text-[#1F5334]">
            Password updated. Other devices now ask you to sign in again.
          </div>
        ) : null}

        <form onSubmit={submit} className="space-y-4" noValidate>
          <div>
            <label htmlFor="portal-current" className="mb-1.5 block text-sm text-neutral-700">
              Current password
            </label>
            <input
              id="portal-current"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              required
              className={inputCls}
            />
          </div>
          <div>
            <label htmlFor="portal-next" className="mb-1.5 block text-sm text-neutral-700">
              New password
            </label>
            <input
              id="portal-next"
              type="password"
              autoComplete="new-password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              required
              minLength={8}
              className={inputCls}
            />
          </div>
          <div>
            <label htmlFor="portal-confirm" className="mb-1.5 block text-sm text-neutral-700">
              Confirm new password
            </label>
            <input
              id="portal-confirm"
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
            {busy ? 'Updating…' : 'Update password'}
          </button>
        </form>
      </div>
    </div>
  )
}
