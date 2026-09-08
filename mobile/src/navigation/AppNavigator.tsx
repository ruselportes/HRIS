/**
 * AppNavigator — unauthenticated -> LoginScreen; authenticated -> bottom
 * tabs matching the prototype's actual nav (Home / Roll call / Timesheet /
 * Sync). Timesheet (Phase 8) and Sync (Phase 6) are real tab slots per the
 * prototype but render ComingSoonScreen — not built yet, not hidden either.
 *
 * @format
 */

import React from 'react';
import {ActivityIndicator, StyleSheet, View} from 'react-native';
import {NavigationContainer} from '@react-navigation/native';
import {createBottomTabNavigator} from '@react-navigation/bottom-tabs';
import {useAuth} from '../auth/AuthContext';
import {LoginScreen} from '../screens/LoginScreen';
import {ForemanHomeScreen} from '../screens/ForemanHomeScreen';
import {RollCallScreen} from '../screens/RollCallScreen';
import {ComingSoonScreen} from '../screens/ComingSoonScreen';

const Tab = createBottomTabNavigator();

function TimesheetTab() {
  return <ComingSoonScreen title="Timesheet" phase="Phase 8 — Payroll Engine" />;
}

function SyncTab() {
  return <ComingSoonScreen title="Sync" phase="Phase 6 — Background Sync Engine" />;
}

function AppNavigator() {
  const {ready, user} = useAuth();

  if (!ready) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      {user ? (
        <Tab.Navigator screenOptions={{headerShown: false}}>
          <Tab.Screen name="Home" component={ForemanHomeScreen} />
          <Tab.Screen name="RollCall" component={RollCallScreen} options={{title: 'Roll call'}} />
          <Tab.Screen name="Timesheet" component={TimesheetTab} />
          <Tab.Screen name="Sync" component={SyncTab} />
        </Tab.Navigator>
      ) : (
        <LoginScreen />
      )}
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  loading: {flex: 1, alignItems: 'center', justifyContent: 'center'},
});

export default AppNavigator;
