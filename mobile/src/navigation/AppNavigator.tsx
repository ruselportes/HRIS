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
import {isBound, onBindingChange} from '../crypto/deviceCredentials';
import {LoginScreen} from '../screens/LoginScreen';
import {ForemanHomeScreen} from '../screens/ForemanHomeScreen';
import {RollCallScreen} from '../screens/RollCallScreen';
import {ComingSoonScreen} from '../screens/ComingSoonScreen';
import {DeviceBindingScreen} from '../screens/DeviceBindingScreen';
import {SyncQueueScreen} from '../screens/SyncQueueScreen';
import {useSyncTriggers} from '../sync/useSyncTriggers';

const Tab = createBottomTabNavigator();

function TimesheetTab() {
  return <ComingSoonScreen title="Timesheet" phase="Phase 8 — Payroll Engine" />;
}

/**
 * The bound, signed-in app. Sync triggers mount here and nowhere earlier: before
 * binding there is nothing able to sign, so there is nothing to send.
 */
function BoundTabs() {
  useSyncTriggers();

  return (
    <Tab.Navigator screenOptions={{headerShown: false}}>
      <Tab.Screen name="Home" component={ForemanHomeScreen} />
      <Tab.Screen name="RollCall" component={RollCallScreen} options={{title: 'Roll call'}} />
      <Tab.Screen name="Timesheet" component={TimesheetTab} />
      <Tab.Screen name="Sync" component={SyncQueueScreen} />
    </Tab.Navigator>
  );
}

function AppNavigator() {
  const {ready, user} = useAuth();
  const [bound, setBound] = useState<boolean | null>(null);

  /*
   * Bound for THIS foreman, not merely bound: the server ties a device to one
   * employee, so a second foreman signing in (an acting foreman borrowing the
   * phone, TC-05) must set it up for themselves before recording. Re-checked
   * whenever the binding changes, e.g. "Set up this phone again" on Sync.
   */
  useEffect(() => {
    if (!user) {
      setBound(null);
      return;
    }

    const check = () => {
      isBound(user.employee_id).then(setBound);
    };

    check();
    return onBindingChange(check);
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
      <BoundTabs />
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  loading: {flex: 1, alignItems: 'center', justifyContent: 'center'},
});

export default AppNavigator;
