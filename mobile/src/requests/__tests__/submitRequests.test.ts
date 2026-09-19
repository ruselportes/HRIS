/**
 * @format
 */

import {OvertimeDraft} from '../requestDraft';
import {sendDraft} from '../submitRequests';

jest.mock('../../api/client', () => ({apiClient: {post: jest.fn()}}));

const draft = (overrides: Partial<OvertimeDraft> = {}): OvertimeDraft => ({
  kind: 'overtime',
  workerIds: [11, 12, 13],
  date: '2026-09-21',
  start: '16:00',
  hours: 3,
  reason: '',
  batchKey: null,
  ...overrides,
});

const refused = (message: string) => Object.assign(new Error('422'), {response: {status: 422, data: {message}}});

test('everything filed leaves nothing to send, and all share one batch key', async () => {
  const post = jest.fn().mockResolvedValue({});

  const result = await sendDraft(draft(), post);

  expect(result.remaining).toBeNull();
  expect(post).toHaveBeenCalledTimes(3);
  const keys = post.mock.calls.map(([, body]) => body.batch_key);
  expect(new Set(keys).size).toBe(1);
  expect(keys[0]).toMatch(/^ot-/);
});

test('only what failed is kept, with the server reason, in the same batch', async () => {
  const post = jest
    .fn()
    .mockResolvedValueOnce({})
    .mockRejectedValueOnce(refused('2026-09-21 is covered by an approved leave; overtime cannot be filed on it.'))
    .mockRejectedValueOnce(new Error('Network Error'));

  const result = await sendDraft(draft(), post);

  expect(result.outcomes).toEqual([
    {employeeId: 11, ok: true, message: null},
    {employeeId: 12, ok: false, message: '2026-09-21 is covered by an approved leave; overtime cannot be filed on it.'},
    {employeeId: 13, ok: false, message: 'Could not reach the server. Nothing was filed for this one.'},
  ]);

  const remaining = result.remaining as OvertimeDraft;
  expect(remaining.workerIds).toEqual([12, 13]);
  // The retry joins the batch the first worker was filed in.
  expect(remaining.batchKey).toBe(post.mock.calls[0][1].batch_key);
});

test('a retry sends only the rest, under the batch key it started with', async () => {
  const post = jest.fn().mockResolvedValue({});

  await sendDraft(draft({workerIds: [12, 13], batchKey: 'ot-first-send'}), post);

  expect(post.mock.calls.map(([, body]) => [body.employee_id, body.batch_key])).toEqual([
    [12, 'ot-first-send'],
    [13, 'ot-first-send'],
  ]);
});

test('a refused leave stays as it was', async () => {
  const leave = {
    kind: 'leave' as const,
    workerId: 11,
    leaveType: 'vacation' as const,
    dateFrom: '2026-09-24',
    days: 1,
    reason: 'Family matter',
  };
  const post = jest.fn().mockRejectedValue(refused('Retrospective leave may only be sick leave.'));

  const result = await sendDraft(leave, post);

  expect(result.remaining).toEqual(leave);
  expect(post).toHaveBeenCalledWith('/leaves', expect.objectContaining({employee_id: 11, date_to: '2026-09-24'}));
});
