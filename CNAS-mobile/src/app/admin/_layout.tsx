import { Stack } from 'expo-router';

import { colors } from '@/theme';

// The tabs are the first screen, so a page opened from a tab goes back to them.
export const unstable_settings = { anchor: '(tabs)' };

/**
 * The admin area (admins only, root layout): the tabs (Dashboard, Map, Concerns, Accounts, Settings) and the pages they
 * open. Pages live here, not at the root, so the redirect after a sign-in lands on the tabs.
 */
export default function AdminLayout() {
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Screen name="(tabs)" />
      <Stack.Screen name="send-alert" options={{ gestureEnabled: false }} />
      <Stack.Screen name="edit-alert" />
      <Stack.Screen name="end-alert" />
      <Stack.Screen name="safety" />
      <Stack.Screen name="alert-history" />
      <Stack.Screen name="concern" />
      <Stack.Screen name="account-view" />
      <Stack.Screen name="account-form" />
      <Stack.Screen name="account" />
      <Stack.Screen name="academic-term" />
      <Stack.Screen name="emergency-contacts" />
      <Stack.Screen name="contact-details" />
      <Stack.Screen name="system-status" />
      <Stack.Screen name="change-password" />
      <Stack.Screen name="change-email" />
    </Stack>
  );
}
