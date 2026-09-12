/**
 * AppNavigator — unauthenticated -> LoginScreen; authenticated -> bottom
 * tabs matching the prototype's actual nav (Home / Roll call / Timesheet /
 * Sync). Timesheet (Phase 8) and Sync (Phase 6) are real tab slots per the
 * prototype but render ComingSoonScreen — not built yet, not hidden either.
 *
 * @format
 */

import React, {useEffect, useState} from 'react';
import {ActivityIndicator, StyleSheet, View} from 'react-native';
import {NavigationContainer} from '@react-navigation/native';
import {createBottomTabNavigator} from '@react-navigation/bottom-tabs';
import {useAuth} from '../auth/AuthContext';
import {isBound} from '../crypto/deviceCredentials';
import {LoginScreen} from '../screens/LoginScreen';
import {ForemanHomeScreen} from '../screens/ForemanHomeScreen';
import {RollCallScreen} from '../screens/RollCallScreen';
import {ComingSoonScreen} from '../screens/ComingSoonScreen';
import {DeviceBindingScreen} from '../screens/DeviceBindingScreen';

const Tab = createBottomTabNavigator();

function TimesheetTab() {
  return <ComingSoonScreen title="Timesheet" phase="Phase 8 — Payroll Engine" />;
}

function SyncTab() {
  return <ComingSoonScreen title="Sync" phase="Phase 6 — Background Sync Engine" />;
}

function AppNavigator() {
  const {ready, user} = useAuth();
  const [bound, setBound] = useState<boolean | null>(null);

  useEffect(() => {
    if (!user) {
      setBound(null);
      return;
    }

    isBound().then(setBound);
  }, [user]);

  if (!ready || (user && bound === null)) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  if (!user) {
    return (
      <NavigationContainer>
        <LoginScreen />
      </NavigationContainer>
    );
  }

  /*
   * Binding gates the tabs rather than sitting inside them. Capture refuses
   * outright on an unbound device, so letting a foreman reach Roll Call first
   * would only present a screen where every tap fails.
   */
  if (!bound) {
    return (
      <NavigationContainer>
        <DeviceBindingScreen onBound={() => setBound(true)} />
      </NavigationContainer>
    );
  }

  return (
    <NavigationContainer>
      <Tab.Navigator screenOptions={{headerShown: false}}>
        <Tab.Screen name="Home" component={ForemanHomeScreen} />
        <Tab.Screen name="RollCall" component={RollCallScreen} options={{title: 'Roll call'}} />
        <Tab.Screen name="Timesheet" component={TimesheetTab} />
        <Tab.Screen name="Sync" component={SyncTab} />
      </Tab.Navigator>
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  loading: {flex: 1, alignItems: 'center', justifyContent: 'center'},
});

export default AppNavigator;
