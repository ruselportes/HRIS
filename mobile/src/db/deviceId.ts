/**
 * PLACEHOLDER device identity for the sync queue's device_id column.
 * This is NOT the Phase 5 hardware-backed device binding (Android Keystore
 * TEE / iOS Secure Enclave) — that's a separate, dedicated screen and flow
 * (docs/prototypes/HRIS Foreman Device Binding.dc.html) with real key
 * generation. This just gives Phase 4's queue rows a stable-per-install
 * string so multiple devices used by the same foreman are distinguishable
 * once Phase 6 builds the actual sync engine.
 *
 * @format
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

const STORAGE_KEY = 'hris.device_id.placeholder';

function randomId(): string {
  return `dev-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export async function getOrCreateDeviceId(): Promise<string> {
  const existing = await AsyncStorage.getItem(STORAGE_KEY);
  if (existing) {
    return existing;
  }

  const id = randomId();
  await AsyncStorage.setItem(STORAGE_KEY, id);
  return id;
}
