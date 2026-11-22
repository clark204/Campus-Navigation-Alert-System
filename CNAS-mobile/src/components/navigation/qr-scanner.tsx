import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import { useEffect, useRef, useState } from 'react';
import { Linking, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { Navigation, Place } from '@/lib/navigation/engine';
import { readQr } from '@/lib/navigation/places';
import { colors, fonts, radius, space } from '@/theme';
import { Icon } from '../icon';

const NOT_CNAS_MS = 2500;

type Props = {
  navigation: Navigation;
  visible: boolean;
  /** Why the code is scanned, under the viewfinder. */
  hint: string;
  onClose: () => void;
  onScan: (scan: { from: Place | null; to: Place | null }) => void;
};

/** Scans the CNAS QR codes posted on campus (the web's /navigate?from=<place> links). */
export function QrScanner({ navigation, visible, hint, onClose, onScan }: Props) {
  const insets = useSafeAreaInsets();
  const [permission, requestPermission] = useCameraPermissions();
  const [notCnas, setNotCnas] = useState(false);
  const done = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!visible) return;
    done.current = false;
    if (permission && !permission.granted && permission.canAskAgain) requestPermission();
  }, [visible, permission, requestPermission]);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const scanned = ({ data }: BarcodeScanningResult) => {
    if (done.current) return;
    const scan = readQr(navigation, data);
    if (!scan) {
      setNotCnas(true);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => setNotCnas(false), NOT_CNAS_MS);
      return;
    }
    done.current = true;
    onScan(scan);
  };

  const blocked = permission && !permission.granted;

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.screen}>
        {permission?.granted && (
          <CameraView style={StyleSheet.absoluteFill} facing="back" barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={scanned} />
        )}
        <View style={[styles.top, { paddingTop: insets.top + space.md }]}>
          <Pressable onPress={onClose} accessibilityRole="button" accessibilityLabel="Close the scanner" hitSlop={8} style={styles.close}>
            <Icon name="close" size={26} color={colors.white} strokeWidth={2.4} />
          </Pressable>
          <Text style={styles.title}>Scan a CNAS QR code</Text>
        </View>

        {blocked ? (
          <View style={styles.blocked}>
            <Text style={styles.blockedText}>CNAS needs the camera to read the QR codes posted around campus.</Text>
            <Pressable
              onPress={() => (permission.canAskAgain ? requestPermission() : Linking.openSettings())}
              accessibilityRole="button"
              style={({ pressed }) => [styles.allow, pressed && styles.pressed]}>
              <Text style={styles.allowText}>{permission.canAskAgain ? 'Allow camera' : 'Open settings'}</Text>
            </Pressable>
          </View>
        ) : (
          <View style={styles.frame} pointerEvents="none">
            <View style={[styles.corner, styles.tl]} />
            <View style={[styles.corner, styles.tr]} />
            <View style={[styles.corner, styles.bl]} />
            <View style={[styles.corner, styles.br]} />
          </View>
        )}

        <View style={[styles.bottom, { paddingBottom: insets.bottom + space.lg }]}>
          <Text style={[styles.hint, notCnas && styles.warn]} accessibilityLiveRegion="polite">
            {notCnas ? 'That code isn’t a CNAS place. Look for the QR codes on the CNAS signs.' : hint}
          </Text>
        </View>
      </View>
    </Modal>
  );
}

const CORNER = 34;

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#000', justifyContent: 'space-between' },
  top: { flexDirection: 'row', alignItems: 'center', gap: space.md, paddingHorizontal: space.md, paddingBottom: space.md, backgroundColor: 'rgba(0,0,0,0.45)' },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: fonts.bold, fontSize: 19, color: colors.white },
  frame: { alignSelf: 'center', width: 250, height: 250 },
  corner: { position: 'absolute', width: CORNER, height: CORNER, borderColor: colors.secondary },
  tl: { top: 0, left: 0, borderTopWidth: 5, borderLeftWidth: 5, borderTopLeftRadius: 14 },
  tr: { top: 0, right: 0, borderTopWidth: 5, borderRightWidth: 5, borderTopRightRadius: 14 },
  bl: { bottom: 0, left: 0, borderBottomWidth: 5, borderLeftWidth: 5, borderBottomLeftRadius: 14 },
  br: { bottom: 0, right: 0, borderBottomWidth: 5, borderRightWidth: 5, borderBottomRightRadius: 14 },
  bottom: { paddingHorizontal: space.lg, paddingTop: space.md, backgroundColor: 'rgba(0,0,0,0.45)' },
  hint: { fontFamily: fonts.medium, fontSize: 17, lineHeight: 24, color: colors.white, textAlign: 'center' },
  warn: { color: colors.advisory },
  blocked: { paddingHorizontal: space.xl, gap: space.md, alignItems: 'center' },
  blockedText: { fontFamily: fonts.regular, fontSize: 17, lineHeight: 25, color: colors.white, textAlign: 'center' },
  allow: { minHeight: 52, paddingHorizontal: space.lg, borderRadius: radius.md, backgroundColor: colors.secondary, justifyContent: 'center' },
  allowText: { fontFamily: fonts.bold, fontSize: 17, color: colors.primary },
  pressed: { opacity: 0.85 },
});
