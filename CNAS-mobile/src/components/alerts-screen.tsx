import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { NeedHelpButton, SafeButton } from '@/components/alert-parts';
import { alertIcon, Icon } from '@/components/icon';
import { BackLink, Body, Button, Notice, Screen, Title } from '@/components/ui';
import { alertKind, showsSafeButton } from '@/lib/alert-rules';
import { useAlerts } from '@/lib/alerts';
import { api, errorMessage } from '@/lib/api';
import { isAdmin } from '@/lib/roles';
import { useSession } from '@/lib/session';
import type { Alert } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

/** The edge color of a card: the alert's level while active, green once over. */
function edgeOf(alert: Alert) {
  if (alert.status === 'resolved') return colors.safe;
  if (alert.severity === 'warning') return colors.warning;
  if (alert.severity === 'advisory') return '#EAB308';
  return colors.alarm;
}

function when(iso: string) {
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/**
 * Where this person's Alerts screen is. It lives inside each area (Welcome's guests, the portal, the admin), never at
 * the root: a root screen open to signed-in people wins the redirect after signing in (app/_layout.tsx).
 */
export function useAlertsHref() {
  const { user } = useSession();
  if (!user) return '/campus-alerts' as const;
  return isAdmin(user) ? ('/admin/campus-alerts' as const) : ('/portal/campus-alerts' as const);
}

/**
 * Alerts, for everyone (guests too): the current alert in full, then every alert of the last 30 days
 * (GET /api/alerts/recent). Toasts close and notifications get swiped away; nothing is lost here. During an active
 * emergency the answers are here too, and the takeover can be opened again.
 */
export function AlertsScreen() {
  const { banner: current, reopenTakeover } = useAlerts();
  const { user } = useSession();
  const [recent, setRecent] = useState<Alert[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const { data } = await api<{ data: Alert[] }>('/alerts/recent');
      setRecent(data);
      setError(null);
    } catch (failure) {
      setError(`The list didn’t load. ${errorMessage(failure)}`);
    }
  }, []);

  useEffect(() => {
    let alive = true;
    api<{ data: Alert[] }>('/alerts/recent')
      .then(({ data }) => alive && setRecent(data))
      .catch((failure) => alive && setError(`The list didn’t load. ${errorMessage(failure)}`));
    return () => {
      alive = false;
    };
  }, []);

  const refresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  const earlier = (recent ?? []).filter((alert) => alert.id !== current?.id);
  const emergency = current !== null && showsSafeButton(current);

  return (
    <Screen onRefresh={refresh} refreshing={refreshing}>
      <BackLink />
      <Title>Alerts</Title>
      <Body muted>Every campus alert from the last 30 days, newest first.</Body>

      {current ? (
        emergency ? (
          <View style={styles.emergency}>
            <AlertText alert={current} onDark open />
            {user ? (
              <>
                <SafeButton alert={current} />
                <NeedHelpButton alert={current} />
              </>
            ) : null}
            <Button title="Open the full-screen alert" variant="quiet" onPress={reopenTakeover} />
          </View>
        ) : (
          <AlertCard alert={current} open />
        )
      ) : (
        <View style={styles.none}>
          <Icon name="check" size={22} color={colors.safe} strokeWidth={2.6} />
          <Text style={[type.body, styles.flex]}>No alert right now.</Text>
        </View>
      )}

      {error ? <Notice tone="error">{error}</Notice> : null}
      {earlier.length > 0 && (
        <>
          <Text style={type.heading} accessibilityRole="header">
            Earlier
          </Text>
          {earlier.map((alert) => (
            <AlertCard key={alert.id} alert={alert} />
          ))}
        </>
      )}
      {recent !== null && earlier.length === 0 && <Text style={type.small}>No other alerts in the last 30 days.</Text>}
    </Screen>
  );
}

/** One alert: tap to show or hide the full message. */
function AlertCard({ alert, open: startOpen = false }: { alert: Alert; open?: boolean }) {
  const [open, setOpen] = useState(startOpen);
  return (
    <Pressable
      onPress={() => setOpen(!open)}
      accessibilityRole="button"
      accessibilityState={{ expanded: open }}
      style={({ pressed }) => [styles.card, { borderLeftColor: edgeOf(alert) }, pressed && styles.pressed]}>
      <AlertText alert={alert} open={open} />
    </Pressable>
  );
}

function AlertText({ alert, open, onDark }: { alert: Alert; open: boolean; onDark?: boolean }) {
  const ink = onDark ? colors.white : colors.text;
  const soft = onDark ? 'rgba(255,255,255,0.85)' : colors.muted;
  const resolved = alert.status === 'resolved';
  return (
    <View style={styles.text}>
      <View style={styles.row}>
        <Icon name={alertIcon(alert)} size={18} color={onDark ? colors.white : edgeOf(alert)} strokeWidth={2.2} />
        <Text style={[styles.kind, { color: onDark ? colors.white : edgeOf(alert) }]}>{alertKind(alert)}</Text>
      </View>
      <Text style={[styles.title, { color: ink }]}>{alert.title}</Text>
      <Text style={[styles.meta, { color: soft }]}>
        {[alert.status === 'active' ? alert.location : null, when(alert.sent_at)].filter(Boolean).join(' · ')}
      </Text>
      {resolved && alert.resolved_message ? <Text style={[styles.action, { color: ink }]}>{alert.resolved_message}</Text> : null}
      {!resolved && alert.what_to_do ? <Text style={[styles.action, { color: ink }]}>{alert.what_to_do}</Text> : null}
      {open && (
        <>
          <Text style={[styles.detail, { color: ink }]}>{alert.message}</Text>
          {alert.instructions ? <Text style={[styles.detail, { color: ink }]}>{alert.instructions}</Text> : null}
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.8 },
  emergency: { backgroundColor: colors.alarm, borderRadius: radius.lg, padding: space.md, gap: space.md },
  none: { flexDirection: 'row', alignItems: 'center', gap: space.sm, backgroundColor: colors.safeTint, borderRadius: radius.lg, padding: space.md },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, borderLeftWidth: 5, padding: space.md },
  text: { gap: 4 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  kind: { fontFamily: fonts.bold, fontSize: 12, letterSpacing: 1.2 },
  title: { fontFamily: fonts.bold, fontSize: 19, lineHeight: 25 },
  meta: { fontFamily: fonts.regular, fontSize: 14 },
  action: { fontFamily: fonts.semibold, fontSize: 16, lineHeight: 22 },
  detail: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23 },
});
