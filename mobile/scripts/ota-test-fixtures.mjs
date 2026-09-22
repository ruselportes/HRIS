#!/usr/bin/env node
/**
 * OTA test fixtures (Add-on C, O2).
 *
 * Signs a small deterministic fixture set with the COMMITTED test-only
 * keypair (android/app/src/test/resources/ota/test-key-*.pem) — so
 * regenerating fixtures needs no secret, and the O1 unit tests never touch
 * the production key. The production public key is compiled into OtaManager
 * as a constructor parameter precisely so tests can pass a different one.
 *
 * Fixtures (all under src/test/resources/ota/):
 *   valid-manifest.txt + valid-bundle.bin      — bundleVersion 2, verifies
 *   tampered-bundle.bin                        — valid bundle + 1 flipped byte
 *                                                (same manifest: sha256 fails)
 *   wrong-key-manifest.txt + wrong-key-bundle.bin — same bytes, manifest
 *                                                signed with an ephemeral key
 *                                                the test public key rejects
 *   old-manifest.txt + old-bundle.bin          — bundleVersion 1, test-key
 *                                                signed (staging anti-downgrade)
 *   foreign-manifest.txt + foreign-bundle.bin  — nativeVersion 2, test-key
 *                                                signed (APK-upgrade discard:
 *                                                stored bundles for another
 *                                                native version are dropped)
 *
 * Bundle payloads are small synthetic byte strings, not real hermesc output:
 * the verifier is byte-agnostic (sha256 + signature), so the crypto and
 * file-layout paths are identical while fixtures stay tiny and instant.
 *
 * Idempotent: rewrites the whole set. ECDSA uses a random nonce, so
 * signatures change on every run — always valid as a set, never hand-edit.
 *
 * Usage: npm run ota:fixtures   (from mobile/)
 *
 * @format
 */

import {createHash, generateKeyPairSync, sign, createPrivateKey} from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {buildManifest} from './ota-manifest.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FIXTURE_DIR = path.join(
  ROOT,
  'android',
  'app',
  'src',
  'test',
  'resources',
  'ota',
);

const CREATED_AT = '2026-09-22T00:00:00+08:00';

function sha256Hex(bytes) {
  return createHash('sha256').update(bytes).digest('hex');
}

/** Deterministic N-byte payload: byte i is (seed + i) mod 256. */
function patternBytes(seed, length) {
  const bytes = Buffer.alloc(length);
  for (let i = 0; i < length; i++) {
    bytes[i] = (seed + i) % 256;
  }
  return bytes;
}

function writeManifest(name, values, privateKey) {
  const bundleBytes = values.__bundle;
  const {manifestText} = buildManifest(
    {
      id: values.id,
      bundleVersion: String(values.bundleVersion),
      nativeVersion: String(values.nativeVersion),
      sha256: sha256Hex(bundleBytes),
      createdAt: CREATED_AT,
      notes: values.notes,
    },
    signedBytes => sign('sha256', signedBytes, privateKey).toString('hex'),
  );
  fs.writeFileSync(path.join(FIXTURE_DIR, name), manifestText);
}

function main() {
  const testPrivatePem = fs.readFileSync(
    path.join(FIXTURE_DIR, 'test-key-private.pem'),
    'utf8',
  );
  const testKey = createPrivateKey({key: testPrivatePem, format: 'pem'});

  // An ephemeral wrong key, never committed: proves the verifier rejects a
  // well-formed signature from an untrusted key, not just garbage.
  const {privateKey: wrongKey} = generateKeyPairSync('ec', {
    namedCurve: 'prime256v1',
  });

  const validBundle = patternBytes(7, 128);
  fs.writeFileSync(path.join(FIXTURE_DIR, 'valid-bundle.bin'), validBundle);
  writeManifest(
    'valid-manifest.txt',
    {
      id: 'ota-1-2',
      bundleVersion: 2,
      nativeVersion: 1,
      notes: 'test fixture: verifies',
      __bundle: validBundle,
    },
    testKey,
  );

  const tampered = Buffer.from(validBundle);
  tampered[0] ^= 0xff;
  fs.writeFileSync(path.join(FIXTURE_DIR, 'tampered-bundle.bin'), tampered);

  fs.writeFileSync(path.join(FIXTURE_DIR, 'wrong-key-bundle.bin'), validBundle);
  writeManifest(
    'wrong-key-manifest.txt',
    {
      id: 'ota-1-2',
      bundleVersion: 2,
      nativeVersion: 1,
      notes: 'test fixture: wrong signing key',
      __bundle: validBundle,
    },
    wrongKey,
  );

  const oldBundle = patternBytes(99, 128);
  fs.writeFileSync(path.join(FIXTURE_DIR, 'old-bundle.bin'), oldBundle);
  writeManifest(
    'old-manifest.txt',
    {
      id: 'ota-1-1',
      bundleVersion: 1,
      nativeVersion: 1,
      notes: 'test fixture: superseded version',
      __bundle: oldBundle,
    },
    testKey,
  );

  const foreignBundle = patternBytes(55, 128);
  fs.writeFileSync(path.join(FIXTURE_DIR, 'foreign-bundle.bin'), foreignBundle);
  writeManifest(
    'foreign-manifest.txt',
    {
      id: 'ota-2-1',
      bundleVersion: 1,
      nativeVersion: 2,
      notes: 'test fixture: another native version',
      __bundle: foreignBundle,
    },
    testKey,
  );

  console.log(`ota-test-fixtures: wrote 10 fixture files to ${FIXTURE_DIR}.`);
}

try {
  main();
} catch (err) {
  console.error(err instanceof Error ? err.message : err);
  process.exit(1);
}
