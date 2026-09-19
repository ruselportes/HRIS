/**
 * The Arcenas logo, recreated from the client's artwork. Master copies live in
 * docs/assets/brand/ (arcenas-mark.svg, arcenas-logo.svg).
 *
 * Drawn with plain Views rather than an SVG: the mark is five rectangles, and
 * rendering SVG in React Native would need react-native-svg, a library outside
 * the fixed stack. The rectangles below are the SVG's paths on its 134x140 grid.
 *
 * @format
 */

import React from 'react';
import {StyleSheet, Text, View} from 'react-native';

const MARK_WIDTH = 134;
const MARK_HEIGHT = 140;

/** [left, top, width, height] on the 134x140 grid: each element is a stem plus a cap. */
const MARK_RECTS: ReadonlyArray<readonly [number, number, number, number]> = [
  [0, 64, 12, 76],
  [0, 64, 22, 12],
  [30, 20, 12, 120],
  [30, 20, 22, 12],
  [61, 0, 12, 140],
  [92, 20, 12, 120],
  [82, 20, 22, 12],
  [122, 64, 12, 76],
  [112, 64, 22, 12],
];

export const ARCENAS_SLATE = '#7A8A9A';

export function ArcenasMark({height, color = ARCENAS_SLATE}: {height: number; color?: string}) {
  const scale = height / MARK_HEIGHT;
  return (
    <View
      style={{width: MARK_WIDTH * scale, height}}
      accessible={false}
      importantForAccessibility="no-hide-descendants">
      {MARK_RECTS.map(([left, top, width, h], i) => (
        <View
          key={i}
          style={{
            position: 'absolute',
            left: left * scale,
            top: top * scale,
            width: width * scale,
            height: h * scale,
            backgroundColor: color,
          }}
        />
      ))}
    </View>
  );
}

/** The full lockup: mark, name, rule and tagline, as on the client's artwork. */
export function ArcenasLogo({height = 64}: {height?: number}) {
  const unit = height / MARK_HEIGHT;
  return (
    <View
      style={styles.row}
      accessible
      accessibilityRole="image"
      accessibilityLabel="Arcenas Properties, Designing Life's Luxury">
      <ArcenasMark height={height} />
      <View style={{marginLeft: 32 * unit}}>
        <Text style={[styles.name, {fontSize: 40 * unit}]}>
          ARCENAS PROPERTIES
        </Text>
        <View style={styles.taglineBlock}>
          <View style={[styles.rule, {height: Math.max(1, 3 * unit)}]} />
          <Text style={[styles.tagline, {fontSize: 20 * unit}]}>Designing Life’s Luxury</Text>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {flexDirection: 'row', alignItems: 'center', alignSelf: 'center'},
  name: {color: '#393D42', fontWeight: '400', includeFontPadding: false},
  taglineBlock: {alignSelf: 'center', marginTop: 4},
  rule: {backgroundColor: '#6D6F6E', alignSelf: 'stretch'},
  tagline: {color: '#525557', fontWeight: '600', marginTop: 2, includeFontPadding: false},
});
