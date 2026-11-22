import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, {
  Easing,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSequence,
  withTiming,
} from 'react-native-reanimated';
import Svg, { Path, Polyline } from 'react-native-svg';

import { svgPoints, type CampusMap } from '@/lib/campus-map';
import { drawInFraction, GLIDE_MS, partialLines, type LngLat } from '@/lib/navigation/engine';
import { colors } from '@/theme';
import { Anchored, type MapFrame } from '../zoomable-map';

// The web's route colors (CNAS-web navigation/main.js): maroon, green on a route to safety.
export const ROUTE_COLOR = '#581825';
export const SAFE_COLOR = '#0F6B3A';
const DOT_BLUE = '#1a73e8';
// Draw-in steps (motion.js DRAW_STEP_MS): each one redraws the route.
const DRAW_STEP_MS = 100;

/** The route drawing in from its start (motion.js drawInFraction and partialLines); at once with reduce motion. */
function useDrawIn(parts: LngLat[][], key: string | number): LngLat[][] {
  const reduceMotion = useReducedMotion();
  // Starts at nothing drawn for each new key; the timer moves it on.
  const [progress, setProgress] = useState<{ key: string | number; fraction: number }>({ key, fraction: 0 });
  useEffect(() => {
    if (reduceMotion) return;
    const started = Date.now();
    const timer = setInterval(() => {
      const fraction = drawInFraction(Date.now() - started);
      setProgress({ key, fraction });
      if (fraction >= 1) clearInterval(timer);
    }, DRAW_STEP_MS);
    return () => clearInterval(timer);
  }, [key, reduceMotion]);
  const fraction = progress.key === key ? progress.fraction : 0;
  return reduceMotion || fraction >= 1 ? parts : partialLines(parts, fraction);
}

type RouteLayerProps = {
  map: CampusMap;
  frame: MapFrame;
  /** Solid parts: the whole ground route in the preview, the rest of the leg while walking. */
  parts: LngLat[][];
  /** Dashed parts: the upstairs part in the preview. */
  upper?: LngLat[][];
  drawKey: string | number;
  color?: string;
  /** While walking upstairs: that floor's corridors are drawn (ground walkways fade). */
  floor?: number;
};

/** Drawn in projected meters inside the zooming map, so it is as wide as a walkway at every zoom. */
export function RouteLayer({ map, frame, parts, upper = [], drawKey, color = ROUTE_COLOR, floor = 1 }: RouteLayerProps) {
  const shown = useDrawIn(parts, drawKey);
  const { projection, viewBox, width, height } = frame;
  const corridors = floor > 1 ? map.paths.filter((path) => path.floor === floor) : [];
  return (
    <Svg style={StyleSheet.absoluteFill} width={width} height={height} viewBox={viewBox.join(' ')} pointerEvents="none">
      {corridors.map((path) => (
        <Polyline key={path.id} points={svgPoints(projection, path.line)} fill="none" stroke="#FFF7E0" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" />
      ))}
      {upper.map((line, i) => (
        <Polyline key={`u${i}`} points={svgPoints(projection, line)} fill="none" stroke={color} strokeWidth={1.4} strokeDasharray="2 1.6" strokeLinecap="round" strokeLinejoin="round" />
      ))}
      {shown.map((line, i) => (
        <Polyline key={`c${i}`} points={svgPoints(projection, line)} fill="none" stroke="#FFFFFF" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round" />
      ))}
      {shown.map((line, i) => (
        <Polyline key={`r${i}`} points={svgPoints(projection, line)} fill="none" stroke={color} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" />
      ))}
    </Svg>
  );
}

const DOT = 20;

/**
 * You are here: a blue dot that glides between fixes (motion.js GLIDE_MS, ease-out), with a soft pulse, and a cone
 * the way the person is going when that is known (GPS movement or the start door; never a compass). With reduce
 * motion the dot jumps and does not pulse.
 */
