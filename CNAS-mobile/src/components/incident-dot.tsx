import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useReducedMotion, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { colors } from '@/theme';

/** A pulsing red dot where an emergency is (mini-map and map screen). Centered on left/top. Still with reduce motion. */
export function IncidentDot({ left, top, size = 10 }: { left: number; top: number; size?: number }) {
  const reduced = useReducedMotion();
  const pulse = useSharedValue(0);

  useEffect(() => {
    if (!reduced) pulse.set(withRepeat(withTiming(1, { duration: 1300, easing: Easing.out(Easing.quad) }), -1, false));
  }, [reduced, pulse]);

  const ring = useAnimatedStyle(() => ({ opacity: 0.6 * (1 - pulse.get()), transform: [{ scale: 1 + pulse.get() * 2.2 }] }));
  const half = size / 2;

  return (
    <View pointerEvents="none" style={[styles.wrap, { left: left - half, top: top - half, width: size, height: size }]}>
      {!reduced && <Animated.View style={[styles.ring, { borderRadius: half }, ring]} />}
      <View style={[styles.dot, { borderRadius: half }]} />
    </View>
  );
}

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;

const styles = StyleSheet.create({
  wrap: { position: 'absolute' },
  ring: { ...FILL, backgroundColor: colors.alarm },
  dot: { ...FILL, backgroundColor: colors.alarm, borderWidth: 1.5, borderColor: colors.white },
});
