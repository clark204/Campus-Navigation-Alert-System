import { router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import { alertKind } from '@/lib/alert-rules';
import { useAlerts } from '@/lib/alerts';
import { useSession } from '@/lib/session';
import type { Alert } from '@/lib/types';
import { colors, fonts, radius, space } from '@/theme';
import { DrillStamp, HazardTape, NeedHelpButton, SafeButton } from './alert-parts';
import { alertIcon, Icon } from './icon';

// Big text is welcome, but the answers must stay on screen: past this the text stops growing.
const MAX_SCALE = 1.6;

/**
 * The emergency takeover (full screen, alarm and vibration until the first tap). Shown once per alert version on this
 * phone; after Dismiss the red pill (EmergencyPill) stays at the top until the alert ends. Warnings, advisories and
 * the end of an alert are toasts (components/alert-toasts.tsx). Mounted once in the root layout.
 */
export function AlertOverlays() {
  return <Takeover />;
}

/** "Sent at 2:14 PM". */
function sentTime(alert: Alert) {
  return `Sent at ${new Date(alert.sent_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`;
}

/**
 * Read in three seconds: the kind, the title (two lines at most), where, and one bold "What to do now" line. The rest
 * (the full message, longer instructions, the time) is under "More details", which scrolls by itself. "I'm safe"
 * (white) and "I need help" (outlined) are pinned to the bottom, always visible without scrolling, down to a 360 px
 * phone with large text; then Dismiss. Guests get a link to sign in instead of the answers.
 */
function Takeover() {
  const { takeover: alert, sounding, silence, dismissTakeover } = useAlerts();
  const { user } = useSession();
  const insets = useSafeAreaInsets();
  // "More details" is open for this alert version only: a new alert (or version) starts with it closed.
  const [detailsFor, setDetailsFor] = useState<string | null>(null);
  const details = alert !== null && detailsFor === alert.version;

  return (
    <Modal visible={alert !== null} animationType="fade" statusBarTranslucent onRequestClose={dismissTakeover}>
      {alert && (
        // Any touch silences the alarm, like the web ("Tap anywhere to silence the alarm").
        <KeyboardAvoidingView style={styles.takeover} behavior={Platform.OS === 'ios' ? 'padding' : undefined} onTouchStart={silence} accessibilityViewIsModal>
          {alert.is_drill && <HazardTape />}
          <SafeAreaView style={styles.flex} edges={['top', 'left', 'right']}>
            <ScrollView style={styles.flex} contentContainerStyle={styles.body}>
              <View style={styles.kindRow}>
                <View style={styles.bigIcon}>
                  <Icon name={alertIcon(alert)} size={26} color={colors.alarm} strokeWidth={2} />
                </View>
                <Text style={styles.kind} maxFontSizeMultiplier={MAX_SCALE}>
                  {alertKind(alert)}
                </Text>
                {alert.is_drill && <DrillStamp large />}
              </View>

              <Text style={styles.headline} numberOfLines={2} accessibilityRole="header" maxFontSizeMultiplier={MAX_SCALE}>
                {alert.title}
              </Text>

              {alert.location ? (
                <View style={styles.locationRow}>
                  <Icon name="pin" size={20} color={colors.white} />
                  <Text style={styles.location} numberOfLines={1} maxFontSizeMultiplier={MAX_SCALE}>
                    {alert.location}
                  </Text>
                </View>
              ) : null}

              {alert.what_to_do ? (
                <Text style={styles.action} maxFontSizeMultiplier={MAX_SCALE}>
                  {alert.what_to_do}
                </Text>
              ) : null}

              <View style={styles.details}>
                <Pressable
                  onPress={() => setDetailsFor(details ? null : alert.version)}
                  accessibilityRole="button"
                  accessibilityState={{ expanded: details }}
                  style={({ pressed }) => [styles.detailsToggle, pressed && styles.pressed]}>
                  <Text style={styles.detailsLabel} maxFontSizeMultiplier={MAX_SCALE}>
                    More details
                  </Text>
                  <View style={details && styles.flip}>
                    <Icon name="chevron" size={20} color={colors.white} strokeWidth={2.4} />
                  </View>
                </Pressable>
                {details && (
                  <View style={styles.detailsBody}>
                    <Text style={styles.message}>{alert.message}</Text>
                    {alert.instructions ? <Text style={styles.instructions}>{alert.instructions}</Text> : null}
                    <Text style={styles.sent}>{sentTime(alert)}</Text>
                  </View>
                )}
              </View>

              {sounding && (
                <View style={styles.locationRow}>
                  <Icon name="mute" size={20} color="rgba(255,255,255,0.85)" />
                  <Text style={styles.hint} maxFontSizeMultiplier={MAX_SCALE}>
                    Tap anywhere to silence the alarm
                  </Text>
                </View>
              )}
            </ScrollView>
          </SafeAreaView>

          {/* Pinned: never scrolls away. */}
          <View style={[styles.actions, { paddingBottom: insets.bottom + space.md }]}>
            {user ? (
              <>
                <SafeButton alert={alert} large />
                <NeedHelpButton alert={alert} large />
              </>
            ) : (
              <Pressable
                onPress={() => {
                  dismissTakeover();
                  router.push('/sign-in');
                }}
                accessibilityRole="link"
                style={styles.signIn}>
                <Text style={styles.signInText} maxFontSizeMultiplier={MAX_SCALE}>
                  Sign in to tell the school you’re safe
                </Text>
              </Pressable>
            )}
            <Pressable onPress={dismissTakeover} accessibilityRole="button" style={({ pressed }) => [styles.dismiss, pressed && styles.pressed]}>
              <Text style={styles.dismissText} maxFontSizeMultiplier={MAX_SCALE}>
                Dismiss
              </Text>
            </Pressable>
          </View>
        </KeyboardAvoidingView>
      )}
    </Modal>
  );
}

/** Height the pill takes under the status bar, for screens that keep their own content clear of it (navigation). */
export const PILL_SPACE = 48;

/** PILL_SPACE while the red pill shows, else 0. */
export function usePillSpace() {
  const { pill } = useAlerts();
  return pill ? PILL_SPACE : 0;
}

/**
 * After Dismiss: a small red pill under the status bar ("EMERGENCY · FIRE ›") floating over the screen (no layout
 * shift) until the alert ends; the green "All clear" toast takes its place. Tapping it opens the takeover again,
 * without the alarm.
 */
export function EmergencyPill() {
  const { pill, reopenTakeover } = useAlerts();
  const insets = useSafeAreaInsets();
  if (!pill) return null;

  return (
    <View pointerEvents="box-none" style={[styles.pillHost, { top: insets.top + 6 }]}>
      <Pressable
        onPress={reopenTakeover}
        accessibilityRole="button"
        accessibilityLabel={`${alertKind(pill)}: ${pill.title}. Open the alert`}
        style={({ pressed }) => [styles.pill, pressed && styles.pressed]}>
        {pill.is_drill && <View style={styles.pillDrill} />}
        <Icon name={alertIcon(pill)} size={16} color={colors.white} strokeWidth={2.2} />
        <Text style={styles.pillText} numberOfLines={1} maxFontSizeMultiplier={1.3}>
          {alertKind(pill)}
        </Text>
        <Icon name="chevron" size={16} color={colors.white} strokeWidth={2.6} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.75 },
  takeover: { flex: 1, backgroundColor: colors.alarm },
  body: { paddingHorizontal: space.lg, paddingTop: space.lg, paddingBottom: space.md, gap: 14 },
  kindRow: { flexDirection: 'row', alignItems: 'center', gap: 12, flexWrap: 'wrap' },
  bigIcon: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  kind: { flexShrink: 1, fontFamily: fonts.bold, fontSize: 15, letterSpacing: 1.6, color: colors.white },
  headline: { fontFamily: fonts.bold, fontSize: 32, lineHeight: 37, color: colors.white },
  locationRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  location: { flex: 1, fontFamily: fonts.semibold, fontSize: 18, color: colors.white },
  action: { fontFamily: fonts.bold, fontSize: 21, lineHeight: 28, color: colors.white, backgroundColor: 'rgba(0,0,0,0.2)', borderRadius: radius.lg, paddingHorizontal: space.md, paddingVertical: 12 },
  details: { backgroundColor: 'rgba(0,0,0,0.12)', borderRadius: radius.lg },
  detailsToggle: { minHeight: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: space.md },
  detailsLabel: { fontFamily: fonts.semibold, fontSize: 17, color: colors.white },
  flip: { transform: [{ rotate: '90deg' }] },
  detailsBody: { gap: space.sm, paddingHorizontal: space.md, paddingBottom: space.md },
  message: { fontFamily: fonts.regular, fontSize: 17, lineHeight: 25, color: 'rgba(255,255,255,0.92)' },
  instructions: { fontFamily: fonts.medium, fontSize: 17, lineHeight: 25, color: colors.white },
  sent: { fontFamily: fonts.regular, fontSize: 14, color: 'rgba(255,255,255,0.75)' },
  hint: { flex: 1, fontFamily: fonts.medium, fontSize: 15, color: 'rgba(255,255,255,0.85)' },
  actions: { gap: 10, paddingHorizontal: space.lg, paddingTop: 12, borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.15)', backgroundColor: 'rgba(122,10,16,0.4)' },
  signIn: { minHeight: 48, alignItems: 'center', justifyContent: 'center' },
  signInText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.white, textDecorationLine: 'underline' },
  dismiss: { minHeight: 48, alignItems: 'center', justifyContent: 'center' },
  dismissText: { fontFamily: fonts.semibold, fontSize: 17, color: 'rgba(255,255,255,0.9)', textDecorationLine: 'underline' },
  pillHost: { position: 'absolute', left: 0, right: 0, alignItems: 'center' },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    maxWidth: '92%',
    minHeight: 36,
    paddingHorizontal: 14,
    borderRadius: 999,
    backgroundColor: colors.alarm,
    overflow: 'hidden',
    shadowColor: '#000',
    shadowOpacity: 0.3,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
    elevation: 10,
  },
  pillDrill: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 6, backgroundColor: colors.hazard },
  pillText: { flexShrink: 1, fontFamily: fonts.bold, fontSize: 13, letterSpacing: 1.2, color: colors.white },
});
