import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { useAlerts } from '@/lib/alerts';
import { affectedCodes, incidentPoint, projectionFor, useCampusMap } from '@/lib/campus-map';
import { colors } from '@/theme';
import { CampusSvg } from './campus-svg';
import { Icon } from './icon';
import { IncidentDot } from './incident-dot';

const SIZE = 68;

/** The round campus mini-map (Welcome and Home, top right). Tap to open the map screen. */
export function MiniMap() {
  const { map } = useCampusMap();
  const { banner } = useAlerts();
  const projection = useMemo(() => (map ? projectionFor(map) : null), [map]);

  // The campus fills the circle: the furthest outline point touches the edge.
  const r = projection ? projection.radius * 1.04 : 1;
  const incident = map && projection ? incidentPoint(map, projection, banner) : null;
  const toPixels = (meters: number) => ((meters + r) / (2 * r)) * SIZE;

  return (
    <Pressable
      onPress={() => router.push('/map')}
      accessibilityRole="button"
      accessibilityLabel={incident ? 'Campus map. An emergency is marked on it.' : 'Campus map'}
      hitSlop={6}
      style={({ pressed }) => [styles.shadow, pressed && styles.pressed]}>
      <View style={styles.circle}>
        {map && projection ? (
          <CampusSvg map={map} projection={projection} viewBox={[-r, -r, 2 * r, 2 * r]} width={SIZE} height={SIZE} affected={affectedCodes(banner)} lineScale={2.2} />
        ) : (
          <View style={styles.empty}>
            <Icon name="pin" size={26} color={colors.primary} />
          </View>
        )}
        {incident && <IncidentDot left={toPixels(incident.x)} top={toPixels(incident.y)} size={9} />}
        <View pointerEvents="none" style={styles.border} />
      </View>
    </Pressable>
  );
}

const FILL = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 } as const;

const styles = StyleSheet.create({
  shadow: {
    width: SIZE,
    height: SIZE,
    borderRadius: SIZE / 2,
    backgroundColor: colors.surface,
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 3 },
    elevation: 5,
  },
  circle: { width: SIZE, height: SIZE, borderRadius: SIZE / 2, overflow: 'hidden' },
  // Drawn on top, so the map under it keeps the full size and the dot lines up.
  border: { ...FILL, borderRadius: SIZE / 2, borderWidth: 2, borderColor: colors.white },
  empty: { flex: 1, backgroundColor: '#d5e8c3', alignItems: 'center', justifyContent: 'center' },
  pressed: { transform: [{ scale: 0.95 }] },
});
