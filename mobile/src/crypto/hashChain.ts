/**
 * HashChainBuilder (Phase 5, supports UC-04 — named in STD TC-01/TC-02).
 *
 * Device-side counterpart to backend/app/Services/Crypto/HashChainVerifier.php.
 * Each record's HMAC covers a payload that embeds the previous record's hash,
 * so a record's hash commits to both its contents and its position. Editing
 * any row invalidates that row and breaks the linkage of every row after it,
 * which is the property STD TC-02 asserts.
 *
 * KEY CONVENTION, and a cross-language footgun worth stating plainly: the
 * server issues the per-device HMAC secret as base64 at device binding, and
 * both sides HMAC using the DECODED RAW BYTES, never the base64 text. Getting
 * this wrong produces a valid-looking HMAC that never matches. Use
 * hmacKeyFromBase64() rather than passing the string through.
 *
 * The HMAC secret is shared with the server (it has to be, for TC-02's
 * recomputation), so this layer alone proves only that the local log was not
 * edited or reordered — anyone holding the key could forge it. Proof of
 * origin comes from the ECDSA signature over the same payload, whose private
 * key never leaves the TEE.
 *
 * @format
 */

import {hmac} from '@noble/hashes/hmac.js';
import {sha256} from '@noble/hashes/sha2.js';
import {bytesToHex, utf8ToBytes} from '@noble/hashes/utils.js';
import {AttendancePayloadRecord, canonicalize} from './payload';

const BASE64_ALPHABET =
  'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

/**
 * Decode the base64 secret issued at binding into the raw bytes used as the
 * HMAC key.
 *
 * Hand-rolled rather than using atob() or Buffer: atob is a global whose
 * availability varies by React Native version and is not typed in this
 * project's tsconfig, and Buffer is Node-only. Both would work today and
 * break on a different runtime, which is not a trade worth making for
 * fifteen lines.
 */
export function hmacKeyFromBase64(base64Key: string): Uint8Array {
  const cleaned = base64Key.replace(/[=]+$/, '');
  const bytes: number[] = [];
  let buffer = 0;
  let bitsCollected = 0;

  for (const char of cleaned) {
    const value = BASE64_ALPHABET.indexOf(char);

    if (value === -1) {
      throw new Error(`Invalid base64 character in HMAC key: ${char}`);
    }

    // Bit shifting is the base64 algorithm itself, not an accidental && typo,
    // which is what no-bitwise exists to catch.
    /* eslint-disable no-bitwise */
    buffer = (buffer << 6) | value;
    bitsCollected += 6;

    if (bitsCollected >= 8) {
      bitsCollected -= 8;
      bytes.push((buffer >> bitsCollected) & 0xff);
    }
    /* eslint-enable no-bitwise */
  }

  return new Uint8Array(bytes);
}

/** HMAC-SHA256 over the canonical payload, lowercase hex. */
export function computeHmac(
  record: AttendancePayloadRecord,
  hmacKey: Uint8Array,
): string {
  return bytesToHex(hmac(sha256, hmacKey, utf8ToBytes(canonicalize(record))));
}

export type ChainedRecord = AttendancePayloadRecord & {hmac_hash: string};

/**
 * Link one record onto the end of the chain.
 *
 * `prevHash` is the hmac_hash of the last record this device produced, or
 * null for the device's very first record. It must be threaded through from
 * persisted state, not recomputed — that continuity is what stops a device
 * from silently starting a fresh chain and discarding history.
 */
export function appendToChain(
  record: Omit<AttendancePayloadRecord, 'prev_hash'>,
  prevHash: string | null,
  hmacKey: Uint8Array,
): ChainedRecord {
  const linked: AttendancePayloadRecord = {...record, prev_hash: prevHash};

  return {...linked, hmac_hash: computeHmac(linked, hmacKey)};
}

/**
 * Local self-check: recompute each record's HMAC and confirm linkage.
 * Mirrors the server's verifier so the app can surface a broken chain in the
 * Sync Queue screen before transmitting, rather than having the server reject
 * a batch the foreman has already walked away from.
 */
export function verifyLocalChain(
  records: ChainedRecord[],
  hmacKey: Uint8Array,
  expectedFirstPrevHash: string | null = null,
): {index: number; valid: boolean; reason: string | null}[] {
  const results: {index: number; valid: boolean; reason: string | null}[] = [];
  let previousHash = expectedFirstPrevHash;
  let chainBroken = false;

  records.forEach((record, index) => {
    if (chainBroken) {
      results.push({index, valid: false, reason: 'chain_broken_upstream'});
      return;
    }

    if (record.prev_hash !== previousHash) {
      chainBroken = true;
      results.push({index, valid: false, reason: 'prev_hash_mismatch'});
      return;
    }

    const expected = computeHmac(record, hmacKey);

    if (expected !== record.hmac_hash) {
      chainBroken = true;
      results.push({index, valid: false, reason: 'hmac_mismatch'});
      return;
    }

    results.push({index, valid: true, reason: null});
    previousHash = expected;
  });

  return results;
}
