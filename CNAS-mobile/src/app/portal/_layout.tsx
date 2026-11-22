import { Tabs } from 'expo-router/js-tabs';

import { tab, useTabBarOptions } from '@/components/tab-options';

/** The portal layout for students, faculty and staff: Home, Map and Account tabs (signing out is under Account). */
export default function PortalLayout() {
  return (
    <Tabs screenOptions={useTabBarOptions()}>
      <Tabs.Screen name="index" options={tab('Home', 'home')} />
      <Tabs.Screen name="map" options={tab('Map', 'map')} />
      <Tabs.Screen name="account" options={tab('Account', 'user')} />
    </Tabs>
  );
}
