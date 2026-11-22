import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { usePush } from '@/lib/push-context';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';
import { Button } from './ui';

/**
 * "Turn on emergency alerts": shown once after the intro, before the phone's own permission question, so people know
 * why the app asks. An emergency takeover (a Modal) still shows on top of it.
 */
export function AlertsPrompt() {
  const { turnOn, dismissPrompt } = usePush();
  const [busy, setBusy] = useState(false);

  return (
    <View style={[StyleSheet.absoluteFill, styles.screen]}>
      <SafeAreaView style={styles.safe}>
        <View style={styles.body}>
          <View style={styles.badge}>
            <Icon name="bell" size={40} color={colors.white} strokeWidth={2} />
          </View>
          <Text style={type.title} accessibilityRole="header">
            Turn on emergency alerts
          </Text>
          <Text style={type.body}>
            Get fire, earthquake and other campus alerts even when CNAS is closed or your phone is locked.
          </Text>
          <View style={styles.list}>
            <Point color={colors.alarm} text="Emergencies ring like an alarm." />
            <Point color={colors.hazard} text="Drills use a softer sound." />
            <Point color={colors.safe} text="You also hear when it is all clear." />
          </View>
        </View>
        <View style={styles.actions}>
          <Button
            title="Turn on alerts"
            loading={busy}
            onPress={async () => {
              setBusy(true);
              await turnOn();
              setBusy(false);
            }}
          />
          <Button title="Not now" variant="quiet" onPress={dismissPrompt} />
          <Text style={[type.small, styles.center]}>You can change this later under Account.</Text>
        </View>
      </SafeAreaView>
    </View>
  );
}

function Point({ color, text }: { color: string; text: string }) {
  return (
    <View style={styles.point}>
      <View style={[styles.dot, { backgroundColor: color }]} />
      <Text style={[type.body, styles.flex]}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.bg, zIndex: 5 },
  safe: { flex: 1, padding: space.lg, justifyContent: 'space-between' },
  body: { gap: space.md, paddingTop: space.xl },
  badge: { width: 76, height: 76, borderRadius: 22, backgroundColor: colors.alarm, alignItems: 'center', justifyContent: 'center', marginBottom: space.sm },
  list: { gap: space.sm, marginTop: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  point: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  dot: { width: 12, height: 12, borderRadius: 6 },
  flex: { flex: 1 },
  actions: { gap: space.sm },
  center: { textAlign: 'center', fontFamily: fonts.regular },
});
