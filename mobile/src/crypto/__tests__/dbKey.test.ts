/**
 * SQLCipher database key lifecycle (STD TC-02): created once per
 * installation, stored wrapped, unwrapped as `x'<hex>'` for open().
 *
 * The native boundary is the mocked TeeSigner (deterministic stand-ins, not
 * real wrapping): what these tests own is the JS contract — store-once,
 * reuse, corrupt-entry recovery, and the exact key shape SQLCipher treats as
 * a raw key.
 *
 * @format
 */

import {getDbEncryptionKey, getDbKeyHexForTester, DB_WRAP_KEY_ALIAS} from '../dbKey';
import NativeHrisTeeSigner, {TEST_DB_HEX} from '../../native/__mocks__/NativeHrisTeeSigner';

jest.mock('@react-native-async-storage/async-storage', () => {
  const store = new Map<string, string>();
  return {
    __esModule: true,
    default: {
      getItem: jest.fn(async (key: string) => store.get(key) ?? null),
      setItem: jest.fn(async (key: string, value: string) => {
        store.set(key, value);
      }),
      removeItem: jest.fn(async (key: string) => {
        store.delete(key);
      }),
      __clear: () => store.clear(),
    },
  };
});

import AsyncStorage from '@react-native-async-storage/async-storage';

const storage = AsyncStorage as unknown as {
  getItem: jest.Mock;
  setItem: jest.Mock;
  __clear: () => void;
};

beforeEach(() => {
  storage.__clear();
  jest.clearAllMocks();
});

test('first run generates, stores wrapped, and opens with x-hex shape', async () => {
  const key = await getDbEncryptionKey();

  // Exactly what SQLCipher treats as a raw key with no KDF — and what the
  // tester pastes into a raw-key field. Any other shape would derive on one
  // side and stay raw on the other.
  expect(key).toBe(`x'${TEST_DB_HEX}'`);

  const stored = JSON.parse((await storage.getItem('hris.db.key')) ?? '');
  expect(stored).toEqual({v: 1, iv: 'aa', cipher: TEST_DB_HEX});
  // Only the wrapped form is ever persisted — no raw-key field exists.
  // (The mock's ciphertext happens to equal its test hex; on device the
  // ciphertext is AES-GCM output, opaque without the wrap key.)
  expect(Object.keys(stored).sort()).toEqual(['cipher', 'iv', 'v']);
});

test('later runs reuse the stored key without generating again', async () => {
  await getDbEncryptionKey();
  const generate = jest.spyOn(NativeHrisTeeSigner, 'generateWrappedDbKey');

  await getDbEncryptionKey();
  await getDbEncryptionKey();

  expect(generate).not.toHaveBeenCalled();
});

test('a corrupt stored entry is replaced, not trusted', async () => {
  await storage.setItem('hris.db.key', 'not-json{{{');

  const key = await getDbEncryptionKey();

  expect(key).toBe(`x'${TEST_DB_HEX}'`);
});

test('a non-hex unwrap result is refused, never passed to open()', async () => {
  jest.spyOn(NativeHrisTeeSigner, 'unwrapDbKey').mockResolvedValueOnce('oops');

  await expect(getDbEncryptionKey()).rejects.toThrow('dbkey_malformed');
});

test('the tester display reads the stored key and names its format', async () => {
  await getDbEncryptionKey();

  expect(await getDbKeyHexForTester()).toBe(TEST_DB_HEX);
});

test('the tester display fails loudly with no key stored', async () => {
  await expect(getDbKeyHexForTester()).rejects.toThrow('dbkey_absent');
});

test('the wrap alias is fixed per installation', () => {
  expect(DB_WRAP_KEY_ALIAS).toBe('hris.db.wrap.v1');
});
