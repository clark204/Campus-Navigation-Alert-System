import { StyleSheet, Text, View } from 'react-native';

import type { AlertFormData } from '@/lib/admin';
import type { Alert, AlertSeverity } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from '../icon';
import { Field } from '../ui';

export type Wording = { title: string; message: string; what_to_do: string; instructions: string };

const multiline = { minHeight: 88, textAlignVertical: 'top', paddingTop: 14, paddingBottom: 14, lineHeight: 24 } as const;

/**
 * The alert wording, like the web's admin/alert-wording-fields: the title (live counter, 60 at most), "What to do now"
 * (one sentence, 100 at most, required for an emergency: the bold line on the takeover, toasts and push), then what
 * people find under "More details". The server checks the same limits (UpdateAlertRequest::wordingRules).
 */
export function WordingFields({
  value,
  onChange,
  severity,
  limits,
  errors = {},
}: {
  value: Wording;
  onChange: (patch: Partial<Wording>) => void;
  severity: AlertSeverity;
  limits: AlertFormData['limits'];
  errors?: Record<string, string | undefined>;
}) {
  const emergency = severity === 'emergency';
  return (
    <>
      <Field label="Title" value={value.title} onChangeText={(title) => onChange({ title })} maxLength={limits.title} hint={`${value.title.length}/${limits.title}`} error={errors.title} />
      <Field
        label={emergency ? 'What to do now' : 'What to do now (optional)'}
        value={value.what_to_do}
        onChangeText={(what_to_do) => onChange({ what_to_do })}
        maxLength={limits.what_to_do}
        placeholder="Leave the building now. Go to the open field."
        hint={`One short sentence, in bold on everyone’s screen. ${value.what_to_do.length}/${limits.what_to_do}`}
        error={errors.what_to_do}
      />
      <View style={styles.more}>
        <Text style={styles.moreLabel}>Under “More details”</Text>
        <Field label="What is happening" value={value.message} onChangeText={(message) => onChange({ message })} multiline maxLength={1000} style={multiline} error={errors.message} />
        <Field
          label="More instructions (optional)"
          value={value.instructions}
          onChangeText={(instructions) => onChange({ instructions })}
          multiline
          maxLength={2000}
          style={multiline}
          error={errors.instructions}
        />
      </View>
    </>
  );
}

/** "What to do now" is required for an emergency (like the server); the title and message always. */
export const wordingReady = (wording: Wording, severity: AlertSeverity) =>
  wording.title.trim() !== '' && wording.message.trim() !== '' && (severity !== 'emergency' || wording.what_to_do.trim() !== '');

/**
 * A small phone with what people will see: the takeover for an emergency (kind, two-line title, where, the bold line,
 * "More details", the answers pinned at the bottom), the toast for a warning or advisory. Like the web's
 * admin/alert-phone-preview.
 */
