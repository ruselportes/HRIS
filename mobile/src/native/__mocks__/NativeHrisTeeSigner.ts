/**
 * Jest stand-in for the TEESigner TurboModule.
 *
 * Deliberately NOT a real signer. It records what it was asked to sign and
 * returns a deterministic token, which is what the wrapper's tests need to
 * assert: that the exact canonical payload reaches the native boundary. Real
 * ECDSA behaviour is covered where it can actually be verified — on the PHP
 * side in SignatureVerifierTest, against committed test keys.
 *
 * Wired via moduleNameMapper in jest.config.js, since
 * TurboModuleRegistry.getEnforcing() throws at import time with no native
 * runtime.
 *
 * @format
 */

const PUBLIC_KEY_PEM =
  '-----BEGIN PUBLIC KEY-----\n' +
  'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEZ/qRNvjsKs4egbV0nnqxBsZUy99F\n' +
  'pMvaxz2DLkPSBWBVJ3o4bWyvrgs25cn1pTsgeydUqxqWHRqMhz5o+SY3HQ==\n' +
  '-----END PUBLIC KEY-----\n';

let keys: Record<string, boolean> = {};
let securityLevel = 'TRUSTED_ENVIRONMENT';
let failNextSign = false;

/** Payloads passed to sign(), in order — the contract under test. */
export const __signedPayloads: string[] = [];

export const __setSecurityLevel = (value: string): void => {
  securityLevel = value;
};

export const __failNextSign = (): void => {
  failNextSign = true;
};

export const __reset = (): void => {
  keys = {};
  securityLevel = 'TRUSTED_ENVIRONMENT';
  failNextSign = false;
  __signedPayloads.length = 0;
};

export default {
  generateKeyPair: async (alias: string): Promise<string> => {
    keys[alias] = true;
    return PUBLIC_KEY_PEM;
  },
  hasKey: (alias: string): boolean => keys[alias] === true,
  getPublicKeyPem: (alias: string): string => {
    if (!keys[alias]) {
      throw new Error(`No signing key exists for alias [${alias}].`);
    }
    return PUBLIC_KEY_PEM;
  },
  getSecurityLevel: (alias: string): string => {
    if (!keys[alias]) {
      throw new Error(`No signing key exists for alias [${alias}].`);
    }
    return securityLevel;
  },
  sign: async (alias: string, payload: string): Promise<string> => {
    if (failNextSign) {
      failNextSign = false;
      throw new Error('sign_failed');
    }
    if (!keys[alias]) {
      throw new Error(`No signing key exists for alias [${alias}].`);
    }
    __signedPayloads.push(payload);
    // Deterministic stand-in, not a real signature.
    return `mock-signature:${payload.length}`;
  },
  deleteKey: (alias: string): boolean => {
    delete keys[alias];
    return true;
  },
};
