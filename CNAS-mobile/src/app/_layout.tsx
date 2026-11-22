import {
  InstrumentSans_400Regular,
  InstrumentSans_500Medium,
  InstrumentSans_600SemiBold,
  InstrumentSans_700Bold,
  useFonts,
} from '@expo-google-fonts/instrument-sans';
import { SplashScreen, Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useState } from 'react';
import { StyleSheet } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AlertOverlays } from '@/components/alert-overlays';
import { AlertsPrompt } from '@/components/alerts-prompt';
import { Intro } from '@/components/intro';
import { AlertsProvider, useAlerts } from '@/lib/alerts';
import { IntroDoneContext } from '@/lib/intro';
import { PushProvider, usePush } from '@/lib/push-context';
import { isAdmin } from '@/lib/roles';
import { SessionProvider, useSession } from '@/lib/session';
import { colors } from '@/theme';

// The intro (components/intro.tsx) hides the native splash on its first frame.
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    InstrumentSans_400Regular,
    InstrumentSans_500Medium,
    InstrumentSans_600SemiBold,
    InstrumentSans_700Bold,
  });
  const fontsReady = fontsLoaded || fontError !== null;

  return (
    <GestureHandlerRootView style={styles.root}>
      <SafeAreaProvider>
        <SessionProvider>
          <AlertsProvider>
            <PushProvider>
              <StatusBar style="dark" />
              <App fontsReady={fontsReady} />
              {/* A Modal, so it covers the screens, the intro and the alerts prompt. Guests get it too. */}
              <AlertOverlays />
            </PushProvider>
          </AlertsProvider>
        </SessionProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

/** The screens, with the intro on top on every cold start while the session and fonts load. */
function App({ fontsReady }: { fontsReady: boolean }) {
  const { ready } = useSession();
  const { takeover } = useAlerts();
  const { needsPrompt } = usePush();
  const [introDone, setIntroDone] = useState(false);
  const finishIntro = useCallback(() => setIntroDone(true), []);
  const loaded = ready && fontsReady;

  return (
    <IntroDoneContext value={introDone}>
      {loaded && <RootNavigator />}
      {/* Once, after the intro: why the app asks to send notifications, then the phone's own question. */}
      {loaded && introDone && needsPrompt && <AlertsPrompt />}
      {!introDone && <Intro ready={loaded} fontsReady={fontsReady} urgent={takeover !== null} onDone={finishIntro} />}
    </IntroDoneContext>
  );
}

/**
 * Which screens exist depends on the session (Expo Router protected routes), like the website's areas:
 * - guests: Welcome, Sign in, Register, Forgot password and the campus map
 * - guests and both signed-in areas: Navigate (walking directions)
 * - signed in with an unverified email: Verify email (until "Skip for now")
 * - the admin: the admin layout (app/admin: Dashboard, Map, Account tabs)
 * - students, faculty and staff: the portal (app/portal: Home, Map, Account tabs)
 *
 * Exactly one of these is open at a time. When the session changes, Expo Router moves to the first screen that is
 * still allowed; with only one area open that is always the right one, so signing in, verifying and signing out need
 * no navigation of their own. (An unguarded screen, like the map used to be, would win that redirect: after signing in
 * people landed on the map with no way back.)
 */
function RootNavigator() {
  const { user, verifyLater } = useSession();
  const mustVerify = user !== null && !user.email_verified && !verifyLater;
  const admin = user !== null && isAdmin(user) && !mustVerify;
  const portal = user !== null && !isAdmin(user) && !mustVerify;

  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Protected guard={user === null}>
        <Stack.Screen name="index" />
        <Stack.Screen name="sign-in" />
        <Stack.Screen name="register" />
        <Stack.Screen name="forgot-password" />
        <Stack.Screen name="map" />
      </Stack.Protected>
      <Stack.Protected guard={mustVerify}>
        <Stack.Screen name="verify-email" />
      </Stack.Protected>
      <Stack.Protected guard={admin}>
        <Stack.Screen name="admin" />
      </Stack.Protected>
      <Stack.Protected guard={portal}>
        <Stack.Screen name="portal" />
      </Stack.Protected>
      {/* Navigation, for guests and both signed-in areas. Listed last, so after a sign-in or sign-out the redirect still
          lands on that area's own first screen, never here. */}
      <Stack.Protected guard={user === null || admin || portal}>
        <Stack.Screen name="navigate" />
      </Stack.Protected>
    </Stack>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
});
