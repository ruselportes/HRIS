/**
 * Local storage of what device binding issued: the device id the server knows
 * this installation by, and the per-device HMAC secret the chain is keyed on
 * (Phase 5).
 *
 * THREAT MODEL, stated plainly because the storage here is the weakest link
 * in the engine and pretending otherwise would be worse than the weakness:
 * the HMAC secret sits in AsyncStorage, which is app-private but not
 * encrypted. An attacker with root or a debug bridge can read it, and with it
 * they can forge chain entries that self-verify.
 *
 * What saves the design is that the chain is not the only layer. The ECDSA
 * private key lives in the Keystore TEE and is non-exportable, so the same
 * attacker still cannot produce a signature the server will accept. Layer 2
 * degrades; layer 3 holds. That is the whole reason both exist rather than
 * just the cheaper one.
 *
 * Proper hardening is SQLCipher for the local database, which op-sqlite
 * supports via a build flag and which Phase 1 deliberately deferred. Until
 * that lands this is the honest state, not a claim of encryption at rest.
 *
 * @format
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import {sha256} from '@noble/hashes/sha2.js';
import {bytesToHex, utf8ToBytes} from '@noble/hashes/utils.js';

const KEY_DEVICE_ID = 'hris.device.id';
const KEY_HMAC_SECRET = 'hris.device.hmac_key';
const KEY_OWNER = 'hris.device.owner_employee_id';

/*
 * Told when this phone's binding changes, so the navigator can send the
 * foreman back to setup without an app restart (Phase 7: "Set up this phone
 * again" on the Sync screen, and a second foreman signing in — TC-05).
 */
type BindingListener = () => void;
const listeners = new Set<BindingListener>();

export function onBindingChange(listener: BindingListener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function notifyBindingChange(): void {
  listeners.forEach(listener => listener());
}

export type DeviceCredentials = {
  deviceId: string;
  /** Base64, exactly as the server issued it. Decode before use as an HMAC key. */
  hmacKeyBase64: string;
};

/**
 * Persist what POST /api/me/devices returned. Called once per binding.
 *
 * The owner is recorded because the server binds a device to ONE foreman: a
 * second foreman signing in on this phone (an acting foreman borrowing it,
 * Phase 7) would otherwise sign with the first one's binding, and every record
 * would be refused as coming from a device that is not theirs.
 *
 * Uses AsyncStorage v3's object-based setMany/getMany/removeMany — v3 dropped
 * the older multiSet/multiGet/multiRemove array API.
 */
export async function saveCredentials(
  credentials: DeviceCredentials,
  ownerEmployeeId: number | null = null,
): Promise<void> {
  await AsyncStorage.setMany({
    [KEY_DEVICE_ID]: credentials.deviceId,
    [KEY_HMAC_SECRET]: credentials.hmacKeyBase64,
    [KEY_OWNER]: ownerEmployeeId === null ? '' : String(ownerEmployeeId),
  });
  notifyBindingChange();
}

/** The foreman this phone is bound to, or null (unbound, or bound before this was recorded). */
export async function boundEmployeeId(): Promise<number | null> {
  if ((await loadCredentials()) === null) {
    return null;
  }

  const stored = await AsyncStorage.getItem(KEY_OWNER);
  return stored ? Number(stored) : null;
}

/**
 * Returns null when the device is not bound. Callers must treat that as "no
 * capture is possible yet" rather than falling back to unsigned records —
 * an unsigned record is one the server will reject anyway, so writing it would
 * only lose the foreman's work silently.
 */
export async function loadCredentials(): Promise<DeviceCredentials | null> {
  const stored = await AsyncStorage.getMany([KEY_DEVICE_ID, KEY_HMAC_SECRET]);

  const deviceId = stored[KEY_DEVICE_ID];
  const hmacKeyBase64 = stored[KEY_HMAC_SECRET];

  // Both or neither. A half-written binding (id without secret) cannot sign
  // anything, so treating it as unbound is the only useful reading.
  if (!deviceId || !hmacKeyBase64) {
    return null;
  }

  return {deviceId, hmacKeyBase64};
}

/**
 * Which chain an event belongs to: a short fingerprint of the binding's HMAC
 * secret.
 *
 * Needed because binding resets the SERVER's chain tip to null, while the
 * device's tip would otherwise be "the most recent event ever captured". After
 * a rebind the next event would link to pre-rebind history, the server would
 * expect null, and the device could never sync again — which would break
 * rebind, the one recovery path for a device whose chain is broken.
 *
 * Every binding issues a fresh secret, so the fingerprint changes exactly when
 * the chain must restart, with no counter to keep in step. Truncated SHA-256 of
 * a 256-bit random key does not reveal the key.
 */
export function chainEpoch(credentials: DeviceCredentials): string {
  return bytesToHex(sha256(utf8ToBytes(credentials.hmacKeyBase64))).slice(0, 16);
}

/**
 * Whether this phone can record for the given foreman: bound, and bound to
 * them. Without an employee id, only whether it is bound at all.
 */
export async function isBound(employeeId?: number): Promise<boolean> {
  if (employeeId === undefined) {
    return (await loadCredentials()) !== null;
  }

  return (await boundEmployeeId()) === employeeId;
}

/** Clear on revoke or rebind. The old secret must not outlive its binding. */
export async function clearCredentials(): Promise<void> {
  await AsyncStorage.removeMany([KEY_DEVICE_ID, KEY_HMAC_SECRET, KEY_OWNER]);
  notifyBindingChange();
}
