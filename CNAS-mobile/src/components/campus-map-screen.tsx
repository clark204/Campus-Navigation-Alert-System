import { router } from 'expo-router';
import { useMemo, useState, type ReactNode } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import { GestureDetector, usePanGesture, usePinchGesture, useSimultaneousGestures, useTapGesture } from 'react-native-gesture-handler';
import Animated, { useAnimatedStyle, useSharedValue, withTiming, type SharedValue } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle } from 'react-native-svg';

import { useAlerts } from '@/lib/alerts';
import {
  affectedCodes,
  buildingLabel,
  centroid,
  incidentPoint,
  projectionFor,
  useCampusMap,
  type CampusMap,
  type Projection,
} from '@/lib/campus-map';
import { SITE_URL } from '@/lib/config';
import { colors, fonts, radius, space, type } from '@/theme';
import { CampusSvg } from './campus-svg';
import { Icon } from './icon';
import { IncidentDot } from './incident-dot';

const MIN_SCALE = 1;
const MAX_SCALE = 6;
// Space the bottom card and the back button take, so the campus first fits between them.
const CARD_SPACE = 190;
const TOP_SPACE = 72;
const SIDE_PADDING = 16;

/**
 * The campus map, full screen: pinch to zoom, drag to pan, double-tap to zoom in or back out. Guests get it as its own
 * screen with a Back button (app/map.tsx); signed in, it is the Map tab of the admin and portal layouts (no Back, and
 * the tab bar already keeps clear of the phone's bottom edge).
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
        <ZoomableMap map={map} width={size.width} height={size.height} top={insets.top + topSpace} bottom={bottomInset + CARD_SPACE} />
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
        <Text style={styles.cardText}>Navigation is coming soon. For now, use Navigate on the CNAS website.</Text>
        <Pressable
          onPress={() => Linking.openURL(`${SITE_URL}/navigate`)}
          accessibilityRole="link"
          style={({ pressed }) => [styles.open, pressed && styles.openPressed]}>
          <Text style={styles.openText}>Open Navigate on the website</Text>
        </Pressable>
      </View>
    </View>
  );
}

type ZoomableProps = { map: CampusMap; width: number; height: number; top: number; bottom: number };

function ZoomableMap({ map, width, height, top, bottom }: ZoomableProps) {
  const { banner } = useAlerts();
  const projection = useMemo(() => projectionFor(map), [map]);

  // Pixels per meter, and the viewBox that puts the campus box in the middle of the free area.
  const fit = useMemo(() => {
    if (!projection) return null;
    const { box } = projection;
    const boxW = Math.max(box.maxX - box.minX, 1);
    const boxH = Math.max(box.maxY - box.minY, 1);
    const areaW = width - SIDE_PADDING * 2;
    const areaH = Math.max(height - top - bottom, 120);
    const k = Math.min(areaW / boxW, areaH / boxH);
    const left = SIDE_PADDING + (areaW - boxW * k) / 2;
    const topPx = top + (areaH - boxH * k) / 2;
    const viewBox: [number, number, number, number] = [box.minX - left / k, box.minY - topPx / k, width / k, height / k];
    return { k, viewBox };
  }, [projection, width, height, top, bottom]);

  const scale = useSharedValue(1);
  const tx = useSharedValue(0);
  const ty = useSharedValue(0);
  const start = useSharedValue({ scale: 1, tx: 0, ty: 0, px: 0, py: 0 });
  const half = { x: width / 2, y: height / 2 };

  // Keep some of the map on screen however far it is dragged.
  const clamp = (value: number, limit: number) => {
    'worklet';
    return Math.min(limit, Math.max(-limit, value));
  };

  const pinch = usePinchGesture({
    onActivate: (event) => {
      // The map point under the fingers stays under them while zooming.
      start.set({
        scale: scale.get(),
        tx: tx.get(),
        ty: ty.get(),
        px: (event.focalX - half.x - tx.get()) / scale.get(),
        py: (event.focalY - half.y - ty.get()) / scale.get(),
      });
    },
    onUpdate: (event) => {
      const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, start.get().scale * event.scale));
      scale.set(next);
      tx.set(clamp(event.focalX - half.x - next * start.get().px, half.x * next));
      ty.set(clamp(event.focalY - half.y - next * start.get().py, half.y * next));
    },
  });

  const pan = usePanGesture({
    maxPointers: 1,
    onUpdate: (event) => {
      tx.set(clamp(tx.get() + event.changeX, half.x * scale.get()));
      ty.set(clamp(ty.get() + event.changeY, half.y * scale.get()));
    },
  });

  const doubleTap = useTapGesture({
    numberOfTaps: 2,
    onActivate: (event) => {
      if (scale.get() > 1.5) {
        scale.set(withTiming(1));
        tx.set(withTiming(0));
        ty.set(withTiming(0));
        return;
      }
      const next = 2.5;
      const px = (event.x - half.x - tx.get()) / scale.get();
      const py = (event.y - half.y - ty.get()) / scale.get();
      scale.set(withTiming(next));
      tx.set(withTiming(clamp(event.x - half.x - next * px, half.x * next)));
      ty.set(withTiming(clamp(event.y - half.y - next * py, half.y * next)));
    },
  });

  const gesture = useSimultaneousGestures(pinch, pan, doubleTap);

  const mapStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: tx.get() }, { translateY: ty.get() }, { scale: scale.get() }],
  }));

  if (!projection || !fit) return null;

  const toScreen = ([x, y]: [number, number]) => ({ x: (x - fit.viewBox[0]) * fit.k, y: (y - fit.viewBox[1]) * fit.k });
  const incident = incidentPoint(map, projection, banner);
  const labels = placeLabels(
    map.buildings
      .filter((b) => b.type !== 'campus')
      .map((b) => ({ id: b.id, name: buildingLabel(map, b), at: toScreen(centroid(projection, b.ring)), area: area(projection, b.ring) })),
  );

  return (
    <GestureDetector gesture={gesture}>
      <View style={StyleSheet.absoluteFill} collapsable={false}>
        <Animated.View style={[StyleSheet.absoluteFill, mapStyle]}>
          <CampusSvg map={map} projection={projection} viewBox={fit.viewBox} width={width} height={height} affected={affectedCodes(banner)} />
          {incident?.radius ? (
            <Svg style={StyleSheet.absoluteFill} width={width} height={height} viewBox={fit.viewBox.join(' ')} pointerEvents="none">
              <Circle cx={incident.x} cy={incident.y} r={incident.radius} fill={colors.alarm} fillOpacity={0.16} stroke={colors.alarm} strokeWidth={0.8} />
            </Svg>
          ) : null}
        </Animated.View>

        {labels.map((label) => (
          <Anchored key={label.id} at={label.at} half={half} scale={scale} tx={tx} ty={ty} showAt={label.showAt}>
            <Text style={styles.label} numberOfLines={1}>
              {label.name}
            </Text>
          </Anchored>
        ))}
        {incident && (
          <Anchored at={toScreen([incident.x, incident.y])} half={half} scale={scale} tx={tx} ty={ty} showAt={0}>
            <View style={styles.dotBox}>
              <IncidentDot left={8} top={8} size={14} />
            </View>
          </Anchored>
        )}
      </View>
    </GestureDetector>
  );
}

const LABEL_HEIGHT = 16;
const LABEL_MAX_WIDTH = 120;
const labelWidth = (name: string) => Math.min(name.length * 6.6 + 6, LABEL_MAX_WIDTH);

/** Square meters of an outline (shoelace), to give bigger buildings their name first. */
function area(projection: Projection, ring: [number, number][]): number {
  const points = ring.map(projection.project);
  let sum = 0;
  for (let i = 0; i < points.length - 1; i++) sum += points[i][0] * points[i + 1][1] - points[i + 1][0] * points[i][1];
  return Math.abs(sum / 2);
}

