/**
 * Shown for prototype bottom-nav tabs not yet built in this phase
 * (Timesheet/Sync — Phase 8/6 respectively). Mirrors web's ComingSoonPage
 * pattern: the nav slot exists now, the screen catches up later.
 *
 * @format
 */

import React from 'react';
import {StyleSheet, Text, View} from 'react-native';

export function ComingSoonScreen({title, phase}: {title: string; phase: string}) {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.subtitle}>Not built yet — {phase}.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#f2f2f3'},
  title: {fontSize: 24, fontWeight: '700', color: '#1d1f20'},
  subtitle: {fontSize: 14, color: '#5d5d60', marginTop: 8},
});
