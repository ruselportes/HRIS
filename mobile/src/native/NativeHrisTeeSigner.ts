/**
 * TEESigner spec (Phase 5, layer 3 of the integrity engine — named in STD
 * TC-03).
 *
 * Generates and uses an ECDSA P-256 keypair inside the Android Keystore. The
 * private key is created in, and never leaves, the TEE/StrongBox: there is no
 * API that exports it, which is what makes TC-03's second attempt fail. An
 * attacker can produce a structurally valid signature from their own keypair,
 * but not one that verifies against the public key registered for this device.
 *
 * Every method returns primitives only. Object returns are supported by
 * codegen but add configuration surface, and this pipeline has exactly one
 * proven module behind it — not the place to spend risk budget.
 *
 * @format
 */

import type {TurboModule} from 'react-native';
import {TurboModuleRegistry} from 'react-native';

export interface Spec extends TurboModule {
  /**
   * Create (or replace) the signing keypair for `alias` and return its public
   * half as PEM, ready to register with POST /api/me/devices.
   *
   * Async because StrongBox key generation is not instant. Replacing an
   * existing alias is deliberate — rebinding on the server issues a fresh HMAC
   * secret and resets the chain, so the old keypair must not survive.
   */
  generateKeyPair(alias: string): Promise<string>;

  /** Whether a keypair already exists for this alias. */
  hasKey(alias: string): boolean;

  /** The public half as PEM. Throws if the alias has no key. */
  getPublicKeyPem(alias: string): string;

  /**
   * Where the private key actually lives: 'STRONGBOX', 'TRUSTED_ENVIRONMENT',
   * or 'SOFTWARE'. Reported to the server at binding rather than assumed.
   *
   * An emulator returns SOFTWARE — it has no secure hardware — so the
   * project's hardware-backing claim can only be demonstrated on a physical
   * device. The server decides whether SOFTWARE is acceptable via
   * config('crypto.require_hardware_backed_keys').
   */
  getSecurityLevel(alias: string): string;

  /**
   * Sign the canonical attendance payload, returning base64 of the DER ECDSA
   * signature — the exact shape PHP's SignatureVerifier expects.
   *
   * Async on purpose. The timestamps inside `payload` were captured
   * synchronously at the tap, so signing a few milliseconds later cannot
   * affect what is attested; blocking the JS thread per tap would be felt by
   * a foreman working down a roster.
   */
  sign(alias: string, payload: string): Promise<string>;

  /** Remove the keypair. Used when a device is revoked or rebound. */
  deleteKey(alias: string): boolean;
}

export default TurboModuleRegistry.getEnforcing<Spec>('HrisTeeSigner');
