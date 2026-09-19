/**
 * Sending a request draft (Phase 9 — UC-10).
 *
 * Requests are filed one at a time, one per worker for overtime. Any the
 * server refuses — or that never reached it — stay in the draft, so a retry
 * sends only those and never files the others twice. A retried overtime keeps
 * the batch key of its first send, so it joins the same batch.
 *
 * @format
 */

import {apiClient} from '../api/client';
import {RequestDraft, newBatchKey, toApiRequests} from './requestDraft';

export type SendOutcome = {employeeId: number; ok: boolean; message: string | null};

export type SendResult = {
  outcomes: SendOutcome[];
  /** What is left to send, or null when everything went through. */
  remaining: RequestDraft | null;
};

type Post = (url: string, body: Record<string, unknown>) => Promise<unknown>;

const defaultPost: Post = (url, body) => apiClient.post(url, body);

/** The server's own words when it answered; otherwise say it never got there. */
export function failureMessage(error: any): string {
  const message = error?.response?.data?.message;
  if (typeof message === 'string' && message.trim()) {
    return message;
  }
  return error?.response
    ? 'The server could not file this request.'
    : 'Could not reach the server. Nothing was filed for this one.';
}

export async function sendDraft(draft: RequestDraft, post: Post = defaultPost): Promise<SendResult> {
  const batchKey =
    draft.kind === 'overtime' && draft.workerIds.length > 1 ? draft.batchKey ?? newBatchKey() : null;
  const outcomes: SendOutcome[] = [];

  for (const request of toApiRequests(draft, batchKey)) {
    try {
      await post(request.endpoint, request.body);
      outcomes.push({employeeId: request.employeeId, ok: true, message: null});
    } catch (error) {
      outcomes.push({employeeId: request.employeeId, ok: false, message: failureMessage(error)});
    }
  }

  const failed = outcomes.filter(o => !o.ok).map(o => o.employeeId);

  if (failed.length === 0) {
    return {outcomes, remaining: null};
  }

  return {
    outcomes,
    remaining: draft.kind === 'overtime' ? {...draft, workerIds: failed, batchKey} : draft,
  };
}
