/**
 * OTA manifest construction (Add-on C, O2) — shared by ota-publish.mjs and
 * ota-test-fixtures.mjs so the two can never drift apart. The Kotlin verifier
 * (OtaManager, O1) implements the same rules; they are stated here once:
 *
 * The manifest is newline-delimited key=value, the project's own canonical
 * form (same discipline as AttendancePayload: fixed field order, values may
 * not contain \n or \r). Field order is fixed:
 *   id, bundleVersion, nativeVersion, sha256, createdAt, notes
 * each line terminated with \n. NO JSON anywhere: making Node and Kotlin
 * produce byte-identical JSON is fragile (Unicode in notes alone breaks it),
 * and org.json is unavailable in JVM unit tests.
 *
 * The ECDSA P-256 signature is over the RAW manifest bytes — everything
 * before the signature line, i.e. the six lines with their newlines.
 * manifestText = signedBytes + `signature=<hex>\n`. Verification therefore
 * needs no parser at all: find the last `\nsignature=`, verify the hex
 * signature over the preceding bytes, and only then split lines on the
 * FIRST `=` to read fields. signature itself is hex (not base64) because
 * java.util.Base64 is API 26+ and minSdk is 24.
 *
 * @format
 */

export const MANIFEST_FIELDS = [
  'id',
  'bundleVersion',
  'nativeVersion',
  'sha256',
  'createdAt',
  'notes',
];

function assertFieldValue(key, value) {
  if (typeof value !== 'string' || value.length === 0) {
    throw new Error(`ota-manifest: field ${key} must be a non-empty string`);
  }
  if (value.includes('\n') || value.includes('\r')) {
    throw new Error(
      `ota-manifest: field ${key} contains a line break, which would forge a field boundary`,
    );
  }
}

/**
 * Returns {signedBytes: Buffer, manifestText: string}. signFn takes the
 * signed bytes and returns the signature as lowercase hex.
 */
export function buildManifest(values, signFn) {
  const lines = MANIFEST_FIELDS.map(key => {
    if (!(key in values)) {
      throw new Error(`ota-manifest: missing field ${key}`);
    }
    assertFieldValue(key, values[key]);
    return `${key}=${values[key]}`;
  });
  const signedBytes = Buffer.from(lines.join('\n') + '\n', 'utf8');
  const signature = signFn(signedBytes);
  if (!/^[0-9a-f]+$/.test(signature)) {
    throw new Error('ota-manifest: signature must be lowercase hex');
  }
  return {
    signedBytes,
    manifestText: signedBytes.toString('utf8') + `signature=${signature}\n`,
  };
}
