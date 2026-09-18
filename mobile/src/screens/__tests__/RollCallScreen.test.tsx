/**
 * Roll Call late start (Phase 7 — UC-05, STD TC-04 steps 2-4) and time-out.
 *
 * The repository is mocked: what signing and storage do is covered in
 * attendanceRepository.test.ts. This covers the decisions the screen makes —
 * when the override is offered, and which write each tap turns into.
 *
 * @format
 */

import React from 'react';
import {Alert, Text} from 'react-native';
import ReactTestRenderer, {ReactTestInstance} from 'react-test-renderer';
import * as repository from '../../db/attendanceRepository';
import {DEFAULT_SHIFT} from '../../db/shiftRules';
import {RollCallScreen} from '../RollCallScreen';

jest.mock('@react-navigation/native', () => {
  const {useEffect} = jest.requireActual('react');
  return {useFocusEffect: (effect: () => void) => useEffect(effect, [effect])};
});

jest.mock('../../auth/AuthContext', () => ({
  useAuth: () => ({user: {first_name: 'Ronel', last_name: 'Dela Cruz'}}),
}));

jest.mock('../../db/attendanceRepository', () => {
  const actual = jest.requireActual('../../db/attendanceRepository');
  return {
    DeviceNotBoundError: actual.DeviceNotBoundError,
    TimeOutRefusedError: actual.TimeOutRefusedError,
    closeShift: jest.fn(),
    getCachedCrew: jest.fn(),
    getLateStartChoice: jest.fn(),
    getShiftConfig: jest.fn(),
    hasRollCallStarted: jest.fn(),
    listTodayAttendance: jest.fn(),
    recordManualTime: jest.fn(),
    recordManualTimeOut: jest.fn(),
    recordShiftCredit: jest.fn(),
    recordStatus: jest.fn(),
    recordTimeOut: jest.fn(),
    saveLateStartChoice: jest.fn(),
    undoAttendance: jest.fn(),
  };
});

const repo = repository as jest.Mocked<typeof repository>;

// The first render pulls in FlatList and Modal, which is slow under Jest.
jest.setTimeout(30_000);

/** 2026-09-12 at a given Manila time, whatever timezone the test runs in. */
const manila = (hours: number, minutes: number) =>
  Date.UTC(2026, 8, 12, hours, minutes) - 480 * 60_000;

const CREW = {
  crewId: 7,
  crewName: 'Formwork crew B',
  siteName: 'Site 07',
  cachedAt: 0,
  acting: null,
  members: [
    {employeeId: 1, employeeCode: 'ADC-0001', firstName: 'Elmer', lastName: 'Bacus', tradeSkill: 'Mason'},
  ],
};

const NOT_MARKED = {
  employeeId: 1,
  date: '2026-09-12',
  timeIn: null,
  overrideType: null,
  timeOut: null,
  timeOutType: null,
  timeOutCapturedAt: null,
};

/** Elmer, marked Present at 06:58, and however his day has ended so far. */
function onSiteToday(overrides: Partial<repository.TodayRecord> = {}): void {
  const record: repository.TodayRecord = {
    ...NOT_MARKED,
    status: 'present',
    timeIn: manila(6, 58),
    capturedAt: manila(6, 58),
    ...overrides,
  };

  repo.hasRollCallStarted.mockResolvedValue(true);
  repo.listTodayAttendance.mockResolvedValue(new Map([[1, record]]));
}

const textOf = (node: ReactTestInstance): string =>
  ([] as unknown[])
    .concat(node.props.children)
    .filter(part => typeof part === 'string' || typeof part === 'number')
    .join('');

function texts(root: ReactTestInstance): string[] {
  return root.findAllByType(Text).map(textOf);
}

/** Press the nearest pressable around the text, as a finger on the label would. */
async function press(root: ReactTestInstance, label: string, index = 0): Promise<void> {
  let node: ReactTestInstance | null = root
    .findAllByType(Text)
    .filter(text => textOf(text) === label)[index];

  if (!node) {
    throw new Error(`No text "${label}" on screen. Showing: ${texts(root).join(' | ')}`);
  }

  while (node && !node.props.onPress) {
    node = node.parent;
  }

  await ReactTestRenderer.act(async () => {
    node!.props.onPress();
  });
}

