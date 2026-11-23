import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { GestureDetector, usePanGesture, usePinchGesture, useSimultaneousGestures, useTapGesture } from 'react-native-gesture-handler';
import Animated, { Easing, useAnimatedStyle, useSharedValue, withTiming, type SharedValue } from 'react-native-reanimated';
import { scheduleOnRN } from 'react-native-worklets';

import { useAlerts } from '@/lib/alerts';
import { affectedCodes, buildingLabel, centroid, projectionFor, type CampusMap, type Projection } from '@/lib/campus-map';
import { fonts } from '@/theme';
import { CampusSvg } from './campus-svg';

export const MIN_SCALE = 1;
export const MAX_SCALE = 6;
const SIDE_PADDING = 16;

type Point = { x: number; y: number };
type Position = [number, number];

/** How the campus fits the screen: pixels per meter at zoom 1 and the SVG viewBox (projected meters). */
export type MapFrame = {
  projection: Projection;
  k: number;
  viewBox: [number, number, number, number];
  /** [longitude, latitude] → screen point at zoom 1. */
  toScreen: (position: Position) => Point;
  /** Projected meters → screen point at zoom 1. */
  metersToScreen: (point: [number, number]) => Point;
  width: number;
  height: number;
  top: number;
  bottom: number;
};

/** The campus box in the middle of the free area between `top` and `bottom`. */
export function useMapFrame(map: CampusMap, width: number, height: number, top: number, bottom: number): MapFrame | null {
  const projection = useMemo(() => projectionFor(map), [map]);
  return useMemo(() => {
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
    const metersToScreen = ([x, y]: [number, number]) => ({ x: (x - viewBox[0]) * k, y: (y - viewBox[1]) * k });
    return { projection, k, viewBox, toScreen: (p: Position) => metersToScreen(projection.project(p)), metersToScreen, width, height, top, bottom };
  }, [projection, width, height, top, bottom]);
}

/**
 * Where the camera goes, animated, each time `key` changes:
 * - center: this point (lng/lat) in the middle of the free area, at `pixelsPerMeter` zoom
 * - fit: these points all in view
 */
export type CameraTarget =
  | { key: string | number; center: Position; pixelsPerMeter: number; duration?: number }
  | { key: string | number; fit: Position[]; duration?: number };

type Transform = { scale: SharedValue<number>; tx: SharedValue<number>; ty: SharedValue<number>; half: Point };
const TransformContext = createContext<Transform | null>(null);

type Props = {
  map: CampusMap;
  frame: MapFrame;
  /** Drawn with the campus, in projected meters (viewBox), so it zooms with it: a route line. */
  overlay?: ReactNode;
  /** On top, keeping their size: dots and pins (use <Anchored>). */
  children?: ReactNode;
  camera?: CameraTarget | null;
  /** The person moved the map (drag, pinch or double-tap): stop following the dot. */
  onUserMove?: () => void;
  /** Ground walkways fade while an upper floor is shown. */
  walkwayOpacity?: number;
  /** The part of the screen the camera centers in (between the cards); the frame's by default. */
  viewport?: { top: number; bottom: number };
  /** A single tap on the map, as [longitude, latitude] (the alert form's incident pin). Waits for a double tap to fail. */
  onTap?: (position: Position) => void;
};