export function UserDot({ frame, coord, heading }: { frame: MapFrame; coord: LngLat; heading: number | null }) {
  const reduceMotion = useReducedMotion();
  const point = frame.toScreen(coord);
  const x = useSharedValue(point.x);
  const y = useSharedValue(point.y);
  const pulse = useSharedValue(0);

  useEffect(() => {
    const timing = { duration: GLIDE_MS, easing: Easing.out(Easing.cubic) };
    x.set(reduceMotion ? point.x : withTiming(point.x, timing));
    y.set(reduceMotion ? point.y : withTiming(point.y, timing));
  }, [point.x, point.y, reduceMotion, x, y]);

  useEffect(() => {
    pulse.set(reduceMotion ? 0 : withRepeat(withTiming(1, { duration: 1800, easing: Easing.out(Easing.quad) }), -1, false));
  }, [reduceMotion, pulse]);

  const pulseStyle = useAnimatedStyle(() => ({
    opacity: 0.45 * (1 - pulse.get()),
    transform: [{ scale: 1 + pulse.get() * 1.6 }],
  }));

  return (
    <Anchored x={x} y={y}>
      <View style={styles.dotBox}>
        {heading !== null && (
          <View style={[styles.coneBox, { transform: [{ rotate: `${heading}deg` }] }]}>
            <Svg width={44} height={44} viewBox="0 0 44 44">
              <Path d="M22 22 L10 2 Q22 -2 34 2 Z" fill={DOT_BLUE} fillOpacity={0.32} />
            </Svg>
          </View>
        )}
        <Animated.View style={[styles.pulse, pulseStyle]} />
        <View style={styles.dot} />
      </View>
    </Anchored>
  );
}

const PIN_W = 36;
const PIN_H = 44;

/** The destination pin (on the stairs first for an upstairs room), dropping in each time it moves. */
export function DestinationPin({ frame, coord, dropKey, color = colors.secondary }: { frame: MapFrame; coord: LngLat; dropKey: number; color?: string }) {
  const reduceMotion = useReducedMotion();
  const drop = useSharedValue(1);
  useEffect(() => {
    if (reduceMotion) return;
    drop.set(0);
    drop.set(withSequence(withTiming(1, { duration: 420, easing: Easing.out(Easing.back(2)) })));
  }, [dropKey, reduceMotion, drop]);
  const style = useAnimatedStyle(() => ({
    opacity: Math.min(1, drop.get() * 2),
    transform: [{ translateY: -PIN_H / 2 - (1 - drop.get()) * 28 }],
  }));
  return (
    <Anchored at={frame.toScreen(coord)}>
      <Animated.View style={[styles.pin, style]}>
        <Svg width={PIN_W} height={PIN_H} viewBox="0 0 24 29">
          <Path d="M12 28s-9-8.2-9-15.5a9 9 0 0 1 18 0C21 19.8 12 28 12 28z" fill={color} stroke={ROUTE_COLOR} strokeWidth={1.6} />
          <Path d="M12 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z" fill={ROUTE_COLOR} />
        </Svg>
      </Animated.View>
    </Anchored>
  );
}

/** Where the route starts, in the preview. */
export function StartDot({ frame, coord }: { frame: MapFrame; coord: LngLat }) {
  return (
    <Anchored at={frame.toScreen(coord)}>
      <View style={styles.start} />
    </Anchored>
  );
}

const styles = StyleSheet.create({
  dotBox: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  // The cone's tip is the box's middle (the dot), so rotating the box turns it around the dot.
  coneBox: { position: 'absolute', width: 44, height: 44, top: 0, left: 0 },
  pulse: { position: 'absolute', width: DOT, height: DOT, borderRadius: DOT / 2, backgroundColor: DOT_BLUE },
  dot: {
    width: DOT,
    height: DOT,
    borderRadius: DOT / 2,
    backgroundColor: DOT_BLUE,
    borderWidth: 3,
    borderColor: colors.white,
    shadowColor: '#000',
    shadowOpacity: 0.3,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
    elevation: 4,
  },
  pin: { width: PIN_W, height: PIN_H },
  start: { width: 18, height: 18, borderRadius: 9, borderWidth: 4, borderColor: ROUTE_COLOR, backgroundColor: colors.white },
});
