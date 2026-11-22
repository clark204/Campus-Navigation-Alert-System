import Svg, { Path } from 'react-native-svg';

import type { Alert } from '@/lib/types';

// Stroke icons with the same paths as CNAS-web (alert types: App\Enums\AlertType::icon()).
const PATHS = {
  fire: 'M12 3c.4 3.6 5 5.6 5 10.5a5 5 0 0 1-10 0c0-2.3 1.1-4 2.4-5.2.2 2 1.1 3.1 2.3 3.5.3-3.4-1.3-5.8.3-8.8z',
  earthquake: 'M2 12h4l2-5 3 10 3-14 3 13 2-4h3',
  flood:
    'M3 9.5c1.5 0 2.3-1.5 4.5-1.5S9.8 9.5 12 9.5s2.3-1.5 4.5-1.5S18.8 9.5 21 9.5M3 14.5c1.5 0 2.3-1.5 4.5-1.5s2.3 1.5 4.5 1.5 2.3-1.5 4.5-1.5 2.3 1.5 4.5 1.5M3 19.5c1.5 0 2.3-1.5 4.5-1.5s2.3 1.5 4.5 1.5 2.3-1.5 4.5-1.5 2.3 1.5 4.5 1.5',
  typhoon: 'M12 9.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM9.6 11.3C9 7 11.5 4 16.5 3M14.4 12.7C15 17 12.5 20 7.5 21',
  security: 'M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6zM12 8v4.5m0 3.5h.01',
  other: 'M12 3 2 20h20zM12 10v4m0 3h.01',
  check: 'm5 12 5 5 9-10',
  clear: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm-4-9 3 3 5-6',
  pin: 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  phone:
    'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
  close: 'M6 6l12 12M18 6 6 18',
  eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  eyeOff: 'M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.4 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2',
  back: 'M15 18l-6-6 6-6',
  file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5',
  mute: 'M11 5 6 9H3v6h3l5 4zM16 9l5 6M21 9l-5 6',
  mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({ name, size = 24, color, strokeWidth = 2 }: { name: IconName; size?: number; color: string; strokeWidth?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round">
      <Path d={PATHS[name]} />
    </Svg>
  );
}

export const alertIcon = (alert: Alert): IconName => (alert.status === 'resolved' ? 'clear' : alert.type);
