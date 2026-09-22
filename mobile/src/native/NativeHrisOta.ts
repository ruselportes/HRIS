/**
 * HrisOta spec (Add-on C, O1) — verified over-the-air bundle loading.
 *
 * The native side owns everything the trust root touches: download,
 * signature + hash verification, staging, cold-start selection, crash
 * rollback. JS (O4) only decides WHEN to check — it can neither stage nor
 * select a bundle, because package.json has no filesystem library and JS
 * cannot write a binary bundle to disk.
 *
 * downloadAndStage statuses (plain strings, parsed by the O4 client):
 *   "staged:<id>"       — verified and staged; applies at the next cold start
 *   "up-to-date"        — the newest signed bundle is already running
 *   "refused:<reason>"  — bad-signature | bad-bundle-hash | bad-manifest |
 *                         wrong-native | refused-id | older-version |
 *                         network-error | internal-error
 *
 * markHealthy() must be called once the first screen renders (O4). Killing
 * the app before that counts as a crash: the next cold start rolls the
 * staged bundle back and refuses its id.
 *
 * Codegen turns this file into the Kotlin abstract class NativeHrisOtaSpec
 * (package com.hrismobile.spec), which HrisOtaModule implements. The file
 * name must stay Native*.ts for codegen to pick it up.
 *
 * @format
 */

import type {TurboModule} from 'react-native';
import {TurboModuleRegistry} from 'react-native';

export interface Spec extends TurboModule {
  /**
   * Fetch the newest manifest for this native version, verify it, download
   * the bundle when it is newer than anything installed, verify the bundle
   * hash, and stage it for the next cold start. Never touches the running
   * bundle and never blocks the caller beyond the network round trips.
   */
  downloadAndStage(baseUrl: string): Promise<string>;

  /**
   * Clear the `verifying` flag set when a staged bundle was promoted. Call
   * once the first screen renders — not earlier, not bundled with other
   * startup work.
   */
  markHealthy(): void;

  /**
   * Bundle version currently selected (0 = embedded APK bundle). Integer
   * valued; transported as a double because that is what codegen maps
   * TypeScript numbers to.
   */
  getCurrentBundleVersion(): number;

  /** The running APK's versionCode (= nativeVersion in manifests). */
  getNativeVersion(): number;
}

export default TurboModuleRegistry.getEnforcing<Spec>('HrisOta');
