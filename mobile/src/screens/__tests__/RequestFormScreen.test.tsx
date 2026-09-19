/**
 * Request form (Phase 9 — UC-10, PR-10). The draft logic and sending are
 * covered in src/requests; this covers what the screen does with them — who a
 * request is for by default, when it can be sent, what happens to a draft,
 * and what the foreman is told.
 *
 * @format
 */

import React from 'react';
import {Text} from 'react-native';
import ReactTestRenderer, {ReactTestInstance} from 'react-test-renderer';
import NetInfo from '@react-native-community/netinfo';
import {apiClient} from '../../api/client';
import * as drafts from '../../db/requestDrafts';
import {DEFAULT_SHIFT} from '../../db/shiftRules';
import {RequestFormScreen} from '../RequestFormScreen';

jest.mock('@react-native-community/netinfo', () => ({
  fetch: jest.fn(),
  addEventListener: jest.fn(() => () => {}),
}));

jest.mock('../../api/client', () => ({apiClient: {post: jest.fn()}}));

jest.mock('../../auth/AuthContext', () => ({
  useAuth: () => ({user: {employee_id: 900, first_name: 'Ronel', last_name: 'Dela Cruz'}}),
}));

jest.mock('../../db/requestDrafts', () => ({
  loadDraft: jest.fn(),
  saveDraft: jest.fn(),
  clearDraft: jest.fn(),
}));

const net = NetInfo as jest.Mocked<typeof NetInfo>;
const post = apiClient.post as jest.Mock;
const store = drafts as jest.Mocked<typeof drafts>;

jest.setTimeout(30_000);

/** 2026-09-21 at a given Manila time, whatever timezone the test runs in. */
const manila = (hours: number, minutes: number) => Date.UTC(2026, 8, 21, hours, minutes) - 480 * 60_000;

const CREW = {
  crewId: 7,
  crewName: 'Formwork crew B',
  siteName: 'Site 07',
  cachedAt: 0,
  acting: null,
  members: [
    {employeeId: 11, employeeCode: 'ADC-0011', firstName: 'Elmer', lastName: 'Bacus', tradeSkill: 'Mason'},
    {employeeId: 12, employeeCode: 'ADC-0012', firstName: 'Lito', lastName: 'Cabahug', tradeSkill: 'Welder'},
  ],
};

const textOf = (node: ReactTestInstance): string =>
  ([] as unknown[])
    .concat(node.props.children)
    .filter(part => typeof part === 'string' || typeof part === 'number')
    .join('');

const texts = (root: ReactTestInstance) => root.findAllByType(Text).map(textOf);

async function press(root: ReactTestInstance, label: string): Promise<void> {
  let node: ReactTestInstance | null = root.findAllByType(Text).find(t => textOf(t) === label) ?? null;

  if (!node) {
    throw new Error(`No text "${label}" on screen. Showing: ${texts(root).join(' | ')}`);
  }

  while (node && !node.props.onPress) {
    node = node.parent;
  }

  await ReactTestRenderer.act(async () => {
    await node!.props.onPress();
  });
}

async function render(onClose = jest.fn()): Promise<ReactTestInstance> {
  let renderer: ReactTestRenderer.ReactTestRenderer;

  await ReactTestRenderer.act(async () => {
    renderer = ReactTestRenderer.create(<RequestFormScreen crew={CREW} shift={DEFAULT_SHIFT} onClose={onClose} />);
  });

  return renderer!.root;
}

const online = (up: boolean) => net.fetch.mockResolvedValue({isConnected: up, isInternetReachable: up} as any);

beforeEach(() => {
  jest.useFakeTimers({now: manila(14, 0), doNotFake: ['nextTick', 'setImmediate']});
  online(true);
  store.loadDraft.mockResolvedValue(null);
  store.saveDraft.mockResolvedValue();
  store.clearDraft.mockResolvedValue();
  post.mockResolvedValue({});
});

afterEach(() => {
  jest.useRealTimers();
  jest.clearAllMocks();
});

