/**
 * Binding ownership (Phase 7 — TC-05 uses a second foreman on a phone).
 *
 * The server binds a device to one foreman. A phone set up for F1 that F2
 * signs in on must count as NOT bound for F2, or F2's taps would be signed
 * with F1's binding and refused.
 *
 * @format
 */

import {
  boundEmployeeId,
  clearCredentials,
  isBound,
  onBindingChange,
  saveCredentials,
} from '../deviceCredentials';

jest.mock('@react-native-async-storage/async-storage', () => {
  const store = new Map<string, string>();
  return {
    __esModule: true,
    default: {
      setMany: jest.fn(async (entries: Record<string, string>) => {
        Object.entries(entries).forEach(([k, v]) => store.set(k, v));
      }),
      getMany: jest.fn(async (keys: string[]) =>
        Object.fromEntries(keys.map(k => [k, store.get(k) ?? null])),
      ),
      getItem: jest.fn(async (key: string) => store.get(key) ?? null),
      removeMany: jest.fn(async (keys: string[]) => keys.forEach(k => store.delete(k))),
    },
  };
});

const CREDENTIALS = {deviceId: 'dev-1', hmacKeyBase64: 'a2V5'};

afterEach(async () => {
  await clearCredentials();
});

test('a phone bound for one foreman is not bound for another', async () => {
  await saveCredentials(CREDENTIALS, 7);

  expect(await isBound(7)).toBe(true);
  expect(await isBound(8)).toBe(false);
  expect(await boundEmployeeId()).toBe(7);
});

test('a binding saved before owners were recorded binds nobody in particular', async () => {
  await saveCredentials(CREDENTIALS);

  // Still bound as a device, but no foreman can record on it until set up again.
  expect(await isBound()).toBe(true);
  expect(await isBound(7)).toBe(false);
});

test('clearing the binding unbinds everyone and tells listeners', async () => {
  const listener = jest.fn();
  const unsubscribe = onBindingChange(listener);

  await saveCredentials(CREDENTIALS, 7);
  await clearCredentials();

  expect(await isBound(7)).toBe(false);
  expect(await boundEmployeeId()).toBeNull();
  expect(listener).toHaveBeenCalledTimes(2);

  unsubscribe();
  await saveCredentials(CREDENTIALS, 7);
  expect(listener).toHaveBeenCalledTimes(2);
});
