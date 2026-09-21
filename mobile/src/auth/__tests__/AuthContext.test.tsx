/**
 * Mobile session restore (Phase 4 note, found 2026-09-21). Two bugs lived in
 * the launch effect: any error — including no network — cleared the saved
 * token, signing a foreman out on every offline cold start (FR-05); and the
 * restore saved the whole `/auth/me` body as the user while the endpoint
 * wraps it as {user}, so names read "undefined undefined" and drafts keyed
 * under `undefined` after an online restart.
 *
 * @format
 */

import React from 'react';
import ReactTestRenderer, {act} from 'react-test-renderer';
import {
  apiClient,
  clearToken,
  clearUser,
  getPersistedToken,
  getPersistedUser,
  persistToken,
  persistUser,
} from '../../api/client';
import {AuthProvider, useAuth, FOREMAN_ONLY_REFUSAL} from '../AuthContext';

jest.mock('../../api/client', () => ({
  apiClient: {get: jest.fn(), post: jest.fn()},
  persistToken: jest.fn(),
  getPersistedToken: jest.fn(),
  clearToken: jest.fn(),
  persistUser: jest.fn(),
  getPersistedUser: jest.fn(),
  clearUser: jest.fn(),
}));

const get = apiClient.get as jest.Mock;
const post = apiClient.post as jest.Mock;
const mockGetToken = getPersistedToken as jest.Mock;
const mockGetUser = getPersistedUser as jest.Mock;

const USER = {
  employee_id: 7,
  employee_code: 'ADC-0007',
  first_name: 'Ronel',
  last_name: 'Dela Cruz',
  role: {slug: 'foreman', role_name: 'Site Foreman'},
};

const rejection = (status: number) => ({isAxiosError: true, response: {status}});

type Seen = ReturnType<typeof useAuth> | null;

async function renderAuth(): Promise<() => Seen> {
  let seen: Seen = null;
  function Probe() {
    seen = useAuth();
    return null;
  }
  await act(async () => {
    ReactTestRenderer.create(
      <AuthProvider>
        <Probe />
      </AuthProvider>,
    );
  });
  for (let i = 0; i < 20 && !isReady(); i += 1) {
    await act(async () => {});
  }
  return () => seen;

  // Read through a function: `seen` is assigned inside the probe callback,
  // so direct access here would keep its initial `null` narrowing.
  function isReady(): boolean {
    return !!seen?.ready;
  }
}

beforeEach(() => {
  jest.clearAllMocks();
  mockGetToken.mockResolvedValue(null);
  mockGetUser.mockResolvedValue(null);
});

describe('launch restore', () => {
  it('unwraps {user} from /auth/me and persists the copy', async () => {
    mockGetToken.mockResolvedValue('tok-1');
    get.mockResolvedValue({data: {user: USER}});

    const seen = await renderAuth();

    expect(seen()?.user).toEqual(USER);
    expect(persistUser).toHaveBeenCalledWith(USER);
    expect(clearToken).not.toHaveBeenCalled();
  });

  it.each([401, 403])('clears the saved sign-in on %i', async status => {
    mockGetToken.mockResolvedValue('tok-1');
    mockGetUser.mockResolvedValue(USER);
    get.mockRejectedValue(rejection(status));

    const seen = await renderAuth();

    expect(seen()?.user).toBeNull();
    expect(clearToken).toHaveBeenCalledTimes(1);
    expect(clearUser).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['network error', new Error('Network Error')],
    ['server 500', rejection(500)],
    ['rate limit 429', rejection(429)],
    ['timeout', {isAxiosError: true, code: 'ECONNABORTED', request: {}}],
  ])('keeps the saved sign-in on %s', async (_label, err) => {
    mockGetToken.mockResolvedValue('tok-1');
    mockGetUser.mockResolvedValue(USER);
    get.mockRejectedValue(err);

    const seen = await renderAuth();

    // The token is only checked when the phone reaches the server: the app
    // opens offline from the saved copy, drafts under the right key.
    expect(seen()?.user).toEqual(USER);
    expect(clearToken).not.toHaveBeenCalled();
    expect(clearUser).not.toHaveBeenCalled();
  });

  it('stays signed out but keeps the token when nothing is saved and the server is unreachable', async () => {
    mockGetToken.mockResolvedValue('tok-1');
    get.mockRejectedValue(new Error('Network Error'));

    const seen = await renderAuth();

    expect(seen()?.user).toBeNull();
    expect(clearToken).not.toHaveBeenCalled();
  });

  it.each(['worker', 'operator'])(
    'drops a saved %s session on restore instead of opening from it',
    async slug => {
      const saved = {...USER, role: {slug, role_name: 'Field'}};
      mockGetToken.mockResolvedValue('tok-1');
      get.mockResolvedValue({data: {user: saved}});

      const seen = await renderAuth();

      expect(seen()?.user).toBeNull();
      expect(clearToken).toHaveBeenCalledTimes(1);
      expect(clearUser).toHaveBeenCalledTimes(1);
      expect(persistUser).not.toHaveBeenCalled();
    },
  );

  it('drops a saved non-foreman session even when the server is unreachable', async () => {
    mockGetToken.mockResolvedValue('tok-1');
    mockGetUser.mockResolvedValue({
      ...USER,
      role: {slug: 'worker', role_name: 'Field'},
    });
    get.mockRejectedValue(new Error('Network Error'));

    const seen = await renderAuth();

    expect(seen()?.user).toBeNull();
    expect(clearToken).toHaveBeenCalledTimes(1);
    expect(clearUser).toHaveBeenCalledTimes(1);
  });
});

