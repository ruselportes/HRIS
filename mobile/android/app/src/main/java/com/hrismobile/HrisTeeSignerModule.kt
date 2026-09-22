package com.hrismobile

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyInfo
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import android.util.Base64
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.module.annotations.ReactModule
import com.hrismobile.spec.NativeHrisTeeSignerSpec
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.SecureRandom
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * TEESigner (Phase 5, layer 3 — named in STD TC-03).
 *
 * The private key is generated inside the Android Keystore and is never
 * returned to this process: `getKey()` hands back a PrivateKey handle whose
 * material lives in the TEE, and signing happens there. That is the property
 * TC-03 turns on — a signature cannot be produced off-device, so an attacker
 * who intercepts and alters a payload has no way to re-sign it.
 */
@ReactModule(name = HrisTeeSignerModule.NAME)
class HrisTeeSignerModule(reactContext: ReactApplicationContext) :
  NativeHrisTeeSignerSpec(reactContext) {

  companion object {
    const val NAME = "HrisTeeSigner"

    private const val KEYSTORE = "AndroidKeyStore"
    private const val CURVE = "secp256r1" // NIST P-256, per the SPMP fixed stack
    private const val SIGNATURE_ALGORITHM = "SHA256withECDSA"

    // Database-key wrapping (SQLCipher at rest, STD TC-02). AES-256-GCM via
    // the Keystore, so the wrap key itself is non-exportable like the
    // signing key. 12-byte IV, 32-byte database key.
    private const val WRAP_TRANSFORMATION = "AES/GCM/NoPadding"
    private const val WRAP_KEY_SIZE = 256
    private const val GCM_IV_BYTES = 12
    private const val GCM_TAG_BITS = 128
    private const val DB_KEY_BYTES = 32

    const val LEVEL_STRONGBOX = "STRONGBOX"
    const val LEVEL_TEE = "TRUSTED_ENVIRONMENT"
    const val LEVEL_SOFTWARE = "SOFTWARE"
  }

  override fun getName(): String = NAME

  private fun keyStore(): KeyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }

  override fun generateKeyPair(alias: String, promise: Promise) {
    try {
      // Replace rather than reuse. A rebind on the server issues a fresh HMAC
      // secret and resets the chain, so leaving the old keypair in place would
      // let a key the server has stopped trusting keep producing signatures.
      keyStore().deleteEntry(alias)

      val publicKey =
        try {
          generate(alias, strongBox = true).public
        } catch (e: StrongBoxUnavailableException) {
          // Most devices have TEE but no dedicated StrongBox chip. Falling
          // back keeps those usable; getSecurityLevel() then reports
          // TRUSTED_ENVIRONMENT rather than claiming StrongBox.
          generate(alias, strongBox = false).public
        }

      promise.resolve(toPem(publicKey.encoded))
    } catch (e: Exception) {
      promise.reject("keygen_failed", "Could not generate a signing key: ${e.message}", e)
    }
  }

  private fun generate(alias: String, strongBox: Boolean): java.security.KeyPair {
    val builder =
      KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
        .setAlgorithmParameterSpec(ECGenParameterSpec(CURVE))
        .setDigests(KeyProperties.DIGEST_SHA256)
        /*
         * No per-signature user authentication. A foreman marks a whole crew
         * in sequence, often with gloves on at a site with no signal —
         * requiring a device unlock per worker would make the primary flow
         * unusable, and the attendance record's integrity does not depend on
         * re-proving who is holding the phone at each tap. Who the device
         * belongs to is established once, at binding.
         */
        .setUserAuthenticationRequired(false)

    if (strongBox && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
      builder.setIsStrongBoxBacked(true)
    }

    val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, KEYSTORE)
    generator.initialize(builder.build())

    return generator.generateKeyPair()
  }

  override fun hasKey(alias: String): Boolean =
    try {
      keyStore().containsAlias(alias)
    } catch (e: Exception) {
      false
    }

  override fun getPublicKeyPem(alias: String): String {
    val certificate =
      keyStore().getCertificate(alias)
        ?: throw IllegalStateException("No signing key exists for alias [$alias].")

    return toPem(certificate.publicKey.encoded)
  }

  override fun getSecurityLevel(alias: String): String {
    val privateKey =
      keyStore().getKey(alias, null) as? PrivateKey
        ?: throw IllegalStateException("No signing key exists for alias [$alias].")

    val keyInfo =
      KeyFactory.getInstance(privateKey.algorithm, KEYSTORE)
        .getKeySpec(privateKey, KeyInfo::class.java)

    return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
      when (keyInfo.securityLevel) {
        KeyProperties.SECURITY_LEVEL_STRONGBOX -> LEVEL_STRONGBOX
        KeyProperties.SECURITY_LEVEL_TRUSTED_ENVIRONMENT -> LEVEL_TEE
        KeyProperties.SECURITY_LEVEL_SOFTWARE -> LEVEL_SOFTWARE
        // SECURITY_LEVEL_UNKNOWN / UNKNOWN_SECURE: the platform will not say.
        // Reported as SOFTWARE rather than guessed upward — an unverifiable
        // claim of hardware backing is worse than an understated one.
        else -> LEVEL_SOFTWARE
      }
    } else {
      // API 24-30 only exposes a boolean, which cannot distinguish StrongBox
      // from TEE. TRUSTED_ENVIRONMENT is the accurate floor.
      @Suppress("DEPRECATION")
      if (keyInfo.isInsideSecureHardware) LEVEL_TEE else LEVEL_SOFTWARE
    }
  }

  override fun sign(alias: String, payload: String, promise: Promise) {
    try {
      val privateKey =
        keyStore().getKey(alias, null) as? PrivateKey
          ?: throw IllegalStateException("No signing key exists for alias [$alias].")

      val signature =
        Signature.getInstance(SIGNATURE_ALGORITHM).apply {
          initSign(privateKey)
          // The canonical payload is signed as UTF-8 bytes. PHP's
          // openssl_verify() is handed the same string, so both sides digest
          // identical input — see AttendancePayload on both sides.
          update(payload.toByteArray(Charsets.UTF_8))
        }

      // DER-encoded, then base64 with NO_WRAP: line breaks would corrupt it in
      // transit through JSON and PHP's base64_decode(strict) would reject it.
      promise.resolve(Base64.encodeToString(signature.sign(), Base64.NO_WRAP))
    } catch (e: Exception) {
      promise.reject("sign_failed", "Could not sign the attendance payload: ${e.message}", e)
    }
  }

  override fun deleteKey(alias: String): Boolean =
    try {
      keyStore().deleteEntry(alias)
      true
    } catch (e: Exception) {
      false
    }

  /**
   * Database-key wrapping for SQLCipher at rest (STD TC-02).
   *
   * The 32 random bytes come from SecureRandom inside this method and are
   * returned only as AES-GCM ciphertext under a Keystore-held wrap key, as
   * `ivHex.cipherHex`. The raw key never crosses the bridge in either
   * direction — generate returns ciphertext, unwrap returns hex for open()
   * to consume immediately — so a memory dump of the JS side never yields
   * key material that outlives the call.
   *
   * The wrap key is created once per alias and never deleted by app code:
   * the database outlives bindings and revocations, and deleting the wrap
   * key would orphan the encrypted database. Uninstalling the app wipes both
   * the Keystore entry and the database file.
   */
  override fun generateWrappedDbKey(alias: String, promise: Promise) {
    try {
      val wrapKey = wrapKey(alias)
      val raw = ByteArray(DB_KEY_BYTES).also { SecureRandom().nextBytes(it) }
      try {
        val cipher = Cipher.getInstance(WRAP_TRANSFORMATION).apply {
          init(Cipher.ENCRYPT_MODE, wrapKey)
        }
        val cipherText = cipher.doFinal(raw)
        promise.resolve("${cipher.iv.toHex()}.${cipherText.toHex()}")
      } finally {
        raw.fill(0)
      }
    } catch (e: Exception) {
      promise.reject("dbkey_failed", "Could not generate a database key: ${e.message}", e)
    }
  }

  override fun unwrapDbKey(alias: String, wrapped: String, promise: Promise) {
    try {
      val wrapKey =
        keyStore().getKey(alias, null) as? SecretKey
          ?: throw IllegalStateException("No database wrap key exists for alias [$alias].")

      val parts = wrapped.split(".")
      if (parts.size != 2) {
        throw IllegalArgumentException("Wrapped database key has the wrong shape.")
      }
      val iv = parts[0].hexToBytes()
      val cipherText = parts[1].hexToBytes()
      if (iv.size != GCM_IV_BYTES || cipherText.isEmpty()) {
        throw IllegalArgumentException("Wrapped database key has the wrong shape.")
      }

      val plain =
        Cipher.getInstance(WRAP_TRANSFORMATION).apply {
          init(Cipher.DECRYPT_MODE, wrapKey, GCMParameterSpec(GCM_TAG_BITS, iv))
        }.doFinal(cipherText)
      try {
        promise.resolve(plain.toHex())
      } finally {
        plain.fill(0)
      }
    } catch (e: Exception) {
      promise.reject("dbkey_failed", "Could not unwrap the database key: ${e.message}", e)
    }
  }

  private fun wrapKey(alias: String): SecretKey {
    (keyStore().getKey(alias, null) as? SecretKey)?.let { return it }

    return try {
      generateWrapKey(alias, strongBox = true)
    } catch (e: StrongBoxUnavailableException) {
      // Same fallback as the signing key: TEE instead of StrongBox.
      generateWrapKey(alias, strongBox = false)
    }
  }

  private fun generateWrapKey(alias: String, strongBox: Boolean): SecretKey {
    val builder =
      KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
        .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
        .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
        .setKeySize(WRAP_KEY_SIZE)
        /*
         * No user authentication, for the same reason as the signing key:
         * background sync must open the database with nobody holding the
         * phone, and the record's integrity does not depend on re-proving
         * who is holding it.
         */
        .setUserAuthenticationRequired(false)
        .setRandomizedEncryptionRequired(true)

    if (strongBox && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
      builder.setIsStrongBoxBacked(true)
    }

    val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
    generator.init(builder.build())

    return generator.generateKey()
  }

  private fun ByteArray.toHex(): String = joinToString("") { "%02x".format(it) }

  private fun String.hexToBytes(): ByteArray {
    require(length % 2 == 0) { "Odd-length hex string." }
    return chunked(2).map { it.toInt(16).toByte() }.toByteArray()
  }

  /**
   * X.509 SubjectPublicKeyInfo DER wrapped as PEM, at 64 characters per line.
   *
   * 64 rather than Base64.DEFAULT's 76: PHP's openssl_pkey_get_public() is
   * tolerant in practice, but 64 is what the PEM convention specifies and
   * there is no reason to hand the server something non-standard.
   */
  private fun toPem(derEncoded: ByteArray): String {
    val body =
      Base64.encodeToString(derEncoded, Base64.NO_WRAP).chunked(64).joinToString("\n")

    return "-----BEGIN PUBLIC KEY-----\n$body\n-----END PUBLIC KEY-----\n"
  }
}
