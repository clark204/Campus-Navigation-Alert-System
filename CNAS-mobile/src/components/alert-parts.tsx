import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, TextInput, View } from 'react-native';

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
 * "I'm safe" (POST /api/alerts/{id}/safe), the white button. After it works: "Marked safe" (green) and the thank-you
 * line. On a dark (red) background.
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
        {sending ? (
          <ActivityIndicator color={marked ? colors.white : colors.alarm} />
        ) : (
          <Icon name="check" size={large ? 26 : 22} color={marked ? colors.white : colors.alarm} strokeWidth={2.6} />
        )}
        <Text style={[styles.safeText, large && styles.safeTextLarge, marked && styles.onDarkText]} maxFontSizeMultiplier={1.6}>
          {marked ? 'Marked safe' : 'I’m safe'}
        </Text>
      </Pressable>
      {(justMarked || marked) && !error && (
        <Text style={styles.onDark} maxFontSizeMultiplier={1.6}>
          {SAFE_THANKS}
        </Text>
      )}
      {error && <Text style={[styles.onDark, styles.errorOnDark]}>{error}</Text>}
    </View>
  );
}

/**
 * "I need help" (POST /api/alerts/{id}/help), the outlined button: opens a short note for the administrators (where you are, what
 * happened), who get a push, and "Share my location" (on by default): the app then keeps sending where you are every 15 s
 * until an admin marks you helped (lib/help-tracker.ts). Below it, where the request is ("Rita Reyes is on the way.").
 * It replaces an earlier "I'm safe", and "I'm safe" replaces it. On a dark (red) background.
 */
export function NeedHelpButton({ alert, large }: { alert: Alert; large?: boolean }) {
  const { answerOf, askForHelp, helpOf, locationDenied } = useAlerts();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [share, setShare] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [thanks, setThanks] = useState<string | null>(null);
  const requested = answerOf(alert) === 'need_help';
  const help = helpOf(alert);

  const send = async () => {
    setSending(true);
    setError(null);
    try {
      setThanks(await askForHelp(alert, note, share));
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
        <Icon name="phone" size={large ? 24 : 20} color={colors.white} />
        <Text style={[styles.helpText, large && styles.safeTextLarge]} maxFontSizeMultiplier={1.6}>
          {requested ? 'Help requested' : 'I need help'}
        </Text>
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
          <View style={styles.shareRow}>
            <View style={styles.shareText}>
              <Text style={styles.onDarkStrong}>Share my location with the responders</Text>
              <Text style={styles.onDarkSmall}>Only while the app is open, until you are helped.</Text>
            </View>
            <Switch
              value={share}
              onValueChange={setShare}
              accessibilityLabel="Share my location with the responders"
              trackColor={{ false: 'rgba(255,255,255,0.35)', true: colors.white }}
              thumbColor={share ? colors.alarm : '#f4f4f5'}
            />
          </View>
          <Pressable onPress={send} disabled={sending} accessibilityRole="button" style={({ pressed }) => [styles.helpSend, pressed && styles.pressed]}>
            {sending ? <ActivityIndicator color={colors.alarm} /> : <Text style={styles.helpSendText}>Send to the administrators</Text>}
          </Pressable>
        </View>
      )}
      {(help || thanks || (requested && !open)) && !error && (
        <View style={styles.helpState} accessibilityLiveRegion="polite">
          <Text style={styles.onDarkStrong} maxFontSizeMultiplier={1.6}>
            {help?.label ?? thanks ?? HELP_THANKS}
          </Text>
          {requested && help?.status !== 'helped' && (
            <Text style={styles.onDarkSmall} maxFontSizeMultiplier={1.6}>
              {locationDenied ? 'Location is off. Add where you are in the note.' : 'Keep the app open so they can see where you are.'}
            </Text>
          )}
        </View>
      )}
      {error && <Text style={[styles.onDark, styles.errorOnDark]}>{error}</Text>}
    </View>
  );
}

/** NeedHelpController::THANKS: not "on the way" until an admin responds. */
const HELP_THANKS = 'Sent. The administrators can see your request.';

const styles = StyleSheet.create({
  tape: { flexDirection: 'row', height: 8, overflow: 'hidden' },
  stripe: { width: 16, height: 16, transform: [{ skewX: '-35deg' }], marginTop: -4 },
  stamp: { backgroundColor: colors.hazard, borderRadius: 4, paddingHorizontal: 6, paddingVertical: 1, alignSelf: 'flex-start' },
  stampLarge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 6 },
  stampText: { fontFamily: fonts.bold, fontSize: 13, letterSpacing: 2.4, color: '#1C1917' },
  stampTextLarge: { fontSize: 18, letterSpacing: 4 },
  safeWrap: { gap: space.sm },
  safe: {
    minHeight: 56,
    borderRadius: radius.md,
    backgroundColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
    paddingHorizontal: space.md,
  },
  safeLarge: { minHeight: 60, borderRadius: radius.lg },
  safeDone: { backgroundColor: colors.safe, borderColor: colors.safe },
  onDarkText: { color: colors.white },
  pressed: { opacity: 0.75 },
  safeText: { fontFamily: fonts.bold, fontSize: 17, color: colors.alarm },
  safeTextLarge: { fontSize: 21 },
  onDark: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.white },
  help: {
    minHeight: 56,
    borderRadius: radius.md,
    borderWidth: 2,
    borderColor: colors.white,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
    paddingHorizontal: space.md,
  },
  helpText: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
  helpBox: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: 'rgba(0,0,0,0.2)' },
  helpInput: { minHeight: 72, borderRadius: radius.sm, backgroundColor: colors.white, paddingHorizontal: space.md, paddingVertical: 10, fontFamily: fonts.regular, fontSize: 16, color: colors.text, textAlignVertical: 'top' },
  helpSend: { minHeight: 48, borderRadius: radius.sm, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  helpSendText: { fontFamily: fonts.bold, fontSize: 16, color: colors.alarm },
  shareRow: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  shareText: { flex: 1, gap: 2 },
  onDarkStrong: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 21, color: colors.white },
  onDarkSmall: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: 'rgba(255,255,255,0.85)' },
  helpState: { gap: 2, padding: space.sm, borderRadius: radius.sm, backgroundColor: 'rgba(0,0,0,0.2)' },
  errorOnDark: { backgroundColor: 'rgba(0,0,0,0.25)', borderRadius: 8, padding: space.sm },
});