async function render(): Promise<ReactTestInstance> {
  let renderer: ReactTestRenderer.ReactTestRenderer;

  await ReactTestRenderer.act(async () => {
    renderer = ReactTestRenderer.create(<RollCallScreen />);
  });

  return renderer!.root;
}

beforeEach(() => {
  jest.useFakeTimers({now: manila(9, 20), doNotFake: ['nextTick', 'setImmediate']});

  repo.getCachedCrew.mockResolvedValue(CREW);
  repo.getShiftConfig.mockResolvedValue(DEFAULT_SHIFT);
  repo.getLateStartChoice.mockResolvedValue(null);
  repo.hasRollCallStarted.mockResolvedValue(false);
  repo.listTodayAttendance.mockResolvedValue(new Map());
  repo.saveLateStartChoice.mockImplementation(async (_crewId, mode) => ({
    mode,
    decidedAt: Date.now(),
  }));

  const recorded = {...NOT_MARKED, capturedAt: null};
  repo.recordShiftCredit.mockResolvedValue({...recorded, status: 'present'});
  repo.recordStatus.mockResolvedValue({...recorded, status: 'late'});
  repo.recordManualTime.mockResolvedValue({...recorded, status: 'present'});
});

afterEach(() => {
  jest.useRealTimers();
  jest.clearAllMocks();
});

test('offers the override when roll call opens late with nobody marked', async () => {
  const root = await render();

  expect(texts(root)).toContain('Roll call is starting late');
  expect(texts(root)).toContain('Apply 07:00 shift credit');
  expect(texts(root)).toContain('Set each time myself');
  expect(texts(root)).not.toContain('Roll Call');
});

test('does not offer it inside the grace window', async () => {
  jest.setSystemTime(manila(7, 10));

  const root = await render();

  expect(texts(root)).not.toContain('Roll call is starting late');
  expect(texts(root)).toContain('Roll Call');
});

test('does not offer it once roll call is under way', async () => {
  repo.hasRollCallStarted.mockResolvedValue(true);

  const root = await render();

  expect(texts(root)).not.toContain('Roll call is starting late');
});

test('confirming shows what will be logged, then credits Present from 07:00', async () => {
  const root = await render();

  await press(root, 'Apply 07:00 shift credit');

  expect(texts(root)).toContain('Confirm shift credit');
  expect(texts(root)).toContain('FOREMAN_LATE_OVERRIDE');
  expect(texts(root)).toContain('Ronel Dela Cruz');
  expect(texts(root)).toContain('2 h 20 m');

  await press(root, 'Apply 07:00 shift credit');

  expect(repo.saveLateStartChoice).toHaveBeenCalledWith(7, 'credit', expect.any(String));
  expect(texts(root)).toContain('07:00 shift credit applied');

  await press(root, 'Present');

  expect(repo.recordShiftCredit).toHaveBeenCalledWith(1, 7);
  expect(repo.recordStatus).not.toHaveBeenCalled();
});

test('under the credit, Late is still an ordinary tap', async () => {
  repo.getLateStartChoice.mockResolvedValue({mode: 'credit', decidedAt: manila(9, 20)});

  const root = await render();
  await press(root, 'Late');

  expect(repo.recordStatus).toHaveBeenCalledWith(1, 7, 'late');
  expect(repo.recordShiftCredit).not.toHaveBeenCalled();
});

test('"Set each time myself" asks for a time instead of tapping', async () => {
  const root = await render();

  await press(root, 'Set each time myself');
  await press(root, 'Present');

  expect(texts(root)).toContain('Set arrival time');
  expect(repo.recordStatus).not.toHaveBeenCalled();

  await press(root, 'Mark Present at 07:00');

  expect(repo.recordManualTime).toHaveBeenCalledWith(1, 7, 'present', manila(7, 0));
});

test('declining the override leaves ordinary taps', async () => {
  const root = await render();

  await press(root, 'No override — use actual tap times');
  await press(root, 'Present');

  expect(repo.saveLateStartChoice).toHaveBeenCalledWith(7, 'none', expect.any(String));
  expect(repo.recordStatus).toHaveBeenCalledWith(1, 7, 'present');
});

