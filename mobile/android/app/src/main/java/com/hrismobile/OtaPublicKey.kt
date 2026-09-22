package com.hrismobile

/**
 * OTA update trust root (Add-on C, O2).
 *
 * The SPKI DER of the OTA signing public key, as lowercase hex — hex rather
 * than PEM because java.util.Base64 is API 26+ (minSdk is 24) and a pure-JVM
 * core cannot use android.util.Base64 either. Source of truth is
 * mobile/ota/ota_public.pem; the private half lives outside the repo at
 * %USERPROFILE%/.hris/ota_private.pem (HRIS_OTA_KEY) and is never committed.
 * A rotation ships as an APK, since this constant is compiled in.
 */
object OtaPublicKey {
  const val OTA_PUBLIC_KEY_HEX =
    "3059301306072a8648ce3d020106082a8648ce3d03010703420004b6ae21a86f1d890687eb1863d8b07e956e615022ce0068800c198df7620a2d7edcaf644d182698a78f901bad18999e0eeb0007389b4a9c556585a5ddfcd83a0b"
}
