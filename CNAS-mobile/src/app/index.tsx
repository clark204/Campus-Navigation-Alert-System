import { router, useIsFocused } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { EmergencyContacts } from '@/components/emergency-contacts';
import { Icon } from '@/components/icon';
import { Button } from '@/components/ui';
import { Logo } from '@/components/logo';
import { MiniMap } from '@/components/mini-map';
import { PushCard } from '@/components/push-card';
import { TccLogo } from '@/components/tcc-logo';
import { HeroButton, HeroPhoto, PulseRing, Rise } from '@/components/welcome-parts';
import { useIntroDone } from '@/lib/intro';
import { colors, fonts, space } from '@/theme';

// How far the white sheet rides up over the bottom of the photo.
const OVERLAP = 28;

/**
 * Welcome (guests), like the web landing page: the campus photo with the headline and the ways in (explore the map,
 * sign in, create an account), then a sheet with "Turn on alerts" and the emergency numbers.
 */
export default function Welcome() {
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const introDone = useIntroDone();
  const focused = useIsFocused();

  return (
    <View style={styles.root}>
      {/* Light icons on the photo; dark again under the intro and on the screens pushed on top. */}
      <StatusBar style={focused && introDone ? 'light' : 'dark'} />
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>
        {/* Maroon behind the top bounce (iOS), so pulling down never shows white. */}
        <View style={styles.bounce} />

        <View style={[styles.hero, { minHeight: height - 56, paddingTop: insets.top + space.md }]}>
          <HeroPhoto />


          <View style={styles.top}>
            <Rise index={0} play={introDone} style={styles.brand}>
              <View style={styles.logos}>
                <View style={styles.badge}>
                  <TccLogo size={38} />
                </View>
                <View style={styles.badge}>
                  <Logo size={28} />
                </View>
              </View>
              <View style={styles.names}>
                <Text style={styles.school} maxFontSizeMultiplier={1.4}>
                  Tagoloan Community College
                </Text>
                <Text style={styles.system} maxFontSizeMultiplier={1.4}>
                  Campus Navigation & Alert System
                </Text>
              </View>
            </Rise>
            <Rise index={1} play={introDone}>
              <PulseRing />
              <MiniMap />
            </Rise>
          </View>

          <View style={styles.spacer} />

          <Rise index={2} play={introDone} style={styles.eyebrow}>
            <View style={styles.eyebrowRule} />
            <Text style={styles.eyebrowText} maxFontSizeMultiplier={1.4}>
              YOUR CAMPUS. YOUR NEXT CHAPTER.
            </Text>
          </Rise>

          <Rise index={3} play={introDone}>
            <Text style={styles.headline} accessibilityRole="header" maxFontSizeMultiplier={1.25}>
              Find your way{'\n'}
              <Text style={styles.headlineGold}>Around TCC</Text>
            </Text>
          </Rise>

          <Rise index={4} play={introDone}>
            <Text style={styles.lead}>From your first class to your next destination, feel at home at Tagoloan Community College.</Text>
          </Rise>

          <Rise index={5} play={introDone} style={styles.actions}>
            <HeroButton title="Explore the campus" icon="map" variant="gold" onPress={() => router.push('/map')} />
            <View style={styles.pair}>
              <HeroButton title="Sign in" variant="white" style={styles.half} onPress={() => router.push('/sign-in')} />
              <HeroButton title="Create account" variant="outline" style={styles.half} onPress={() => router.push('/register')} />
            </View>
            <Text style={styles.note}>Campus directions are open to everyone. No login needed.</Text>
          </Rise>

          <Rise index={6} play={introDone} style={styles.meta}>
            <View style={styles.metaItem}>
              <Icon name="pin" size={15} color="rgba(255,255,255,0.7)" />
              <Text style={styles.metaText}>Tagoloan, Misamis Oriental</Text>
            </View>
            <Text style={styles.metaText}>For students, faculty & visitors</Text>
          </Rise>
        </View>

        <View style={[styles.sheet, { paddingBottom: insets.bottom + space.xl }]}>
          {/* Every alert stays here: a toast that closed or a swiped-away notification is never lost. */}
          <Button title="Campus alerts" variant="quiet" onPress={() => router.push('/campus-alerts')} />
          <PushCard compact />
          <EmergencyContacts />
        </View>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.primaryHover },
  scroll: { flex: 1, backgroundColor: colors.surface },
  content: { flexGrow: 1 },
  bounce: { position: 'absolute', top: -1000, left: 0, right: 0, height: 1000, backgroundColor: colors.primaryHover },
  hero: { paddingHorizontal: space.lg, paddingBottom: OVERLAP + space.lg, gap: space.md },
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md },
  brand: { flex: 1, gap: 10 },
  logos: { flexDirection: 'row', gap: 6 },
  // White discs, so the maroon CNAS logo and the seal's brown ring stand out on the maroon photo.
  badge: { width: 42, height: 42, borderRadius: 21, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' },
  names: { gap: 1 },
  school: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 19, color: colors.white },
  system: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 17, color: 'rgba(255,255,255,0.72)' },
  spacer: { flexGrow: 1, minHeight: space.md },
  eyebrow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  eyebrowRule: { width: 28, height: 1, backgroundColor: colors.secondary },
  eyebrowText: { flexShrink: 1, fontFamily: fonts.semibold, fontSize: 10.5, lineHeight: 15, letterSpacing: 1.6, color: '#EAD59C' },
  headline: { fontFamily: fonts.semibold, fontSize: 44, lineHeight: 47, letterSpacing: -1.8, color: colors.white },
  headlineGold: { color: '#DFBC59' },
  lead: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 25, color: 'rgba(255,255,255,0.78)', maxWidth: 420 },
  actions: { gap: 10, marginTop: space.sm },
  pair: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  half: { flexGrow: 1, flexShrink: 0, flexBasis: 156 },
  note: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: 'rgba(255,255,255,0.62)', marginTop: 2 },
  meta: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    columnGap: space.lg,
    rowGap: 6,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.25)',
    paddingTop: space.md,
    marginTop: space.xs,
  },
  metaItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  metaText: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: 'rgba(255,255,255,0.72)' },
  sheet: {
    flexGrow: 1,
    marginTop: -OVERLAP,
    backgroundColor: colors.surface,
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    padding: space.lg,
    gap: space.lg,
  },
});
