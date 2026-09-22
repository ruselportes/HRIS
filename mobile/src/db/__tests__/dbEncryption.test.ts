/**
 * Encrypted open (STD TC-02): getDatabase() passes the TEE-wrapped key as
 * `x'<hex>'`, refuses a non-SQLCipher build instead of writing plaintext,
 * and turns a pre-encryption database file into a reinstall message rather
 * than a corruption mystery.
 *
 * Drives getDatabase() against the op-sqlite jest mock with per-test open()
 * overrides, mirroring schemaGuard.test.ts.
 *
 * @format
 */

import {isSQLCipher, open} from '@op-engineering/op-sqlite';
import {getDatabase, resetDatabaseInstanceForTests} from '../database';
import {TEST_DB_HEX} from '../../native/__mocks__/NativeHrisTeeSigner';

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

const storage = (
  jest.requireMock('@react-native-async-storage/async-storage') as any
).default as {__clear: () => void};

beforeEach(() => {
  storage.__clear();
  jest.clearAllMocks();
  resetDatabaseInstanceForTests();
});

test('open receives the wrapped key in x-hex raw-key shape', async () => {
  const seen: unknown[] = [];
  (open as jest.Mock).mockImplementationOnce((opts: unknown) => {
    seen.push(opts);
    return {execute: jest.fn().mockResolvedValue({rows: [], rowsAffected: 0})};
  });

  await getDatabase();

  expect(seen).toEqual([{name: 'hris_offline.db', encryptionKey: `x'${TEST_DB_HEX}'`}]);
});

test('a build without the SQLCipher backend refuses instead of writing plaintext', async () => {
  (isSQLCipher as jest.Mock).mockReturnValueOnce(false);

  await expect(getDatabase()).rejects.toThrow('db_not_encrypted');
  expect(open as jest.Mock).not.toHaveBeenCalled();
});

test('a pre-encryption database file becomes a reinstall message', async () => {
  (open as jest.Mock).mockImplementationOnce(() => {
    throw new Error('file is not a database');
  });

  await expect(getDatabase()).rejects.toThrow('db_predates_encryption');
});

test('any other open failure keeps its own message', async () => {
  (open as jest.Mock).mockImplementationOnce(() => {
    throw new Error('disk I/O error');
  });

  await expect(getDatabase()).rejects.toThrow('disk I/O error');
});
