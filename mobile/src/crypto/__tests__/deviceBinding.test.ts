/**
 * Binding orchestration tests.
 *
 * The ordering is the thing under test, in particular that credentials are
 * only persisted after the server confirms. Persisting earlier would leave the
 * device believing it is bound with a secret the server never issued, and
 * every record it captured afterwards would be rejected with the foreman
 * having no way to tell why.
 *
 * @format
 */

import {apiClient} from '../../api/client';
import {__reset as resetSigner} from '../../native/__mocks__/NativeHrisTeeSigner';
import {BindingError, BindingStep, bindThisDevice} from '../deviceBinding';
import * as deviceCredentials from '../deviceCredentials';
import * as teeSigner from '../teeSigner';

jest.mock('../../db/deviceId', () => ({
  getOrCreateDeviceId: jest.fn().mockResolvedValue('dev-fixed-0001'),
}));

describe('bindThisDevice', () => {
  let saveSpy: jest.SpyInstance;
  let postSpy: jest.SpyInstance;

  beforeEach(() => {
    resetSigner();

    saveSpy = jest.spyOn(deviceCredentials, 'saveCredentials').mockResolvedValue();

    postSpy = jest.spyOn(apiClient, 'post').mockResolvedValue({
      data: {
        device_id: 'dev-fixed-0001',
        hmac_key: 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=',
        security_level: 'TRUSTED_ENVIRONMENT',
        hardware_backed: true,
        rebound: false,
      },
    } as any);
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  test('registers the generated public key and stores what the server returns', async () => {
    const result = await bindThisDevice();

    expect(postSpy).toHaveBeenCalledWith(
      '/me/devices',
      expect.objectContaining({
        device_id: 'dev-fixed-0001',
        public_key: expect.stringContaining('BEGIN PUBLIC KEY'),
        security_level: 'TRUSTED_ENVIRONMENT',
      }),
    );

    expect(saveSpy).toHaveBeenCalledWith({
      deviceId: 'dev-fixed-0001',
      hmacKeyBase64: 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=',
    });

    expect(result.hardwareBacked).toBe(true);
  });

  test('reports each step in order', async () => {
    const steps: BindingStep[] = [];

    await bindThisDevice(step => steps.push(step));

    expect(steps).toEqual(['generating_key', 'registering', 'saving', 'complete']);
  });

  test('does not persist credentials if registration fails', async () => {
    postSpy.mockRejectedValue({response: {status: 500}});

    await expect(bindThisDevice()).rejects.toThrow(BindingError);

    // The critical assertion: no half-bound state. A stored secret the server
    // never issued would make every later capture fail silently.
    expect(saveSpy).not.toHaveBeenCalled();
  });

  test('a 422 from the server is not retryable', async () => {
    // The server refused this specific key — wrong curve, or hardware backing
    // required and absent. Retrying sends the same key and fails identically.
    postSpy.mockRejectedValue({
      response: {status: 422, data: {message: 'not hardware-backed'}},
    });

    await expect(bindThisDevice()).rejects.toMatchObject({
      step: 'registering',
      retryable: false,
      message: 'not hardware-backed',
    });
  });

  test('a network failure is retryable', async () => {
    postSpy.mockRejectedValue({});

    await expect(bindThisDevice()).rejects.toMatchObject({
      step: 'registering',
      retryable: true,
    });
  });

  test('keystore failure is reported as not retryable', async () => {
    jest
      .spyOn(teeSigner, 'createSigningKey')
      .mockRejectedValue(new Error('keygen_failed'));

    await expect(bindThisDevice()).rejects.toMatchObject({
      step: 'generating_key',
      retryable: false,
    });

    expect(postSpy).not.toHaveBeenCalled();
    expect(saveSpy).not.toHaveBeenCalled();
  });

  test('a software-backed key still binds but is reported as such', async () => {
    // The emulator path. Binding succeeds when the server allows it, but the
    // weaker backing is surfaced rather than hidden.
    postSpy.mockResolvedValue({
      data: {
        hmac_key: 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=',
        security_level: 'SOFTWARE',
        hardware_backed: false,
        rebound: false,
      },
    } as any);

    const result = await bindThisDevice();

    expect(result.hardwareBacked).toBe(false);
    expect(result.securityLevel).toBe('SOFTWARE');
  });

  test('a rebind is reported as one', async () => {
    postSpy.mockResolvedValue({
      data: {
        hmac_key: 'c2VjcmV0LWtleS1mb3ItaG1hYy10ZXN0aW5nLW9ubHk=',
        security_level: 'TRUSTED_ENVIRONMENT',
        hardware_backed: true,
        rebound: true,
      },
    } as any);

    expect((await bindThisDevice()).rebound).toBe(true);
  });
});
