import { useMemo, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import Svg, { Circle, Polygon } from 'react-native-svg';

import { svgPoints, useCampusMap, type CampusMap } from '@/lib/campus-map';
import { colors, type } from '@/theme';
import { DestinationPin } from '../navigation/map-layers';
import { useMapFrame, ZoomableMap, type MapFrame } from '../zoomable-map';

type Position = [number, number];

type Props = {
  pin: Position | null;
  radius: number | null;
  buildingCodes: string[];
  onTap: (position: Position, map: CampusMap) => void;
  /** Space kept free at the top and bottom (the header and the sheet). */
  top: number;
  bottom: number;
};

/**
 * The incident map of the send form: the zoomable campus map; a tap drops the pin. The danger zone is a red circle and
 * the affected buildings are filled red, like the web picker (alert-picker.js).
 */
export function PinMap({ pin, radius, buildingCodes, onTap, top, bottom }: Props) {
  const { map, loading } = useCampusMap();
  const [size, setSize] = useState<{ width: number; height: number } | null>(null);
  const onLayout = (event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    setSize((current) => (current?.width === width && current?.height === height ? current : { width, height }));
  };

  return (
    <View style={styles.fill} onLayout={onLayout}>
      {map && size ? (
        <Inner map={map} width={size.width} height={size.height} pin={pin} radius={radius} buildingCodes={buildingCodes} onTap={onTap} top={top} bottom={bottom} />
      ) : (
        <View style={styles.center}>
          {loading ? <ActivityIndicator color={colors.primary} size="large" /> : <Text style={type.body}>The map hasn’t loaded. Check your internet connection.</Text>}
        </View>
      )}
    </View>
  );
}

function Inner({ map, width, height, pin, radius, buildingCodes, onTap, top, bottom }: Props & { map: CampusMap; width: number; height: number }) {
  const frame = useMapFrame(map, width, height, top, bottom);
  const [dropKey, setDropKey] = useState(0);
  if (!frame) return null;
  return (
    <ZoomableMap
      map={map}
      frame={frame}
      onTap={(position) => {
        setDropKey((key) => key + 1);
        onTap(position, map);
      }}
      overlay={<Danger map={map} frame={frame} pin={pin} radius={radius} buildingCodes={buildingCodes} />}>
      {pin && <DestinationPin frame={frame} coord={pin} dropKey={dropKey} color={colors.alarm} />}
    </ZoomableMap>
  );
}

function Danger({ map, frame, pin, radius, buildingCodes }: { map: CampusMap; frame: MapFrame; pin: Position | null; radius: number | null; buildingCodes: string[] }) {
  const { projection, viewBox, width, height } = frame;
  const affected = useMemo(() => map.buildings.filter((b) => buildingCodes.includes(b.id)), [map, buildingCodes]);
  const center = pin ? projection.project(pin) : null;
  return (
    <Svg style={StyleSheet.absoluteFill} width={width} height={height} viewBox={viewBox.join(' ')} pointerEvents="none">
      {affected.map((building) => (
        <Polygon key={building.id} points={svgPoints(projection, building.ring)} fill={colors.alarm} stroke={colors.alarmDeep} strokeWidth={0.8} />
      ))}
      {center && radius ? (
        // Projected meters are meters, so the radius needs no scaling.
        <Circle cx={center[0]} cy={center[1]} r={radius} fill="rgba(179,18,27,0.18)" stroke={colors.alarm} strokeWidth={0.9} strokeDasharray="3 2" />
      ) : null}
    </Svg>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: '#d5e8c3' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
});
