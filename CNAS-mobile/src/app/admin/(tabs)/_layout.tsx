import { useFocusEffect } from 'expo-router';
import { Tabs } from 'expo-router/js-tabs';
import { useCallback } from 'react';

import { tab, useTabBarOptions } from '@/components/tab-options';
import { concernsStore } from '@/lib/admin';
import { useResource } from '@/lib/portal-data';
import { colors, fonts } from '@/theme';

/**
 * The admin's tabs: Dashboard, Concerns (gold badge: new ones this admin hasn't opened), Accounts, Map and Account
 * (signing out is under Account).
 */
export default function AdminTabs() {
  const concerns = useResource(concernsStore);
  const unread = concerns.data?.meta.unread_count ?? 0;

  // Back on the tabs (from a concern or the dashboard): the badge catches up.
  useFocusEffect(
    useCallback(() => {
      concernsStore.load();
    }, []),
  );

  return (
    <Tabs screenOptions={useTabBarOptions()}>
      <Tabs.Screen name="index" options={tab('Dashboard', 'shield')} />
      <Tabs.Screen
        name="concerns"
        options={{
          ...tab('Concerns', 'inbox'),
          tabBarBadge: unread > 0 ? (unread > 9 ? '9+' : unread) : undefined,
          tabBarBadgeStyle: { backgroundColor: colors.secondary, color: colors.primaryHover, fontFamily: fonts.bold, fontSize: 11 },
        }}
      />
      <Tabs.Screen name="accounts" options={tab('Accounts', 'users')} />
      <Tabs.Screen name="map" options={tab('Map', 'map')} />
      <Tabs.Screen name="account" options={tab('Account', 'user')} />
    </Tabs>
  );
}
