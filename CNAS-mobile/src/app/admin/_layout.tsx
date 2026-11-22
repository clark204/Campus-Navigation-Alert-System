import { Tabs } from 'expo-router/js-tabs';

import { tab, useTabBarOptions } from '@/components/tab-options';

/** The admin layout: Dashboard, Map and Account tabs (signing out is under Account). Admins only (root layout). */
export default function AdminLayout() {
  return (
    <Tabs screenOptions={useTabBarOptions()}>
      <Tabs.Screen name="index" options={tab('Dashboard', 'shield')} />
      <Tabs.Screen name="map" options={tab('Map', 'map')} />
      <Tabs.Screen name="account" options={tab('Account', 'user')} />
    </Tabs>
  );
}
