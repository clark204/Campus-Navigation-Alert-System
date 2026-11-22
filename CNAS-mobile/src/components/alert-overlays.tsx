import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAlerts } from '@/lib/alerts';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space } from '@/theme';
import { DrillStamp, HazardTape, SafeButton } from './alert-parts';
import { alertIcon, Icon } from './icon';

/**
 * The emergency takeover (full screen, alarm and vibration until the first tap) and the warning popup. Shown once per
 * alert version on this phone; then the banner on Home or Welcome takes over. Mounted once in the root layout.
 */
export function AlertOverlays() {
  return (
    <>
      <Takeover />
      <WarningPopup />
    </>
  );
}

function Takeover() {
  const { takeover: alert, sounding, silence, dismissTakeover } = useAlerts();
  const { user } = useSession();

  return (
    <Modal visible={alert !== null} animationType="fade" statusBarTranslucent onRequestClose={dismissTakeover}>
      {alert && (
        // Any touch silences the alarm, like the web ("Tap anywhere to silence the alarm").
        <View style={styles.takeover} onTouchStart={silence} accessibilityViewIsModal>
          {alert.is_drill && <HazardTape />}
          <SafeAreaView style={styles.flex}>
            <ScrollView contentContainerStyle={styles.takeoverBody}>
              <View style={styles.row}>
                <View style={styles.bigIcon}>
                  <Icon name={alertIcon(alert)} size={34} color={colors.white} strokeWidth={1.9} />
                </View>
                <Text style={styles.kind}>{alert.type_label}</Text>
                {alert.is_drill && <DrillStamp large />}
              </View>

              <Text style={styles.headline} accessibilityRole="header">
                {alert.title}
              </Text>

              {alert.location ? (
                <View style={styles.row}>
                  <Icon name="pin" size={20} color={colors.white} />
                  <Text style={styles.location}>{alert.location}</Text>
                </View>
              ) : null}

              <View style={styles.instructionsBox}>
                <Text style={styles.message}>{alert.message}</Text>
                {alert.instructions ? <Text style={styles.instructions}>{alert.instructions}</Text> : null}
              </View>

              {sounding && (
                <View style={styles.row}>
                  <Icon name="mute" size={20} color="rgba(255,255,255,0.85)" />
                  <Text style={styles.hint}>Tap anywhere to silence the alarm</Text>
                </View>
              )}

              <View style={styles.actions}>
                {user && <SafeButton alert={alert} large />}
                <Pressable
                  onPress={dismissTakeover}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.dismiss, pressed && styles.pressed]}>
                  <Text style={styles.dismissText}>Dismiss</Text>
                </Pressable>
              </View>
            </ScrollView>
          </SafeAreaView>
        </View>
      )}
    </Modal>
  );
}

function WarningPopup() {
  const { popup: alert, dismissPopup } = useAlerts();

  return (
    <Modal visible={alert !== null} transparent animationType="slide" statusBarTranslucent onRequestClose={dismissPopup}>
      {alert && (
        <View style={styles.scrim}>
          <View style={styles.card} accessibilityViewIsModal>
            {alert.is_drill && <HazardTape />}
            <View style={styles.cardBody}>
              <View style={styles.row}>
                <View style={styles.cardIcon}>
                  <Icon name={alertIcon(alert)} size={24} color={colors.white} strokeWidth={1.9} />
                </View>
                <View style={styles.flex}>
                  <View style={styles.row}>
                    {alert.is_drill && <DrillStamp />}
                    <Text style={styles.cardKind}>{alert.type_label}</Text>
                  </View>
                  <Text style={styles.cardTitle} accessibilityRole="header">
                    {alert.title}
                  </Text>
                </View>
              </View>
              {alert.location ? (
                <View style={styles.row}>
                  <Icon name="pin" size={18} color={colors.muted} />
                  <Text style={styles.cardSmall}>{alert.location}</Text>
                </View>
              ) : null}
              <Text style={styles.cardText}>{alert.message}</Text>
              {alert.instructions ? <Text style={[styles.cardText, styles.bold]}>{alert.instructions}</Text> : null}
              <Pressable onPress={dismissPopup} accessibilityRole="button" style={({ pressed }) => [styles.cardClose, pressed && styles.pressed]}>
                <Text style={styles.cardCloseText}>Close</Text>
              </Pressable>
            </View>
          </View>
        </View>
      )}
    </Modal>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.75 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' },
  takeover: { flex: 1, backgroundColor: colors.alarm },
  takeoverBody: { padding: space.lg, paddingTop: space.xl, gap: space.lg, flexGrow: 1 },
  bigIcon: { width: 60, height: 60, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.16)', alignItems: 'center', justifyContent: 'center' },
  kind: { fontFamily: fonts.bold, fontSize: 22, color: colors.white },
  headline: { fontFamily: fonts.bold, fontSize: 36, lineHeight: 42, color: colors.white },
  location: { fontFamily: fonts.semibold, fontSize: 19, color: colors.white, flexShrink: 1 },
  instructionsBox: { backgroundColor: colors.alarmDeep, borderRadius: radius.lg, padding: space.lg, gap: space.sm },
  message: { fontFamily: fonts.regular, fontSize: 19, lineHeight: 27, color: colors.white },
  instructions: { fontFamily: fonts.bold, fontSize: 21, lineHeight: 29, color: colors.white },
  hint: { fontFamily: fonts.medium, fontSize: 16, color: 'rgba(255,255,255,0.85)' },
  actions: { gap: space.md, marginTop: 'auto' },
  dismiss: { minHeight: 56, borderRadius: radius.lg, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  dismissText: { fontFamily: fonts.bold, fontSize: 18, color: colors.alarm },
  scrim: { flex: 1, backgroundColor: 'rgba(0,0,0,0.45)', justifyContent: 'flex-end', padding: space.md },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, overflow: 'hidden', borderTopWidth: 6, borderTopColor: colors.warning },
  cardBody: { padding: space.lg, gap: space.md },
  cardIcon: { width: 44, height: 44, borderRadius: 12, backgroundColor: colors.warning, alignItems: 'center', justifyContent: 'center' },
  cardKind: { fontFamily: fonts.semibold, fontSize: 15, color: colors.warning },
  cardTitle: { fontFamily: fonts.bold, fontSize: 21, lineHeight: 27, color: colors.text },
  cardSmall: { fontFamily: fonts.medium, fontSize: 16, color: colors.muted, flexShrink: 1 },
  cardText: { fontFamily: fonts.regular, fontSize: 17, lineHeight: 25, color: colors.text },
  bold: { fontFamily: fonts.semibold },
  cardClose: { minHeight: 54, borderRadius: radius.md, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  cardCloseText: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
});
