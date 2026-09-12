/**
 * TEESigner wrapper (Phase 5, layer 3) — the single seam over the Keystore
 * native module.
 *
 * @format
 */

import NativeHrisTeeSigner from '../native/NativeHrisTeeSigner';
import {AttendancePayloadRecord, canonicalize} from './payload';

/**
 * One alias per installation. Fixed rather than per-employee: the key attests
 * *which device* produced a record, and the server already knows which foreman
 * the device is bound to. A per-employee alias would imply a device can hold
 * several identities, which the device_keys table does not model.
 */
export const SIGNING_KEY_ALIAS = 'hris.attendance.signing.v1';

export type SecurityLevel = 'STRONGBOX' | 'TRUSTED_ENVIRONMENT' | 'SOFTWARE';

export const HARDWARE_BACKED_LEVELS: SecurityLevel[] = [
  'STRONGBOX',
  'TRUSTED_ENVIRONMENT',
];

export function isHardwareBacked(level: string): boolean {
  return HARDWARE_BACKED_LEVELS.includes(level as SecurityLevel);
}

export function hasSigningKey(): boolean {
  return NativeHrisTeeSigner.hasKey(SIGNING_KEY_ALIAS);
}

/**
 * Generate the device keypair and return what device binding needs.
 *
 * Replaces any existing key, matching the server: rebinding issues a fresh
 * HMAC secret and resets the chain, so the previous keypair must not outlive
 * it.
 */
export async function createSigningKey(): Promise<{
  publicKeyPem: string;
  securityLevel: string;
  hardwareBacked: boolean;
}> {
  const publicKeyPem = await NativeHrisTeeSigner.generateKeyPair(SIGNING_KEY_ALIAS);
  const securityLevel = NativeHrisTeeSigner.getSecurityLevel(SIGNING_KEY_ALIAS);

  return {
    publicKeyPem,
    securityLevel,
    hardwareBacked: isHardwareBacked(securityLevel),
  };
}

export function getPublicKeyPem(): string {
  return NativeHrisTeeSigner.getPublicKeyPem(SIGNING_KEY_ALIAS);
}

export function getSecurityLevel(): string {
  return NativeHrisTeeSigner.getSecurityLevel(SIGNING_KEY_ALIAS);
}

/**
 * Sign an attendance record.
 *
 * Canonicalizes here rather than accepting a pre-built string, so no caller
 * can sign something that is not exactly what the server will verify. That
 * mistake would produce a signature that fails for reasons invisible at the
 * call site.
 */
export async function signAttendance(
  record: AttendancePayloadRecord,
): Promise<string> {
  return NativeHrisTeeSigner.sign(SIGNING_KEY_ALIAS, canonicalize(record));
}

export function deleteSigningKey(): boolean {
  return NativeHrisTeeSigner.deleteKey(SIGNING_KEY_ALIAS);
}
