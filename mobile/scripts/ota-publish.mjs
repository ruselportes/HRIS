#!/usr/bin/env node
/**
 * OTA publish (Add-on C, O2): Metro-bundle the app exactly as the release
 * Gradle task does, compile with the same hermesc, hash, sign the manifest
 * with the OTA private key, and write the release zip.
 *
 * Pipeline mirror (checked against BundleHermesCTask + ReactExtension):
 *   metro bundle --platform android --dev false --reset-cache --minify false
 *     (--reset-cache stays: a stale Metro cache ships old code silently)
 *   hermesc -w -emit-binary -max-diagnostic-width=80 -out <out> <bundle> -O
 *     (-O is the load-bearing flag; the plugin's -output-source-map only
 *     affects map output, which this script does not need from hermesc.
 *     The METRO --sourcemap-output IS kept and stored locally per bundle id
 *     for crash symbolication — never uploaded.)
 *
 * Manifest (see ota-manifest.mjs for the byte rules both sides implement):
 *   id, bundleVersion, nativeVersion, sha256, createdAt, notes, signature
 * as key=value lines; the ECDSA P-256 signature (DER, hex) covers the raw
 * bytes before the signature line. No JSON: Node and Kotlin must agree
 * byte-for-byte, and Unicode in notes alone breaks JSON canonicalization.
 *
 * Guards — the script REFUSES when:
 *   - no native-versions.json record exists for this versionCode, its
 *     schemaVersion differs from the tree's, or dependencies changed since
 *     the record (record with ota:record-native right after assembleRelease;
 *     recording at first publish would let the first bundle for a new APK
 *     slip a schema change through);
 *   - bundleVersion is not an integer above every version in
 *     ota/releases/index.json;
 *   - the Metro assets output holds app-owned images (flattened names not
 *     starting with node_modules_): files the APK may not ship. Library
 *     assets pass — pinned by the recorded lock hash, bundled identically
 *     into the APK from the same tree;
 *   - the tree is dirty under the shipped paths (override: --allow-dirty).
 *
 * Key: --key, else HRIS_OTA_KEY, else %USERPROFILE%/.hris/ota_private.pem.
 * The passphrase is prompted (hidden) every run — never a flag, never a file.
 *
 * Usage: npm run ota:publish -- --bundle-version 7 [--notes "..."] [--allow-dirty]
 *
 * @format
 */

import {execFileSync} from 'node:child_process';
import {createHash, createPrivateKey, sign} from 'node:crypto';
import {createInterface} from 'node:readline';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {buildManifest} from './ota-manifest.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OTA_DIR = path.join(ROOT, 'ota');
const RECORD_PATH = path.join(OTA_DIR, 'native-versions.json');

function fail(message) {
  console.error(`ota-publish: ${message}`);
  process.exit(1);
}

function parseArgs(argv) {
  const args = {notes: ''};
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i];
    if (flag === '--bundle-version') {
      args.bundleVersion = argv[++i];
    } else if (flag === '--notes') {
      args.notes = argv[++i] ?? '';
    } else if (flag === '--key') {
      args.keyPath = argv[++i];
    } else if (flag === '--out-dir') {
      args.outDir = argv[++i];
    } else if (flag === '--allow-dirty') {
      args.allowDirty = true;
    } else if (flag === '--help') {
      console.log(
        'Usage: npm run ota:publish -- --bundle-version N [--notes "..."] [--key PATH] [--out-dir DIR] [--allow-dirty]',
      );
      process.exit(0);
    } else {
      fail(`unknown flag ${flag} (see --help)`);
    }
  }
  if (args.bundleVersion === undefined) {
    fail('--bundle-version N is required');
  }
  if (!/^\d+$/.test(args.bundleVersion) || Number(args.bundleVersion) < 1) {
    fail('--bundle-version must be a positive integer');
  }
  args.bundleVersion = Number(args.bundleVersion);
  args.outDir = args.outDir ?? path.join(OTA_DIR, 'releases');
  return args;
}

function readVersionCode() {
  const gradle = fs.readFileSync(path.join(ROOT, 'android', 'app', 'build.gradle'), 'utf8');
  const match = gradle.match(/^(\s*)versionCode (\d+)\s*$/m);
  if (!match) {
    fail('versionCode not found in android/app/build.gradle');
  }
  return Number(match[2]);
}

