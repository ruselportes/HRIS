/**
 * Mirrors web/src/auth/AuthContext.jsx's contract (POST /auth/login ->
 * {token, user}) so the two clients stay behaviorally consistent — same
 * bearer-token design decided in Phase 2, reused here per the original
 * "mobile needs API auth too" rationale.
 *
 * @format
 */

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';
import {apiClient, clearToken, getPersistedToken, persistToken} from '../api/client';

interface EmployeeUser {
  employee_id: number;
  employee_code: string;
  first_name: string;
  last_name: string;
  role?: {slug: string; role_name: string};
}

interface AuthState {
  token: string;
  user: EmployeeUser;
}

interface AuthContextValue {
  ready: boolean;
  user: EmployeeUser | null;
  signIn: (identifier: string, password: string) => Promise<EmployeeUser>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({children}: {children: React.ReactNode}) {
  const [auth, setAuth] = useState<AuthState | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    (async () => {
      const token = await getPersistedToken();
      if (token) {
        try {
          const {data} = await apiClient.get('/auth/me');
          setAuth({token, user: data});
        } catch {
          await clearToken();
        }
      }
      setReady(true);
    })();
  }, []);

  const signIn = useCallback(async (identifier: string, password: string) => {
    const {data} = await apiClient.post('/auth/login', {identifier, password});
    await persistToken(data.token);
    setAuth({token: data.token, user: data.user});
    return data.user as EmployeeUser;
  }, []);

  const signOut = useCallback(async () => {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // token already invalid server-side — clear locally regardless
    }
    await clearToken();
    setAuth(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ready, user: auth?.user ?? null, signIn, signOut}),
    [ready, auth, signIn, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
}
