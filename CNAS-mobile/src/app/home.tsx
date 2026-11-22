import { router } from 'expo-router';
import { useState } from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';

import { AlertBanner } from '@/components/alert-banner';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { MiniMap } from '@/components/mini-map';
import { Button, Screen } from '@/components/ui';
import { SITE_URL } from '@/lib/config';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';

/** Home (signed in): greeting, the alert banner, emergency numbers and sign out. */
export default function Home() {
  const { user, signOut } = useSession();
  const [signingOut, setSigningOut] = useState(false);
  if (!user) return null;

  const name = user.first_name?.trim() || user.email;

  return (
    <Screen>
      <View style={styles.top}>
        <View style={styles.header}>
          <Text style={styles.hello}>Hi, {name}</Text>
          {user.college ? <Text style={type.body}>{user.college.name}</Text> : null}
          {user.program ? <Text style={type.small}>{user.program.name}</Text> : null}
        </View>
        <MiniMap />
      </View>

      <AlertBanner />

      {user.must_change_password && (
        <View style={styles.card}>
          <Text style={type.label}>Choose a new password</Text>
          <Text style={styles.cardText}>You signed in with a temporary password from the administrator. Change it on the CNAS website.</Text>
          <Button title="Open the website" variant="link" onPress={() => Linking.openURL(`${SITE_URL}/login`)} />
        </View>
      )}

      {!user.email_verified && (
        <View style={styles.card}>
          <Text style={type.label}>Your email isn’t verified yet</Text>
          <Text style={styles.cardText}>Enter the code we sent to {user.email}.</Text>
          <Button title="Verify email" variant="link" onPress={() => router.push('/verify-email')} />
        </View>
      )}

      <EmergencyContacts />

      <View style={styles.footer}>
        <Button
          title="Sign out"
          variant="quiet"
          loading={signingOut}
          loadingTitle="Signing out…"
          onPress={async () => {
            setSigningOut(true);
            await signOut();
          }}
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingTop: space.md },
  header: { flex: 1, gap: 2, borderLeftWidth: 5, borderLeftColor: colors.secondary, paddingLeft: space.md },
  hello: { fontFamily: fonts.bold, fontSize: 30, lineHeight: 36, color: colors.primary },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space.md, gap: 4 },
  cardText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
  footer: { marginTop: space.lg },
});
