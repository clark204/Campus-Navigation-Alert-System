import { useState } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { ROLE_LABELS, fullName, initials, isAdmin, webArea } from '@/lib/roles';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';
import { PushCard } from './push-card';
import { Detail, Screen, Title } from './ui';
import { WebLinks } from './web-links';

/** The Account tab (admin and portal): who is signed in, alerts on this phone, website settings, and Sign out. */
export function AccountScreen() {
  const { user, signOut, setVerifyLater } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  if (!user) return null;

  const area = webArea(user);

  return (
    <Screen>
      <Title>Account</Title>

      <View style={styles.profile}>
        <View style={styles.avatar}>
          <Text style={styles.initials}>{initials(user)}</Text>
        </View>
        <View style={styles.who}>
          <Text style={styles.name}>{fullName(user)}</Text>
          <View style={[styles.role, isAdmin(user) && styles.roleAdmin]}>
            <Text style={[styles.roleText, isAdmin(user) && styles.roleTextAdmin]}>{ROLE_LABELS[user.role]}</Text>
          </View>
        </View>
      </View>

      <View style={styles.details}>
        <View style={styles.emailRow}>
          <View style={styles.flex}>
            <Detail label="Email" value={user.email} />
          </View>
          <View style={[styles.badge, user.email_verified ? styles.badgeOk : styles.badgeWarn]}>
            <Text style={[styles.badgeText, { color: user.email_verified ? colors.safe : colors.alarm }]}>
              {user.email_verified ? 'Verified' : 'Not verified'}
            </Text>
          </View>
        </View>
        {user.school_id ? <Detail label="ID number" value={user.school_id} /> : null}
        {user.college ? <Detail label="College" value={user.college.name} /> : null}
        {user.program ? <Detail label="Program" value={user.program.name} /> : null}
        {!user.email_verified && (
          <Pressable onPress={() => setVerifyLater(false)} accessibilityRole="button" style={styles.inlineLink}>
            <Text style={styles.inlineLinkText}>Verify your email</Text>
          </Pressable>
        )}
      </View>

      {/* The alerts switch is for phones; the web preview has no push. */}
      {Platform.OS !== 'web' && (
        <>
          <Text style={type.heading}>Alerts</Text>
          <PushCard />
        </>
      )}

      <Text style={type.heading}>On the website</Text>
      <WebLinks
        links={[
          { title: 'Change password or email', detail: 'Settings on the CNAS website', icon: 'lock', url: `${area}/settings` },
          {
            title: isAdmin(user) ? 'Admin console' : 'My portal',
            detail: isAdmin(user) ? 'Alerts, accounts, map data and settings' : 'Schedule, saved places and help',
            icon: isAdmin(user) ? 'shield' : 'home',
            url: area,
          },
        ]}
      />

      <Pressable
        onPress={async () => {
          setSigningOut(true);
          await signOut();
        }}
        disabled={signingOut}
        accessibilityRole="button"
        style={({ pressed }) => [styles.signOut, pressed && styles.signOutPressed]}>
        {signingOut ? <ActivityIndicator color={colors.alarm} /> : <Icon name="logout" size={22} color={colors.alarm} />}
        <Text style={styles.signOutText}>{signingOut ? 'Signing out…' : 'Sign out'}</Text>
      </Pressable>
      <Text style={[type.small, styles.center]}>This phone keeps getting emergency alerts after you sign out.</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  profile: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  avatar: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', borderWidth: 3, borderColor: colors.secondary },
  initials: { fontFamily: fonts.bold, fontSize: 24, color: colors.white, letterSpacing: 1 },
  who: { flex: 1, gap: 6 },
  name: { fontFamily: fonts.bold, fontSize: 21, lineHeight: 26, color: colors.text },
  role: { alignSelf: 'flex-start', backgroundColor: '#F3ECEA', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  roleAdmin: { backgroundColor: colors.primary },
  roleText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.primary },
  roleTextAdmin: { color: colors.white },
  details: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space.md, gap: space.md },
  emailRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  badge: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  badgeOk: { backgroundColor: colors.safeTint },
  badgeWarn: { backgroundColor: colors.errorTint },
  badgeText: { fontFamily: fonts.semibold, fontSize: 13 },
  inlineLink: { minHeight: 40, justifyContent: 'center', alignSelf: 'flex-start' },
  inlineLinkText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.primary, textDecorationLine: 'underline' },
  signOut: {
    marginTop: space.md,
    minHeight: 56,
    borderRadius: radius.md,
    borderWidth: 1.5,
    borderColor: '#F3C4C7',
    backgroundColor: colors.surface,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
  },
  signOutPressed: { backgroundColor: colors.errorTint },
  signOutText: { fontFamily: fonts.bold, fontSize: 17, color: colors.alarm },
  center: { textAlign: 'center' },
});
