/**
 * HRIS Mobile — Arcenas Development Corporation
 * Attendance capture, crypto signing, and offline sync.
 *
 * @format
 */

import React, {useEffect, useState} from 'react';
import {StatusBar, useColorScheme} from 'react-native';
import {SafeAreaProvider} from 'react-native-safe-area-context';
import {AuthProvider} from './src/auth/AuthContext';
import AppNavigator from './src/navigation/AppNavigator';
import {loadApiBaseUrl, setApiBaseUrl} from './src/api/client';

function App() {
  const isDarkMode = useColorScheme() === 'dark';
  const [booted, setBooted] = useState(false);

  useEffect(() => {
    // Apply the persisted server address before anything can sign in; falls
    // back to the emulator alias on first launch.
    loadApiBaseUrl().then(setApiBaseUrl).finally(() => setBooted(true));
  }, []);

  if (!booted) {
    return null;
  }

  return (
    <SafeAreaProvider>
      <StatusBar barStyle={isDarkMode ? 'light-content' : 'dark-content'} />
      <AuthProvider>
        <AppNavigator />
      </AuthProvider>
    </SafeAreaProvider>
  );
}

export default App;