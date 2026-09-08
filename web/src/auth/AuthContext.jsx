import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { configureClient, http } from '../api/client'

const TOKEN_KEY = 'hris.token'
const USER_KEY = 'hris.user'

const AuthContext = createContext(null)

function readStorage() {
  for (const store of [localStorage, sessionStorage]) {
    try {
      const token = store.getItem(TOKEN_KEY)
      const user = store.getItem(USER_KEY)
      if (token && user) return { token, user: JSON.parse(user), keep: store === localStorage }
    } catch {
      // corrupted entry — ignore and fall through
    }
  }
  return null
}

function writeStorage(token, user, keep) {
  const store = keep ? localStorage : sessionStorage
  try {
    store.setItem(TOKEN_KEY, token)
    store.setItem(USER_KEY, JSON.stringify(user))
    if (keep) sessionStorage.removeItem(TOKEN_KEY)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    // storage full/blocked — session survives in memory only
  }
}

function clearStorage() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
  sessionStorage.removeItem(TOKEN_KEY)
  sessionStorage.removeItem(USER_KEY)
}

export function AuthProvider({ children }) {
  const [auth, setAuth] = useState(() => readStorage())

  useEffect(
    () =>
      configureClient({
        getToken: () => auth?.token ?? null,
        onUnauthorized: () => setAuth(null),
      }),
    [auth?.token],
  )

  const login = useCallback(async (identifier, password, keep) => {
    const { data } = await http.post('/auth/login', { identifier, password })
    const session = { token: data.token, user: data.user }
    setAuth(session)
    writeStorage(data.token, data.user, keep)
    return session.user
  }, [])

  const logout = useCallback(async () => {
    try {
      await http.post('/auth/logout')
    } catch {
      // token already invalid — clear locally regardless
    }
    clearStorage()
    setAuth(null)
  }, [])

  const requestReset = useCallback(async (employeeCode) => {
    await http.post('/auth/forgot-password', { employee_code: employeeCode })
  }, [])

  const value = useMemo(
    () => ({ auth, user: auth?.user ?? null, signIn: login, signOut: logout, requestReset }),
    [auth, login, logout, requestReset],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  return useContext(AuthContext)
}