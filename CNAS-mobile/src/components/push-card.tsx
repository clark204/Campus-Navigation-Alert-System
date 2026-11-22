import { useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { pushSupport } from '@/lib/push';
import { usePush } from '@/lib/push-context';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';

/**
 * Alerts on this phone. Not allowed (or never asked): a "Turn on alerts" card that asks again or opens the phone's
 * settings. Allowed: an on/off switch ("off" deletes the token on the server). `compact` (Welcome) shows only the
 * "Turn on" card.
 */
export function PushCard({ compact }: { compact?: boolean }) {
  const { permission, off, turnOn, turnOff } = usePush();
  const [busy, setBusy] = useState(false);
  if (!permission || pushSupport() === 'web') return null;

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    try {
      await action();
    } finally {
      setBusy(false);
    }
  };

  if (permission.status !== 'granted') {
    return (
      <Pressable
        onPress={() => run(turnOn)}
        disabled={busy}
        accessibilityRole="button"
        style={({ pressed }) => [styles.card, styles.warn, pressed && styles.pressed]}>
        <View style={[styles.icon, { backgroundColor: colors.alarm }]}>
          <Icon name="bellOff" size={22} color={colors.white} />
        </View>
        <View style={styles.text}>
          <Text style={type.label}>Turn on alerts</Text>
          <Text style={styles.small}>
            {permission.canAskAgain
              ? 'Get emergency alerts even when CNAS is closed.'
              : 'Notifications are off for CNAS. Tap to open your phone’s settings and allow them.'}
          </Text>
        </View>
      </Pressable>
    );
  }

  if (compact) return null;

  return (
    <View style={styles.card}>
      <View style={[styles.icon, { backgroundColor: off ? colors.muted : colors.safe }]}>
        <Icon name={off ? 'bellOff' : 'bell'} size={22} color={colors.white} />
      </View>
      <View style={styles.text}>
        <Text style={type.label}>Emergency alerts on this phone</Text>
        <Text style={styles.small}>{off ? 'Off. You only see alerts while CNAS is open.' : 'On, even when CNAS is closed.'}</Text>
      </View>
      <Switch
        value={!off}
        disabled={busy}
        onValueChange={(value) => run(value ? turnOn : turnOff)}
        trackColor={{ true: colors.safe, false: colors.border }}
        accessibilityLabel="Emergency alerts on this phone"
      />
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: space.md,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  warn: { borderColor: '#F3C4C7', backgroundColor: '#FFF6F6' },
  pressed: { opacity: 0.8 },
  icon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  text: { flex: 1, gap: 2 },
  small: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
});
