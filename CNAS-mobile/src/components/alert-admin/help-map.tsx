import { useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import Svg, { Circle, G, Polygon } from 'react-native-svg';

import type { HelpRequest } from '@/lib/admin';
import { svgPoints, useCampusMap, type CampusMap } from '@/lib/campus-map';
import { colors, type } from '@/theme';
import { DestinationPin } from '../navigation/map-layers';
import { useMapFrame, ZoomableMap, type MapFrame } from '../zoomable-map';

type Position = [number, number];

/** Same colours as the web help map (CNAS-web resources/js/alerts/help-map.js). */
export const HELP_COLORS = { new: colors.alarm, responding: '#F59E0B', helped: colors.safe } as const;

type Props = {
  incident: Position | null;
  radius: number | null;
  buildingCodes: string[];
  requests: HelpRequest[];
};

/**
 * Who asked for help, on the zoomable campus map (the "Who is safe" screen): the incident pin and danger zone, and a dot per
 * open request (red waiting, amber on the way, faded once its position is over a minute old) with a faint accuracy circle.
 */
export function HelpMap(props: Props) {
  const { map, loading } = useCampusMap();
  const [size, setSize] = useState<{ width: number; height: number } | null>(null);
  const onLayout = (event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    setSize((current) => (current?.width === width && current?.height === height ? current : { width, height }));
  };

  return (
    <View style={styles.frame} onLayout={onLayout} accessibilityLabel="Map of who asked for help">
      {map && size ? (
        <Inner map={map} width={size.width} height={size.height} {...props} />
      ) : (
        <View style={styles.center}>
          {loading ? <ActivityIndicator color={colors.primary} /> : <Text style={type.small}>The map hasn’t loaded. The list below still shows each request.</Text>}
        </View>
      )}
    </View>
  );
}

function Inner({ map, width, height, incident, radius, buildingCodes, requests }: Props & { map: CampusMap; width: number; height: number }) {
  const frame = useMapFrame(map, width, height, 0, 0);
  if (!frame) return null;
  return (
    <ZoomableMap map={map} frame={frame} onTap={() => {}} overlay={<Overlay map={map} frame={frame} incident={incident} radius={radius} buildingCodes={buildingCodes} requests={requests} />}>
      {incident && <DestinationPin frame={frame} coord={incident} dropKey={0} color={colors.alarm} />}
    </ZoomableMap>
  );
}

function Overlay({ map, frame, incident, radius, buildingCodes, requests }: Props & { map: CampusMap; frame: MapFrame }) {
  const { projection, viewBox, width, height } = frame;
  const affected = map.buildings.filter((b) => buildingCodes.includes(b.id));
  const center = incident ? projection.project(incident) : null;
  const shown = requests.filter((r) => r.status !== 'helped' && r.latitude !== null && r.longitude !== null);
  return (
    <Svg style={StyleSheet.absoluteFill} width={width} height={height} viewBox={viewBox.join(' ')} pointerEvents="none">
      {affected.map((building) => (
        <Polygon key={building.id} points={svgPoints(projection, building.ring)} fill={colors.alarm} fillOpacity={0.85} stroke={colors.alarmDeep} strokeWidth={0.8} />
      ))}
      {center && radius ? <Circle cx={center[0]} cy={center[1]} r={radius} fill="rgba(179,18,27,0.16)" stroke={colors.alarm} strokeWidth={0.9} strokeDasharray="3 2" /> : null}
      {shown.map((request) => {
        const [x, y] = projection.project([request.longitude!, request.latitude!]);
        const color = HELP_COLORS[request.status];
        const opacity = isStale(request) ? 0.5 : 1;
        // Projected meters are meters: the accuracy circle needs no scaling (capped so a bad fix doesn't cover campus).
        return (
          <G key={request.id}>
            {request.accuracy_m && request.accuracy_m > 5 ? (
              <Circle cx={x} cy={y} r={Math.min(request.accuracy_m, 300)} fill={color} fillOpacity={0.12} stroke={color} strokeOpacity={0.5} strokeWidth={0.5} />
            ) : null}
            <Circle cx={x} cy={y} r={4.5} fill={color} stroke={colors.white} strokeWidth={1.5} opacity={opacity} />
          </G>
        );
      })}
    </Svg>
  );
}

/** A position older than a minute (SafetyCheck::LOCATION_FRESH_SECONDS). */
export function isStale(request: HelpRequest, now = Date.now()): boolean {
  return request.located_at === null || now - new Date(request.located_at).getTime() > 60 * 1000;
}

const styles = StyleSheet.create({
  frame: { height: 260, borderRadius: 16, overflow: 'hidden', backgroundColor: '#d5e8c3', borderWidth: 1, borderColor: colors.border },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 16 },
});
