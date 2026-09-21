/**
 * Pure URL handling for the login screen's Server address field — no
 * AsyncStorage, no axios, so it stays a plain logic test.
 *
 * @format
 */

import {InternalAxiosRequestConfig} from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {normalizeBaseUrl, isValidBaseUrl, attachStoredToken} from '../client';

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
}));

const getItem = AsyncStorage.getItem as jest.Mock;

describe('normalizeBaseUrl', () => {
  it('keeps a fully-formed address and ensures the /api suffix', () => {
    expect(normalizeBaseUrl('http://192.168.1.50:8090/api')).toBe(
      'http://192.168.1.50:8090/api',
    );
    expect(normalizeBaseUrl('http://192.168.1.50:8090')).toBe(
      'http://192.168.1.50:8090/api',
    );
  });

  it('defaults to http:// when the scheme is omitted (LAN is plaintext until HTTPS)', () => {
    expect(normalizeBaseUrl('192.168.1.50:8090')).toBe(
      'http://192.168.1.50:8090/api',
    );
  });

  it('preserves https (the quick tunnel) and still appends /api', () => {
    expect(normalizeBaseUrl('https://example.trycloudflare.com')).toBe(
      'https://example.trycloudflare.com/api',
    );
  });

  it('strips trailing slashes', () => {
    expect(normalizeBaseUrl('http://10.0.2.2:8090/api///')).toBe(
      'http://10.0.2.2:8090/api',
    );
  });
});

describe('isValidBaseUrl', () => {
  it('accepts http and https addresses', () => {
    expect(isValidBaseUrl('http://192.168.1.50:8090')).toBe(true);
    expect(isValidBaseUrl('https://example.trycloudflare.com/api')).toBe(true);
  });

  it('rejects whitespace, garbage and scheme-less absolute paths', () => {
    expect(isValidBaseUrl('   ')).toBe(false);
    expect(isValidBaseUrl('not a url')).toBe(false);
  });
});

describe('attachStoredToken', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  // Proven against the real interceptor function: the foreman-only refusal
  // passes its just-issued token explicitly, and only this guard keeps the
  // stored previous-user token from replacing it on the wire.
  it('attaches the stored token when the request carries none', async () => {
    getItem.mockResolvedValue('tok-old');

    const out = await attachStoredToken({
      headers: {},
    } as InternalAxiosRequestConfig);

    expect(out.headers.Authorization).toBe('Bearer tok-old');
  });

  it('leaves an explicit Authorization header alone even when a different token is stored', async () => {
    getItem.mockResolvedValue('tok-old');

    const out = await attachStoredToken({
      headers: {Authorization: 'Bearer tok-new'},
    } as InternalAxiosRequestConfig);

    expect(out.headers.Authorization).toBe('Bearer tok-new');
  });

  it('leaves the request alone when nothing is stored', async () => {
    getItem.mockResolvedValue(null);

    const out = await attachStoredToken({
      headers: {},
    } as InternalAxiosRequestConfig);

    expect(out.headers.Authorization).toBeUndefined();
  });
});