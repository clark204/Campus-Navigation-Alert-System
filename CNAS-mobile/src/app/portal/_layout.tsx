import { Stack } from 'expo-router';

import { colors } from '@/theme';

// The tabs are the first screen, so a page opened from Home goes back to them.
export const unstable_settings = { anchor: '(tabs)' };

/**
 * The portal for students, faculty and staff: the tabs (Home, Map, Account) and the pages they open. The pages live
 * inside the portal (not at the root) so that after a sign-in the root layout's redirect lands on the tabs.
 */
export default function PortalLayout() {
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Screen name="(tabs)" />
      <Stack.Screen name="schedule" />
      <Stack.Screen name="class-form" />
      <Stack.Screen name="schedule-import" />
      <Stack.Screen name="saved-places" />
      <Stack.Screen name="help" />
      <Stack.Screen name="notifications" />
      <Stack.Screen name="alerts" />
      <Stack.Screen name="send-alert" options={{ gestureEnabled: false }} />
      <Stack.Screen name="end-alert" />
      <Stack.Screen name="alert-history" />
      <Stack.Screen name="change-password" />
      <Stack.Screen name="change-email" />
    </Stack>
  );
}
