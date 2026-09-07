/**
 * SignInScreen — placeholder for the employee sign-in screen.
 * Phase 2+: credential check against Employee.role_id (no separate users table).
 *
 * @format
 */

import React from 'react';
import {StyleSheet, Text, View} from 'react-native';

function SignInScreen() {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Sign In</Text>
      <Text style={styles.subtitle}>Employee authentication — coming in Phase 2</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#f2f2f3',
  },
  title: {
    fontSize: 32,
    fontWeight: '600',
    color: '#1d1f20',
  },
  subtitle: {
    marginTop: 8,
    fontSize: 14,
    color: '#5d5d60',
  },
});

export default SignInScreen;