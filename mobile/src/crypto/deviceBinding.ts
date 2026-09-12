/**
 * Device binding orchestration (Phase 5) — backs the Foreman Device Binding
 * screen (docs/prototypes/HRIS Foreman Device Binding.dc.html).
 *
 * Kept out of the screen so the sequence is testable without rendering, and
 * so the ordering is stated in one place. Order matters:
 *
 *   1. get/create this installation's device id
 *   2. generate the keypair IN the TEE
 *   3. register the public half with the server, which returns the HMAC secret
 *   4. only then persist credentials locally
 *
 * Step 4 last is the important one. Persisting before the server confirms
 * would leave the device believing it is bound with a secret the server never
 * issued, and every record it then captured would be rejected — with the
 * foreman having no way to tell why.
 *
 * @format
 */

import {apiClient} from '../api/client';
import {getOrCreateDeviceId} from '../db/deviceId';
import {saveCredentials} from './deviceCredentials';
import {createSigningKey} from './teeSigner';

export type BindingStep =
  | 'generating_key'
  | 'registering'
  | 'saving'
  | 'complete';

export type BindingResult = {
  deviceId: string;
  securityLevel: string;
  hardwareBacked: boolean;
  rebound: boolean;
};

export class BindingError extends Error {
  constructor(
    message: string,
    readonly step: BindingStep,
    readonly retryable: boolean,
  ) {
    super(message);
    this.name = 'BindingError';
  }
}

/**
 * Run the full binding sequence.
 *
 * `onStep` exists so the screen can show which stage is running — the
 * prototype pins "Please keep the app open until this finishes", which is only
 * honest if the user can see what is still outstanding.
 */
export async function bindThisDevice(
  onStep?: (step: BindingStep) => void,
): Promise<BindingResult> {
  const deviceId = await getOrCreateDeviceId();

  onStep?.('generating_key');

  let key: Awaited<ReturnType<typeof createSigningKey>>;
  try {
    key = await createSigningKey();
  } catch {
    // Keystore failure is not retryable by tapping again — it usually means
    // the hardware refused the key parameters, which will refuse them again.
    throw new BindingError(
      "This phone could not create its safety lock. It may not support the required security features.",
      'generating_key',
      false,
    );
  }

  onStep?.('registering');

  let response;
  try {
    response = await apiClient.post('/me/devices', {
      device_id: deviceId,
      public_key: key.publicKeyPem,
      security_level: key.securityLevel,
    });
  } catch (err: any) {
    // A 422 is the server refusing this key — wrong curve, or hardware
    // backing required and not present. Retrying sends the same key, so it
    // would fail identically; anything else (network, 5xx) is worth retrying.
    const status = err?.response?.status;
    const serverReason = err?.response?.data?.message;

    throw new BindingError(
      serverReason ??
        'Could not reach HR to register this phone. Check your connection and try again.',
      'registering',
      status !== 422,
    );
  }

  onStep?.('saving');

  await saveCredentials({
    deviceId,
    hmacKeyBase64: response.data.hmac_key,
  });

  onStep?.('complete');

  return {
    deviceId,
    securityLevel: response.data.security_level ?? key.securityLevel,
    hardwareBacked: response.data.hardware_backed ?? key.hardwareBacked,
    rebound: response.data.rebound ?? false,
  };
}
