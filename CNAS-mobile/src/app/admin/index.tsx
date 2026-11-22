import { Pressable, StyleSheet, Text, View } from 'react-native';

import { AlertBanner } from '@/components/alert-banner';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { Icon } from '@/components/icon';
import { MiniMap } from '@/components/mini-map';
import { PushCard } from '@/components/push-card';
import { Screen } from '@/components/ui';
import { openWebsite, WebLinks } from '@/components/web-links';
import { useAlerts } from '@/lib/alerts';
import { SITE_URL } from '@/lib/config';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';

/**
 * The admin's dashboard. The app has no admin API yet, so managing happens on the website (in-app browser, same
 * sign-in there): the dashboard shows what students see right now and is one tap from sending an alert.
 */
export default function AdminDashboard() {
  const { user } = useSession();
  const { banner } = useAlerts();
  if (!user) return null;

  const active = banner?.status === 'active';

  return (
    <Screen>
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

      {banner ? (
        <View style={styles.block}>
          <Text style={type.label}>{active ? 'Students see this alert now' : 'The last alert ended'}</Text>
          <AlertBanner />
        </View>
      ) : (
        <View style={styles.quiet}>
          <View style={styles.quietIcon}>
            <Icon name="check" size={24} color={colors.white} strokeWidth={2.6} />
          </View>
          <View style={styles.flex}>
            <Text style={type.label}>No active alert</Text>
            <Text style={styles.small}>Everyone sees a normal home screen.</Text>
          </View>
        </View>
      )}

      <Pressable
        onPress={() => openWebsite(`${SITE_URL}/admin/alerts/create`)}
        accessibilityRole="link"
        accessibilityHint="Opens the CNAS website"
        style={({ pressed }) => [styles.send, pressed && styles.sendPressed]}>
        <Icon name="megaphone" size={24} color={colors.white} />
        <View style={styles.flex}>
          <Text style={styles.sendTitle}>{active ? 'Edit or end the alert' : 'Send an alert'}</Text>
          <Text style={styles.sendDetail}>Opens the alert page on the CNAS website</Text>
        </View>
        <Icon name="external" size={20} color={colors.white} />
      </Pressable>

      <PushCard compact />

      <Text style={type.heading}>Manage on the website</Text>
      <WebLinks
        links={[
          { title: 'Alerts', detail: 'Active alert, history and “I’m safe” answers', icon: 'history', url: `${SITE_URL}/admin/alerts` },
          { title: 'Accounts', detail: 'Students, faculty and staff', icon: 'users', url: `${SITE_URL}/admin/accounts` },
          { title: 'Concerns', detail: 'Messages from students and guests', icon: 'inbox', url: `${SITE_URL}/admin/concerns` },
          { title: 'Settings', detail: 'Emergency numbers, contact details, system status', icon: 'sliders', url: `${SITE_URL}/admin/settings` },
        ]}
      />
      <Text style={type.small}>Sign in on the website with the same account if it asks.</Text>

      <EmergencyContacts />
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingTop: space.md },
  header: { flex: 1, gap: 8, borderLeftWidth: 5, borderLeftColor: colors.secondary, paddingLeft: space.md },
  hello: { fontFamily: fonts.bold, fontSize: 30, lineHeight: 36, color: colors.primary },
  role: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', backgroundColor: colors.primary, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  roleText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.white },
  block: { gap: space.sm },
  quiet: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: space.md, borderRadius: radius.md, backgroundColor: colors.safeTint },
  quietIcon: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.safe, alignItems: 'center', justifyContent: 'center' },
  small: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  send: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 68, padding: space.md, borderRadius: radius.md, backgroundColor: colors.alarm },
  sendPressed: { backgroundColor: colors.alarmDeep },
  sendTitle: { fontFamily: fonts.bold, fontSize: 18, color: colors.white },
  sendDetail: { fontFamily: fonts.regular, fontSize: 14, color: 'rgba(255,255,255,0.85)' },
});
