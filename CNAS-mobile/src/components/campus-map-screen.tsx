import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle } from 'react-native-svg';

import { useAlerts } from '@/lib/alerts';
import { incidentPoint, useCampusMap, type CampusMap } from '@/lib/campus-map';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';
import { IncidentDot } from './incident-dot';
import { Anchored, useMapFrame, ZoomableMap } from './zoomable-map';

// Space the bottom card and the back button take, so the campus first fits between them.
const CARD_SPACE = 150;
const TOP_SPACE = 72;

/**
 * The campus map, full screen: pinch to zoom, drag to pan, double-tap to zoom in or back out. Guests get it as its own
 * screen with a Back button (app/map.tsx); signed in, it is the Map tab of the admin and portal layouts (no Back, and
 * the tab bar already keeps clear of the phone's bottom edge). "Where to?" opens navigation (app/navigate.tsx).
 */
export function CampusMapScreen({ withBack = false }: { withBack?: boolean }) {
  const insets = useSafeAreaInsets();
  const bottomInset = withBack ? insets.bottom : 0;
  const topSpace = withBack ? TOP_SPACE : space.md;
  const { map, loading, retry } = useCampusMap();
  const [size, setSize] = useState<{ width: number; height: number } | null>(null);

  const onLayout = (event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    setSize((current) => (current?.width === width && current?.height === height ? current : { width, height }));
  };

  return (
    <View style={styles.screen} onLayout={onLayout}>
      {map && size ? (
        <BrowseMap map={map} width={size.width} height={size.height} top={insets.top + topSpace} bottom={bottomInset + CARD_SPACE} />
      ) : (
        <View style={styles.empty}>
          {loading ? (
            <ActivityIndicator color={colors.primary} size="large" />
          ) : (
            <>
              <Text style={[type.body, styles.center]}>The map hasn’t loaded yet. Check your internet connection.</Text>
              <Pressable onPress={retry} accessibilityRole="button" style={styles.retry}>
                <Text style={styles.retryText}>Try again</Text>
              </Pressable>
            </>
          )}
        </View>
      )}

      {withBack && (
        <Pressable
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
          accessibilityRole="button"
          accessibilityLabel="Back"
          style={({ pressed }) => [styles.back, { top: insets.top + 12 }, pressed && styles.pressed]}>
          <Icon name="back" size={24} color={colors.primary} strokeWidth={2.4} />
          <Text style={styles.backText}>Back</Text>
        </Pressable>
      )}

      <View style={[styles.card, { paddingBottom: bottomInset + space.md }]}>
        <Text style={type.label}>Campus map</Text>
        <Pressable
          onPress={() => router.push('/navigate')}
          accessibilityRole="button"
          accessibilityLabel="Where to? Find a room or building and get walking directions"
          style={({ pressed }) => [styles.whereTo, pressed && styles.whereToPressed]}>
          <Icon name="pin" size={22} color={colors.secondary} strokeWidth={2.2} />
          <Text style={styles.whereToText}>Where to?</Text>
          <Text style={styles.whereToHint}>Rooms, buildings, gates</Text>
        </Pressable>
      </View>
    </View>
  );
}

type BrowseProps = { map: CampusMap; width: number; height: number; top: number; bottom: number };

function BrowseMap({ map, width, height, top, bottom }: BrowseProps) {
  const { banner } = useAlerts();
  const frame = useMapFrame(map, width, height, top, bottom);
  if (!frame) return null;
  const incident = incidentPoint(map, frame.projection, banner);

  return (
    <ZoomableMap
      map={map}
      frame={frame}
      overlay={
        incident?.radius ? (
          <Svg style={StyleSheet.absoluteFill} width={width} height={height} viewBox={frame.viewBox.join(' ')} pointerEvents="none">
            <Circle cx={incident.x} cy={incident.y} r={incident.radius} fill={colors.alarm} fillOpacity={0.16} stroke={colors.alarm} strokeWidth={0.8} />
          </Svg>
        ) : null
      }>
      {incident && (
        <Anchored at={frame.metersToScreen([incident.x, incident.y])}>
          <View style={styles.dotBox}>
            <IncidentDot left={8} top={8} size={14} />
          </View>
        </Anchored>
      )}
    </ZoomableMap>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#d5e8c3', overflow: 'hidden' },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md, paddingBottom: CARD_SPACE },
  center: { textAlign: 'center' },
  retry: { minHeight: 44, justifyContent: 'center' },
  retryText: { fontFamily: fonts.semibold, fontSize: 17, color: colors.primary, textDecorationLine: 'underline' },
  back: {
    position: 'absolute',
    left: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 2,
    minHeight: 48,
    paddingLeft: 8,
    paddingRight: 16,
    borderRadius: 24,
    backgroundColor: colors.surface,
    shadowColor: '#000',
    shadowOpacity: 0.15,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
    elevation: 4,
  },
  backText: { fontFamily: fonts.semibold, fontSize: 17, color: colors.primary },
  pressed: { opacity: 0.8 },
  card: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: space.lg,
    gap: space.sm,
    shadowColor: '#000',
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: -2 },
    elevation: 8,
  },
  whereTo: {
    minHeight: 56,
    borderRadius: 28,
    backgroundColor: colors.primary,
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    paddingHorizontal: space.md + 2,
  },
  whereToPressed: { backgroundColor: colors.primaryHover },
  whereToText: { fontFamily: fonts.bold, fontSize: 18, color: colors.white },
  whereToHint: { flex: 1, textAlign: 'right', fontFamily: fonts.regular, fontSize: 14, color: 'rgba(255,255,255,0.75)' },
  dotBox: { width: 16, height: 16, marginTop: 4 },
});
