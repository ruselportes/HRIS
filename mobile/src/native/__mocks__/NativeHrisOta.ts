/**
 * Jest stand-in for the HrisOta TurboModule.
 *
 * Records downloadAndStage calls so O4's client tests can assert WHEN the
 * check fires (launch, reconnect, foreground) without performing it. Staging
 * and verification themselves are native and are covered where they can
 * actually run — OtaManagerTest on the JVM, against committed fixtures.
 *
 * Wired via moduleNameMapper in jest.config.js, since
 * TurboModuleRegistry.getEnforcing() throws at import time with no native
 * runtime.
 *
 * @format
 */

export const __downloadCalls: string[] = [];

export const __reset = (): void => {
  __downloadCalls.length = 0;
};

export default {
  downloadAndStage: async (baseUrl: string): Promise<string> => {
    __downloadCalls.push(baseUrl);
    return 'up-to-date';
  },
  markHealthy: (): void => {},
  getCurrentBundleVersion: (): number => 0,
  getNativeVersion: (): number => 1,
};
