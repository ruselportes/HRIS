/**
 * HashChainBuilder tests, including a cross-language HMAC vector.
 *
 * The HMAC vector is the part that matters most here: it proves this device's
 * HMAC of the shared canonical payload equals PHP's hash_hmac over the same
 * payload with the same key bytes. That is the exact place the base64-vs-raw
 * key convention could silently diverge.
 *
 * @format
 */

import {utf8ToBytes} from '@noble/hashes/utils.js';
import {AttendancePayloadRecord} from '../payload';
import {
  ChainedRecord,
  appendToChain,
  computeHmac,
  hmacKeyFromBase64,
  verifyLocalChain,
} from '../hashChain';

/** Same key the PHP-side vector uses, as issued at binding (base64). */
const KEY_BASE64 = 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=';

const KEY = hmacKeyFromBase64(KEY_BASE64);

const VECTOR_RECORD: AttendancePayloadRecord = {
  employee_id: 42,
  crew_id: 7,
  date: '2026-09-12',
  status: 'present',
  time_in: 1789200000000,
  monotonic_timestamp: 86400000,
  boot_id: 'b7f3c1a2',
  device_id: 'dev-mgk3f1-a83bd0e1',
  prev_hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
};

/**
 * Computed independently with PHP:
 *   hash_hmac('sha256', canonical, base64_decode(KEY_BASE64))
 */
const VECTOR_HMAC =
  'a82f7ef2c38cf3e92cb99da6aa8016701bc33444d25004f3cf0d2133517c5529';

function buildChain(count: number): ChainedRecord[] {
  const records: ChainedRecord[] = [];
  let prevHash: string | null = null;

  for (let i = 0; i < count; i++) {
    const record = appendToChain(
      {
        employee_id: 100 + i,
        crew_id: 7,
        date: '2026-09-12',
        status: 'present',
        time_in: 1789200000000 + i * 60_000,
        monotonic_timestamp: 86400000 + i * 60_000,
        boot_id: 'b7f3c1a2',
        device_id: 'dev-mgk3f1-a83bd0e1',
      },
      prevHash,
      KEY,
    );

    prevHash = record.hmac_hash;
    records.push(record);
  }

  return records;
}

describe('hmacKeyFromBase64', () => {
  test('decodes to the raw key bytes, not the base64 text', () => {
    const decoded = String.fromCharCode(...KEY);

    expect(decoded).toBe('secret-key-for-hmac-testing-only');
  });

  test('handles padded and unpadded input identically', () => {
    expect(Array.from(hmacKeyFromBase64('YWJjZA=='))).toEqual(
      Array.from(hmacKeyFromBase64('YWJjZA')),
    );
  });

  test('rejects a non-base64 character rather than decoding garbage', () => {
    expect(() => hmacKeyFromBase64('abc$def')).toThrow(/Invalid base64/);
  });

  test('decodes byte values across the full range', () => {
    // "/w+A" -> 0xFF 0x0F 0x80, covering high bit and boundary values.
    expect(Array.from(hmacKeyFromBase64('/w+A'))).toEqual([0xff, 0x0f, 0x80]);
  });
});

describe('computeHmac', () => {
  test('matches the HMAC computed independently by PHP', () => {
    expect(computeHmac(VECTOR_RECORD, KEY)).toBe(VECTOR_HMAC);
  });

  test('HMACing with the base64 text instead of the decoded bytes differs', () => {
    // Documents the footgun: this is the wrong thing to do, and it produces a
    // structurally valid HMAC that the server will never accept.
    const wrongKey = utf8ToBytes(KEY_BASE64);

    expect(computeHmac(VECTOR_RECORD, wrongKey)).not.toBe(VECTOR_HMAC);
  });
});

describe('appendToChain', () => {
  test('first record links to null', () => {
    const chain = buildChain(1);

    expect(chain[0].prev_hash).toBeNull();
    expect(chain[0].hmac_hash).toMatch(/^[0-9a-f]{64}$/);
  });

  test('each record links to the previous record hash', () => {
    const chain = buildChain(3);

    expect(chain[1].prev_hash).toBe(chain[0].hmac_hash);
    expect(chain[2].prev_hash).toBe(chain[1].hmac_hash);
  });

  test('identical content at different chain positions yields different hashes', () => {
    // Because prev_hash is inside the signed payload, position is committed to
    // — this is what makes reordering detectable.
    const a = appendToChain({...VECTOR_RECORD}, null, KEY);
    const b = appendToChain({...VECTOR_RECORD}, 'a'.repeat(64), KEY);

    expect(a.hmac_hash).not.toBe(b.hmac_hash);
  });
});

describe('verifyLocalChain', () => {
  test('an untampered chain verifies end to end', () => {
    const results = verifyLocalChain(buildChain(3), KEY);

    expect(results.every(r => r.valid)).toBe(true);
  });

  test('editing the middle row rejects it and everything after', () => {
    const chain = buildChain(3);
    chain[1] = {...chain[1], time_in: chain[1].time_in! - 75 * 60_000};

    const results = verifyLocalChain(chain, KEY);

    expect(results[0]).toMatchObject({valid: true});
    expect(results[1]).toMatchObject({valid: false, reason: 'hmac_mismatch'});
    expect(results[2]).toMatchObject({
      valid: false,
      reason: 'chain_broken_upstream',
    });
  });

  test('reordering is detected', () => {
    const chain = buildChain(3);
    [chain[1], chain[2]] = [chain[2], chain[1]];

    const results = verifyLocalChain(chain, KEY);

    expect(results[0]).toMatchObject({valid: true});
    expect(results[1]).toMatchObject({
      valid: false,
      reason: 'prev_hash_mismatch',
    });
  });

  test('a chain that ignores prior server history is rejected', () => {
    const results = verifyLocalChain(buildChain(2), KEY, 'a'.repeat(64));

    expect(results[0]).toMatchObject({
      valid: false,
      reason: 'prev_hash_mismatch',
    });
  });
});
