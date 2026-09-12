/**
 * Foreman Device Binding (docs/prototypes/HRIS Foreman Device Binding.dc.html).
 *
 * One-time setup, run once per phone. Language follows the prototype's
 * deliberately non-technical register — "this phone's safety lock" rather
 * than "ECDSA P-256 keypair in the TEE". The foreman does not need the
 * cryptography explained to them; they need to know whether it worked and
 * whether they can start working.
 *
 * @format
 */

import React, {useCallback, useEffect, useState} from 'react';
import {
  ActivityIndicator,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import {useAuth} from '../auth/AuthContext';
import {
  BindingError,
  BindingResult,
  BindingStep,
  bindThisDevice,
} from '../crypto/deviceBinding';
import {isBound} from '../crypto/deviceCredentials';

const STEP_LABELS: {key: BindingStep; label: string}[] = [
  {key: 'generating_key', label: "Creating this phone's safety lock"},
  {key: 'registering', label: 'Registering the phone with HR'},
  {key: 'saving', label: 'Securing this phone'},
];

const STEP_ORDER: BindingStep[] = [
  'generating_key',
  'registering',
  'saving',
  'complete',
];

export function DeviceBindingScreen({onBound}: {onBound?: () => void}) {
  const {user} = useAuth();
  const [step, setStep] = useState<BindingStep | null>(null);
  const [result, setResult] = useState<BindingResult | null>(null);
  const [error, setError] = useState<{message: string; retryable: boolean} | null>(
    null,
  );
  const [alreadyBound, setAlreadyBound] = useState<boolean | null>(null);

  useEffect(() => {
    isBound().then(setAlreadyBound);
  }, []);

  const run = useCallback(async () => {
    setError(null);
    setResult(null);
    try {
      const bound = await bindThisDevice(setStep);
      setResult(bound);
      onBound?.();
    } catch (err) {
      setStep(null);
      setError(
        err instanceof BindingError
          ? {message: err.message, retryable: err.retryable}
          : {
              message: 'Setup did not finish. Please try again.',
              retryable: true,
            },
      );
    }
  }, [onBound]);

  const running = step !== null && step !== 'complete' && result === null;

  if (alreadyBound === null) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.eyebrow}>One-time setup</Text>
      <Text style={styles.title}>
        Welcome{user ? `, ${user.first_name}` : ''}
      </Text>
      <Text style={styles.lede}>
        {alreadyBound && !result
          ? 'This phone is already set up. You only need to do this again if HR asks you to.'
          : 'This phone needs to be set up once before you can record attendance. It takes a few seconds.'}
      </Text>

      {(running || result) && (
        <View style={styles.steps}>
          {STEP_LABELS.map(({key, label}) => {
            const reached = STEP_ORDER.indexOf(step ?? 'generating_key');
            const index = STEP_ORDER.indexOf(key);
            const done = result !== null || reached > index;
            const active = !done && step === key;

            return (
              <View key={key} style={styles.stepRow}>
                <View
                  style={[
                    styles.stepDot,
                    done && styles.stepDotDone,
                    active && styles.stepDotActive,
                  ]}
                />
                <Text style={[styles.stepLabel, done && styles.stepLabelDone]}>
                  {label}
                </Text>
                {active && <ActivityIndicator size="small" />}
              </View>
            );
          })}
        </View>
      )}

      {running && (
        <Text style={styles.keepOpen}>
          Please keep the app open until this finishes.
        </Text>
      )}

      {result && (
        <View style={styles.successBox}>
          <Text style={styles.successTitle}>
            {result.rebound ? 'This phone is set up again' : 'Almost done'}
          </Text>
          <Text style={styles.successBody}>
            Your account is confirmed and this phone is registered. You can
            start recording attendance.
          </Text>

          {/*
            Reported honestly rather than hidden. On an emulator, or a handset
            without secure hardware, the key is software-backed — the foreman
            is not the audience for that detail, but HR reviewing a device list
            is, and overstating it here would make the audit trail misleading.
          */}
          {!result.hardwareBacked && (
            <Text style={styles.warnBody}>
              Note: this phone does not have a hardware security chip, so its
              protection is weaker. HR may ask you to use a different handset.
            </Text>
          )}
        </View>
      )}

      {error && (
        <View style={styles.errorBox}>
          <Text style={styles.errorText}>{error.message}</Text>
          {!error.retryable && (
            <Text style={styles.errorHint}>
              Trying again will not help — please contact HR.
            </Text>
          )}
        </View>
      )}

      {!result && (!error || error.retryable) && (
        <TouchableOpacity
          style={[styles.button, running && styles.buttonDisabled]}
          onPress={run}
          disabled={running}>
          {running ? (
            <ActivityIndicator color="#f2f2f3" />
          ) : (
            <Text style={styles.buttonText}>
              {error ? 'Try again' : alreadyBound ? 'Set up again' : 'Set up this phone'}
            </Text>
          )}
        </TouchableOpacity>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#f2f2f3'},
  content: {padding: 24, paddingBottom: 48},
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#f2f2f3',
  },
  eyebrow: {
    fontSize: 11,
    letterSpacing: 1.6,
    textTransform: 'uppercase',
    color: '#416180',
    marginBottom: 8,
  },
  title: {fontSize: 30, fontWeight: '700', color: '#1d1f20'},
  lede: {fontSize: 15, color: '#5d5d60', marginTop: 8, lineHeight: 21},
  steps: {marginTop: 28, gap: 14},
  stepRow: {flexDirection: 'row', alignItems: 'center', gap: 12},
  stepDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: '#d4d4d7',
  },
  stepDotActive: {backgroundColor: '#597ea3'},
  stepDotDone: {backgroundColor: '#2f7a4d'},
  stepLabel: {flex: 1, fontSize: 15, color: '#5d5d60'},
  stepLabelDone: {color: '#1d1f20'},
  keepOpen: {fontSize: 13, color: '#7a7a7d', marginTop: 20, fontStyle: 'italic'},
  successBox: {
    marginTop: 24,
    padding: 16,
    borderRadius: 4,
    backgroundColor: '#e6f1ea',
  },
  successTitle: {fontSize: 17, fontWeight: '700', color: '#1f5334'},
  successBody: {fontSize: 14, color: '#1d1f20', marginTop: 6, lineHeight: 20},
  warnBody: {fontSize: 13, color: '#8a5211', marginTop: 12, lineHeight: 19},
  errorBox: {
    marginTop: 24,
    padding: 16,
    borderRadius: 4,
    backgroundColor: '#f6e1de',
  },
  errorText: {fontSize: 14, color: '#75261c', lineHeight: 20},
  errorHint: {fontSize: 13, color: '#75261c', marginTop: 8, fontWeight: '600'},
  button: {
    minHeight: 60,
    borderRadius: 4,
    backgroundColor: '#1d2d3d',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 28,
  },
  buttonDisabled: {opacity: 0.6},
  buttonText: {color: '#f2f2f3', fontSize: 17, fontWeight: '600'},
});
