import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { ActiveAlertPanel, useLiveAlerts } from '@/components/alert-admin/active-alert-panel';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { Icon } from '@/components/icon';
import { MiniMap } from '@/components/mini-map';
import { LoadState, MenuList } from '@/components/portal-parts';
import { PushCard } from '@/components/push-card';
import { Screen } from '@/components/ui';
import { WebLinks } from '@/components/web-links';
import { alertsStore, concernsStore } from '@/lib/admin';
import { SITE_URL } from '@/lib/config';
import { useResource } from '@/lib/portal-data';
import { useSession } from '@/lib/session';
import { colors, fonts, space, type } from '@/theme';

/**
 * The admin's dashboard: the active alert with its live "I'm safe" count and every action on it (or Send an alert),
 * then Concerns, Accounts and the alert history. Map data, photos, terms, emergency numbers and settings stay on the
 * website (links at the bottom).
 */
export default function AdminDashboard() {
  const { user } = useSession();
  const overview = useResource(alertsStore);
  const concerns = useResource(concernsStore);
  useLiveAlerts();
  if (!user) return null;

  const unread = concerns.data?.meta.unread_count ?? 0;
  const open = concerns.data?.meta.open_count ?? 0;

  return (
    <Screen onRefresh={() => Promise.all([alertsStore.load(true), concernsStore.load(true)])} refreshing={false}>
      <View style={styles.top}>
        <View style={styles.header}>
          <Text style={styles.hello}>Hi, {user.first_name?.trim() || 'Admin'}</Text>
          <View style={styles.role}>
            <Icon name="shield" size={16} color={colors.white} strokeWidth={2.2} />
            <Text style={styles.roleText}>Administrator</Text>
          </View>
        </View>
        <MiniMap />
      </View>

      <LoadState resource={overview} what="alerts" onRetry={() => alertsStore.load(true)} />
      <ActiveAlertPanel overview={overview.data} area="/admin" />

      <MenuList
        items={[
          {
            title: 'Concerns',
            detail: unread > 0 ? `${unread} new` : open > 0 ? `${open} open` : 'Messages from students and guests',
            icon: 'inbox',
            badge: unread,
            onPress: () => router.push('/admin/concerns'),
          },
          { title: 'Accounts', detail: 'Students, faculty and staff', icon: 'users', onPress: () => router.push('/admin/accounts') },
          { title: 'Alert history', detail: 'Every alert, who sent it and how it ended', icon: 'history', onPress: () => router.push('/admin/alert-history') },
        ]}
      />

      <PushCard compact />

      <Text style={type.heading}>On the website</Text>
      <WebLinks
        links={[
          { title: 'Campus map data', detail: 'Import and export the map', icon: 'map', url: `${SITE_URL}/admin/map` },
          { title: 'Buildings and rooms', detail: 'Names and photos', icon: 'home', url: `${SITE_URL}/admin/buildings` },
          { title: '360° photos', detail: 'Photo points along the routes', icon: 'eye', url: `${SITE_URL}/admin/panoramas` },
          { title: 'Academic terms', detail: 'The current term for COR checks', icon: 'calendar', url: `${SITE_URL}/admin/academic-terms` },
          { title: 'Emergency numbers', detail: 'The numbers everyone sees', icon: 'phone', url: `${SITE_URL}/admin/settings?tab=emergency-contacts` },
          { title: 'Settings', detail: 'Contact details and system status', icon: 'sliders', url: `${SITE_URL}/admin/settings` },
        ]}
      />
      <Text style={type.small}>Sign in on the website with the same account if it asks.</Text>

      <EmergencyContacts />
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingTop: space.md },
  header: { flex: 1, gap: 8, borderLeftWidth: 5, borderLeftColor: colors.secondary, paddingLeft: space.md },
  hello: { fontFamily: fonts.bold, fontSize: 30, lineHeight: 36, color: colors.primary },
  role: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', backgroundColor: colors.primary, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  roleText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.white },
});