describe('time-out', () => {
  test('Out times a worker out as they leave', async () => {
    jest.setSystemTime(manila(11, 0));
    onSiteToday();
    repo.recordTimeOut.mockResolvedValue({
      ...NOT_MARKED,
      status: 'present',
      timeIn: manila(6, 58),
      capturedAt: manila(6, 58),
      timeOut: manila(11, 0),
      timeOutCapturedAt: manila(11, 0),
    });

    const root = await render();
    await press(root, 'Out');

    expect(repo.recordTimeOut).toHaveBeenCalledWith(1, 7);
    expect(texts(root)).toContain('Out 11:00');
    // A closed day is reopened before anything else changes.
    expect(texts(root)).toContain('Undo out');
    expect(texts(root)).not.toContain('Late');
    expect(texts(root)).not.toContain('Out');
  });

  test('Close shift is not offered before shift end', async () => {
    jest.setSystemTime(manila(15, 59));
    onSiteToday();

    const root = await render();

    expect(texts(root)).not.toContain('Close shift');
  });

  test('from shift end, Close shift asks first, then times out everyone on site', async () => {
    jest.setSystemTime(manila(16, 5));
    onSiteToday();
    repo.closeShift.mockResolvedValue([
      {
        ...NOT_MARKED,
        status: 'present',
        timeIn: manila(6, 58),
        capturedAt: manila(6, 58),
        timeOut: manila(16, 0),
        timeOutType: 'shift_end',
        timeOutCapturedAt: manila(16, 5),
      },
    ]);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

    const root = await render();

    expect(texts(root)).toContain('Time out 1 still on site at 16:00');

    await press(root, 'Close shift');

    expect(repo.closeShift).not.toHaveBeenCalled();
    const [title, , buttons] = alert.mock.calls[0];
    expect(title).toBe('Close shift?');

    await ReactTestRenderer.act(async () => {
      await buttons!.find(button => button.text === 'Close shift')!.onPress!();
    });

    expect(repo.closeShift).toHaveBeenCalledWith(7, [1]);
    expect(texts(root)).toContain('Out 16:00 · shift closed');
    // Nobody left on site, so nothing left to close.
    expect(texts(root)).not.toContain('Close shift');
  });

  test('Set out time states a time-out, held for HR review', async () => {
    jest.setSystemTime(manila(18, 30));
    onSiteToday();
    repo.recordManualTimeOut.mockResolvedValue({
      ...NOT_MARKED,
      status: 'present',
      timeIn: manila(6, 58),
      capturedAt: manila(6, 58),
      timeOut: manila(17, 0),
      timeOutType: 'manual_time',
      timeOutCapturedAt: manila(18, 30),
    });

    const root = await render();
    await press(root, 'Set out time');

    expect(texts(root)).toContain('Set time-out');
    expect(texts(root)).toContain('Present · in 06:58');

    // Starts at shift end; the foreman steps it to when the worker left.
    await press(root, '+1 h');
    await press(root, 'Time out at 17:00');

    expect(repo.recordManualTimeOut).toHaveBeenCalledWith(1, 7, manila(17, 0));
    expect(texts(root)).toContain('Out set to 17:00 · tapped 18:30');
  });

  test('a stated time-out shows the tap beside it, as HR will see it', async () => {
    jest.setSystemTime(manila(18, 30));
    onSiteToday({
      timeOut: manila(17, 0),
      timeOutType: 'manual_time',
      timeOutCapturedAt: manila(18, 30),
    });

    const root = await render();

    expect(texts(root)).toContain('Tapped 06:58');
    expect(texts(root)).toContain('Out set to 17:00 · tapped 18:30');
  });

  test('a refused time-out is explained, not swallowed', async () => {
    jest.setSystemTime(manila(11, 0));
    onSiteToday();
    repo.recordTimeOut.mockRejectedValue(
      new repository.TimeOutRefusedError('This worker is already timed out. Undo it first to change it.'),
    );

    const root = await render();
    await press(root, 'Out');

    expect(texts(root)).toContain('This worker is already timed out. Undo it first to change it.');
  });
});
