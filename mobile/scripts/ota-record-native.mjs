#!/usr/bin/env node
/**
 * OTA native-version record (Add-on C, O2).
 *
 * Writes mobile/ota/native-versions.json: which SCHEMA_VERSION each APK
 * nativeVersion (== versionCode) shipped, plus the package-lock hash the APK
 * was built from. Run right after every assembleRelease — ota-publish.mjs
 * refuses a bundle for a nativeVersion with no record, when the record's
 * schemaVersion differs from the tree's, or when dependencies changed since
 * the record (a library upgrade can add images or native code the APK lacks;
 * that ships as an APK, then re-record).
 *
 * Usage: npm run ota:record-native   (from mobile/)
 *
 * @format
 */

import fs from 'node:fs';
import {createHash} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const RECORD_PATH = path.join(ROOT, 'ota', 'native-versions.json');

function readVersionCode() {
  const gradle = fs.readFileSync(
    path.join(ROOT, 'android', 'app', 'build.gradle'),
    'utf8',
  );
  const match = gradle.match(/^(\s*)versionCode (\d+)\s*$/m);
  if (!match) {
    throw new Error('ota-record-native: versionCode not found in android/app/build.gradle');
  }
  return Number(match[2]);
}

function readSchemaVersion() {
  const database = fs.readFileSync(path.join(ROOT, 'src', 'db', 'database.ts'), 'utf8');
  const match = database.match(/export const SCHEMA_VERSION = (\d+);/);
  if (!match) {
    throw new Error('ota-record-native: export const SCHEMA_VERSION not found in src/db/database.ts');
  }
  return Number(match[1]);
}

function main() {
  const versionCode = readVersionCode();
  const schemaVersion = readSchemaVersion();
  const packageLockSha256 = createHash('sha256')
    .update(fs.readFileSync(path.join(ROOT, 'package-lock.json')))
    .digest('hex');

  let records = {};
  if (fs.existsSync(RECORD_PATH)) {
    records = JSON.parse(fs.readFileSync(RECORD_PATH, 'utf8'));
  }

  const previous = records[String(versionCode)];
  records[String(versionCode)] = {
    schemaVersion,
    packageLockSha256,
    recordedAt: new Date().toISOString(),
  };
  fs.mkdirSync(path.dirname(RECORD_PATH), {recursive: true});
  fs.writeFileSync(RECORD_PATH, JSON.stringify(records, null, 2) + '\n');

  if (previous && previous.schemaVersion !== schemaVersion) {
    console.log(
      `ota-record-native: nativeVersion ${versionCode} schema ${previous.schemaVersion} -> ${schemaVersion}.`,
    );
  } else {
    console.log(
      `ota-record-native: nativeVersion ${versionCode} records schemaVersion ${schemaVersion} + lock ${packageLockSha256.slice(0, 12)}….`,
    );
  }
}

try {
  main();
} catch (err) {
  console.error(err instanceof Error ? err.message : err);
  process.exit(1);
}
