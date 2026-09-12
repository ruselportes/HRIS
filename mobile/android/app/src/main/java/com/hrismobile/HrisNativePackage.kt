package com.hrismobile

import com.facebook.react.BaseReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.module.model.ReactModuleInfo
import com.facebook.react.module.model.ReactModuleInfoProvider

/**
 * Registers this project's own TurboModules (Phase 5 integrity engine).
 *
 * BaseReactPackage rather than the older ReactPackage: it resolves modules
 * lazily by name, so a module is only constructed when JS actually requires
 * it. Autolinking does not cover modules that live inside the app itself, so
 * this package is added explicitly in MainApplication.
 */
class HrisNativePackage : BaseReactPackage() {

  override fun getModule(name: String, reactContext: ReactApplicationContext): NativeModule? =
    when (name) {
      HrisMonotonicClockModule.NAME -> HrisMonotonicClockModule(reactContext)
      HrisTeeSignerModule.NAME -> HrisTeeSignerModule(reactContext)
      else -> null
    }

  override fun getReactModuleInfoProvider(): ReactModuleInfoProvider = ReactModuleInfoProvider {
    listOf(HrisMonotonicClockModule.NAME, HrisTeeSignerModule.NAME).associateWith { name ->
      ReactModuleInfo(
        name,
        name,
        false, // canOverrideExistingModule
        false, // needsEagerInit
        false, // isCxxModule
        true, // isTurboModule
      )
    }
  }
}
