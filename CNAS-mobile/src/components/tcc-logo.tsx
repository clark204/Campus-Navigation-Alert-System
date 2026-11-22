import { Image } from 'expo-image';

// The official Tagoloan Community College seal. assets/images/tcc-seal.png is a 360 px copy of tcc-logo.png (2048 px,
// 3.9 MB): enough for 120 dp at 3x. The seal is square, so width = height keeps its real proportions.
const SEAL = require('../../assets/images/tcc-seal.png');

export function TccLogo({ size }: { size: number }) {
  return (
    <Image
      source={SEAL}
      style={{ width: size, height: size }}
      contentFit="contain"
      accessibilityLabel="Tagoloan Community College seal"
    />
  );
}
