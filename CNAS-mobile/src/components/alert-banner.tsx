import { Pressable, StyleSheet, Text, View } from 'react-native';

import { showsSafeButton } from '@/lib/alert-rules';
import { useAlerts } from '@/lib/alerts';
import { useSession } from '@/lib/session';
import type { Alert } from '@/lib/types';
import { colors, fonts, radius, space } from '@/theme';
import { DrillStamp, HazardTape, SafeButton } from './alert-parts';
import { alertIcon, Icon } from './icon';

// Same colors as the web banner (partials/alert-banner): red emergency, orange warning, yellow advisory, green when
// it's over.
function bannerColors(alert: Alert) {
  if (alert.status === 'resolved') return { bg: colors.safe, fg: colors.white };
  if (alert.severity === 'warning') return { bg: colors.warning, fg: colors.white };
  if (alert.severity === 'advisory') return { bg: colors.advisory, fg: colors.advisoryText };
  return { bg: colors.alarm, fg: colors.white };
}

/** The alert banner (Welcome and Home). Nothing when there is no alert. */
export function AlertBanner() {
  const { banner: alert, dismissBanner } = useAlerts();
  const { user } = useSession();
  if (!alert) return null;

  const resolved = alert.status === 'resolved';
  const { bg, fg } = bannerColors(alert);

  return (
    <View style={[styles.banner, { backgroundColor: bg }]} accessibilityRole="alert">
      {alert.is_drill && <HazardTape />}
      <View style={styles.body}>
        <View style={styles.top}>
          <View style={[styles.iconBox, { backgroundColor: fg === colors.white ? 'rgba(255,255,255,0.16)' : 'rgba(0,0,0,0.08)' }]}>
            <Icon name={alertIcon(alert)} size={24} color={fg} strokeWidth={1.9} />
          </View>
          <View style={styles.text}>
            <View style={styles.meta}>
              {alert.is_drill && <DrillStamp />}
              <Text style={[styles.kind, { color: fg }]}>{resolved ? (alert.resolution_label ?? 'All clear') : alert.type_label}</Text>
            </View>
            {!resolved && alert.location ? (
              <View style={styles.meta}>
                <Icon name="pin" size={16} color={fg} />
                <Text style={[styles.small, { color: fg }]}>{alert.location}</Text>
              </View>
            ) : null}
            <Text style={[styles.title, { color: fg }]}>{alert.title}</Text>
            {resolved ? (
              alert.resolved_message ? <Text style={[styles.small, { color: fg }]}>{alert.resolved_message}</Text> : null
            ) : (
              <>
                <Text style={[styles.small, { color: fg }]}>{alert.message}</Text>
                {alert.instructions ? <Text style={[styles.small, styles.instructions, { color: fg }]}>{alert.instructions}</Text> : null}
              </>
            )}
          </View>
          {resolved && (
            <Pressable
              onPress={() => dismissBanner(alert)}
              accessibilityRole="button"
              accessibilityLabel="Close"
              hitSlop={8}
              style={styles.close}>
              <Icon name="close" size={22} color={fg} strokeWidth={2.2} />
            </Pressable>
          )}
        </View>
        {user && showsSafeButton(alert) && <SafeButton alert={alert} />}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: { borderRadius: radius.lg, overflow: 'hidden' },
  body: { padding: space.md, gap: space.md },
  top: { flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  iconBox: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  text: { flex: 1, gap: 3 },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 6, flexWrap: 'wrap' },
  kind: { fontFamily: fonts.semibold, fontSize: 15 },
  title: { fontFamily: fonts.bold, fontSize: 19, lineHeight: 25 },
  small: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21 },
  instructions: { fontFamily: fonts.semibold },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', marginTop: -8, marginRight: -8 },
});
