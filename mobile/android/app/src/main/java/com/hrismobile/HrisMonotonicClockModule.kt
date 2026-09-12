package com.hrismobile

import android.content.Context
import android.os.SystemClock
import android.provider.Settings
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.module.annotations.ReactModule
import com.hrismobile.spec.NativeHrisMonotonicClockSpec

/**
 * MonotonicClockService (Phase 5, layer 1 — named in STD TC-01).
 *
 * Supplies the one timestamp on a device that a user cannot set: a counter
 * that only moves forward and is not reachable from the Settings clock. The
 * wall clock is still recorded alongside it, and the server cross-checks the
 * two — see ClockIntegrityVerifier.
 */
@ReactModule(name = HrisMonotonicClockModule.NAME)
class HrisMonotonicClockModule(reactContext: ReactApplicationContext) :
  NativeHrisMonotonicClockSpec(reactContext) {

  companion object {
    const val NAME = "HrisMonotonicClock"

    /** Persisted across launches so a reboot can be detected without the wall clock. */
    private const val PREFS = "hris_monotonic_clock"
    private const val KEY_FALLBACK_BOOT_ID = "fallback_boot_id"
    private const val KEY_LAST_ELAPSED = "last_elapsed_realtime"
  }

  override fun getName(): String = NAME

  /**
   * SystemClock.elapsedRealtime() — milliseconds since boot, including time
   * spent in deep sleep. Not settable by the user, which is exactly why it is
   * the trusted half of the pair.
   */
  override fun getElapsedRealtime(): Double = SystemClock.elapsedRealtime().toDouble()

  /**
   * Boot session identifier, from Settings.Global.BOOT_COUNT — a counter the
   * system increments on each boot and an ordinary app cannot write.
   *
   * NOT derived from (currentTimeMillis - elapsedRealtime). That expression is
   * the obvious way to identify a boot session and it defeats the whole
   * mechanism: it moves whenever the wall clock is changed, so a rollback
   * would present as a new boot session, the server would skip its drift
   * comparison, and the attack in TC-01 would pass unnoticed.
   */
  override fun getBootId(): String {
    readSystemBootCount()?.let { return "bc$it" }

    return fallbackBootId()
  }

  override fun isBootIdSystemBacked(): Boolean = readSystemBootCount() != null

  private fun readSystemBootCount(): Int? =
    try {
      // BOOT_COUNT is API 24+; minSdkVersion is 24, so it is always declared.
      // Still guarded: reads of Settings.Global can throw on unusual OEM
      // builds, and a thrown exception here would break attendance capture
      // entirely, which is a far worse outcome than a weaker boot id.
      Settings.Global.getInt(reactApplicationContext.contentResolver, Settings.Global.BOOT_COUNT)
    } catch (e: Settings.SettingNotFoundException) {
      null
    } catch (e: SecurityException) {
      null
    }

  /**
   * Fallback when BOOT_COUNT is unavailable: keep a random id and the highest
   * elapsedRealtime seen. If the current reading is lower than the stored one,
   * the device rebooted, so rotate the id.
   *
   * Weaker than BOOT_COUNT — it trusts app-private storage rather than the
   * system — which is why isBootIdSystemBacked() reports which one produced
   * the value instead of letting the server assume.
   */
  private fun fallbackBootId(): String {
    val prefs = reactApplicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    val currentElapsed = SystemClock.elapsedRealtime()
    val lastElapsed = prefs.getLong(KEY_LAST_ELAPSED, -1L)
    val storedId = prefs.getString(KEY_FALLBACK_BOOT_ID, null)

    val bootId =
      if (storedId == null || currentElapsed < lastElapsed) {
        "fb${java.util.UUID.randomUUID().toString().take(12)}"
      } else {
        storedId
      }

    prefs
      .edit()
      .putString(KEY_FALLBACK_BOOT_ID, bootId)
      .putLong(KEY_LAST_ELAPSED, currentElapsed)
      .apply()

    return bootId
  }
}
