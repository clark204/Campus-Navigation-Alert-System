import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError, errorMessage } from '@/lib/api';
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

/**
 * "I need help" (POST /api/alerts/{id}/help): opens a short note for the administrators (where you are, what
 * happened), who get a push. It replaces an earlier "I'm safe", and "I'm safe" replaces it. On a dark (red) background.
 */
export function NeedHelpButton({ alert, large }: { alert: Alert; large?: boolean }) {
  const { answerOf, askForHelp } = useAlerts();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [thanks, setThanks] = useState<string | null>(null);
  const requested = answerOf(alert) === 'need_help';

  const send = async () => {
    setSending(true);
    setError(null);
    try {
      setThanks(await askForHelp(alert, note));
      setOpen(false);
    } catch (failure) {
      setError(failure instanceof ApiError ? (failure.field('note') ?? failure.message) : errorMessage(failure));
    } finally {
      setSending(false);
    }
  };

  return (
    <View style={styles.safeWrap}>
      <Pressable
        onPress={() => setOpen(!open)}
        accessibilityRole="button"
        accessibilityState={{ expanded: open }}
        style={({ pressed }) => [styles.help, large && styles.safeLarge, pressed && styles.pressed]}>
        <Icon name="phone" size={large ? 24 : 20} color={colors.alarm} />
        <Text style={[styles.helpText, large && styles.safeTextLarge]}>{requested ? 'Help requested' : 'I need help'}</Text>
      </Pressable>
      {open && (
        <View style={styles.helpBox}>
          <Text style={styles.onDark}>Where are you? What happened? (optional)</Text>
          <TextInput
            value={note}
            onChangeText={setNote}
            placeholder="2nd floor, NEE 201. I hurt my ankle."
            placeholderTextColor="#9AA0A6"
            multiline
            maxLength={200}
            accessibilityLabel="Where are you? What happened?"
            style={styles.helpInput}
          />
          <Pressable onPress={send} disabled={sending} accessibilityRole="button" style={({ pressed }) => [styles.helpSend, pressed && styles.pressed]}>
            {sending ? <ActivityIndicator color={colors.alarm} /> : <Text style={styles.helpSendText}>Send to the administrators</Text>}
          </Pressable>
        </View>
      )}
      {(thanks || (requested && !open)) && !error && <Text style={styles.onDark}>{thanks ?? HELP_THANKS}</Text>}
      {error && <Text style={[styles.onDark, styles.errorOnDark]}>{error}</Text>}
    </View>
  );
}

const HELP_THANKS = 'Help is on the way. The administrators can see your message.';

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
  help: {
    minHeight: 52,
    borderRadius: radius.md,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
    paddingHorizontal: space.md,
  },
  helpText: { fontFamily: fonts.bold, fontSize: 17, color: colors.alarm },
  helpBox: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: 'rgba(0,0,0,0.2)' },
  helpInput: { minHeight: 72, borderRadius: radius.sm, backgroundColor: colors.white, paddingHorizontal: space.md, paddingVertical: 10, fontFamily: fonts.regular, fontSize: 16, color: colors.text, textAlignVertical: 'top' },
  helpSend: { minHeight: 48, borderRadius: radius.sm, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  helpSendText: { fontFamily: fonts.bold, fontSize: 16, color: colors.alarm },
  errorOnDark: { backgroundColor: 'rgba(0,0,0,0.25)', borderRadius: 8, padding: space.sm },
});
