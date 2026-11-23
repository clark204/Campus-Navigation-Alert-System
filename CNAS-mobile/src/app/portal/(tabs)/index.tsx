import { router, useFocusEffect } from 'expo-router';
import { useCallback } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { AlertBanner } from '@/components/alert-banner';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { Icon } from '@/components/icon';
import { MiniMap } from '@/components/mini-map';
import { Badge, DirectionsButton, MenuList, RoomLine, useMinute, type MenuItem } from '@/components/portal-parts';
import { PushCard } from '@/components/push-card';
import { Button, Screen } from '@/components/ui';
import { bellStore, placesStore, scheduleStore, useResource } from '@/lib/portal-data';
import { campusNow, classesOn, formatTime, nextClass, scheduleName } from '@/lib/schedule';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';

/**
 * Portal home (students, faculty and staff): greeting and the bell, the alert banner, the next class, My schedule,
 * Saved places and Help & contact, alerts on this phone and the emergency numbers. Staff don't teach, so they get no
 * class card (docs/schedule.md).
 */
export default function PortalHome() {
  const { user, setVerifyLater } = useSession();
  const schedule = useResource(scheduleStore);
  const places = useResource(placesStore);
  const bell = useResource(bellStore);
  useMinute();

  // Back on Home (from Help & contact, a class edit…): refresh what may have changed (each skips if it's fresh).
  useFocusEffect(
    useCallback(() => {
      bellStore.load();
      scheduleStore.load();
      placesStore.load();
    }, []),
  );

  if (!user) return null;

  const name = user.first_name?.trim() || user.email;
  const scheduleTitle = scheduleName(user);
  const classes = schedule.data?.classes ?? [];
  const next = scheduleTitle ? nextClass(classes) : null;
  const todayCount = classesOn(classes, campusNow().day).length;
  const unread = bell.data?.unread_count ?? 0;
  const saved = places.data?.saved ?? [];

  const items: MenuItem[] = [
    // Faculty and staff the admin trusts send and end alerts from here (like their portal's Emergency alerts page).
    ...(user.can_send_alerts
      ? [{ title: 'Emergency alerts', detail: 'Send an alert, or end the active one', icon: 'megaphone' as const, onPress: () => router.push('/portal/alerts') }]
      : []),
    ...(scheduleTitle
      ? [
          {
            title: scheduleTitle,
            detail: classes.length === 0 ? 'Your classes and rooms this term' : todayCount === 0 ? 'No classes today' : `${todayCount} ${todayCount === 1 ? 'class' : 'classes'} today`,
            icon: 'calendar' as const,
            onPress: () => router.push('/portal/schedule'),
          },
        ]
      : []),
    {
      title: 'Saved places',
      detail: saved.length === 0 ? 'Rooms you save on the map' : saved.slice(0, 3).map((place) => place.name).join(', '),
      icon: 'star',
      onPress: () => router.push('/portal/saved-places'),
    },
    {
      title: 'Help & contact',
      detail: unread > 0 ? 'The administrator replied' : 'Ask the administrator a question',
      icon: 'inbox',
      badge: unread,
      onPress: () => router.push('/portal/help'),
    },
  ];

  return (
    <Screen onRefresh={() => Promise.all([bellStore.load(true), scheduleStore.load(true), placesStore.load(true)])} refreshing={false}>
      <View style={styles.top}>
        <View style={styles.header}>
          <Text style={styles.hello}>Hi, {name}</Text>
          {user.college ? <Text style={type.body}>{user.college.name}</Text> : null}
          {user.program ? <Text style={type.small}>{user.program.name}</Text> : null}
        </View>
        <Pressable
          onPress={() => router.push('/portal/notifications')}
          accessibilityRole="button"
          accessibilityLabel={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
          hitSlop={6}
          style={({ pressed }) => [styles.bell, pressed && styles.pressed]}>
          <Icon name="bell" size={24} color={colors.primary} />
          {unread > 0 && (
            <View style={styles.bellBadge}>
              <Badge count={unread} />
            </View>
          )}
        </Pressable>
        <MiniMap />
      </View>

      <AlertBanner />

      {user.must_change_password && (
        <View style={styles.card}>
          <Text style={type.label}>Choose a new password</Text>
          <Text style={styles.cardText}>You signed in with a temporary password from the administrator. Choose your own now.</Text>
          <Button title="Change password" variant="link" onPress={() => router.push('/portal/change-password')} />
        </View>
      )}

      {!user.email_verified && (
        <View style={styles.card}>
          <Text style={type.label}>Your email isn’t verified yet</Text>
          <Text style={styles.cardText}>Enter the code we sent to {user.email}.</Text>
          <Button title="Verify email" variant="link" onPress={() => setVerifyLater(false)} />
        </View>
      )}

      {next && (
        <View style={styles.next}>
          <View style={styles.nextHead}>
            <Text style={styles.nextLabel}>{next.when === 'Now' ? 'In class now' : 'Next class'}</Text>
            <Text style={styles.nextWhen}>
              {next.when === 'Now' ? `until ${formatTime(next.class.ends_at)}` : `${next.when}, ${formatTime(next.class.starts_at)}`}
            </Text>
          </View>
          <View style={styles.nextBody}>
            <Text style={styles.nextSubject}>
              {next.class.subject}
              {next.class.section ? <Text style={styles.nextSection}>{`  ${next.class.section}`}</Text> : null}
            </Text>
            <RoomLine item={next.class} />
            {next.class.room_code ? <DirectionsButton code={next.class.room_code} /> : null}
          </View>
        </View>
      )}

      <MenuList items={items} />

      <PushCard compact />

      <EmergencyContacts />
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm, paddingTop: space.md },
  header: { flex: 1, gap: 2, borderLeftWidth: 5, borderLeftColor: colors.secondary, paddingLeft: space.md },
  hello: { fontFamily: fonts.bold, fontSize: 30, lineHeight: 36, color: colors.primary },
  bell: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  bellBadge: { position: 'absolute', top: -6, right: -6 },
  pressed: { opacity: 0.7 },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space.md, gap: 4 },
  cardText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
  next: { borderRadius: radius.md, overflow: 'hidden', backgroundColor: colors.surface, borderWidth: 1.5, borderColor: colors.secondary },
  nextHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: space.sm, paddingHorizontal: space.md, paddingVertical: 10, backgroundColor: colors.primary },
  nextLabel: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
  nextWhen: { fontFamily: fonts.semibold, fontSize: 15, color: colors.secondary },
  nextBody: { padding: space.md, gap: 6 },
  nextSubject: { fontFamily: fonts.bold, fontSize: 22, lineHeight: 28, color: colors.text },
  nextSection: { fontFamily: fonts.regular, fontSize: 15, color: colors.muted },
});
