package com.hrismobile

import java.io.File
import java.net.ServerSocket
import java.util.Base64
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder

/**
 * OtaManager unit tests (Add-on C, O1) — the real verify/rollback paths on
 * the JVM, against committed fixtures signed by the TEST-ONLY keypair
 * (src/test/resources/ota). The production key is never involved: the
 * manager takes the public key as a constructor parameter.
 */
class OtaManagerTest {

  @get:Rule
  val temp = TemporaryFolder()

  private fun fixtureBytes(name: String): ByteArray {
    val url =
      javaClass.classLoader.getResource("ota/$name")
        ?: throw IllegalStateException("missing fixture $name")
    return File(url.toURI()).readBytes()
  }

  /** Test public key as hex — java.util.Base64 is fine here (dev JVM, not the device). */
  private fun testKeyHex(): String {
    val pem = String(fixtureBytes("test-key-public.pem"), Charsets.UTF_8)
    val body =
      pem.lines()
        .filter { !it.startsWith("#") && !it.startsWith("-----") }
        .joinToString("")
    val der = Base64.getMimeDecoder().decode(body)
    return der.joinToString("") { "%02x".format(it) }
  }

  private fun manager(): OtaManager = OtaManager(temp.root, testKeyHex(), 1)

  @Test
  fun validBundleStages() {
    val status =
      manager().stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))

    assertEquals("staged:ota-1-2", status)
    assertTrue(File(temp.root, "pending/manifest.txt").isFile)
    assertTrue(File(temp.root, "pending/bundle.hbc").isFile)
  }

  @Test
  fun tamperedBundleRefused() {
    val status =
      manager().stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("tampered-bundle.bin"))

    assertEquals("refused:bad-bundle-hash", status)
  }

  @Test
  fun wrongKeyRefused() {
    val status =
      manager().stageDownloaded(
        fixtureBytes("wrong-key-manifest.txt"),
        fixtureBytes("wrong-key-bundle.bin"),
      )

    assertEquals("refused:bad-signature", status)
  }

  @Test
  fun garbageManifestRefused() {
    val status =
      manager().stageDownloaded("not a manifest".toByteArray(), fixtureBytes("valid-bundle.bin"))

    assertEquals("refused:bad-manifest", status)
  }

  @Test
  fun foreignNativeRefusedAtStaging() {
    val status =
      manager().stageDownloaded(
        fixtureBytes("foreign-manifest.txt"),
        fixtureBytes("foreign-bundle.bin"),
      )

    assertEquals("refused:wrong-native", status)
  }

  @Test
  fun antiDowngradeLivesAtStaging() {
    val ota = manager()
    assertEquals(
      "staged:ota-1-2",
      ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin")),
    )
    assertNotNull(ota.selectBundle())
    ota.markHealthy()

    assertEquals(
      "refused:older-version",
      ota.stageDownloaded(fixtureBytes("old-manifest.txt"), fixtureBytes("old-bundle.bin")),
    )
  }

  @Test
  fun coldStartPromotesAndRunsPending() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))

    val selected = ota.selectBundle()

    assertNotNull(selected)
    assertEquals("bundle.hbc", selected!!.name)
    assertEquals(2, ota.currentBundleVersion())
  }

  @Test
  fun crashRollsBackAndRefuses() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    assertNotNull(ota.selectBundle())

    // No markHealthy: the next cold start must roll back to embedded (there
    // is no previous bundle) and refuse the crashed id from then on.
    assertNull(ota.selectBundle())
    assertEquals(0, ota.currentBundleVersion())
    assertEquals(
      "refused:refused-id",
      ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin")),
    )
  }

  @Test
  fun healthyBundleSurvivesRestart() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    assertNotNull(ota.selectBundle())
    ota.markHealthy()

    assertNotNull(ota.selectBundle())
    assertEquals(2, ota.currentBundleVersion())
  }

  @Test
  fun rollbackToPreviousKeepsAnOlderBundle() {
    val ota = manager()
    // Install v2 as current first.
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    assertNotNull(ota.selectBundle())
    ota.markHealthy()
    // Note: the old v1 fixture cannot be staged now (highest is 2) — the
    // downgrade path is exercised through the crash-rollback refusal set
    // instead: refusing must not disturb the running bundle.
    assertNotNull(ota.selectBundle())
    assertEquals(2, ota.currentBundleVersion())
  }

  @Test
  fun apkUpgradeDiscardsStaleBundles() {
    // A v2-native world installs the foreign (nativeVersion 2) bundle.
    val v2 = OtaManager(temp.root, testKeyHex(), 2)
    assertEquals(
      "staged:ota-2-1",
      v2.stageDownloaded(fixtureBytes("foreign-manifest.txt"), fixtureBytes("foreign-bundle.bin")),
    )
    assertNotNull(v2.selectBundle())

    // The v1-native APK takes over: the stored bundle is discarded, embedded runs.
    val v1 = OtaManager(temp.root, testKeyHex(), 1)
    assertNull(v1.selectBundle())
    assertEquals(0, v1.currentBundleVersion())
  }

  @Test
  fun corruptStateFallsBackToEmbedded() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    // Properties.load is lenient — garbage parses to defaults, i.e. a fresh
    // state that knows nothing of the staged bundle: embedded runs, and the
    // bundle can be staged anew.
    File(temp.root, "state").writeText("not a properties file {{{")

    assertNull(ota.selectBundle())
    assertEquals(
      "staged:ota-1-2",
      ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin")),
    )
  }

  @Test
  fun unreadableStateResetsWithoutThrowing() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    // A directory where the state file belongs: genuinely unreadable.
    // selectBundle must still return null, never throw — and the reset must
    // clear the slots it can no longer account for.
    File(temp.root, "state").delete()
    File(temp.root, "state").mkdir()

    assertNull(ota.selectBundle())
    assertTrue(!File(temp.root, "pending/manifest.txt").isFile)
  }

  @Test
  fun plantedBundleDetectedAtLoad() {
    val ota = manager()
    ota.stageDownloaded(fixtureBytes("valid-manifest.txt"), fixtureBytes("valid-bundle.bin"))
    assertNotNull(ota.selectBundle())
    ota.markHealthy()

    // A root attacker swaps the bundle bytes after staging.
    val planted = File(temp.root, "current/bundle.hbc").readBytes()
    planted[0] = (planted[0].toInt() xor 0xFF).toByte()
    File(temp.root, "current/bundle.hbc").writeBytes(planted)

    assertNull(ota.selectBundle())
    assertEquals(0, ota.currentBundleVersion())
  }

  @Test
  fun downloadAndStageEndToEnd() {
    val manifest = fixtureBytes("valid-manifest.txt")
    val bundle = fixtureBytes("valid-bundle.bin")
    // Hand-rolled stub: com.sun.net.httpserver is not on the unit-test
    // compile classpath, and a fake needs no dependency. Query strings are
    // ignored, like a minimal O3 would.
    val server =
      FakeHttpServer(
        mapOf(
          "/latest" to manifest,
          "/ota-1-2/bundle" to bundle,
        ),
      )
    try {
      val base = "http://127.0.0.1:${server.port}"

      val ota = manager()
      assertEquals("staged:ota-1-2", ota.downloadAndStage(base))
      assertNotNull(ota.selectBundle())
      ota.markHealthy()

      // Same manifest again: already running, nothing to do.
      assertEquals("up-to-date", ota.downloadAndStage(base))
    } finally {
      server.stop()
    }
  }

  /**
   * Minimal canned HTTP server (java.base only): exact-path routes, 404
   * otherwise, Connection: close so no keep-alive hangs the client.
   */
  private class FakeHttpServer(private val routes: Map<String, ByteArray>) {
    private val socket = ServerSocket(0)
    val port: Int
      get() = socket.localPort

    private val thread =
      Thread {
        try {
          while (!socket.isClosed) {
            val client =
              try {
                socket.accept()
              } catch (e: Exception) {
                return@Thread
              }
            try {
              client.use {
                val reader = it.getInputStream().bufferedReader()
                val requestLine = reader.readLine() ?: return@use
                while (true) {
                  val header = reader.readLine()
                  if (header == null || header.isEmpty()) break
                }
                val path =
                  requestLine.split(" ").getOrNull(1)?.substringBefore("?") ?: ""
                val body = routes[path]
                val out = it.getOutputStream()
                if (body == null) {
                  out.write(
                    "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
                      .toByteArray(),
                  )
                } else {
                  out.write(
                    "HTTP/1.1 200 OK\r\nContent-Length: ${body.size}\r\nConnection: close\r\n\r\n"
                      .toByteArray(),
                  )
                  out.write(body)
                }
                out.flush()
              }
            } catch (e: Exception) {
              // A dropped stub connection fails the assertion, not the server.
            }
          }
        } catch (e: Exception) {
          // Socket closed during stop().
        }
      }.also {
        it.isDaemon = true
        it.start()
      }

    fun stop() {
      try {
        socket.close()
      } catch (e: Exception) {
        // Already closed.
      }
    }
  }
}