function readSchemaVersion() {
  const database = fs.readFileSync(path.join(ROOT, 'src', 'db', 'database.ts'), 'utf8');
  const match = database.match(/export const SCHEMA_VERSION = (\d+);/);
  if (!match) {
    fail('export const SCHEMA_VERSION not found in src/db/database.ts');
  }
  return Number(match[1]);
}

function checkNativeRecord(versionCode, schemaVersion) {
  if (!fs.existsSync(RECORD_PATH)) {
    fail(
      `no ${path.relative(ROOT, RECORD_PATH)} — run npm run ota:record-native right after assembleRelease first`,
    );
  }
  const records = JSON.parse(fs.readFileSync(RECORD_PATH, 'utf8'));
  const record = records[String(versionCode)];
  if (!record) {
    fail(
      `nativeVersion ${versionCode} has no record — run npm run ota:record-native right after assembleRelease first`,
    );
  }
  if (record.schemaVersion !== schemaVersion) {
    fail(
      `tree schema is v${schemaVersion} but nativeVersion ${versionCode} recorded v${record.schemaVersion} — schema changes ship as an APK, never OTA`,
    );
  }
  const lockSha256 = createHash('sha256')
    .update(fs.readFileSync(path.join(ROOT, 'package-lock.json')))
    .digest('hex');
  if (record.packageLockSha256 !== lockSha256) {
    fail(
      'dependencies changed since nativeVersion ' +
        `${versionCode} was recorded — a library upgrade can add images or native code the APK lacks. ` +
        'Rebuild the APK, re-run npm run ota:record-native, then publish again',
    );
  }
}

function checkTreeClean(args) {
  if (args.allowDirty) {
    return;
  }
  const shipped = [
    'src',
    'index.js',
    'App.tsx',
    'package.json',
    'package-lock.json',
    path.join('android', 'app', 'src', 'main'),
  ];
  const status = execFileSync('git', ['status', '--porcelain', '--', ...shipped], {
    cwd: ROOT,
    encoding: 'utf8',
  }).trim();
  if (status) {
    fail(
      `tree is dirty under shipped paths — commit first or pass --allow-dirty:\n${status}`,
    );
  }
}

function readLedger(outDir) {
  const ledgerPath = path.join(outDir, 'index.json');
  if (!fs.existsSync(ledgerPath)) {
    return [];
  }
  return JSON.parse(fs.readFileSync(ledgerPath, 'utf8'));
}

function checkBundleVersion(bundleVersion, ledger) {
  const max = ledger.reduce((m, entry) => Math.max(m, entry.bundleVersion), 0);
  if (bundleVersion <= max) {
    fail(`bundleVersion ${bundleVersion} is not above the published max ${max}`);
  }
}

function walkFiles(dir) {
  const found = [];
  for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      found.push(...walkFiles(full));
    } else {
      found.push(full);
    }
  }
  return found;
}

function metroBundle(tmp) {
  const cliBin = path.join(
    ROOT,
    'node_modules',
    '@react-native-community',
    'cli',
    'build',
    'bin.js',
  );
  const bundleOut = path.join(tmp, 'index.android.bundle');
  const assetsDest = path.join(tmp, 'assets');
  const mapOut = path.join(tmp, 'packager.map');
  execFileSync(
    process.execPath,
    [
      cliBin,
      'bundle',
      '--platform',
      'android',
      '--dev',
      'false',
      '--reset-cache',
      '--entry-file',
      'index.js',
      '--bundle-output',
      bundleOut,
      '--assets-dest',
      assetsDest,
      '--sourcemap-output',
      mapOut,
      '--minify',
      'false',
    ],
    {cwd: ROOT, stdio: 'inherit'},
  );

  const assets = fs.existsSync(assetsDest) ? walkFiles(assetsDest) : [];
  // Metro flattens asset sources into names like
  // drawable-mdpi/node_modules_reactnavigation_…_backicon.png. Library
  // assets are pinned by the recorded package-lock hash (checked above) and
  // bundled identically into the APK from the same tree. App-owned images —
  // anything whose flattened name does not start with node_modules_ — are
  // files the APK may not ship, so they stay an APK-only change. keep.xml is
  // a toolchain-emitted marker, not an app image, and is exempt.
  const ownedByApp = assets.filter(
    file =>
      path.basename(file) !== 'keep.xml' &&
      !path.basename(file).startsWith('node_modules_'),
  );
  if (ownedByApp.length > 0) {
    fail(
      `bundle pulled in ${ownedByApp.length} app-owned asset file(s) (e.g. ${path.relative(tmp, ownedByApp[0])}) — require()d images ship as an APK, never OTA`,
    );
  }
  if (assets.length > 0) {
    console.log(
      `ota-publish: ${assets.length} library asset(s), all pinned by the recorded lock hash — not shipped, only referenced.`,
    );
  }
  return {bundleOut, mapOut};
}

