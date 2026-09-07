import { BrowserRouter, Routes, Route } from 'react-router-dom'

function SignIn() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-canvas px-4">
      <div className="w-full max-w-md rounded-lg border border-neutral-300 bg-surface p-8 shadow-md">
        <h1 className="text-3xl tracking-tight text-ink">HRIS</h1>
        <p className="mt-1 text-sm text-neutral-500">
          Arcenas Development Corporation
        </p>

        <form className="mt-6 space-y-4" onSubmit={(e) => e.preventDefault()}>
          <label className="block text-sm text-neutral-700" htmlFor="email">
            Email
          </label>
          <input
            id="email"
            type="email"
            className="w-full rounded-md border border-neutral-400 bg-canvas px-3 py-2 text-ink"
            placeholder="you@arcenas.ph"
          />
          <label className="block text-sm text-neutral-700" htmlFor="password">
            Password
          </label>
          <input
            id="password"
            type="password"
            className="w-full rounded-md border border-neutral-400 bg-canvas px-3 py-2 text-ink"
            placeholder="••••••••"
          />
          <button
            type="submit"
            className="w-full rounded-md bg-primary px-4 py-2 font-semibold text-canvas hover:bg-primary-600 active:bg-primary-700"
          >
            Sign in
          </button>
        </form>
      </div>
    </main>
  )
}

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<SignIn />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App