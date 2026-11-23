import { router, useFocusEffect } from 'expo-router';
import { useCallback } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { LastEmergency, RankedBars, roleRows, SignInsChart, StatTiles } from '@/components/admin/analytics';
import { TermWarningCard } from '@/components/admin/settings';
import { ActiveAlertPanel, useLiveAlerts } from '@/components/alert-admin/active-alert-panel';
import { Icon } from '@/components/icon';
import { MiniMap } from '@/components/mini-map';
import { LoadState, MenuList } from '@/components/portal-parts';
import { PushCard } from '@/components/push-card';
import { Screen } from '@/components/ui';
import { alertsStore, concernsStore, dashboardStore } from '@/lib/admin';
import { useResource } from '@/lib/portal-data';
import { useSession } from '@/lib/session';
import { colors, fonts, space, type } from '@/theme';

/**
 * The admin's dashboard, an analytics view with the website overview's numbers (GET /api/admin/dashboard): the active
 * alert (live "I'm safe" count and every action on it) or Send an alert, the term warning when the admin should act,
 * headline tiles, sign-ins per day (website and app), accounts, students by college, concerns by topic, the last
 * emergency's answers and where people go. The last copy shows offline. Pull down to refresh.
 */
export default function AdminDashboard() {
  const { user } = useSession();
  const overview = useResource(alertsStore);
  const concerns = useResource(concernsStore);
  const dashboard = useResource(dashboardStore);
  useLiveAlerts();
  useFocusEffect(
    useCallback(() => {
      dashboardStore.load();
    }, []),
  );
  if (!user) return null;

  const stats = dashboard.data;
  const unread = concerns.data?.meta.unread_count ?? 0;
  const refresh = () => Promise.all([alertsStore.load(true), concernsStore.load(true), dashboardStore.load(true)]);

  return (
    <Screen onRefresh={refresh} refreshing={false}>
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

      {stats && <TermWarningCard warning={stats.term_warning} />}
      <LoadState resource={overview} what="alerts" onRetry={() => alertsStore.load(true)} />
      <ActiveAlertPanel overview={overview.data} area="/admin" />

      <Text style={type.heading} accessibilityRole="header">
        Campus at a glance
      </Text>
      <LoadState resource={dashboard} what="numbers" onRetry={() => dashboardStore.load(true)} />
      {stats && (
        <>
          <StatTiles stats={stats} />
          <SignInsChart days={stats.sign_ins.days} />
          <RankedBars title="Accounts" rows={roleRows(stats)} of={stats.accounts.total} empty="No accounts yet." />
          <RankedBars
            title="Students by college"
            rows={stats.colleges.map((c) => ({ label: c.name, value: c.students }))}
            stacked
            of={stats.accounts.by_role.student}
            empty="No students have registered yet."
          />
          <RankedBars title="Concerns by topic" note="Last 30 days" rows={stats.concerns.by_topic.map((t) => ({ label: t.label, value: t.count }))} empty="No concerns in the last 30 days." />
          <LastEmergency last={stats.alerts.last} />
          <RankedBars
            title="Where people go"
            note="Directions started"
            rows={stats.places.started.map((p) => ({ label: p.name, value: p.count }))}
            empty="No one has started directions to a room yet."
          />
        </>
      )}

      <MenuList
        items={[
          {
            title: 'Concerns',
            detail: unread > 0 ? `${unread} new` : 'Messages from students and guests',
            icon: 'inbox',
            badge: unread,
            onPress: () => router.push('/admin/concerns'),
          },
          { title: 'Accounts', detail: 'Students, faculty and staff', icon: 'users', onPress: () => router.push('/admin/accounts') },
          { title: 'Alert history', detail: 'Every alert, who sent it and how it ended', icon: 'history', onPress: () => router.push('/admin/alert-history') },
        ]}
      />

      <PushCard compact />
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
