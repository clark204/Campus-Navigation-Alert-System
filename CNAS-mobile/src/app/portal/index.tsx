import { StyleSheet, Text, View } from 'react-native';

import { AlertBanner } from '@/components/alert-banner';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { MiniMap } from '@/components/mini-map';
import { PushCard } from '@/components/push-card';
import { Button, Screen } from '@/components/ui';
import { openWebsite, WebLinks, type WebLink } from '@/components/web-links';
import { webArea } from '@/lib/roles';
import { useSession } from '@/lib/session';
import { colors, fonts, radius, space, type } from '@/theme';

/** Portal home (students, faculty and staff): greeting, the alert banner, alerts on this phone, emergency numbers. */
export default function PortalHome() {
  const { user, setVerifyLater } = useSession();
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
          <Button title="Open the website" variant="link" onPress={() => openWebsite(`${webArea(user)}/settings`)} />
        </View>
      )}

      {!user.email_verified && (
        <View style={styles.card}>
          <Text style={type.label}>Your email isn’t verified yet</Text>
          <Text style={styles.cardText}>Enter the code we sent to {user.email}.</Text>
          <Button title="Verify email" variant="link" onPress={() => setVerifyLater(false)} />
        </View>
      )}

      <PushCard compact />

      <EmergencyContacts />

      <Text style={type.heading}>On the website</Text>
      <WebLinks links={portalLinks(user.role, webArea(user))} />
    </Screen>
  );
}

/** The web portal pages the app doesn't have yet (students: /student, faculty and staff: /faculty). */
function portalLinks(role: string, area: string): WebLink[] {
  const student = role === 'student';
  return [
    student
      ? { title: 'My schedule', detail: 'Your classes and rooms this term', icon: 'calendar', url: `${area}/schedule` }
      : { title: 'My classes', detail: 'Your classes and rooms this term', icon: 'calendar', url: `${area}/classes` },
    { title: 'Saved places', detail: 'Rooms and buildings you saved on the map', icon: 'star', url: `${area}/saved` },
    { title: 'Help and contact', detail: 'Ask the administrator a question', icon: 'inbox', url: `${area}/help` },
  ];
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingTop: space.md },
  header: { flex: 1, gap: 2, borderLeftWidth: 5, borderLeftColor: colors.secondary, paddingLeft: space.md },
  hello: { fontFamily: fonts.bold, fontSize: 30, lineHeight: 36, color: colors.primary },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space.md, gap: 4 },
  cardText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
});
