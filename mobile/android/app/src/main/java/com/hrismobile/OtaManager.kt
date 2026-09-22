package com.hrismobile

import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.KeyFactory
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.X509EncodedKeySpec
import java.util.Properties

/**
 * OTA bundle selection + verification (Add-on C, O1).
 *
 * Pure JVM — java.io + java.net + java.security only, no android.* imports —
 * so plain JUnit tests exercise the real verify/rollback paths. Hex (not
 * base64) for the key and signatures: java.util.Base64 is API 26+ and minSdk
 * is 24. No JSON parser anywhere: the manifest is key=value lines (see
 * mobile/scripts/ota-manifest.mjs for the byte rules both sides implement);
 * the signature covers the raw bytes before the signature line, so
 * verification needs no parsing at all — verify first, parse after.
 *
 * The public key arrives as a constructor parameter: production passes the
 * compiled-in key (OtaPublicKey), tests pass the committed fixture key, and
 * O1's unit tests never touch the production key.
 *
 * Layout under otaDir: current/, pending/, previous/ (each manifest.txt +
 * bundle.hbc) plus a state file (current/pending ids, highest version ever
 * installed, a verifying flag, refused ids). The state file is written
 * atomically (temp + rename).
 */
class OtaManager(
  private val otaDir: File,
  private val publicKeyHex: String,
  private val versionCode: Int,
) {

  data class Manifest(
    val id: String,
    val bundleVersion: Int,
    val nativeVersion: Int,
    val sha256: String,
    val createdAt: String,
    val notes: String,
  )

  private data class State(
    val currentId: String,
    val currentVersion: Int,
    val pendingId: String,
    val highestVersion: Int,
    val verifyingId: String,
    val refusedIds: Set<String>,
  ) {
    companion object {
      fun fresh(): State = State("", 0, "", 0, "", emptySet())
    }
  }

  companion object {
    private const val MANIFEST_NAME = "manifest.txt"
    private const val BUNDLE_NAME = "bundle.hbc"
    private const val STATE_NAME = "state"
    private val SIG_MARKER = "\nsignature=".toByteArray(Charsets.UTF_8)
    private val MANIFEST_KEYS = setOf(
      "id", "bundleVersion", "nativeVersion", "sha256", "createdAt", "notes",
    )
  }

  // -- hex (hand-rolled — see the class docs for why) ----------------------

  private fun hexDigit(c: Char): Int =
    when (c) {
      in '0'..'9' -> c - '0'
      in 'a'..'f' -> c - 'a' + 10
      in 'A'..'F' -> c - 'A' + 10
      else -> throw IllegalArgumentException("not hex: $c")
    }

  private fun hexToBytes(hex: String): ByteArray? {
    if (hex.isEmpty() || hex.length % 2 != 0) return null
    return try {
      ByteArray(hex.length / 2) { i ->
        ((hexDigit(hex[2 * i]) shl 4) or hexDigit(hex[2 * i + 1])).toByte()
      }
    } catch (e: IllegalArgumentException) {
      null
    }
  }

  private fun bytesToHex(bytes: ByteArray): String {
    val alphabet = "0123456789abcdef"
    val chars = CharArray(bytes.size * 2)
    bytes.forEachIndexed { i, byte ->
      val v = byte.toInt() and 0xFF
      chars[i * 2] = alphabet[v ushr 4]
      chars[i * 2 + 1] = alphabet[v and 0x0F]
    }
    return String(chars)
  }

  private val publicKey by lazy {
    val der =
      hexToBytes(publicKeyHex)
        ?: throw IllegalStateException("OTA public key is not hex")
    KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(der))
  }

  // -- manifest: split, verify, parse ---------------------------------------

  private fun lastIndexOf(haystack: ByteArray, needle: ByteArray): Int {
    if (needle.isEmpty() || haystack.size < needle.size) return -1
    var i = haystack.size - needle.size
    while (i >= 0) {
      var j = 0
      while (j < needle.size && haystack[i + j] == needle[j]) j++
      if (j == needle.size) return i
      i--
    }
    return -1
  }

  /**
   * Split raw manifest bytes into (signed bytes, signature bytes). The signed
   * bytes are everything before the signature line, INCLUDING the '\n' that
   * precedes it — exactly what the publish script signed. Null when the
   * structure is wrong (missing/garbled signature line); that is a
   * bad-manifest refusal, distinct from a bad signature.
   */
  private fun splitSignature(manifestBytes: ByteArray): Pair<ByteArray, ByteArray>? {
    val marker = lastIndexOf(manifestBytes, SIG_MARKER)
    if (marker < 0) return null
    val sigHex =
      String(
        manifestBytes, marker + SIG_MARKER.size,
        manifestBytes.size - marker - SIG_MARKER.size, Charsets.UTF_8,
      ).trim()
    val sigBytes = hexToBytes(sigHex) ?: return null
    return Pair(manifestBytes.copyOfRange(0, marker + 1), sigBytes)
  }

  private fun checkSignature(signed: ByteArray, sigBytes: ByteArray): Boolean {
    return try {
      Signature.getInstance("SHA256withECDSA").apply {
        initVerify(publicKey)
        update(signed)
      }.verify(sigBytes)
    } catch (e: Exception) {
      false
    }
  }

  private fun parseManifest(signed: ByteArray): Manifest? {
    val fields = mutableMapOf<String, String>()
    for (line in String(signed, Charsets.UTF_8).split('\n')) {
      if (line.isEmpty()) continue
      val eq = line.indexOf('=')
      if (eq < 1) return null
      fields[line.substring(0, eq)] = line.substring(eq + 1)
    }
    if (fields.keys != MANIFEST_KEYS) return null
    val bundleVersion = fields.getValue("bundleVersion").toIntOrNull() ?: return null
    val nativeVersion = fields.getValue("nativeVersion").toIntOrNull() ?: return null
    if (bundleVersion < 1 || nativeVersion < 1) return null
    val sha256 = fields.getValue("sha256")
    if (!sha256.matches(Regex("[0-9a-f]{64}"))) return null
    val id = fields.getValue("id")
    val createdAt = fields.getValue("createdAt")
    val notes = fields.getValue("notes")
    if (id.isEmpty() || createdAt.isEmpty() || notes.isEmpty()) return null
    return Manifest(id, bundleVersion, nativeVersion, sha256, createdAt, notes)
  }

  private fun sha256Hex(bytes: ByteArray): String {
    val digest = MessageDigest.getInstance("SHA-256")
    return bytesToHex(digest.digest(bytes))
  }

  // -- state (java.util.Properties: pure JVM, unlike org.json) --------------

  private fun stateFile(): File = File(otaDir, STATE_NAME)

  private fun loadState(): State? {
    return try {
      val file = stateFile()
      if (!file.exists()) return State.fresh()
      // Present but not a plain file (e.g. a directory at the state path):
      // genuinely unreadable, so the caller resets — unlike a first launch,
      // where there is simply nothing to read yet.
      if (!file.isFile) return null
      val props = Properties()
      file.inputStream().use { props.load(it) }
      State(
        currentId = props.getProperty("currentId", ""),
        currentVersion = props.getProperty("currentVersion", "0").toIntOrNull() ?: return null,
        pendingId = props.getProperty("pendingId", ""),
        highestVersion = props.getProperty("highestVersion", "0").toIntOrNull() ?: return null,
        verifyingId = props.getProperty("verifyingId", ""),
        refusedIds =
          props.getProperty("refusedIds", "")
            .split(",").map { it.trim() }.filter { it.isNotEmpty() }.toSet(),
      )
    } catch (e: Exception) {
      null
    }
  }

  private fun saveState(state: State) {
    val props = Properties()
    props.setProperty("currentId", state.currentId)
    props.setProperty("currentVersion", state.currentVersion.toString())
    props.setProperty("pendingId", state.pendingId)
    props.setProperty("highestVersion", state.highestVersion.toString())
    props.setProperty("verifyingId", state.verifyingId)
    props.setProperty("refusedIds", state.refusedIds.sorted().joinToString(","))
    otaDir.mkdirs()
    val tmp = File(otaDir, "$STATE_NAME.tmp")
    tmp.outputStream().use { props.store(it, null) }
    atomicReplace(tmp, stateFile())
  }

  // -- slots -----------------------------------------------------------------

  /**
   * Replace target with tmp. renameTo cannot overwrite on Windows (and the
   * unit tests run there), so prefer Files.move with REPLACE_EXISTING —
   * atomic on the same filesystem — falling back to delete + renameTo on
   * API 24/25, which has no java.nio.file. The fallback's references resolve
   * lazily inside the try, so merely loading this class is safe everywhere.
   */
  private fun atomicReplace(tmp: File, target: File) {
    try {
      java.nio.file.Files.move(
        tmp.toPath(),
        target.toPath(),
        java.nio.file.StandardCopyOption.REPLACE_EXISTING,
      )
    } catch (e: LinkageError) {
      target.delete()
      if (!tmp.renameTo(target)) {
        throw java.io.IOException("OTA file replace failed")
      }
    }
  }

  private fun slotDir(name: String): File = File(otaDir, name)

  private fun readSlot(name: String): Pair<ByteArray, ByteArray>? {
    val dir = slotDir(name)
    val manifest = File(dir, MANIFEST_NAME)
    val bundle = File(dir, BUNDLE_NAME)
    if (!manifest.isFile || !bundle.isFile) return null
    return try {
      Pair(manifest.readBytes(), bundle.readBytes())
    } catch (e: Exception) {
      null
    }
  }

  private fun clearSlot(name: String) {
    slotDir(name).deleteRecursively()
  }

  private fun writeSlot(name: String, manifestBytes: ByteArray, bundleBytes: ByteArray) {
    val dir = slotDir(name)
    dir.mkdirs()
    val manifestTmp = File(dir, "$MANIFEST_NAME.tmp")
    val bundleTmp = File(dir, "$BUNDLE_NAME.tmp")
    manifestTmp.writeBytes(manifestBytes)
    bundleTmp.writeBytes(bundleBytes)
    atomicReplace(manifestTmp, File(dir, MANIFEST_NAME))
    atomicReplace(bundleTmp, File(dir, BUNDLE_NAME))
  }

  private fun moveSlot(from: String, to: String) {
    clearSlot(to)
    val src = slotDir(from)
    if (src.exists()) {
      atomicReplace(src, slotDir(to))
    }
  }

  /**
   * Parse-only native version for the discard pass. Unverified bytes are fine
   * for a discard decision: a forged manifest either gets discarded here or
   * fails full verification below — there is no path where parsing alone
   * trusts anything.
   */
  private fun parseNativeVersion(manifestBytes: ByteArray): Int? {
    return try {
      String(manifestBytes, Charsets.UTF_8).split('\n')
        .firstOrNull { it.startsWith("nativeVersion=") }
        ?.substringAfter("=")
        ?.toIntOrNull()
    } catch (e: Exception) {
      null
    }
  }

  private fun parseField(manifestBytes: ByteArray, key: String): String {
    return try {
      String(manifestBytes, Charsets.UTF_8).split('\n')
        .firstOrNull { it.startsWith("$key=") }
        ?.substringAfter("=") ?: ""
    } catch (e: Exception) {
      ""
    }
  }

  // -- public API --------------------------------------------------------------

  fun nativeVersionCode(): Int = versionCode

  fun currentBundleVersion(): Int {
    return try {
      loadState()?.currentVersion ?: 0
    } catch (e: Exception) {
      0
    }
  }

  fun markHealthy() {
    try {
      val state = loadState() ?: return
      if (state.verifyingId.isEmpty()) return
      saveState(state.copy(verifyingId = ""))
    } catch (e: Exception) {
      // Best effort: a failed clear re-triggers rollback, which is the safe
      // direction — a healthy bundle can be re-selected, a bad one cannot run.
    }
  }

  /**
   * Verify an already-downloaded pair and stage it for the next cold start.
   * Anti-downgrade lives HERE, at staging — never at load — so a legitimate
   * crash rollback to `previous` (whose version is below highest) is never
   * blocked.
   */
  fun stageDownloaded(manifestBytes: ByteArray, bundleBytes: ByteArray): String {
    val split =
      try {
        splitSignature(manifestBytes)
      } catch (e: Exception) {
        null
      } ?: return "refused:bad-manifest"
    val signatureOk =
      try {
        checkSignature(split.first, split.second)
      } catch (e: Exception) {
        false
      }
    if (!signatureOk) return "refused:bad-signature"
    val manifest =
      try {
        parseManifest(split.first)
      } catch (e: Exception) {
        null
      } ?: return "refused:bad-manifest"
    if (sha256Hex(bundleBytes) != manifest.sha256) return "refused:bad-bundle-hash"
    if (manifest.nativeVersion != versionCode) return "refused:wrong-native"
    val state =
      try {
        loadState()
      } catch (e: Exception) {
        null
      } ?: State.fresh()
    if (manifest.id in state.refusedIds) return "refused:refused-id"
    if (manifest.bundleVersion < state.highestVersion) return "refused:older-version"
    if (manifest.bundleVersion == state.highestVersion) {
      return if (manifest.id == state.currentId) "up-to-date" else "refused:older-version"
    }
    return try {
      writeSlot("pending", manifestBytes, bundleBytes)
      saveState(state.copy(pendingId = manifest.id))
      "staged:${manifest.id}"
    } catch (e: Exception) {
      "refused:internal-error"
    }
  }

  /**
   * Fetch the newest manifest, verify it BEFORE downloading megabytes, then
   * fetch and stage the bundle. The served manifest bytes are the signed
   * bytes — O3 must serve the stored manifest.txt verbatim — so the client
   * verifies exactly what the publisher signed.
   */
  fun downloadAndStage(baseUrl: String): String {
    return try {
      downloadAndStageInner(baseUrl.trimEnd('/'))
    } catch (e: Exception) {
      "refused:network-error"
    }
  }

  private fun downloadAndStageInner(base: String): String {
    if (base.isEmpty()) return "refused:network-error"
    val state =
      try {
        loadState()
      } catch (e: Exception) {
        null
      } ?: State.fresh()
    val latest =
      httpGet("$base/latest?native=$versionCode&current=${state.highestVersion}")
        ?: return "refused:network-error"
    if (latest.isEmpty()) return "up-to-date"
    val split =
      try {
        splitSignature(latest)
      } catch (e: Exception) {
        null
      } ?: return "refused:bad-manifest"
    val signatureOk =
      try {
        checkSignature(split.first, split.second)
      } catch (e: Exception) {
        false
      }
    if (!signatureOk) return "refused:bad-signature"
    val manifest =
      try {
        parseManifest(split.first)
      } catch (e: Exception) {
        null
      } ?: return "refused:bad-manifest"
    if (manifest.nativeVersion != versionCode) return "refused:wrong-native"
    if (manifest.id in state.refusedIds) return "refused:refused-id"
    if (manifest.bundleVersion < state.highestVersion) return "refused:older-version"
    if (manifest.bundleVersion == state.highestVersion) {
      return if (manifest.id == state.currentId) "up-to-date" else "refused:older-version"
    }
    val bundle = httpGet("$base/${manifest.id}/bundle") ?: return "refused:network-error"
    return stageDownloaded(latest, bundle)
  }

  private fun httpGet(url: String): ByteArray? {
    return try {
      val connection = URL(url).openConnection() as HttpURLConnection
      try {
        connection.instanceFollowRedirects = true
        connection.connectTimeout = 10000
        connection.readTimeout = 30000
        connection.requestMethod = "GET"
        connection.connect()
        if (connection.responseCode == 204) return ByteArray(0)
        if (connection.responseCode != 200) return null
        val out = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(8192)
        var total = 0
        val stream = connection.inputStream
        while (true) {
          val read = stream.read(buffer)
          if (read < 0) break
          total += read
          if (total > 32 * 1024 * 1024) return null
          out.write(buffer, 0, read)
        }
        out.toByteArray()
      } finally {
        connection.disconnect()
      }
    } catch (e: Exception) {
      null
    }
  }

  /** Cold-start entry point. Selects the bundle file, or null for the embedded one. Never throws. */
  fun selectBundlePath(): String? {
    return try {
      selectBundle()?.absolutePath
    } catch (e: Exception) {
      null
    }
  }

  fun selectBundle(): File? {
    return try {
      selectBundleInner()
    } catch (e: Exception) {
      null
    }
  }

  private fun resetAll() {
    clearSlot("current")
    clearSlot("pending")
    clearSlot("previous")
    saveState(State.fresh())
  }

  private fun selectBundleInner(): File? {
    // Non-null by construction from here on: later `state = state.copy(...)`
    // reassignments would otherwise discard the Elvis smart cast.
    var state: State =
      loadState() ?: run {
        // State unreadable: embedded bundle, fresh state.
        resetAll()
        return null
      }

    // Crash rollback: the previous cold start promoted a bundle that never
    // marked healthy. Its id is refused so it is never staged again.
    if (state.verifyingId.isNotEmpty()) {
      val rolledBack = rollbackToPrevious()
      state =
        state.copy(
          currentId = rolledBack.first,
          currentVersion = rolledBack.second,
          pendingId = "",
          verifyingId = "",
          refusedIds = state.refusedIds + state.verifyingId,
        )
      clearSlot("pending")
      saveState(state)
    }

    // Discard bundles built for a different native version (APK upgrade).
    for (slot in listOf("current", "pending", "previous")) {
      val manifestBytes =
        File(slotDir(slot), MANIFEST_NAME).takeIf { it.isFile }?.readBytes() ?: continue
      if (parseNativeVersion(manifestBytes) != versionCode) {
        clearSlot(slot)
        if (slot == "current") state = state.copy(currentId = "", currentVersion = 0)
        if (slot == "pending") state = state.copy(pendingId = "")
      }
    }
    saveState(state)

    // Promote pending. Verified BEFORE moving files, so a forged pending can
    // never clobber the running bundle.
    if (state.pendingId.isNotEmpty()) {
      val slot = readSlot("pending")
      val manifest =
        slot?.let {
          try {
            verifyBundle(it.first, it.second)
          } catch (e: Exception) {
            null
          }
        }
      if (manifest != null &&
        manifest.id == state.pendingId &&
        manifest.id !in state.refusedIds &&
        manifest.nativeVersion == versionCode &&
        manifest.bundleVersion > state.highestVersion
      ) {
        moveSlot("current", "previous")
        moveSlot("pending", "current")
        state =
          state.copy(
            currentId = manifest.id,
            currentVersion = manifest.bundleVersion,
            pendingId = "",
            highestVersion = manifest.bundleVersion,
            verifyingId = manifest.id,
          )
      } else {
        clearSlot("pending")
        state = state.copy(pendingId = "")
      }
      saveState(state)
    }

    // Re-verify the selected bundle: filesDir is root-writable, so a planted
    // bundle must never become permanent code the TEE signs for. A few MB of
    // SHA-256 plus one ECDSA verify costs tens of milliseconds. A mismatch
    // means embedded bundle plus state reset.
    if (state.currentId.isEmpty()) return null
    val slot = readSlot("current")
    val manifest =
      slot?.let {
        try {
          verifyBundle(it.first, it.second)
        } catch (e: Exception) {
          null
        }
      }
    if (manifest == null || manifest.id != state.currentId || manifest.nativeVersion != versionCode) {
      resetAll()
      return null
    }
    state =
      state.copy(
        currentVersion = manifest.bundleVersion,
        highestVersion = maxOf(state.highestVersion, manifest.bundleVersion),
      )
    saveState(state)
    return File(slotDir("current"), BUNDLE_NAME)
  }

  private fun verifyBundle(manifestBytes: ByteArray, bundleBytes: ByteArray): Manifest? {
    val split =
      try {
        splitSignature(manifestBytes)
      } catch (e: Exception) {
        null
      } ?: return null
    val signatureOk =
      try {
        checkSignature(split.first, split.second)
      } catch (e: Exception) {
        false
      }
    if (!signatureOk) return null
    val manifest =
      try {
        parseManifest(split.first)
      } catch (e: Exception) {
        null
      } ?: return null
    if (sha256Hex(bundleBytes) != manifest.sha256) return null
    return manifest
  }

  /**
   * Roll back to previous; returns (id, version), or ("", 0) when there is
   * nothing to roll back to. Id/version are bookkeeping only — the next
   * selectBundle re-verifies before use.
   */
  private fun rollbackToPrevious(): Pair<String, Int> {
    clearSlot("current")
    val manifestBytes =
      File(slotDir("previous"), MANIFEST_NAME).takeIf { it.isFile }?.readBytes()
    if (manifestBytes == null || !File(slotDir("previous"), BUNDLE_NAME).isFile) {
      clearSlot("previous")
      return Pair("", 0)
    }
    val id = parseField(manifestBytes, "id")
    val version = parseField(manifestBytes, "bundleVersion").toIntOrNull() ?: 0
    moveSlot("previous", "current")
    return Pair(id, version)
  }
}
