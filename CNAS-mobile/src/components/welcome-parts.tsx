import { Image } from 'expo-image';
import { useEffect, type ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  Easing,
  ReduceMotion,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withRepeat,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';

import { colors, fonts, radius, space } from '@/theme';
import { Icon, type IconName } from './icon';
import { MINI_MAP_SIZE } from './mini-map';

// Pieces of the Welcome screen (app/index.tsx), which mirrors the web landing page (CNAS-web land-page.blade.php).

// A 1080 px copy of CNAS-web/public/images/background.png.
const PHOTO = require('../../assets/images/campus-hero.jpg');

/** The campus photo, slowly zooming in and out (Ken Burns). Still with reduce motion on. */
export function HeroPhoto() {
  const reduced = useReducedMotion();
  const drift = useSharedValue(0);

  useEffect(() => {
    if (reduced) return;
    drift.set(withRepeat(withTiming(1, { duration: 18000, easing: Easing.inOut(Easing.sin) }), -1, true));
  }, [reduced, drift]);

  const style = useAnimatedStyle(() => ({
    transform: [{ scale: 1 + drift.get() * 0.12 }, { translateX: drift.get() * -10 }, { translateY: drift.get() * -8 }],
  }));

  return (
    <View style={[StyleSheet.absoluteFill, styles.clip]} pointerEvents="none">
      <Animated.View style={[StyleSheet.absoluteFill, style]}>
        <Image source={PHOTO} style={StyleSheet.absoluteFill} contentFit="cover" />
      </Animated.View>
      {/* The web hero's overlay: from-primary-hover/95 via-primary/90 to-primary-hover/80, left to right. */}
      <Svg style={StyleSheet.absoluteFill} width="100%" height="100%" preserveAspectRatio="none">
        <Defs>
          <LinearGradient id="hero" x1="0" y1="0" x2="1" y2="0">
            <Stop offset="0" stopColor={colors.primaryHover} stopOpacity={0.95} />
            <Stop offset="0.5" stopColor={colors.primary} stopOpacity={0.9} />
            <Stop offset="1" stopColor={colors.primaryHover} stopOpacity={0.8} />
          </LinearGradient>
        </Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill="url(#hero)" />
      </Svg>
    </View>
  );
}

/** Fades and slides its content up once `play` turns true, `index` steps after the first. Only fades with reduce motion. */
export function Rise({ index, play, children, style }: { index: number; play: boolean; children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const reduced = useReducedMotion();
  const shown = useSharedValue(0);

  useEffect(() => {
    if (!play) return;
    // ReduceMotion.Never: with reduce motion on, Reanimated would skip the fade too; there it is only a fade.
    shown.set(withDelay(index * 70, withTiming(1, { duration: 460, easing: Easing.out(Easing.cubic), reduceMotion: ReduceMotion.Never })));
  }, [play, index, shown]);

  const animated = useAnimatedStyle(() => ({
    opacity: shown.get(),
    transform: [{ translateY: reduced ? 0 : (1 - shown.get()) * 18 }],
  }));

  return <Animated.View style={[style, animated]}>{children}</Animated.View>;
}

/** A soft gold ring pulsing out from the mini-map, so it reads as something to tap. None with reduce motion. */
export function PulseRing() {
  const reduced = useReducedMotion();
  const pulse = useSharedValue(0);

  useEffect(() => {
    if (reduced) return;
    pulse.set(withDelay(900, withRepeat(withTiming(1, { duration: 2400, easing: Easing.out(Easing.quad) }), -1, false)));
  }, [reduced, pulse]);

  const style = useAnimatedStyle(() => ({
    opacity: pulse.get() === 0 ? 0 : 0.75 * (1 - pulse.get()),
    transform: [{ scale: 1 + pulse.get() * 0.5 }],
  }));

  // Always rendered (it just stays at opacity 0 with reduce motion), so the web preview's server render matches.
  return <Animated.View pointerEvents="none" style={[styles.ring, style]} />;
}

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

type HeroButtonProps = {
  title: string;
  onPress: () => void;
  variant: 'gold' | 'white' | 'outline';
  icon?: IconName;
  style?: StyleProp<ViewStyle>;
};

/** The hero's buttons: gold (the main action), white, or outlined white. They shrink a little while pressed. */
export function HeroButton({ title, onPress, variant, icon, style }: HeroButtonProps) {
  const scale = useSharedValue(1);
  const animated = useAnimatedStyle(() => ({ transform: [{ scale: scale.get() }] }));
  const textColor = variant === 'gold' ? colors.primaryHover : variant === 'white' ? colors.primary : colors.white;

  return (
    <AnimatedPressable
      onPress={onPress}
      onPressIn={() => scale.set(withTiming(0.96, { duration: 90 }))}
      onPressOut={() => scale.set(withSpring(1, { damping: 14, stiffness: 320 }))}
      accessibilityRole="button"
      style={[styles.button, styles[variant], style, animated]}>
      {icon && <Icon name={icon} size={20} color={textColor} strokeWidth={2.2} />}
      <Text style={[styles.buttonText, { color: textColor }]}>{title}</Text>
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  clip: { overflow: 'hidden', backgroundColor: colors.primaryHover },
  ring: {
    position: 'absolute',
    top: 0,
    left: 0,
    width: MINI_MAP_SIZE,
    height: MINI_MAP_SIZE,
    borderRadius: MINI_MAP_SIZE / 2,
    borderWidth: 2,
    borderColor: colors.secondary,
  },
  button: {
    minHeight: 54,
    borderRadius: radius.md,
    paddingHorizontal: space.md,
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  gold: {
    backgroundColor: colors.secondary,
    shadowColor: '#000',
    shadowOpacity: 0.25,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 6,
  },
  white: { backgroundColor: colors.white },
  outline: { borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.7)' },
  buttonText: { fontFamily: fonts.bold, fontSize: 17, textAlign: 'center' },
});
