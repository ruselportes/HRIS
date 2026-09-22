/**
 * Employee sign-in — same identifier+password contract as web/src/pages/LoginPage.jsx
 * (POST /auth/login). No offline-PIN fallback here; that's explicitly out of
 * Phase 4 scope (see CLAUDE.md/SPMP crypto engine note — device binding and
 * any offline-auth story belongs to Phase 5).
 *
 * Laid out like the web sign-in, since the prototypes have no mobile one:
 * labelled fields (a placeholder alone vanishes as soon as the foreman types),
 * a Show toggle on the password, and the error in the web's red callout. The
 * server address is set once per phone, so it sits folded at the bottom and
 * opens by itself when the server cannot be reached.
 *
 * @format
 */

import React, {useRef, useState} from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import {useAuth, FOREMAN_ONLY_REFUSAL} from '../auth/AuthContext';
import {ArcenasLogo} from '../components/ArcenasLogo';
import {
  apiClient,
  setAndPersistApiBaseUrl,
  isValidBaseUrl,
  normalizeBaseUrl,
} from '../api/client';

type ErrorKind = 'credentials' | 'server' | 'other';
type Field = 'identifier' | 'password' | 'server';

/** "http://10.0.2.2:8090/api" -> "10.0.2.2:8090": what the foreman would recognise. */
export function displayHost(url: string): string {
  return url
    .trim()
    .replace(/^https?:\/\//i, '')
    .replace(/\/+$/, '')
    .replace(/\/api$/i, '');
}

export function LoginScreen() {
  const {signIn} = useAuth();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [serverUrl, setServerUrl] = useState(apiClient.defaults.baseURL ?? '');
  const [serverOpen, setServerOpen] = useState(false);
  const [serverSaved, setServerSaved] = useState(false);
  const [error, setError] = useState<{kind: ErrorKind; message: string} | null>(null);
  const [focused, setFocused] = useState<Field | null>(null);
  const [busy, setBusy] = useState(false);
  const passwordRef = useRef<React.ComponentRef<typeof TextInput>>(null);

  const canSubmit = !busy && identifier.trim() !== '' && password !== '';

  const fail = (kind: ErrorKind, message: string) => {
    setError({kind, message});
    if (kind === 'server') {
      setServerOpen(true);
    }
  };

  const submit = async () => {
    if (!canSubmit) {
      return;
    }
    setError(null);
    setBusy(true);
    try {
      // Apply the address field even on a fresh device: it survives the
      // sign-in attempt only when it parses and was persistable.
      const normalized = normalizeBaseUrl(serverUrl);
      if (!isValidBaseUrl(serverUrl)) {
        fail('server', 'That does not look like a server address. Use http://host:port.');
        return;
      }
      await setAndPersistApiBaseUrl(normalized);
      setServerSaved(true);
      await signIn(identifier.trim(), password);
    } catch (err: any) {
      // Distinguish "server unreachable" from "server said no" — collapsing
      // both into one message made a wrong API port look like bad credentials.
      if (err?.message === FOREMAN_ONLY_REFUSAL) {
        fail('other', FOREMAN_ONLY_REFUSAL);
      } else if (!err?.response) {
        fail(
          'server',
          `Can't reach the server at ${apiClient.defaults.baseURL}. Is the backend running?`,
        );
      } else if (err.response.status === 429) {
        fail('other', err.response.data?.message ?? 'Too many attempts. Try again later.');
      } else {
        fail('credentials', 'Incorrect ID/email or password.');
      }
    } finally {
      setBusy(false);
    }
  };

  const credentialError = error?.kind === 'credentials';

  const inputStyle = (field: Field, invalid: boolean) => [
    styles.input,
    focused === field && styles.inputFocused,
    invalid && styles.inputInvalid,
  ];

  return (
    <KeyboardAvoidingView
      style={styles.flex}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={styles.container}
        keyboardShouldPersistTaps="handled">
        <View style={styles.logo}>
          <ArcenasLogo height={56} />
        </View>

        <Text style={styles.overline}>HRIS · Site Foreman</Text>
        <Text style={styles.title}>Sign in</Text>
        <Text style={styles.helper}>Use the employee ID and password HR gave you.</Text>

        {error && (
          <View style={styles.errorBox} accessibilityRole="alert">
            <Text style={styles.errorText}>{error.message}</Text>
          </View>
        )}

        <Text style={styles.label} nativeID="identifierLabel">
          Email or Employee ID
        </Text>
        <TextInput
          style={inputStyle('identifier', credentialError)}
          accessibilityLabelledBy="identifierLabel"
          accessibilityLabel="Email or Employee ID"
          placeholder="e.g. ADC-0002"
          placeholderTextColor="#98989b"
          autoCapitalize="none"
          autoCorrect={false}
          autoComplete="username"
          textContentType="username"
          returnKeyType="next"
          blurOnSubmit={false}
          onSubmitEditing={() => passwordRef.current?.focus()}
          onFocus={() => setFocused('identifier')}
          onBlur={() => setFocused(null)}
          value={identifier}
          onChangeText={setIdentifier}
        />

        <Text style={styles.label} nativeID="passwordLabel">
          Password
        </Text>
        <View style={[inputStyle('password', credentialError), styles.passwordRow]}>
          <TextInput
            ref={passwordRef}
            style={styles.passwordInput}
            accessibilityLabelledBy="passwordLabel"
            accessibilityLabel="Password"
            placeholder="••••••••"
            placeholderTextColor="#98989b"
            secureTextEntry={!showPassword}
            autoCapitalize="none"
            autoCorrect={false}
            autoComplete="password"
            textContentType="password"
            returnKeyType="go"
            onSubmitEditing={submit}
            onFocus={() => setFocused('password')}
            onBlur={() => setFocused(null)}
            value={password}
            onChangeText={setPassword}
          />
          <TouchableOpacity
            style={styles.showButton}
            accessibilityRole="button"
            accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
            onPress={() => setShowPassword(s => !s)}>
            <Text style={styles.showButtonText}>{showPassword ? 'Hide' : 'Show'}</Text>
          </TouchableOpacity>
        </View>

        <TouchableOpacity
          style={[styles.button, !canSubmit && styles.buttonDisabled]}
          accessibilityRole="button"
          accessibilityState={{disabled: !canSubmit, busy}}
          onPress={submit}
          disabled={!canSubmit}>
          {busy ? (
            <ActivityIndicator color="#f2f2f3" />
          ) : (
            <Text style={styles.buttonText}>Sign in</Text>
          )}
        </TouchableOpacity>

        <Text style={styles.footnote}>
          Forgot your password? Ask HR to reset it. Accounts are created by an administrator.
        </Text>

        <View style={styles.serverBlock}>
          {serverOpen ? (
            <>
              <Text style={styles.label} nativeID="serverLabel">
                Server address
              </Text>
              <TextInput
                style={inputStyle('server', error?.kind === 'server')}
                accessibilityLabelledBy="serverLabel"
                accessibilityLabel="Server address"
                placeholder="http://192.168.1.50:8090/api"
                placeholderTextColor="#98989b"
                keyboardType="url"
                autoCapitalize="none"
                autoCorrect={false}
                onFocus={() => setFocused('server')}
                onBlur={() => setFocused(null)}
                value={serverUrl}
                onChangeText={text => {
                  setServerUrl(text);
                  setServerSaved(false);
                }}
              />
              <Text style={serverSaved ? styles.saved : styles.hint}>
                {serverSaved
                  ? 'Server address saved.'
                  : 'Set once per phone. Ask your administrator for this address.'}
              </Text>
            </>
          ) : (
            <View style={styles.serverRow}>
              <Text style={styles.serverSummary} numberOfLines={1}>
                Server <Text style={styles.serverHost}>{displayHost(serverUrl)}</Text>
              </Text>
              <TouchableOpacity
                accessibilityRole="button"
                accessibilityLabel="Change server address"
                onPress={() => setServerOpen(true)}>
                <Text style={styles.link}>Change</Text>
              </TouchableOpacity>
            </View>
          )}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: {flex: 1, backgroundColor: '#f2f2f3'},
  container: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingHorizontal: 24,
    paddingVertical: 40,
    backgroundColor: '#f2f2f3',
  },
  logo: {alignItems: 'center', marginBottom: 36},
  overline: {
    fontSize: 12,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: '#5d5d60',
    marginBottom: 6,
  },
  title: {fontSize: 30, fontWeight: '700', color: '#1d1f20', marginBottom: 6},
  helper: {fontSize: 15, lineHeight: 21, color: '#5d5d60', marginBottom: 24},
  errorBox: {
    borderLeftWidth: 3,
    borderLeftColor: '#a83a2c',
    backgroundColor: '#f7e8e5',
    paddingVertical: 10,
    paddingHorizontal: 12,
    marginBottom: 18,
  },
  errorText: {color: '#75261c', fontSize: 14, lineHeight: 20},
  label: {fontSize: 14, fontWeight: '500', color: '#424244', marginBottom: 6},
  input: {
    minHeight: 52,
    borderWidth: 1,
    borderColor: '#b7b7ba',
    borderRadius: 4,
    paddingHorizontal: 14,
    fontSize: 16,
    color: '#1d1f20',
    marginBottom: 18,
    backgroundColor: '#fff',
  },
  inputFocused: {borderColor: '#5980a6', borderWidth: 2, paddingHorizontal: 13},
  inputInvalid: {borderColor: '#a83a2c'},
  passwordRow: {flexDirection: 'row', alignItems: 'center', paddingRight: 6},
  passwordInput: {flex: 1, minHeight: 50, fontSize: 16, color: '#1d1f20', padding: 0},
  showButton: {
    borderWidth: 1,
    borderColor: '#d4d4d7',
    borderRadius: 3,
    paddingHorizontal: 10,
    paddingVertical: 6,
    marginLeft: 8,
  },
  showButtonText: {fontSize: 13, color: '#1d1f20'},
  button: {
    minHeight: 54,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 6,
  },
  buttonDisabled: {opacity: 0.5},
  buttonText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
  footnote: {fontSize: 13, lineHeight: 19, color: '#7a7a7d', textAlign: 'center', marginTop: 16},
  serverBlock: {
    marginTop: 32,
    paddingTop: 16,
    borderTopWidth: 1,
    borderTopColor: '#d4d4d7',
  },
  serverRow: {flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between'},
  serverSummary: {flex: 1, fontSize: 13, color: '#7a7a7d', marginRight: 12},
  serverHost: {color: '#424244'},
  link: {fontSize: 14, fontWeight: '600', color: '#416180'},
  hint: {fontSize: 13, color: '#7a7a7d', marginTop: -10},
  saved: {fontSize: 13, color: '#2e7d32', marginTop: -10},
});
