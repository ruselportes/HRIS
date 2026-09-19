/**
 * The mobile mark is hand-drawn from Views, so nothing ties it to the master
 * SVG except these numbers. Hold it to the SVG's geometry.
 *
 * @format
 */

import React from 'react';
import ReactTestRenderer from 'react-test-renderer';
import {Text, View} from 'react-native';
import {ArcenasLogo, ArcenasMark} from '../ArcenasLogo';

function render(element: React.ReactElement) {
  let renderer!: ReactTestRenderer.ReactTestRenderer;
  ReactTestRenderer.act(() => {
    renderer = ReactTestRenderer.create(element);
  });
  return renderer;
}

test('the mark keeps the 134x140 proportions at any height', () => {
  const root = render(<ArcenasMark height={70} />).root;
  const [frame, ...rects] = root.findAllByType(View);

  expect(frame.props.style).toMatchObject({width: 67, height: 70});
  // Five elements: the centre bar, plus a stem and a cap for each of the other four.
  expect(rects).toHaveLength(9);
});

test('the tallest element is the full-height centre bar', () => {
  const rects = render(<ArcenasMark height={140} />)
    .root.findAllByType(View)
    .slice(1)
    .map(v => v.props.style);

  expect(rects).toContainEqual(
    expect.objectContaining({left: 61, top: 0, width: 12, height: 140}),
  );
  // Nothing may extend past the 134x140 grid.
  for (const r of rects) {
    expect(r.left + r.width).toBeLessThanOrEqual(134);
    expect(r.top + r.height).toBeLessThanOrEqual(140);
  }
});

test('the lockup carries the name and tagline from the artwork', () => {
  const texts = render(<ArcenasLogo />)
    .root.findAllByType(Text)
    .map(t => t.props.children);

  expect(texts).toEqual(['ARCENAS PROPERTIES', 'Designing Life’s Luxury']);
});
