/**
 * Employee sign-in — same identifier+password contract as web/src/pages/LoginPage.jsx
 * (POST /auth/login). No offline-PIN fallback here; that's explicitly out of
 * Phase 4 scope (see CLAUDE.md/SPMP crypto engine note — device binding and
 * any offline-auth story belongs to Phase 5).
 *
 * @format
 */

import React, {useState} from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
} from 'react-native';
import {useAuth} from '../auth/AuthContext';
import {apiClient} from '../api/client';

export function LoginScreen() {
  const {signIn} = useAuth();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setError(null);
    setBusy(true);
    try {
      await signIn(identifier.trim(), password);
    } catch (err: any) {
      // Distinguish "server unreachable" from "server said no" — collapsing
      // both into one message made a wrong API port look like bad credentials.
      if (!err?.response) {
        setError(
          `Can't reach the server at ${apiClient.defaults.baseURL}. Is the backend running?`,
        );
      } else if (err.response.status === 429) {
        setError(err.response.data?.message ?? 'Too many attempts. Try again later.');
      } else {
        setError('Incorrect ID/email or password.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Text style={styles.title}>HRIS</Text>
      <Text style={styles.subtitle}>Site Foreman sign in</Text>

      <TextInput
        style={styles.input}
        placeholder="Employee code or email"
        autoCapitalize="none"
        autoCorrect={false}
        value={identifier}
        onChangeText={setIdentifier}
      />
      <TextInput
        style={styles.input}
        placeholder="Password"
        secureTextEntry
        value={password}
        onChangeText={setPassword}
      />

      {error && <Text style={styles.error}>{error}</Text>}

      <TouchableOpacity
        style={[styles.button, busy && styles.buttonDisabled]}
        onPress={submit}
        disabled={busy || !identifier || !password}>
        {busy ? (
          <ActivityIndicator color="#f2f2f3" />
        ) : (
          <Text style={styles.buttonText}>Sign in</Text>
        )}
      </TouchableOpacity>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    paddingHorizontal: 28,
    backgroundColor: '#f2f2f3',
  },
  title: {fontSize: 40, fontWeight: '700', color: '#1d1f20', textAlign: 'center'},
  subtitle: {fontSize: 15, color: '#5d5d60', textAlign: 'center', marginBottom: 32},
  input: {
    minHeight: 56,
    borderWidth: 1,
    borderColor: '#d4d4d7',
    borderRadius: 4,
    paddingHorizontal: 16,
    fontSize: 16,
    marginBottom: 14,
    backgroundColor: '#fff',
  },
  error: {color: '#a83a2c', fontSize: 14, marginBottom: 14},
  button: {
    minHeight: 56,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 8,
  },
  buttonDisabled: {opacity: 0.6},
  buttonText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
});