export function PhonePreview({
  wording,
  severity,
  severityLabel,
  typeLabel,
  icon,
  isDrill,
  location,
}: {
  wording: Wording;
  severity: AlertSeverity;
  severityLabel: string;
  typeLabel: string;
  icon: Alert['type'];
  isDrill: boolean;
  location: string;
}) {
  const kind = `${isDrill ? 'DRILL' : severityLabel.toUpperCase()} · ${typeLabel.toUpperCase()}`;
  const title = wording.title.trim() || 'No title yet';

  return (
    <View style={styles.previewWrap} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <View style={styles.phone}>
        {severity === 'emergency' ? (
          <View style={styles.takeover}>
            {isDrill && <View style={styles.tape} />}
            <View style={styles.top}>
              <View style={styles.kindRow}>
                <View style={styles.iconDot}>
                  <Icon name={icon} size={13} color={colors.alarm} strokeWidth={2.2} />
                </View>
                <Text style={styles.kind} numberOfLines={1} maxFontSizeMultiplier={1}>
                  {kind}
                </Text>
              </View>
              <Text style={styles.title} numberOfLines={2} maxFontSizeMultiplier={1}>
                {title}
              </Text>
              <Text style={styles.where} numberOfLines={1} maxFontSizeMultiplier={1}>
                {location}
              </Text>
              <Text style={styles.action} numberOfLines={3} maxFontSizeMultiplier={1}>
                {wording.what_to_do.trim() || 'Say what to do now'}
              </Text>
              <Text style={styles.moreLink} maxFontSizeMultiplier={1}>
                More details
              </Text>
            </View>
            <View style={styles.buttons}>
              <Text style={styles.safe} maxFontSizeMultiplier={1}>
                I’m safe
              </Text>
              <Text style={styles.help} maxFontSizeMultiplier={1}>
                I need help
              </Text>
            </View>
          </View>
        ) : (
          <View style={styles.page}>
            <View style={styles.ghost} />
            <View style={[styles.ghost, styles.ghostTall]} />
            <View style={[styles.toast, { borderLeftColor: severity === 'warning' ? colors.warning : '#EAB308' }]}>
              <Text style={[styles.toastKind, { color: severity === 'warning' ? '#C2410C' : '#854D0E' }]} numberOfLines={1} maxFontSizeMultiplier={1}>
                {kind}
              </Text>
              <Text style={styles.toastTitle} numberOfLines={2} maxFontSizeMultiplier={1}>
                {title}
              </Text>
              {wording.what_to_do.trim() ? (
                <Text style={styles.toastBody} numberOfLines={2} maxFontSizeMultiplier={1}>
                  {wording.what_to_do}
                </Text>
              ) : null}
            </View>
          </View>
        )}
      </View>
      <Text style={type.small}>What a phone shows</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  more: { gap: space.md, borderWidth: 1, borderStyle: 'dashed', borderColor: colors.border, borderRadius: radius.md, padding: space.md },
  moreLabel: { fontFamily: fonts.semibold, fontSize: 13, color: colors.muted },
  previewWrap: { alignItems: 'center', gap: 6 },
  phone: { width: 200, height: 384, borderRadius: 28, borderWidth: 6, borderColor: '#1C1917', overflow: 'hidden', backgroundColor: colors.bg },
  takeover: { flex: 1, backgroundColor: colors.alarm },
  tape: { height: 6, backgroundColor: colors.hazard },
  top: { flex: 1, gap: 6, paddingHorizontal: 12, paddingTop: 20 },
  kindRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  iconDot: { width: 22, height: 22, borderRadius: 11, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  kind: { flex: 1, fontFamily: fonts.bold, fontSize: 10, letterSpacing: 0.8, color: colors.white },
  title: { fontFamily: fonts.bold, fontSize: 19, lineHeight: 22, color: colors.white },
  where: { fontFamily: fonts.medium, fontSize: 11, color: 'rgba(255,255,255,0.9)' },
  action: { fontFamily: fonts.bold, fontSize: 12, lineHeight: 16, color: colors.white, backgroundColor: 'rgba(0,0,0,0.2)', borderRadius: 8, padding: 6 },
  moreLink: { fontFamily: fonts.semibold, fontSize: 11, color: 'rgba(255,255,255,0.85)', textDecorationLine: 'underline' },
  buttons: { gap: 6, paddingHorizontal: 12, paddingTop: 8, paddingBottom: 14 },
  safe: { height: 30, lineHeight: 30, borderRadius: 8, overflow: 'hidden', backgroundColor: colors.white, textAlign: 'center', fontFamily: fonts.bold, fontSize: 12, color: colors.alarm },
  help: { height: 30, lineHeight: 27, borderRadius: 8, borderWidth: 2, borderColor: colors.white, textAlign: 'center', fontFamily: fonts.bold, fontSize: 12, color: colors.white },
  page: { flex: 1, justifyContent: 'flex-end', gap: 8, padding: 10 },
  ghost: { height: 50, borderRadius: 8, backgroundColor: colors.border, opacity: 0.6 },
  ghostTall: { height: 80 },
  toast: { backgroundColor: colors.surface, borderRadius: 10, borderWidth: 1, borderColor: colors.border, borderLeftWidth: 4, padding: 8, gap: 2, marginBottom: 40 },
  toastKind: { fontFamily: fonts.bold, fontSize: 9, letterSpacing: 0.8 },
  toastTitle: { fontFamily: fonts.semibold, fontSize: 12, lineHeight: 15, color: colors.text },
  toastBody: { fontFamily: fonts.regular, fontSize: 11, lineHeight: 14, color: colors.muted },
});
