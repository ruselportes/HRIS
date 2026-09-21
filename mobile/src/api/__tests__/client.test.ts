/**
 * Pure URL handling for the login screen's Server address field — no
 * AsyncStorage, no axios, so it stays a plain logic test.
 *
 * @format
 */

import {normalizeBaseUrl, isValidBaseUrl} from '../client';

describe('normalizeBaseUrl', () => {
  it('keeps a fully-formed address and ensures the /api suffix', () => {
    expect(normalizeBaseUrl('http://192.168.1.50:8090/api')).toBe(
      'http://192.168.1.50:8090/api',
    );
    expect(normalizeBaseUrl('http://192.168.1.50:8090')).toBe(
      'http://192.168.1.50:8090/api',
    );
  });

  it('defaults to http:// when the scheme is omitted (LAN is plaintext until HTTPS)', () => {
    expect(normalizeBaseUrl('192.168.1.50:8090')).toBe(
      'http://192.168.1.50:8090/api',
    );
  });

  it('preserves https (the quick tunnel) and still appends /api', () => {
    expect(normalizeBaseUrl('https://example.trycloudflare.com')).toBe(
      'https://example.trycloudflare.com/api',
    );
  });

  it('strips trailing slashes', () => {
    expect(normalizeBaseUrl('http://10.0.2.2:8090/api///')).toBe(
      'http://10.0.2.2:8090/api',
    );
  });
});

describe('isValidBaseUrl', () => {
  it('accepts http and https addresses', () => {
    expect(isValidBaseUrl('http://192.168.1.50:8090')).toBe(true);
    expect(isValidBaseUrl('https://example.trycloudflare.com/api')).toBe(true);
  });

  it('rejects whitespace, garbage and scheme-less absolute paths', () => {
    expect(isValidBaseUrl('   ')).toBe(false);
    expect(isValidBaseUrl('not a url')).toBe(false);
  });
});