test('overtime is for the whole crew by default and goes out as one batch', async () => {
  const onClose = jest.fn();
  const root = await render(onClose);

  expect(texts(root)).toContain('2 workers');
  // From shift end, three hours.
  expect(texts(root).join(' ')).toContain('16:00 – 19:00');

  await press(root, 'Check before sending');
  expect(texts(root)).toContain('Send now');

  await press(root, 'Send now');

  expect(post).toHaveBeenCalledTimes(2);
  const bodies = post.mock.calls.map(([url, body]) => ({url, ...body}));
  expect(bodies.map(b => b.employee_id)).toEqual([11, 12]);
  for (const body of bodies) {
    expect(body).toMatchObject({url: '/overtimes', ot_date: '2026-09-21', start_time: '16:00', end_time: '19:00'});
    expect(body.batch_key).toBe(bodies[0].batch_key);
  }
  expect(store.clearDraft).toHaveBeenCalledWith(900);
  expect(onClose).toHaveBeenCalledWith('Overtime filed for 2 workers. It goes to the endorser, then HR.');
});

test('offline, it cannot be sent but can be kept as a draft', async () => {
  online(false);
  const onClose = jest.fn();
  const root = await render(onClose);

  await press(root, 'Check before sending');

  expect(texts(root)).toContain('Needs signal to send');
  expect(texts(root)).not.toContain('Send now');

  await press(root, 'Save as draft — send later');

  expect(post).not.toHaveBeenCalled();
  expect(store.saveDraft).toHaveBeenCalledWith(900, expect.objectContaining({kind: 'overtime', workerIds: [11, 12]}));
  expect(onClose).toHaveBeenCalledWith('Saved on this phone, not sent. Nobody can see it until you send it.');
});

test('a worker the server refuses stays in the draft with its reason', async () => {
  post
    .mockResolvedValueOnce({})
    .mockRejectedValueOnce({response: {status: 422, data: {message: '2026-09-21 is covered by an approved leave; overtime cannot be filed on it.'}}});
  const onClose = jest.fn();
  const root = await render(onClose);

  await press(root, 'Check before sending');
  await press(root, 'Send now');

  expect(onClose).not.toHaveBeenCalled();
  expect(texts(root)).toContain('Not everything was filed');
  expect(texts(root)).toContain('2026-09-21 is covered by an approved leave; overtime cannot be filed on it.');
  expect(texts(root)).toContain('Try again for 1');
  expect(store.saveDraft).toHaveBeenCalledWith(900, expect.objectContaining({workerIds: [12]}));
});

test('a saved draft whose day has passed is dropped, and the foreman is told', async () => {
  store.loadDraft.mockResolvedValue({
    savedAt: manila(8, 0),
    draft: {kind: 'overtime', workerIds: [11], date: '2026-09-20', start: '16:00', hours: 2, reason: '', batchKey: null},
  });

  const root = await render();

  expect(store.clearDraft).toHaveBeenCalledWith(900);
  expect(texts(root)).toContain('Your saved request was dropped: its date has passed, so it can no longer be filed.');
});

test('a saved draft still in date is reopened, marked as not sent', async () => {
  store.loadDraft.mockResolvedValue({
    savedAt: manila(8, 0),
    draft: {kind: 'overtime', workerIds: [12], date: '2026-09-22', start: '17:00', hours: 2, reason: '', batchKey: null},
  });

  const root = await render();

  expect(texts(root)).toContain('This is your saved draft. It has not been sent — nobody can see it yet.');
  expect(texts(root)).toContain('1 worker');
  expect(texts(root).join(' ')).toContain('17:00 – 19:00');
});

test('a leave needs a reason before it can be checked', async () => {
  const root = await render();

  await press(root, 'Leave');
  await press(root, 'Lito Cabahug');
  await press(root, 'Check before sending');

  expect(texts(root)).toContain('Give a reason for the leave.');
  expect(texts(root)).not.toContain('Send now');
});