function hermesBinary() {
  const dirs = {win32: 'win64-bin', linux: 'linux64-bin', darwin: 'osx-bin'};
  const dir = dirs[os.platform()];
  if (!dir) {
    fail(`unsupported platform ${os.platform()} for the bundled hermesc`);
  }
  const binary = path.join(
    ROOT,
    'node_modules',
    'hermes-compiler',
    'hermesc',
    dir,
    os.platform() === 'win32' ? 'hermesc.exe' : 'hermesc',
  );
  if (!fs.existsSync(binary)) {
    fail(`hermesc not found at ${path.relative(ROOT, binary)} — is hermes-compiler installed?`);
  }
  return binary;
}

function compileHermes(bundleOut, hbcOut) {
  execFileSync(
    hermesBinary(),
    ['-w', '-emit-binary', '-max-diagnostic-width=80', '-out', hbcOut, bundleOut, '-O'],
    {stdio: 'inherit'},
  );
}

function defaultKeyPath() {
  if (process.env.HRIS_OTA_KEY) {
    return process.env.HRIS_OTA_KEY;
  }
  const home = os.platform() === 'win32' ? process.env.USERPROFILE : process.env.HOME;
  return path.join(home, '.hris', 'ota_private.pem');
}

/** Hidden passphrase prompt (raw mode); visible fallback off-TTY. */
function promptPassphrase() {
  return new Promise(resolve => {
    if (!process.stdin.isTTY || !process.stdout.isTTY) {
      console.warn('ota-publish: stdin is not a TTY — passphrase will be visible');
      const rl = createInterface({input: process.stdin, output: process.stdout});
      rl.question('OTA key passphrase: ', answer => {
        rl.close();
        resolve(answer);
      });
      return;
    }
    process.stdout.write('OTA key passphrase: ');
    process.stdin.setRawMode(true);
    process.stdin.resume();
    let answer = '';
    const onData = chunk => {
      const char = chunk.toString('utf8');
      if (char === '\r' || char === '\n' || char === '\u0004') {
        process.stdin.setRawMode(false);
        process.stdin.pause();
        process.stdin.removeListener('data', onData);
        process.stdout.write('\n');
        resolve(answer);
      } else if (char === '\u0003') {
        process.stdout.write('\n');
        process.exit(130);
      } else if (char === '\u007f' || char === '\b') {
        answer = answer.slice(0, -1);
      } else {
        answer += char;
      }
    };
    process.stdin.on('data', onData);
  });
}

function loadPrivateKey(keyPath) {
  if (!fs.existsSync(keyPath)) {
    fail(`key not found at ${keyPath} (--key, HRIS_OTA_KEY, or %USERPROFILE%/.hris/ota_private.pem)`);
  }
  const pem = fs.readFileSync(keyPath, 'utf8');
  try {
    // Unencrypted keys (test fixtures) never prompt.
    return Promise.resolve(createPrivateKey({key: pem, format: 'pem'}));
  } catch {
    return promptPassphrase().then(passphrase => {
      try {
        return createPrivateKey({key: pem, format: 'pem', passphrase});
      } catch {
        fail('could not decrypt the key — wrong passphrase, or not a passphrase-encrypted PKCS#8 PEM');
      }
    });
  }
}

/* -- Minimal deterministic STORED zip writer (no new dependencies) -------- */

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    table[n] = c;
  }
  return table;
})();