/**
 * The zoom from which each name shows, so names never sit on top of each other: bigger buildings first, and a name
 * waits until the zoom pulls it clear of every name already showing at that zoom.
 */
function placeLabels(items: { id: string; name: string; at: { x: number; y: number }; area: number }[]) {
  const placed: { id: string; name: string; at: { x: number; y: number }; width: number; showAt: number }[] = [];
  for (const item of [...items].sort((a, b) => b.area - a.area)) {
    const width = labelWidth(item.name);
    let showAt = 1;
    for (const other of placed) {
      const dx = Math.abs(item.at.x - other.at.x);
      const dy = Math.abs(item.at.y - other.at.y);
      // At zoom s the two centers are s × (dx, dy) apart; they stop overlapping once either gap is wide enough.
      const separateAt = Math.min(dx > 0 ? (width + other.width) / 2 / dx : Infinity, dy > 0 ? LABEL_HEIGHT / dy : Infinity);
      if (other.showAt < separateAt) showAt = Math.max(showAt, separateAt);
    }
    placed.push({ ...item, width, showAt: Math.min(showAt, MAX_SCALE) });
  }
  return placed;
}

type AnchoredProps = {
  at: { x: number; y: number };
  half: { x: number; y: number };
  scale: SharedValue<number>;
  tx: SharedValue<number>;
  ty: SharedValue<number>;
  /** Zoom from which it shows (0 = always). */
  showAt: number;
  children: ReactNode;
};

/** Something that follows a map point while zooming but keeps its own size (names, the incident dot). */
function Anchored({ at, half, scale, tx, ty, showAt, children }: AnchoredProps) {
  const style = useAnimatedStyle(() => ({
    // Fades in just after its zoom, once it is clear of its neighbours.
    opacity: showAt <= 1 ? 1 : Math.min(1, Math.max(0, (scale.get() - showAt) / (showAt * 0.15))),
    transform: [
      { translateX: half.x + tx.get() + scale.get() * (at.x - half.x) },
      { translateY: half.y + ty.get() + scale.get() * (at.y - half.y) },
    ],
  }));
  return (
    <Animated.View pointerEvents="none" style={[styles.anchor, style]}>
      <View style={styles.anchorInner}>{children}</View>
    </Animated.View>
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
  cardText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
  open: { minHeight: 52, borderRadius: radius.md, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', marginTop: 4 },
  openPressed: { backgroundColor: colors.primaryHover },
  openText: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
  // A zero-size anchor at the screen's top-left, moved by transform; the label is centered on it.
  anchor: { position: 'absolute', left: 0, top: 0, width: 0, height: 0, overflow: 'visible' },
  anchorInner: { position: 'absolute', width: LABEL_MAX_WIDTH, left: -LABEL_MAX_WIDTH / 2, top: -12, alignItems: 'center' },
  label: {
    fontFamily: fonts.semibold,
    fontSize: 12,
    lineHeight: 15,
    color: '#1C1917',
    textAlign: 'center',
    textShadowColor: 'rgba(255,255,255,0.95)',
    textShadowRadius: 3,
    textShadowOffset: { width: 0, height: 0 },
  },
  dotBox: { width: 16, height: 16, marginTop: 4 },
});
