/**
 * AttendanceScreen — placeholder for the offline crew attendance checklist.
 * Phase 2+: tap-based time-in/time-out with crypto signing + SQLite capture.
 *
 * @format
 */

import React from 'react';
import {StyleSheet, Text, View} from 'react-native';

function AttendanceScreen() {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>HRIS</Text>
      <Text style={styles.subtitle}>Offline Attendance — coming in Phase 2</Text>
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

export default AttendanceScreen;