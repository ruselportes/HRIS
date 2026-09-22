/**
 * SQLCipher database key (STD TC-02) — the single seam over the Keystore
 * wrap/unwrap pair in the TeeSigner native module.
 *
 * Shape of the secret: the native side generates 32 random bytes and this
 * module only ever handles them wrapped (AES-GCM under a TEE-held wrap key)
 * or as hex. open() receives them as `x'<64 hex>'`, which is exactly the
 * shape SQLCipher treats as a raw key with no KDF — and exactly what a tester
 * pastes into a SQLCipher client's raw-key field. Any other string shape
 * would be PBKDF2-derived on one side and raw on the other, and the two would
 * silently disagree.
 *
 * Lifecycle: created once per installation, stored wrapped in AsyncStorage,
 * never deleted by app code — the database outlives bindings, revocations
 * and second-foreman sign-ins, and deleting the key would orphan the
 * encrypted database. Uninstalling wipes both the Keystore entry and the
 * database file.
 *
 * What this does NOT cover: the HMAC secret still lives in AsyncStorage
 * (see deviceCredentials.ts). SQLCipher encrypts the database — the roster
 * PII and the chain — not the credential store. That is the documented
 * threat model (layer 2 degrades, layer 3 holds), unchanged by this module.
 *
 * @format
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import NativeHrisTeeSigner from '../native/NativeHrisTeeSigner';

/** Wrap key alias in the Keystore. Fixed per installation, like the DB key. */
export const DB_WRAP_KEY_ALIAS = 'hris.db.wrap.v1';

const STORE_KEY = 'hris.db.key';

type StoredDbKey = {v: 1; iv: string; cipher: string};

const HEX_64 = /^[0-9a-f]{64}$/;

function readStored(): Promise<StoredDbKey | null> {
  return AsyncStorage.getItem(STORE_KEY).then(raw => {
    if (!raw) {
      return null;
    }
    try {
      const parsed = JSON.parse(raw) as Partial<StoredDbKey>;
      if (parsed.v === 1 && typeof parsed.iv === 'string' && typeof parsed.cipher === 'string') {
        return parsed as StoredDbKey;
      }
    } catch {
      // Corrupt entry — treated as absent and replaced below.
    }
    return null;
  });
}

/**
 * The `x'<hex>'` key string for open(). Creates and stores the wrapped key
 * on first run; every later call unwraps the stored one. Throws when the
 * native module is unavailable (iOS has no TeeSigner implementation yet) so
 * the failure names the cause instead of opening an unencrypted database.
 */
export async function getDbEncryptionKey(): Promise<string> {
  let stored = await readStored();
  if (stored === null) {
    const wrapped = await NativeHrisTeeSigner.generateWrappedDbKey(DB_WRAP_KEY_ALIAS);
    const [iv, cipher] = wrapped.split('.');
    if (!iv || !cipher) {
      throw new Error('dbkey_malformed');
    }
    stored = {v: 1, iv, cipher};
    await AsyncStorage.setItem(STORE_KEY, JSON.stringify(stored));
  }

  const hex = await NativeHrisTeeSigner.unwrapDbKey(
    DB_WRAP_KEY_ALIAS,
    `${stored.iv}.${stored.cipher}`,
  );
  if (!HEX_64.test(hex)) {
    throw new Error('dbkey_malformed');
  }
  return `x'${hex}'`;
}

/**
 * The raw 64-hex key for the tester (TC-02 setup). Debug builds only — the
 * caller gates on __DEV__; this function just reads.
 */
export async function getDbKeyHexForTester(): Promise<string> {
  const stored = await readStored();
  if (stored === null) {
    throw new Error('dbkey_absent');
  }
  const hex = await NativeHrisTeeSigner.unwrapDbKey(
    DB_WRAP_KEY_ALIAS,
    `${stored.iv}.${stored.cipher}`,
  );
  if (!HEX_64.test(hex)) {
    throw new Error('dbkey_malformed');
  }
  return hex;
}
