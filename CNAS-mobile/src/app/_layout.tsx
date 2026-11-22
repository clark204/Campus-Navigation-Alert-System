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
import { Intro } from '@/components/intro';
import { AlertsProvider, useAlerts } from '@/lib/alerts';
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
            <StatusBar style="dark" />
            <App fontsReady={fontsReady} />
            {/* A Modal, so it covers the screens and the intro. Guests get it too. */}
            <AlertOverlays />
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
  const [introDone, setIntroDone] = useState(false);
  const finishIntro = useCallback(() => setIntroDone(true), []);
  const loaded = ready && fontsReady;

  return (
    <>
      {loaded && <RootNavigator />}
      {!introDone && <Intro ready={loaded} fontsReady={fontsReady} urgent={takeover !== null} onDone={finishIntro} />}
    </>
  );
}

/**
 * Which screens exist depends on the session (Expo Router protected routes): guests get Welcome, Sign in, Register
 * and Forgot password. Signed in, Verify email comes first while the email is unverified ("Skip for now" goes on to
 * Home), then Home. When the session changes, Expo Router moves to the first screen that is allowed, so signing in,
 * verifying and signing out need no navigation of their own. The campus map is open to everyone.
 */
function RootNavigator() {
  const { user } = useSession();
  const unverified = user !== null && !user.email_verified;

  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Protected guard={user === null}>
        <Stack.Screen name="index" />
        <Stack.Screen name="sign-in" />
        <Stack.Screen name="register" />
        <Stack.Screen name="forgot-password" />
      </Stack.Protected>
      <Stack.Protected guard={unverified}>
        <Stack.Screen name="verify-email" />
      </Stack.Protected>
      <Stack.Protected guard={user !== null}>
        <Stack.Screen name="home" />
      </Stack.Protected>
      <Stack.Screen name="map" />
    </Stack>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
});
