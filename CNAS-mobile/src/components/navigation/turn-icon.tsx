import Svg, { Circle, Path } from 'react-native-svg';

import type { Maneuver } from '@/lib/navigation/engine';

// The web's instruction icons (CNAS-web navigation/main.js ICONS), as path data.
const ICONS: Record<string, string[]> = {
  straight: ['M12 20V4M6 10l6-6 6 6'],
  depart: ['M12 20V4M6 10l6-6 6 6'],
  right: ['M6 20v-7a4 4 0 0 1 4-4h9M15 5l4 4-4 4'],
  left: ['M18 20v-7a4 4 0 0 0-4-4H5M9 5 5 9l4 4'],
  'slight right': ['M8 20v-6l8-8M10 6h6v6'],
  'slight left': ['M16 20v-6L8 6M14 6H8v6'],
  uturn: ['M16 20V9a4 4 0 0 0-8 0v6M4 11l4 4 4-4'],
  stairs: ['M3 20h5v-5h5v-5h5V5h3'],
  arrive: ['M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z'],
};

// Turns, and a first instruction that says which way to go, use the direction's arrow (main.js iconFor).
const iconKey = (m: Maneuver) => (m.type === 'turn' || (m.type === 'depart' && m.modifier) ? m.modifier : m.type);

export function TurnIcon({ maneuver, size = 40, color }: { maneuver: Maneuver; size?: number; color: string }) {
  const key = iconKey(maneuver);
  const paths = ICONS[key] ?? ICONS.straight;
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      {paths.map((d) => (
        <Path key={d} d={d} />
      ))}
      {key === 'arrive' && <Circle cx={12} cy={10} r={2.5} />}
    </Svg>
  );
}
