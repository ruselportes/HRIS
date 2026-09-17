/**
 * Classify a sync attempt's HTTP outcome into what the engine should do next
 * (Phase 6).
 *
 * Pure, and the most consequential decision in the engine. The failure mode it
 * exists to prevent is retrying things that cannot succeed: a rejected event
 * fails verification identically every time, so retrying it burns battery and
 * signal and — worse — keeps the queue looking "in progress" while the foreman
 * assumes everything is fine.
 *
 * @format
 */

/**
 * `refused` (Phase 7) is NOT a chain break. The event was authentic and
 * correctly chained but not permitted — e.g. the crew was handed to another
 * foreman — so the server still advanced its tip and later events link fine.
 * Only `rejected` halts.
 */
export type EventStatus = 'accepted' | 'flagged' | 'refused' | 'rejected';

export type EventResult = {
  hmacHash: string;
  status: EventStatus;
  reason: string | null;
};

/**
 * Stops the engine until a person acts. Distinct from `retry`: no amount of
 * waiting fixes an expired sign-in or a revoked device.
 */
export type HaltReason =
  | 'auth_expired'
  | 'device_revoked'
  | 'device_not_bound'
  | 'invalid_payload'
  /**
   * The server rejected an event in this chain, so every event after it links
   * onto a hash the server refused and can never verify. Deliberately not
   * auto-recovered — see syncEngine.ts for why re-signing would be unsafe.
   */
  | 'chain_broken';

export type AttemptOutcome =
  | {kind: 'retry'; reason: string}
  | {kind: 'halt'; reason: HaltReason}
  | {kind: 'processed'; results: EventResult[]; lastChainHash: string | null};

type HttpLikeError = {
  response?: {status?: number; data?: any};
};

export function classifySuccess(data: any): AttemptOutcome {
  const results: EventResult[] = (data?.results ?? []).map((r: any) => ({
    hmacHash: r.hmac_hash,
    status: normaliseStatus(r),
    reason: r.reason ?? null,
  }));

  return {
    kind: 'processed',
    results,
    lastChainHash: data?.last_chain_hash ?? null,
  };
}

/**
 * Older servers returned only a boolean `accepted`. Reading `status` first and
 * falling back keeps the engine correct against either, and never silently
 * turns an unknown result into "accepted".
 */
function normaliseStatus(result: any): EventStatus {
  if (
    result.status === 'accepted' ||
    result.status === 'flagged' ||
    result.status === 'refused' ||
    result.status === 'rejected'
  ) {
    return result.status;
  }

  return result.accepted === true ? 'accepted' : 'rejected';
}

/** An error never produces results, so this is narrower than AttemptOutcome. */
export type ErrorOutcome = Extract<AttemptOutcome, {kind: 'retry' | 'halt'}>;

export function classifyError(error: HttpLikeError): ErrorOutcome {
  const status = error?.response?.status;

  // No response at all: offline, DNS, timeout, connection reset. Transient.
  if (status === undefined) {
    return {kind: 'retry', reason: 'network'};
  }

  if (status === 401) {
    return {kind: 'halt', reason: 'auth_expired'};
  }

  if (status === 403) {
    const reason = error.response?.data?.reason;

    // Only the two device reasons halt as device problems. Any other 403 —
    // e.g. a role change — is still a halt, reported as auth, since retrying
    // with the same credentials will be refused the same way.
    if (reason === 'device_revoked') {
      return {kind: 'halt', reason: 'device_revoked'};
    }
    if (reason === 'device_not_bound') {
      return {kind: 'halt', reason: 'device_not_bound'};
    }
    return {kind: 'halt', reason: 'auth_expired'};
  }

  /*
   * 422 means the server could not validate the batch's SHAPE. The same batch
   * fails the same way on every retry, and it indicates a client bug rather
   * than a transient condition — so halt and surface it rather than loop.
   */
  if (status === 422) {
    return {kind: 'halt', reason: 'invalid_payload'};
  }

  // Rate limited or server-side failure: transient.
  if (status === 429 || status >= 500) {
    return {kind: 'retry', reason: `http_${status}`};
  }

  // Anything else unexpected (404 from a mis-routed deploy, 409, ...). Retrying
  // is the conservative choice — it keeps the records, which is the one thing
  // the engine must never lose.
  return {kind: 'retry', reason: `http_${status}`};
}
