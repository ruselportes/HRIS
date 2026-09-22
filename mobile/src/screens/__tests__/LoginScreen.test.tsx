/**
 * Sign-in screen: the fields are labelled (not placeholder-only), the password
 * can be shown, and the server address stays folded away until it is the
 * problem. signIn and the address store are mocked; the auth flow itself is
 * covered in AuthContext.test.tsx.
 *
 * @format
 */

import React from 'react';
import {Text, TextInput, TouchableOpacity} from 'react-native';
import ReactTestRenderer, {ReactTestInstance} from 'react-test-renderer';
import {apiClient} from '../../api/client';
import {LoginScreen, displayHost} from '../LoginScreen';

const mockSignIn = jest.fn();

jest.mock('../../auth/AuthContext', () => ({
  useAuth: () => ({signIn: mockSignIn}),
  FOREMAN_ONLY_REFUSAL: 'This app is for site foremen. Please use the HRIS website.',
}));

jest.mock('../../api/client', () => {
  const actual = jest.requireActual('../../api/client');
  return {...actual, setAndPersistApiBaseUrl: jest.fn().mockResolvedValue('')};
});

// A cold first render of a whole screen is slow on CI-class machines; same
// allowance as RollCallScreen.test.tsx.
jest.setTimeout(30_000);

function render() {
  let renderer!: ReactTestRenderer.ReactTestRenderer;
  ReactTestRenderer.act(() => {
    renderer = ReactTestRenderer.create(<LoginScreen />);
  });
  return renderer.root;
}

const texts = (root: ReactTestInstance) =>
  root.findAllByType(Text).map(t => [t.props.children].flat().join(''));

const input = (root: ReactTestInstance, label: string) =>
  root.findAll(n => n.type === TextInput && n.props.accessibilityLabel === label)[0];

const button = (root: ReactTestInstance, label: string) =>
  root.findAll(
    n =>
      n.type === TouchableOpacity &&
      (n.props.accessibilityLabel === label ||
        n.findAllByType(Text).some(t => t.props.children === label)),
  )[0];

async function type(root: ReactTestInstance, label: string, value: string) {
  await ReactTestRenderer.act(async () => {
    input(root, label).props.onChangeText(value);
  });
}

async function press(root: ReactTestInstance, label: string) {
  await ReactTestRenderer.act(async () => {
    button(root, label).props.onPress();
  });
}

beforeEach(() => {
  mockSignIn.mockReset();
});

test('both fields carry a visible label, not just a placeholder', () => {
  const root = render();

  expect(texts(root)).toEqual(expect.arrayContaining(['Email or Employee ID', 'Password']));
  expect(input(root, 'Email or Employee ID')).toBeDefined();
  expect(input(root, 'Password')).toBeDefined();
});

test('Sign in stays disabled until both an ID and a password are entered', async () => {
  const root = render();
  expect(button(root, 'Sign in').props.disabled).toBe(true);

  await type(root, 'Email or Employee ID', 'ADC-0002');
  expect(button(root, 'Sign in').props.disabled).toBe(true);

  await type(root, 'Password', 'secret');
  expect(button(root, 'Sign in').props.disabled).toBe(false);
});

test('Show reveals the password and Hide masks it again', async () => {
  const root = render();
  expect(input(root, 'Password').props.secureTextEntry).toBe(true);

  await press(root, 'Show password');
  expect(input(root, 'Password').props.secureTextEntry).toBe(false);

  await press(root, 'Hide password');
  expect(input(root, 'Password').props.secureTextEntry).toBe(true);
});

test('the server address is folded to its host until Change is tapped', async () => {
  const root = render();

  expect(input(root, 'Server address')).toBeUndefined();
  // The host is its own nested Text, set darker than the "Server" label. The
  // default differs per platform (10.0.2.2 on Android, 127.0.0.1 under Jest).
  expect(texts(root)).toContain(displayHost(apiClient.defaults.baseURL ?? ''));

  await press(root, 'Change server address');
  expect(input(root, 'Server address')).toBeDefined();
});

test('an unreachable server opens the address field with the error', async () => {
  mockSignIn.mockRejectedValue(new Error('Network Error')); // no response
  const root = render();

  await type(root, 'Email or Employee ID', 'ADC-0002');
  await type(root, 'Password', 'secret');
  await press(root, 'Sign in');

  expect(mockSignIn).toHaveBeenCalledWith('ADC-0002', 'secret');
  expect(texts(root).some(t => t.startsWith("Can't reach the server"))).toBe(true);
  expect(input(root, 'Server address')).toBeDefined();
});

test('wrong credentials say so and leave the server address folded', async () => {
  mockSignIn.mockRejectedValue({response: {status: 422, data: {}}});
  const root = render();

  await type(root, 'Email or Employee ID', ' ADC-0002 ');
  await type(root, 'Password', 'wrong');
  await press(root, 'Sign in');

  // The ID is trimmed before it is sent; the password is not.
  expect(mockSignIn).toHaveBeenCalledWith('ADC-0002', 'wrong');
  expect(texts(root)).toContain('Incorrect ID/email or password.');
  expect(input(root, 'Server address')).toBeUndefined();
});

test('displayHost shows what a foreman would recognise', () => {
  expect(displayHost('http://10.0.2.2:8090/api')).toBe('10.0.2.2:8090');
  expect(displayHost('https://hris.example.ph/api/')).toBe('hris.example.ph');
});
