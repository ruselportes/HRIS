/**
 * The classification decides what gets retried. The failure it guards against
 * is retrying things that cannot succeed.
 *
 * @format
 */

import {classifyError, classifySuccess} from '../classifyResponse';

describe('classifyError', () => {
  test('no response at all is a transient network failure', () => {
    expect(classifyError({})).toEqual({kind: 'retry', reason: 'network'});
  });

  test.each([500, 502, 503, 504])('%i is retried', status => {
    expect(classifyError({response: {status}})).toMatchObject({kind: 'retry'});
  });

  test('429 rate limiting is retried', () => {
    expect(classifyError({response: {status: 429}})).toMatchObject({kind: 'retry'});
  });

  test('401 halts — waiting will not refresh an expired sign-in', () => {
    expect(classifyError({response: {status: 401}})).toEqual({
      kind: 'halt',
      reason: 'auth_expired',
    });
  });

  test('a revoked device halts rather than retrying', () => {
    expect(
      classifyError({response: {status: 403, data: {reason: 'device_revoked'}}}),
    ).toEqual({kind: 'halt', reason: 'device_revoked'});
  });

  test('an unbound device halts', () => {
    expect(
      classifyError({response: {status: 403, data: {reason: 'device_not_bound'}}}),
    ).toEqual({kind: 'halt', reason: 'device_not_bound'});
  });

  test('any other 403 halts, since the same credentials will be refused again', () => {
    expect(classifyError({response: {status: 403, data: {}}})).toEqual({
      kind: 'halt',
      reason: 'auth_expired',
    });
  });

  test('422 halts — the same malformed batch fails identically every retry', () => {
    expect(classifyError({response: {status: 422}})).toEqual({
      kind: 'halt',
      reason: 'invalid_payload',
    });
  });

  test('an unexpected status is retried, because retrying never loses records', () => {
    expect(classifyError({response: {status: 404}})).toMatchObject({kind: 'retry'});
  });
});

describe('classifySuccess', () => {
  test('maps each result by hmac_hash with its status', () => {
    const outcome = classifySuccess({
      last_chain_hash: 'b'.repeat(64),
      results: [
        {hmac_hash: 'a'.repeat(64), status: 'accepted', reason: null},
        {hmac_hash: 'b'.repeat(64), status: 'flagged', reason: 'wall_clock_rolled_back'},
      ],
    });

    expect(outcome).toEqual({
      kind: 'processed',
      lastChainHash: 'b'.repeat(64),
      results: [
        {hmacHash: 'a'.repeat(64), status: 'accepted', reason: null},
        {hmacHash: 'b'.repeat(64), status: 'flagged', reason: 'wall_clock_rolled_back'},
      ],
    });
  });

  test('flagged is kept distinct from rejected', () => {
    // The whole point of the three-way server outcome. Collapsing flagged into
    // rejected would halt the chain for an honest clock drift.
    const outcome = classifySuccess({
      results: [{hmac_hash: 'x', status: 'flagged', reason: 'wall_clock_jumped_forward'}],
    });

    expect(outcome.kind === 'processed' && outcome.results[0].status).toBe('flagged');
  });

  test('falls back to the boolean for a server that predates status', () => {
    const outcome = classifySuccess({
      results: [
        {hmac_hash: 'a', accepted: true},
        {hmac_hash: 'b', accepted: false},
      ],
    });

    expect(outcome.kind === 'processed' && outcome.results.map(r => r.status)).toEqual([
      'accepted',
      'rejected',
    ]);
  });

  test('an unrecognised result is never silently treated as accepted', () => {
    const outcome = classifySuccess({results: [{hmac_hash: 'a', status: 'mystery'}]});

    expect(outcome.kind === 'processed' && outcome.results[0].status).toBe('rejected');
  });

  test('a missing results array yields no outcomes rather than throwing', () => {
    expect(classifySuccess({})).toEqual({kind: 'processed', results: [], lastChainHash: null});
  });
});
