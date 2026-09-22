/**
 * Debug-only database key display (STD TC-02 setup).
 *
 * The acceptance run needs the SQLCipher key handed to the tester, and the
 * key must never be reachable in a release build: `adb run-as` cannot reach
 * a release app's files on an unrooted phone anyway, but a display path
 * compiled into release would still be one hidden screen away from leaking
 * it. So this component renders nothing unless __DEV__ is true — Metro sets
 * that false for release bundles, and the release APK used for every other
 * test cannot show the key by construction.
 *
 * TC-02 procedure: with the debug build on P1, open this screen, enter the
 * 64 hex characters below into the SQLCipher client's *raw key* field (not
 * the passphrase field — the app passes `x'<hex>'`, which SQLCipher treats
 * as a raw key with no KDF, and a passphrase entry would derive a different
 * key), then pull and edit hris_offline.db as the test case describes.
 *
 * @format
 */

import React, {useEffect, useState} from 'react';
import {StyleSheet, Text, View} from 'react-native';
import {getDbKeyHexForTester} from '../crypto/dbKey';

declare const __DEV__: boolean;

export function DbKeyDebugPanel() {
  const [key, setKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getDbKeyHexForTester().then(setKey, err => {
      setError(err instanceof Error ? err.message : String(err));
    });
  }, []);

  if (!__DEV__) {
    return null;
  }

  return (
    <View style={styles.box}>
      <Text style={styles.title}>Testing only — database key</Text>
      {key !== null ? (
        <>
          <Text selectable style={styles.key}>
            {key}
          </Text>
          <Text style={styles.body}>
            64 hex characters. Paste into the SQLCipher client's raw-key field, not the
            passphrase field. Debug builds only — this panel does not exist in release.
          </Text>
        </>
      ) : (
        <Text style={styles.body}>{error ?? 'Loading the key…'}</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  box: {marginTop: 16, padding: 14, borderRadius: 4, backgroundColor: '#fff'},
  title: {fontSize: 16, fontWeight: '700', color: '#1d1f20'},
  key: {fontSize: 13, color: '#1d1f20', marginTop: 8, fontFamily: 'monospace'},
  body: {fontSize: 13, color: '#5d5d60', marginTop: 8, lineHeight: 20},
});
