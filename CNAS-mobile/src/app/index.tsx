import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

import { AlertBanner } from '@/components/alert-banner';
import { EmergencyContacts } from '@/components/emergency-contacts';
import { MiniMap } from '@/components/mini-map';
import { Button, Screen } from '@/components/ui';
import { colors, fonts, space, type } from '@/theme';

/** Welcome (guests): sign in, create an account, and the emergency numbers. */
export default function Welcome() {
  return (
    <Screen>
      <View style={styles.top}>
        <View style={styles.brand}>
          <View style={styles.rule} />
          <Text style={styles.wordmark}>CNAS</Text>
          <Text style={styles.school}>Tagoloan Community College</Text>
        </View>
        <MiniMap />
      </View>
      <Text style={[type.body, styles.lead]}>Campus navigation and emergency alerts for TCC students.</Text>

      <AlertBanner />

      <View style={styles.actions}>
        <Button title="Sign in" onPress={() => router.push('/sign-in')} />
        <Button title="Create account" variant="quiet" onPress={() => router.push('/register')} />
      </View>

      <EmergencyContacts />
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingTop: space.lg },
  brand: { flex: 1, gap: 2 },
  rule: { width: 44, height: 5, borderRadius: 3, backgroundColor: colors.secondary, marginBottom: space.md },
  wordmark: { fontFamily: fonts.bold, fontSize: 52, lineHeight: 56, letterSpacing: 1, color: colors.primary },
  school: { fontFamily: fonts.semibold, fontSize: 18, color: colors.text },
  lead: { color: colors.muted, marginBottom: space.sm },
  actions: { gap: space.sm, marginVertical: space.sm },
});
