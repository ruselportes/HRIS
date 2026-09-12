/**
 * Jest stand-in for the MonotonicClock TurboModule.
 *
 * TurboModuleRegistry.getEnforcing() throws at import time when the native
 * module is not registered, which is always the case under Jest — there is no
 * native runtime. Wired globally via moduleNameMapper in jest.config.js rather
 * than per-test, so any screen that reaches the clock does not break the
 * App.test.tsx render smoke test.
 *
 * Values are settable so tests can drive reboot and rollback scenarios.
 *
 * @format
 */

let elapsedRealtime = 86_400_000;
let bootId = 'bc7';
let systemBacked = true;

export const __setElapsedRealtime = (value: number): void => {
  elapsedRealtime = value;
};

export const __setBootId = (value: string): void => {
  bootId = value;
};

export const __setSystemBacked = (value: boolean): void => {
  systemBacked = value;
};

export const __reset = (): void => {
  elapsedRealtime = 86_400_000;
  bootId = 'bc7';
  systemBacked = true;
};

export default {
  getElapsedRealtime: (): number => elapsedRealtime,
  getBootId: (): string => bootId,
  isBootIdSystemBacked: (): boolean => systemBacked,
};
