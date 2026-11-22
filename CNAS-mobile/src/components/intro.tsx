import { SplashScreen } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import Animated, {
  Easing,
  ReduceMotion,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withTiming,
} from 'react-native-reanimated';
import Svg, { Circle, Path } from 'react-native-svg';

import { colors, fonts } from '@/theme';
import { Logo, LOGO_PEAK, LOGO_VIEWBOX } from './logo';

// The intro after the native splash: a pin drops onto the logo's peak, rings spread from it, the name fades in, then
// the whole intro fades into Welcome or Home. Same background and logo size as the native splash (app.json), so the
// hand-over doesn't flash.
const LOGO_SIZE = 120;
const PEAK = {
  x: ((LOGO_PEAK.x - LOGO_VIEWBOX.x) / LOGO_VIEWBOX.size) * LOGO_SIZE,
  y: ((LOGO_PEAK.y - LOGO_VIEWBOX.y) / LOGO_VIEWBOX.size) * LOGO_SIZE,
};
const PIN = 34;
const PIN_TIP = (22 / 24) * PIN;
const RING = 56;

// Timings (ms). The full animation ends at SHOW_MS, then fades out over FADE_MS: about 1.8 s in all. With reduce motion
// on, only the fade.
const SHOW_MS = 1450;
const FADE_MS = 320;
const REDUCED_SHOW_MS = 450;
const REDUCED_FADE_MS = 250;
const RINGS = [520, 680, 840];

type Props = {
  /** Session and fonts are loaded: the screen behind can be shown. */
  ready: boolean;
  fontsReady: boolean;
  /** An emergency takeover is up: leave at once, it must never wait for the intro. */
  urgent: boolean;
  onDone: () => void;
};

export function Intro({ ready, fontsReady, urgent, onDone }: Props) {
  const reduced = useReducedMotion();
  const [shown, setShown] = useState(false);
  const [skipped, setSkipped] = useState(false);
  const leaving = urgent || (ready && (shown || skipped));

  const fade = useSharedValue(1);
  const pin = useSharedValue(reduced ? 1 : 0);
  const text = useSharedValue(reduced ? 1 : 0);

  useEffect(() => {
    if (!reduced) {
      pin.set(withDelay(80, withTiming(1, { duration: 480, easing: Easing.out(Easing.back(1.7)) })));
      text.set(withDelay(760, withTiming(1, { duration: 420, easing: Easing.out(Easing.cubic) })));
    }
    const timer = setTimeout(() => setShown(true), reduced ? REDUCED_SHOW_MS : SHOW_MS);
    return () => clearTimeout(timer);
  }, [reduced, pin, text]);

  useEffect(() => {
    if (!leaving) return;
    const duration = urgent ? 0 : reduced ? REDUCED_FADE_MS : FADE_MS;
    // ReduceMotion.Never: with reduce motion on, Reanimated would otherwise skip this simple fade too.
    fade.set(withTiming(0, { duration, easing: Easing.in(Easing.quad), reduceMotion: ReduceMotion.Never }));
    const timer = setTimeout(onDone, duration);
    return () => clearTimeout(timer);
  }, [leaving, urgent, reduced, fade, onDone]);

  const containerStyle = useAnimatedStyle(() => ({ opacity: fade.get() }));
  const pinStyle = useAnimatedStyle(() => ({
    opacity: Math.min(1, pin.get() * 3),
    transform: [{ translateY: (1 - pin.get()) * -150 }],
  }));
  const textStyle = useAnimatedStyle(() => ({ opacity: text.get(), transform: [{ translateY: (1 - text.get()) * 10 }] }));

  return (
    <Animated.View style={[StyleSheet.absoluteFill, styles.screen, containerStyle]} pointerEvents={leaving ? 'none' : 'auto'}>
      <Pressable
        style={styles.center}
        // The native splash stays until this first frame is laid out, so the two match with no flash.
        onLayout={() => SplashScreen.hide()}
        onPress={() => setSkipped(true)}
        accessibilityRole="button"
        accessibilityLabel="CNAS, Campus Navigation and Alert System. Tap to skip.">
        <View style={styles.logo}>
          <Logo size={LOGO_SIZE} />
          {!reduced && RINGS.map((delay) => <Ring key={delay} delay={delay} />)}
          <Animated.View style={[styles.pin, pinStyle]}>
            <Svg width={PIN} height={PIN} viewBox="0 0 24 24">
              <Path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7z" fill={colors.primary} stroke={colors.white} strokeWidth={1.4} />
              <Circle cx={12} cy={9} r={2.7} fill={colors.secondary} />
            </Svg>
          </Animated.View>
          {fontsReady && (
            <Animated.View style={[styles.text, textStyle]}>
              <Animated.Text style={styles.name}>CNAS</Animated.Text>
              <Animated.Text style={styles.full}>Campus Navigation & Alert System</Animated.Text>
            </Animated.View>
          )}
        </View>
      </Pressable>
    </Animated.View>
  );
}

/** One ripple ring spreading from the logo's peak. */
function Ring({ delay }: { delay: number }) {
  const progress = useSharedValue(0);
  useEffect(() => {
    progress.set(withDelay(delay, withTiming(1, { duration: 760, easing: Easing.out(Easing.quad) })));
  }, [delay, progress]);
  const style = useAnimatedStyle(() => ({
    opacity: progress.get() === 0 ? 0 : 0.55 * (1 - progress.get()),
    transform: [{ scale: 0.25 + progress.get() * 2.6 }],
  }));
  return <Animated.View style={[styles.ring, style]} />;
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.bg, zIndex: 10 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  logo: { width: LOGO_SIZE, height: LOGO_SIZE },
  pin: { position: 'absolute', left: PEAK.x - PIN / 2, top: PEAK.y - PIN_TIP + 1 },
  ring: {
    position: 'absolute',
    left: PEAK.x - RING / 2,
    top: PEAK.y - RING / 2,
    width: RING,
    height: RING,
    borderRadius: RING / 2,
    borderWidth: 2.5,
    borderColor: colors.secondary,
  },
  text: { position: 'absolute', top: LOGO_SIZE + 26, left: (LOGO_SIZE - 320) / 2, width: 320, alignItems: 'center', gap: 4 },
  name: { fontFamily: fonts.bold, fontSize: 40, lineHeight: 46, letterSpacing: 2, color: colors.primary },
  full: { fontFamily: fonts.medium, fontSize: 16, lineHeight: 22, color: colors.muted },
});