describe('signIn / signOut', () => {
  it('sends client mobile and persists token and user together', async () => {
    post.mockResolvedValue({data: {token: 'tok-2', user: USER}});
    mockGetToken.mockResolvedValue(null);

    const seen = await renderAuth();
    let user: unknown;
    await act(async () => {
      user = await seen()?.signIn('ADC-0007', 'password');
    });

    expect(post).toHaveBeenCalledWith('/auth/login', {
      identifier: 'ADC-0007',
      password: 'password',
      client: 'mobile',
    });
    expect(persistToken).toHaveBeenCalledWith('tok-2');
    expect(persistUser).toHaveBeenCalledWith(USER);
    expect(user).toEqual(USER);
    expect(seen()?.user).toEqual(USER);
  });

  it('clears both token and user on sign-out', async () => {
    post.mockResolvedValue({});
    mockGetToken.mockResolvedValue('tok-1');
    mockGetUser.mockResolvedValue(USER);
    get.mockResolvedValue({data: {user: USER}});

    const seen = await renderAuth();
    await act(async () => {
      await seen()?.signOut();
    });

    expect(clearToken).toHaveBeenCalled();
    expect(clearUser).toHaveBeenCalled();
    expect(seen()?.user).toBeNull();
  });

it.each(['worker', 'operator', 'hr', 'admin', 'executive'])(
    'refuses a %s sign-in, revoking the just-issued token first',
    async slug => {
      const portalUser = {...USER, role: {slug, role_name: 'Field'}};
      post.mockImplementation((url: string, _body?: unknown, config?: {headers?: object}) => {
        if (url === '/auth/login') {
          return Promise.resolve({data: {token: 'tok-portal', user: portalUser}});
        }
        return Promise.resolve({data: {message: 'Signed out.'}});
      });
      mockGetToken.mockResolvedValue(null);

      const seen = await renderAuth();
      await act(async () => {
        await expect(seen()?.signIn('ADC-9999', 'password')).rejects.toThrow(FOREMAN_ONLY_REFUSAL);
      });

      // Revoked explicitly as the bearer: the token was never persisted, so
      // the interceptor had nothing to attach — and no live token may linger
      // on the phone. (That the explicit header actually survives the real
      // interceptor is proven in client.test.ts, not here: asserting on these
      // mocked post() arguments alone would pass while the real request went
      // out with the wrong token.)
      expect(post).toHaveBeenCalledWith('/auth/logout', undefined, {
        headers: {Authorization: 'Bearer tok-portal'},
      });
      expect(persistToken).not.toHaveBeenCalled();
      expect(persistUser).not.toHaveBeenCalled();
      expect(seen()?.user).toBeNull();
    },
  );
});
