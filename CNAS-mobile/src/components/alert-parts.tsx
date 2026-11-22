import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { errorMessage } from '@/lib/api';
import { SAFE_THANKS, useAlerts } from '@/lib/alerts';
import type { Alert } from '@/lib/types';
import { colors, fonts, radius, space } from '@/theme';
import { Icon } from './icon';

/** Yellow and black stripes: shown on drills, like the web. */
export function HazardTape() {
  return (
    <View style={styles.tape} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {Array.from({ length: 40 }, (_, i) => (
        <View key={i} style={[styles.stripe, { backgroundColor: i % 2 ? '#1C1917' : colors.hazard }]} />
      ))}
    </View>
  );
}

export function DrillStamp({ large }: { large?: boolean }) {
  return (
    <View style={[styles.stamp, large && styles.stampLarge]}>
      <Text style={[styles.stampText, large && styles.stampTextLarge]}>DRILL</Text>
    </View>
  );
}

/**
 * "I'm safe" (POST /api/alerts/{id}/safe). After it works: "Marked safe" and the thank-you line. On a dark (red)
 * background.
 */
export function SafeButton({ alert, large }: { alert: Alert; large?: boolean }) {
  const { isMarkedSafe, markSafe } = useAlerts();
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [justMarked, setJustMarked] = useState(false);
  const marked = isMarkedSafe(alert);

  const send = async () => {
    setSending(true);
    setError(null);
    try {
      await markSafe(alert);
      setJustMarked(true);
    } catch (failure) {
      setError(errorMessage(failure));
    } finally {
      setSending(false);
    }
  };

  return (
    <View style={styles.safeWrap}>
      <Pressable
        onPress={send}
        disabled={marked || sending}
        accessibilityRole="button"
        accessibilityState={{ disabled: marked || sending, busy: sending }}
        style={({ pressed }) => [styles.safe, large && styles.safeLarge, marked && styles.safeDone, pressed && styles.pressed]}>
        {sending ? <ActivityIndicator color={colors.white} /> : <Icon name="check" size={large ? 26 : 22} color={colors.white} strokeWidth={2.6} />}
        <Text style={[styles.safeText, large && styles.safeTextLarge]}>{marked ? 'Marked safe' : 'I’m safe'}</Text>
      </Pressable>
      {(justMarked || marked) && !error && <Text style={styles.onDark}>{SAFE_THANKS}</Text>}
      {error && <Text style={[styles.onDark, styles.errorOnDark]}>{error}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  tape: { flexDirection: 'row', height: 8, overflow: 'hidden' },
  stripe: { width: 16, height: 16, transform: [{ skewX: '-35deg' }], marginTop: -4 },
  stamp: { backgroundColor: colors.hazard, borderRadius: 4, paddingHorizontal: 6, paddingVertical: 1, alignSelf: 'flex-start' },
  stampLarge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 6 },
  stampText: { fontFamily: fonts.bold, fontSize: 13, letterSpacing: 2.4, color: '#1C1917' },
  stampTextLarge: { fontSize: 18, letterSpacing: 4 },
  safeWrap: { gap: space.sm },
  safe: {
    minHeight: 52,
    borderRadius: radius.md,
    borderWidth: 1.5,
    borderColor: 'rgba(255,255,255,0.85)',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
    paddingHorizontal: space.md,
  },
  safeLarge: { minHeight: 64, borderWidth: 2, borderRadius: radius.lg },
  safeDone: { backgroundColor: colors.safe, borderColor: colors.safe },
  pressed: { opacity: 0.75 },
  safeText: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
  safeTextLarge: { fontSize: 21 },
  onDark: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.white },
  errorOnDark: { backgroundColor: 'rgba(0,0,0,0.25)', borderRadius: 8, padding: space.sm },
});
