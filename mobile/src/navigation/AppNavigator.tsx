/**
 * AppNavigator — root navigation container for HRIS mobile.
 * Tab navigator binds the primary flows: Attendance, Sync, and Settings.
 *
 * @format
 */

import React from 'react';
import {NavigationContainer} from '@react-navigation/native';
import {createBottomTabNavigator} from '@react-navigation/bottom-tabs';
import AttendanceScreen from '../screens/AttendanceScreen';
import SignInScreen from '../screens/SignInScreen';

const Tab = createBottomTabNavigator();

function AppNavigator() {
  return (
    <NavigationContainer>
      <Tab.Navigator>
        <Tab.Screen name="Attendance" component={AttendanceScreen} />
        <Tab.Screen name="Sign In" component={SignInScreen} />
      </Tab.Navigator>
    </NavigationContainer>
  );
}

export default AppNavigator;