package com.hrismobile

import android.os.Build
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.module.annotations.ReactModule
import com.hrismobile.spec.NativeHrisOtaSpec
import java.io.File

/**
 * HrisOta (Add-on C, O1) — verified over-the-air bundle loading.
 *
 * Thin bridge over OtaManager, which holds all selection/verification logic
 * (and is unit-tested on the JVM without this class). downloadAndStage runs
 * its network I/O on the native-modules thread — never the UI thread — and
 * resolves a status string the O4 JS client maps to UI; it never throws into
 * JS, because a staging failure is an ordinary outcome, not an exception.
 */
@ReactModule(name = HrisOtaModule.NAME)
class HrisOtaModule(reactContext: ReactApplicationContext) :
  NativeHrisOtaSpec(reactContext) {

  companion object {
    const val NAME = "HrisOta"
  }

  override fun getName(): String = NAME

  private val manager: OtaManager by lazy {
    OtaManager(
      otaDir = File(reactApplicationContext.filesDir, "ota"),
      publicKeyHex = OtaPublicKey.OTA_PUBLIC_KEY_HEX,
      versionCode = appVersionCode(),
    )
  }

  private fun appVersionCode(): Int {
    val info =
      reactApplicationContext.packageManager.getPackageInfo(
        reactApplicationContext.packageName,
        0,
      )
    return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
      info.longVersionCode.toInt()
    } else {
      @Suppress("DEPRECATION")
      info.versionCode
    }
  }

  override fun downloadAndStage(baseUrl: String, promise: Promise) {
    try {
      promise.resolve(manager.downloadAndStage(baseUrl))
    } catch (e: Exception) {
      promise.resolve("refused:internal-error")
    }
  }

  override fun markHealthy() {
    try {
      manager.markHealthy()
    } catch (e: Exception) {
      // Best effort — see OtaManager.markHealthy.
    }
  }

  override fun getCurrentBundleVersion(): Double {
    return try {
      manager.currentBundleVersion().toDouble()
    } catch (e: Exception) {
      0.0
    }
  }

  override fun getNativeVersion(): Double {
    return try {
      manager.nativeVersionCode().toDouble()
    } catch (e: Exception) {
      0.0
    }
  }
}
