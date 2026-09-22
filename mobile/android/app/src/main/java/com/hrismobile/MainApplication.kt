package com.hrismobile

import android.app.Application
import com.facebook.react.PackageList
import com.facebook.react.ReactApplication
import com.facebook.react.ReactHost
import com.facebook.react.ReactNativeApplicationEntryPoint.loadReactNative
import com.facebook.react.defaults.DefaultReactHost.getDefaultReactHost
import java.io.File

class MainApplication : Application(), ReactApplication {

  override val reactHost: ReactHost by lazy {
    // OTA (Add-on C, O1): a verified staged bundle replaces the embedded one
    // for this cold start. The host is by lazy, so the choice is made once
    // per process. Debug builds always take Metro (null falls back to the
    // asset loader), and any selection failure falls back the same way —
    // OTA can delay an update, never break a launch.
    val otaBundlePath =
      if (BuildConfig.DEBUG) {
        null
      } else {
        OtaManager(
          otaDir = File(applicationContext.filesDir, "ota"),
          publicKeyHex = OtaPublicKey.OTA_PUBLIC_KEY_HEX,
          versionCode = BuildConfig.VERSION_CODE,
        ).selectBundlePath()
      }
    getDefaultReactHost(
      context = applicationContext,
      packageList =
        PackageList(this).packages.apply {
          // Autolinking only covers node_modules packages; modules that live
          // inside this app have to be registered by hand.
          add(HrisNativePackage())
        },
      jsBundleFilePath = otaBundlePath,
    )
  }

  override fun onCreate() {
    super.onCreate()
    loadReactNative(this)
  }
}
