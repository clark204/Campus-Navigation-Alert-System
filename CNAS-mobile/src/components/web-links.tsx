import * as WebBrowser from 'expo-web-browser';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { colors, fonts, radius, space } from '@/theme';
import { Icon, type IconName } from './icon';

export type WebLink = { title: string; detail?: string; icon: IconName; url: string };

/** Opens a CNAS website page in the in-app browser (it shares the phone browser's sign-in). */
export function openWebsite(url: string) {
  WebBrowser.openBrowserAsync(url).catch(() => {});
}

/** A list of website pages, one row each, with an "opens the website" mark. */
export function WebLinks({ links }: { links: WebLink[] }) {
  return (
    <View style={styles.list}>
      {links.map((link, index) => (
        <Pressable
          key={link.url}
          onPress={() => openWebsite(link.url)}
          accessibilityRole="link"
          accessibilityHint="Opens the CNAS website"
          style={({ pressed }) => [styles.row, index > 0 && styles.divider, pressed && styles.pressed]}>
          <View style={styles.icon}>
            <Icon name={link.icon} size={20} color={colors.primary} />
          </View>
          <View style={styles.text}>
            <Text style={styles.title}>{link.title}</Text>
            {link.detail ? <Text style={styles.detail}>{link.detail}</Text> : null}
          </View>
          <Icon name="external" size={18} color={colors.muted} />
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, minHeight: 60, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  pressed: { backgroundColor: '#F3EEE8' },
  icon: { width: 36, height: 36, borderRadius: 10, backgroundColor: '#F3ECEA', alignItems: 'center', justifyContent: 'center' },
  text: { flex: 1, gap: 1 },
  title: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  detail: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 19, color: colors.muted },
});
