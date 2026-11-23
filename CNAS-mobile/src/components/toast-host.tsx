import { useSegments } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAlerts } from '@/lib/alerts';
import { TOAST_GAP, useToasts, type Toast } from '@/lib/toasts';
import { colors, fonts, radius, space } from '@/theme';
import { Icon, type IconName } from './icon';
import { TAB_BAR_HEIGHT } from './tab-options';

const ENTER_MS = 250;
const EXIT_MS = 250;

/**
 * Where the toasts live (lib/toasts.tsx): over everything, at the bottom, above the tab bar when one shows (else above
 * the bottom safe area), stacked upwards with the web's gap. Never part of a screen's layout. Hidden while the
 * emergency takeover is open, which also closes them, like the web.
 */
export function ToastHost() {
  const { toasts, close, clear } = useToasts();
  const { takeover } = useAlerts();
  const insets = useSafeAreaInsets();
  const segments = useSegments() as string[];
  const reduceMotion = useReduceMotion();

  const takeoverOpen = takeover !== null;
  useEffect(() => {
    if (takeoverOpen) clear();
  }, [takeoverOpen, clear]);

  if (takeoverOpen || toasts.length === 0) return null;
  const aboveTabs = segments.includes('(tabs)');
  const bottom = (aboveTabs ? TAB_BAR_HEIGHT + insets.bottom : insets.bottom) + space.md;

  return (
    <View pointerEvents="box-none" style={[styles.host, { bottom }]}>
      {toasts.map((toast) => (
        <ToastCard key={toast.id} toast={toast} reduceMotion={reduceMotion} onClose={() => close(toast.id)} />
      ))}
    </View>
  );
}

function useReduceMotion() {
  const [reduce, setReduce] = useState(false);
  useEffect(() => {
    AccessibilityInfo.isReduceMotionEnabled().then(setReduce).catch(() => {});
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduce);
    return () => subscription.remove();
  }, []);
  return reduce;
}

type Look = { icon: IconName; iconBg: string; iconColor: string; edge: string | null; kindColor: string };

function lookOf(toast: Toast, icon: IconName | null): Look {
  switch (toast.tone) {
    case 'alertWarning':
      return { icon: icon ?? 'other', iconBg: colors.warning, iconColor: colors.white, edge: colors.warning, kindColor: '#C2410C' };
    case 'alertAdvisory':
      return { icon: icon ?? 'other', iconBg: colors.advisory, iconColor: colors.advisoryText, edge: '#EAB308', kindColor: '#854D0E' };
    case 'alertClear':
      return { icon: 'clear', iconBg: colors.safe, iconColor: colors.white, edge: colors.safe, kindColor: colors.safe };
    case 'warning':
      return { icon: 'other', iconBg: 'rgba(197,155,39,0.2)', iconColor: colors.primary, edge: null, kindColor: colors.primary };
    default:
      return { icon: 'check', iconBg: colors.safeTint, iconColor: colors.safe, edge: null, kindColor: colors.safe };
  }
}

function ToastCard({ toast, reduceMotion, onClose }: { toast: Toast; reduceMotion: boolean; onClose: () => void }) {
  const [shown] = useState(() => new Animated.Value(0));
  const [timer] = useState(() => new Animated.Value(1));
  const closing = useRef(false);

  const exit = () => {
    if (closing.current) return;
    closing.current = true;
    Animated.timing(shown, { toValue: 0, duration: EXIT_MS, easing: Easing.out(Easing.quad), useNativeDriver: true }).start(() => onClose());
  };

  useEffect(() => {
    Animated.timing(shown, { toValue: 1, duration: ENTER_MS, easing: Easing.out(Easing.quad), useNativeDriver: true }).start();
    // Read out once: the visible text only, never the button names (like the web's announcementText).
    AccessibilityInfo.announceForAccessibility([toast.kind, toast.title, toast.body].filter(Boolean).join('. '));
    if (toast.seconds === null) return;
    const run = Animated.timing(timer, { toValue: 0, duration: toast.seconds * 1000, easing: Easing.linear, useNativeDriver: false });
    run.start(({ finished }) => finished && exit());
    return () => run.stop();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const look = lookOf(toast, toast.icon ?? null);
  // Slide and fade; fade only with Reduce motion on.
  const translateY = reduceMotion ? 0 : shown.interpolate({ inputRange: [0, 1], outputRange: [16, 0] });

  return (
    <Animated.View style={[styles.toast, look.edge && { borderLeftWidth: 5, borderLeftColor: look.edge }, { opacity: shown, transform: [{ translateY }] }]}>
      <Pressable
        onPress={toast.onPress ? () => (toast.onPress?.(), exit()) : undefined}
        disabled={!toast.onPress}
        accessibilityRole={toast.onPress ? 'button' : undefined}
        accessibilityHint={toast.onPress ? 'Opens the alert' : undefined}
        style={styles.main}>
        <View style={[styles.icon, { backgroundColor: look.iconBg }]}>
          <Icon name={look.icon} size={20} color={look.iconColor} strokeWidth={2.2} />
        </View>
        <View style={styles.text}>
          {toast.kind ? <Text style={[styles.kind, { color: look.kindColor }]}>{toast.kind}</Text> : null}
          <Text style={styles.title} numberOfLines={3}>
            {toast.title}
          </Text>
          {toast.body ? (
            <Text style={styles.body} numberOfLines={3}>
              {toast.body}
            </Text>
          ) : null}
        </View>
      </Pressable>
      <Pressable onPress={exit} accessibilityRole="button" accessibilityLabel="Dismiss notification" hitSlop={4} style={({ pressed }) => [styles.close, pressed && styles.pressed]}>
        <Icon name="close" size={18} color={colors.muted} strokeWidth={2.2} />
      </Pressable>
      {toast.seconds !== null && (
        <Animated.View style={[styles.timer, { width: timer.interpolate({ inputRange: [0, 1], outputRange: ['0%', '100%'] }) }]} />
      )}
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  host: { position: 'absolute', left: space.md, right: space.md, flexDirection: 'column-reverse', gap: TOAST_GAP },
  toast: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    overflow: 'hidden',
    shadowColor: '#3D0F18',
    shadowOpacity: 0.25,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 },
    elevation: 8,
  },
  main: { flex: 1, flexDirection: 'row', gap: 12, paddingVertical: 14, paddingLeft: space.md, paddingBottom: 17 },
  icon: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  text: { flex: 1, gap: 2, paddingTop: 1 },
  kind: { fontFamily: fonts.bold, fontSize: 12, letterSpacing: 1.2 },
  title: { fontFamily: fonts.semibold, fontSize: 16, lineHeight: 21, color: colors.text },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 20, color: colors.muted },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', marginTop: 4, marginRight: 4, borderRadius: 10 },
  pressed: { backgroundColor: colors.bg },
  timer: { position: 'absolute', left: 0, bottom: 0, height: 3, backgroundColor: colors.secondary },
});
