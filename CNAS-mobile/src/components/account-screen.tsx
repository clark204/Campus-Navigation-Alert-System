import { router } from 'expo-router';
import { useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { voice } from '@/lib/navigation/voice';
import { ROLE_LABELS, fullName, initials, isAdmin, webArea } from '@/lib/roles';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';
import { MenuList } from './portal-parts';
import { PushCard } from './push-card';
import { TccLogo } from './tcc-logo';
import { Detail, Screen, Title } from './ui';
import { WebLinks } from './web-links';

/** The Account tab (admin and portal): who is signed in, alerts on this phone, voice directions, Change password and
 * Change email, and Sign out. */
export function AccountScreen() {
  const { user, signOut, setVerifyLater } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  if (!user) return null;

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

      <Text style={type.heading}>Directions</Text>
      <VoiceDirectionsCard />

      <Text style={type.heading}>Sign-in</Text>
      <MenuList
        items={[
          { title: 'Change password', detail: 'Signs you out everywhere else', icon: 'lock', onPress: () => router.push(isAdmin(user) ? '/admin/change-password' : '/portal/change-password') },
          { title: 'Change email', detail: 'We send a code to the new address', icon: 'mail', onPress: () => router.push(isAdmin(user) ? '/admin/change-email' : '/portal/change-email') },
        ]}
      />

      {/* The admin console (alerts, accounts, map data) is only on the website. */}
      {isAdmin(user) && (
        <>
          <Text style={type.heading}>On the website</Text>
          <WebLinks links={[{ title: 'Admin console', detail: 'Alerts, accounts, map data and settings', icon: 'shield', url: webArea(user) }]} />
        </>
      )}

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

      <View style={styles.footer}>
        <TccLogo size={56} />
        <Text style={styles.footerName}>Tagoloan Community College</Text>
        <Text style={[type.small, styles.center]}>Campus Navigation & Alert System</Text>
      </View>
    </Screen>
  );
}

/** "Voice directions": spoken turns while walking with Navigate. Off by default; mute while walking is per trip. */
function VoiceDirectionsCard() {
  const enabled = useSyncExternalStore(
    (listener) => voice.subscribe(listener),
    () => voice.enabled,
  );
  return (
    <View style={styles.card}>
      <View style={[styles.cardIcon, { backgroundColor: enabled ? colors.primary : colors.muted }]}>
        <Icon name={enabled ? 'volume' : 'mute'} size={22} color={colors.white} />
      </View>
      <View style={styles.flex}>
        <Text style={type.label}>Voice directions</Text>
        <Text style={styles.cardText}>{enabled ? 'On. Each turn is spoken while you walk. You can still mute a walk.' : 'Off. Directions show on screen only.'}</Text>
      </View>
      <Switch
        value={enabled}
        onValueChange={(value) => voice.setEnabled(value)}
        trackColor={{ true: colors.primary, false: colors.border }}
        accessibilityLabel="Voice directions"
      />
    </View>
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
  card: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  cardIcon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  cardText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  footer: { alignItems: 'center', gap: 4, marginTop: space.lg, paddingTop: space.lg, borderTopWidth: 1, borderTopColor: colors.border },
  footerName: { fontFamily: fonts.semibold, fontSize: 16, lineHeight: 21, color: colors.primary, textAlign: 'center', marginTop: space.xs },
});