function crc32Bytes(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

// Fixed DOS timestamp (2026-09-22 00:00) so identical inputs zip identically.
const ZIP_DOS_TIME = 0;
const ZIP_DOS_DATE = ((2026 - 1980) << 9) | (9 << 5) | 22;

function writeZip(entries) {
  const chunks = [];
  const central = [];
  let offset = 0;
  for (const {name, data} of entries) {
    const nameBytes = Buffer.from(name, 'utf8');
    const crc = crc32Bytes(data);
    const local = Buffer.alloc(30 + nameBytes.length);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6); // UTF-8 names
    local.writeUInt16LE(0, 8); // stored
    local.writeUInt16LE(ZIP_DOS_TIME, 10);
    local.writeUInt16LE(ZIP_DOS_DATE, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(nameBytes.length, 26);
    local.writeUInt16LE(0, 28);
    nameBytes.copy(local, 30);
    chunks.push(local, data);

    const record = Buffer.alloc(46 + nameBytes.length);
    record.writeUInt32LE(0x02014b50, 0);
    record.writeUInt16LE(20, 4);
    record.writeUInt16LE(20, 6);
    record.writeUInt16LE(0x0800, 8);
    record.writeUInt16LE(0, 10);
    record.writeUInt16LE(ZIP_DOS_TIME, 12);
    record.writeUInt16LE(ZIP_DOS_DATE, 14);
    record.writeUInt32LE(crc, 16);
    record.writeUInt32LE(data.length, 20);
    record.writeUInt32LE(data.length, 24);
    record.writeUInt16LE(nameBytes.length, 28);
    record.writeUInt16LE(0, 30);
    record.writeUInt16LE(0, 32);
    record.writeUInt16LE(0, 34);
    record.writeUInt16LE(0, 36);
    record.writeUInt32LE(0, 38);
    record.writeUInt32LE(offset, 42);
    nameBytes.copy(record, 46);
    central.push(record);
    offset += local.length + data.length;
  }
  const centralStart = offset;
  const centralBytes = Buffer.concat(central);
  chunks.push(centralBytes);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralBytes.length, 12);
  end.writeUInt32LE(centralStart, 16);
  end.writeUInt16LE(0, 20);
  chunks.push(end);
  return Buffer.concat(chunks);
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const versionCode = readVersionCode();
  const schemaVersion = readSchemaVersion();
  checkNativeRecord(versionCode, schemaVersion);
  checkTreeClean(args);
  fs.mkdirSync(args.outDir, {recursive: true});
  const ledger = readLedger(args.outDir);
  checkBundleVersion(args.bundleVersion, ledger);

  const id = `ota-${versionCode}-${args.bundleVersion}`;
  const zipPath = path.join(args.outDir, `${id}.zip`);
  if (fs.existsSync(zipPath)) {
    fail(`${zipPath} already exists — refusing to overwrite a published release`);
  }

  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ota-publish-'));
  try {
    console.log('ota-publish: Metro bundling (release flags, --reset-cache)…');
    const {bundleOut, mapOut} = metroBundle(tmp);
    const hbcOut = path.join(tmp, `${id}.hbc`);
    console.log('ota-publish: hermesc (-O, APK-matching bytecode)…');
    compileHermes(bundleOut, hbcOut);
    const hbcBytes = fs.readFileSync(hbcOut);
    const sha256 = createHash('sha256').update(hbcBytes).digest('hex');

    const createdAt = new Date().toISOString();
    const privateKey = await loadPrivateKey(args.keyPath ?? defaultKeyPath());
    const {manifestText} = buildManifest(
      {
        id,
        bundleVersion: String(args.bundleVersion),
        nativeVersion: String(versionCode),
        sha256,
        createdAt,
        notes: args.notes,
      },
      signedBytes => sign('sha256', signedBytes, privateKey).toString('hex'),
    );

    const zipBytes = writeZip([
      {name: 'manifest.txt', data: Buffer.from(manifestText, 'utf8')},
      {name: 'bundle.hbc', data: hbcBytes},
    ]);
    fs.writeFileSync(zipPath, zipBytes);

    const mapsDir = path.join(OTA_DIR, 'maps');
    fs.mkdirSync(mapsDir, {recursive: true});
    fs.copyFileSync(mapOut, path.join(mapsDir, `${id}.packager.map`));

    ledger.push({
      id,
      bundleVersion: args.bundleVersion,
      nativeVersion: versionCode,
      sha256,
      createdAt,
      notes: args.notes,
    });
    fs.writeFileSync(
      path.join(args.outDir, 'index.json'),
      JSON.stringify(ledger, null, 2) + '\n',
    );

    console.log(`ota-publish: ${id} — ${(zipBytes.length / 1024).toFixed(0)} kB zip`);
    console.log(`  zip:  ${zipPath}`);
    console.log(`  map:  ${path.join(mapsDir, `${id}.packager.map`)} (local only, never uploaded)`);
    console.log('  next: file it server-side (O3 ota:publish), then the handset drills (O5)');
  } finally {
    fs.rmSync(tmp, {recursive: true, force: true});
  }
}

main().catch(err => {
  console.error(err instanceof Error ? err.message : err);
  process.exit(1);
});