/** The campus map: pinch to zoom, drag to pan, double-tap to zoom in or back out, building names. */
export function ZoomableMap({ map, frame, overlay, children, camera, onUserMove, walkwayOpacity = 1, viewport, onTap }: Props) {
  const { banner } = useAlerts();
  const { width, height, projection, viewBox } = frame;
  const scale = useSharedValue(1);
  const tx = useSharedValue(0);
  const ty = useSharedValue(0);
  const start = useSharedValue({ scale: 1, tx: 0, ty: 0, px: 0, py: 0 });
  const half = useMemo(() => ({ x: width / 2, y: height / 2 }), [width, height]);
  const moved = () => onUserMove?.();

  // Keep some of the map on screen however far it is dragged.
  const clamp = (value: number, limit: number) => {
    'worklet';
    return Math.min(limit, Math.max(-limit, value));
  };

  const pinch = usePinchGesture({
    onActivate: (event) => {
      scheduleOnRN(moved);
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
    onActivate: () => {
      scheduleOnRN(moved);
    },
    onUpdate: (event) => {
      tx.set(clamp(tx.get() + event.changeX, half.x * scale.get()));
      ty.set(clamp(ty.get() + event.changeY, half.y * scale.get()));
    },
  });

  const doubleTap = useTapGesture({
    numberOfTaps: 2,
    onActivate: (event) => {
      scheduleOnRN(moved);
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

  // A tap at zoom-1 screen point (x, y): back to projected meters, then to longitude and latitude.
  const tapAt = (x: number, y: number) => onTap?.(projection.unproject([x / frame.k + viewBox[0], y / frame.k + viewBox[1]]));
  const singleTap = useTapGesture({
    numberOfTaps: 1,
    enabled: onTap !== undefined,
    requireToFail: doubleTap,
    onActivate: (event) => {
      scheduleOnRN(tapAt, (event.x - half.x - tx.get()) / scale.get() + half.x, (event.y - half.y - ty.get()) / scale.get() + half.y);
    },
  });

  const gesture = useSimultaneousGestures(pinch, pan, doubleTap, singleTap);

  // The camera: a point of the map (at zoom 1) goes to the middle of the free area, at a zoom.
  const cameraKey = camera?.key;
  useEffect(() => {
    if (!camera) return;
    const freeTop = viewport?.top ?? frame.top;
    const freeBottom = height - (viewport?.bottom ?? frame.bottom);
    const target = { x: width / 2, y: (freeTop + freeBottom) / 2 };
    let point: Point;
    let next: number;
    if ('center' in camera) {
      point = frame.toScreen(camera.center);
      next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, camera.pixelsPerMeter / frame.k));
    } else {
      const points = camera.fit.map(frame.toScreen);
      if (!points.length) return;
      const xs = points.map((p) => p.x);
      const ys = points.map((p) => p.y);
      const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
      point = { x: (minX + maxX) / 2, y: (minY + maxY) / 2 };
      const room = { w: width - SIDE_PADDING * 4, h: Math.max(freeBottom - freeTop - SIDE_PADDING * 2, 80) };
      next = Math.min(MAX_SCALE * 0.8, Math.max(MIN_SCALE, Math.min(room.w / Math.max(maxX - minX, 1), room.h / Math.max(maxY - minY, 1))));
    }
    const timing = { duration: camera.duration ?? 600, easing: Easing.out(Easing.cubic) };
    scale.set(withTiming(next, timing));
    tx.set(withTiming(target.x - half.x - next * (point.x - half.x), timing));
    ty.set(withTiming(target.y - half.y - next * (point.y - half.y), timing));
    // Only a new key moves the camera; the rest of `camera` belongs to it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cameraKey, frame]);

  const mapStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: tx.get() }, { translateY: ty.get() }, { scale: scale.get() }],
  }));

  const labels = useMemo(
    () =>
      placeLabels(
        map.buildings
          .filter((b) => b.type !== 'campus')
          .map((b) => ({ id: b.id, name: buildingLabel(map, b), at: frame.metersToScreen(centroid(projection, b.ring)), area: area(projection, b.ring) })),
      ),
    [map, frame, projection],
  );

  const transform = useMemo(() => ({ scale, tx, ty, half }), [scale, tx, ty, half]);

  return (
    <TransformContext value={transform}>
      <GestureDetector gesture={gesture}>
        <View style={StyleSheet.absoluteFill} collapsable={false}>
          <Animated.View style={[StyleSheet.absoluteFill, mapStyle]}>
            <CampusSvg map={map} projection={projection} viewBox={viewBox} width={width} height={height} affected={affectedCodes(banner)} walkwayOpacity={walkwayOpacity} />
            {overlay}
          </Animated.View>

          {labels.map((label) => (
            <Anchored key={label.id} at={label.at} showAt={label.showAt}>
              <View style={styles.labelBox}>
                <Text style={styles.label} numberOfLines={1}>
                  {label.name}
                </Text>
              </View>
            </Anchored>
          ))}
          {children}
        </View>
      </GestureDetector>
    </TransformContext>
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
function placeLabels(items: { id: string; name: string; at: Point; area: number }[]) {
  const placed: { id: string; name: string; at: Point; width: number; showAt: number }[] = [];
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
  /** A screen point at zoom 1, or shared values for one that moves (the user dot gliding). */
  at?: Point;
  x?: SharedValue<number>;
  y?: SharedValue<number>;
  /** Zoom from which it shows (0 or 1 = always). */
  showAt?: number;
  children: ReactNode;
};

/** Something that follows a map point while zooming but keeps its own size, centered on it: names, dots, pins. */
export function Anchored({ at, x, y, showAt = 0, children }: AnchoredProps) {
  const transform = useContext(TransformContext);
  if (!transform) throw new Error('Anchored must be inside a ZoomableMap');
  const { scale, tx, ty, half } = transform;
  const fixedX = at?.x ?? 0;
  const fixedY = at?.y ?? 0;
  const style = useAnimatedStyle(() => {
    const px = x ? x.get() : fixedX;
    const py = y ? y.get() : fixedY;
    return {
      // Fades in just after its zoom, once it is clear of its neighbours.
      opacity: showAt <= 1 ? 1 : Math.min(1, Math.max(0, (scale.get() - showAt) / (showAt * 0.15))),
      transform: [{ translateX: half.x + tx.get() + scale.get() * (px - half.x) }, { translateY: half.y + ty.get() + scale.get() * (py - half.y) }],
    };
  });
  return (
    <Animated.View pointerEvents="none" style={[styles.anchor, style]}>
      {children}
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  // A zero-size anchor at the screen's top-left, moved by transform; what it holds is centered on it.
  anchor: { position: 'absolute', left: 0, top: 0, width: 0, height: 0, overflow: 'visible', alignItems: 'center', justifyContent: 'center' },
  labelBox: { width: LABEL_MAX_WIDTH, alignItems: 'center' },
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
});
