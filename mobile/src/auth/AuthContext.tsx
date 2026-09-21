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
import axios from 'axios';
import {
  apiClient,
  clearToken,
  clearUser,
  getPersistedToken,
  getPersistedUser,
  persistToken,
  persistUser,
} from '../api/client';

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

/** Shown when anyone but a foreman reaches the foreman app. */
export const FOREMAN_ONLY_REFUSAL =
  'This app is for site foremen. Please use the HRIS website.';

const AuthContext = createContext<AuthContextValue | null>(null);

/** Only the server rejecting the token ends the session (401/403). */
function isAuthRejection(err: unknown): boolean {
  return (
    axios.isAxiosError(err) &&
    (err.response?.status === 401 || err.response?.status === 403)
  );
}

function looksLikeUser(value: unknown): value is EmployeeUser {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as EmployeeUser).employee_id === 'number'
  );
}

/**
 * Foreman-only (W3): acting foremen always carry the foreman role, so no
 * legitimate user is locked out — and every other role gets nothing but 403s
 * from this app's endpoints.
 */
function isForeman(user: EmployeeUser): boolean {
  return user?.role?.slug === 'foreman';
}

export function AuthProvider({children}: {children: React.ReactNode}) {
  const [auth, setAuth] = useState<AuthState | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    (async () => {
      const token = await getPersistedToken();
      if (token) {
        try {
          const {data} = await apiClient.get('/auth/me');
          // `me` wraps the record as {user} — the whole body is not the user.
          // Saving it whole is what showed "undefined undefined" after an
          // online restart and keyed drafts under `undefined`.
          const user = (data as {user?: unknown} | null)?.user;
          if (looksLikeUser(user)) {
            if (!isForeman(user)) {
              // A session saved before the foreman-only rule (or on a shared
              // phone) must not linger: drop it the same way a 401 does.
              await clearToken();
              await clearUser();
            } else {
              await persistUser(user);
              setAuth({token, user});
            }
          } else {
            await restoreSaved(token);
          }
        } catch (err) {
          if (isAuthRejection(err)) {
            await clearToken();
            await clearUser();
          } else {
            // No network, 500, 429, timeout — the token is only checked when
            // the phone reaches the server, so the saved sign-in stays and
            // the app opens offline from it.
            await restoreSaved(token);
          }
        }
      }
      setReady(true);
    })();

    async function restoreSaved(token: string): Promise<void> {
      const user = await getPersistedUser<unknown>();
      if (!looksLikeUser(user)) {
        return;
      }
      if (!isForeman(user)) {
        await clearToken();
        await clearUser();
        return;
      }
      setAuth({token, user});
    }
  }, []);

  const signIn = useCallback(async (identifier: string, password: string) => {
    // The server decides the lifetime from role and client: a foreman here
    // gets the 30-day app token. Must ship in the same APK as this restore
    // fix — an older app sends no client and would take a 12-hour token.
    const {data} = await apiClient.post('/auth/login', {
      identifier,
      password,
      client: 'mobile',
    });
    const user = data.user as EmployeeUser;
    if (!isForeman(user)) {
      // Refuse before persisting anything, but revoke the just-issued token
      // first — passed explicitly as the bearer, and the interceptor leaves
      // explicit headers alone, so the previous user's stored token can never
      // be signed out instead. A live token on a shared phone is exactly what
      // must not linger.
      await apiClient.post('/auth/logout', undefined, {
        headers: {Authorization: `Bearer ${data.token}`},
      });
      throw new Error(FOREMAN_ONLY_REFUSAL);
    }
    await persistToken(data.token);
    await persistUser(user);
    setAuth({token: data.token, user});
    return user;
  }, []);

  const signOut = useCallback(async () => {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // token already invalid server-side — clear locally regardless
    }
    await clearToken();
    await clearUser();
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
