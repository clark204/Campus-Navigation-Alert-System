import type { BottomTabNavigationOptions } from 'expo-router/js-tabs';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors, fonts } from '@/theme';
import { Icon, type IconName } from './icon';

/**
 * The bottom tab bar shared by the admin and portal layouts: white, maroon for the open tab, large labels. Sized by
 * hand because Instrument Sans is taller than the system font, which the default bar height clips.
 */
export function useTabBarOptions(): BottomTabNavigationOptions {
  const insets = useSafeAreaInsets();
  return {
    headerShown: false,
    tabBarActiveTintColor: colors.primary,
    tabBarInactiveTintColor: colors.muted,
    tabBarStyle: { backgroundColor: colors.surface, borderTopColor: colors.border, height: 64 + insets.bottom, paddingTop: 6, paddingBottom: insets.bottom + 6 },
    tabBarLabelStyle: { fontFamily: fonts.semibold, fontSize: 12, lineHeight: 16 },
    sceneStyle: { backgroundColor: colors.bg },
  };
}

/** One tab: its title and icon (thicker when open). */
export function tab(title: string, icon: IconName): BottomTabNavigationOptions {
  return {
    title,
    tabBarIcon: ({ color, focused }) => (
      <Icon name={icon} size={24} color={typeof color === 'string' ? color : colors.primary} strokeWidth={focused ? 2.4 : 1.9} />
    ),
  };
}
