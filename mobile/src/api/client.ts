/**
 * axios client for the Laravel API. Unlike web/ (same-origin via Vite's
 * proxy), the mobile app talks to the backend over the network directly, so
 * there's no proxy to hide the host behind. The emulator's loopback alias
 * (10.0.2.2) is the out-of-the-box default; on a physical device the login
 * screen's Server field overrides it via setAndPersistApiBaseUrl, and the
 * value is re-applied at every launch (loadApiBaseUrl).
 *
 * The release build allows cleartext HTTP (HTTPS on the LAN is deferred) via
 * android network_security_config.xml — see that file for the scope decision
 * and how to tighten it once the server address is fixed.
 *
 * @format
 */

import axios from 'axios';
import {Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

// 8090, not Laravel's default 8000: on the dev machine port 8000 is held by a
// Windows svchost service, so `php artisan serve` auto-increments to 8001+ and
// the app silently can't find it. backend/.env pins SERVER_PORT=8090 to match.
// 10.0.2.2 is the Android emulator's alias for the host loopback; a physical
// device overrides it from the login screen (see below).
const API_PORT = 8090;

const DEFAULT_BASE_URL = Platform.select({
  android: `http://10.0.2.2:${API_PORT}/api`,
  default: `http://127.0.0.1:${API_PORT}/api`,
});

const TOKEN_STORAGE_KEY = 'hris.auth.token';
const BASE_URL_STORAGE_KEY = 'hris.api.baseUrl';

// The whole Laravel API lives under /api; the quick tunnel and any LAN host
// serve it there too. Users type a host, not a path — keep adding the suffix.
const API_PATH = '/api';

export const apiClient = axios.create({
  baseURL: DEFAULT_BASE_URL,
  timeout: 8000,
});

export function setApiBaseUrl(url: string): void {
  apiClient.defaults.baseURL = normalizeBaseUrl(url);
}

/**
 * Normalise a user-typed server address: trim, drop trailing slashes, default
 * to http:// (the LAN is plaintext until HTTPS is landed) and make sure it
 * ends in /api so every request lands under the Laravel API route prefix.
 */
export function normalizeBaseUrl(url: string): string {
  let normalized = url.trim().replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(normalized)) {
    normalized = `http://${normalized}`;
  }
  if (!/\/api$/i.test(normalized)) {
    normalized = `${normalized}${API_PATH}`;
  }
  return normalized;
}

/** True when the string parses as a bare http(s) URL after normalising. */
export function isValidBaseUrl(url: string): boolean {
  // The host part may not contain whitespace, and must not start with a
  // boundary char; the [^\s/$.?#] opener and [^\s]* tail reject space-laden
  // "addresses" like "not a url" (which normalise to "http://not a url/api").
  return /^https?:\/\/[^\s/$.?#][^\s]*$/i.test(normalizeBaseUrl(url));
}

/** Apply a server address now and persist it for the next launch. */
export async function setAndPersistApiBaseUrl(url: string): Promise<string> {
  const normalized = normalizeBaseUrl(url);
  apiClient.defaults.baseURL = normalized;
  await AsyncStorage.setItem(BASE_URL_STORAGE_KEY, normalized);
  return normalized;
}

/** Load the persisted server address; call once at startup, before login. */
export async function loadApiBaseUrl(): Promise<string> {
  const persisted = await AsyncStorage.getItem(BASE_URL_STORAGE_KEY);
  return persisted ? normalizeBaseUrl(persisted) : DEFAULT_BASE_URL;
}

export {DEFAULT_BASE_URL};

apiClient.interceptors.request.use(async config => {
  const token = await AsyncStorage.getItem(TOKEN_STORAGE_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export async function persistToken(token: string): Promise<void> {
  await AsyncStorage.setItem(TOKEN_STORAGE_KEY, token);
}

export async function clearToken(): Promise<void> {
  await AsyncStorage.removeItem(TOKEN_STORAGE_KEY);
}

export async function getPersistedToken(): Promise<string | null> {
  return AsyncStorage.getItem(TOKEN_STORAGE_KEY);
}
