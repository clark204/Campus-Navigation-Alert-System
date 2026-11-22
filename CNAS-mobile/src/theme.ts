// The CNAS-web colors (resources/css/app.css, Tailwind @theme). Light mode only for now.
export const colors = {
  primary: '#581825',
  primaryHover: '#3D0F18',
  secondary: '#C59B27',
  text: '#212529',
  muted: '#6C757D',
  white: '#FFFFFF',
  bg: '#FAF8F5',
  surface: '#FFFFFF',
  border: '#E5E0DB',
  alarm: '#B3121B',
  alarmDeep: '#7A0A10',
  safe: '#0F6B3A',
  safeTint: '#E3F1E6',
  hazard: '#F2B705',
  // The web banner's warning and advisory colors (Tailwind orange-600, yellow-300, stone-900).
  warning: '#EA580C',
  advisory: '#FDE047',
  advisoryText: '#1C1917',
  error: '#DC2626',
  errorTint: '#FEF2F2',
};

export const fonts = {
  regular: 'InstrumentSans_400Regular',
  medium: 'InstrumentSans_500Medium',
  semibold: 'InstrumentSans_600SemiBold',
  bold: 'InstrumentSans_700Bold',
};

// Large on purpose: the app is read outdoors, often in a hurry.
export const type = {
  title: { fontFamily: fonts.bold, fontSize: 28, lineHeight: 34, color: colors.text },
  heading: { fontFamily: fonts.semibold, fontSize: 20, lineHeight: 26, color: colors.text },
  body: { fontFamily: fonts.regular, fontSize: 17, lineHeight: 25, color: colors.text },
  small: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  label: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 20, color: colors.text },
} as const;

export const space = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32 };
export const radius = { sm: 10, md: 14, lg: 20 };
