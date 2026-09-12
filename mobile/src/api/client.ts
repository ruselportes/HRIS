/**
 * axios client for the Laravel API. Unlike web/ (same-origin via Vite's
 * proxy), the mobile app talks to the backend over the network directly, so
 * there's no proxy to hide the host behind — Android's emulator loopback
 * (10.0.2.2) is used as a dev default; override via setApiBaseUrl for a
 * physical device on the same LAN, or wire up a real config layer if this
 * needs to vary by build (out of Phase 4's scope).
 *
 * @format
 */

import axios from 'axios';
import {Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

// 8090, not Laravel's default 8000: on the dev machine port 8000 is held by a
// Windows svchost service, so `php artisan serve` auto-increments to 8001+ and
// the app silently can't find it. backend/.env pins SERVER_PORT=8090 to match.
// 10.0.2.2 is the Android emulator's alias for the host loopback; use
// setApiBaseUrl() for a physical device on the LAN.
const API_PORT = 8090;

const DEFAULT_BASE_URL = Platform.select({
  android: `http://10.0.2.2:${API_PORT}/api`,
  default: `http://127.0.0.1:${API_PORT}/api`,
});

const TOKEN_STORAGE_KEY = 'hris.auth.token';

export const apiClient = axios.create({
  baseURL: DEFAULT_BASE_URL,
  timeout: 8000,
});

export function setApiBaseUrl(url: string): void {
  apiClient.defaults.baseURL = url;
}

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
