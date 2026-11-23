import { router } from 'expo-router';
import { useState, type ReactNode } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';

/** A scrolling screen that keeps fields above the keyboard. `onRefresh` adds pull to refresh. */
export function Screen({ children, top, onRefresh, refreshing = false }: { children: ReactNode; top?: ReactNode; onRefresh?: () => void; refreshing?: boolean }) {
  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      {top}
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} colors={[colors.primary]} tintColor={colors.primary} /> : undefined}>
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

/** "Back" at the top of a form screen. */
export function BackLink({ label = 'Back', onPress }: { label?: string; onPress?: () => void }) {
  return (
    <Pressable
      onPress={onPress ?? (() => (router.canGoBack() ? router.back() : router.replace('/')))}
      accessibilityRole="button"
      hitSlop={12}
      style={({ pressed }) => [styles.back, pressed && styles.pressed]}>
      <Icon name="back" size={22} color={colors.primary} strokeWidth={2.4} />
      <Text style={styles.backText}>{label}</Text>
    </Pressable>
  );
}

export function Title({ children }: { children: ReactNode }) {
  return (
    <Text style={type.title} accessibilityRole="header">
      {children}
    </Text>
  );
}

export function Body({ children, muted }: { children: ReactNode; muted?: boolean }) {
  return <Text style={[type.body, muted && { color: colors.muted }]}>{children}</Text>;
}

type ButtonProps = {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'quiet' | 'link' | 'danger';
  loading?: boolean;
  loadingTitle?: string;
  disabled?: boolean;
  /** Links only: centered under the screen's main button (the closing "Create an account" kind of link). */
  center?: boolean;
};

export function Button({ title, onPress, variant = 'primary', loading, loadingTitle, disabled, center }: ButtonProps) {
  const off = disabled || loading;
  const textColor = variant === 'primary' || variant === 'danger' ? colors.white : colors.primary;
  return (
    <Pressable
      onPress={onPress}
      disabled={off}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!loading }}
      style={({ pressed }) => [
        variant === 'link' ? styles.link : styles.button,
        variant === 'link' && center && styles.linkCenter,
        variant === 'primary' && styles.primary,
        variant === 'quiet' && styles.quiet,
        variant === 'danger' && styles.danger,
        pressed && (variant === 'primary' ? styles.primaryPressed : variant === 'danger' ? styles.dangerPressed : styles.pressed),
        off && styles.disabled,
      ]}>
      {loading && <ActivityIndicator color={textColor} />}
      <Text style={[variant === 'link' ? styles.linkText : styles.buttonText, { color: textColor }]}>
        {loading && loadingTitle ? loadingTitle : title}
      </Text>
    </Pressable>
  );
}

type FieldProps = TextInputProps & { label: string; error?: string; hint?: string; password?: boolean; code?: boolean };

export function Field({ label, error, hint, password, code, style, ...input }: FieldProps) {
  const [shown, setShown] = useState(false);
  return (
    <View style={styles.field}>
      <Text style={type.label}>{label}</Text>
      <View>
        <TextInput
          placeholderTextColor="#9AA0A6"
          autoCorrect={false}
          {...input}
          secureTextEntry={password && !shown}
          accessibilityLabel={label}
          accessibilityHint={error}
          style={[styles.input, code && styles.code, password && styles.inputWithToggle, !!error && styles.inputError, style]}
        />
        {password && (
          <Pressable
            onPress={() => setShown((value) => !value)}
            accessibilityRole="button"
            accessibilityLabel={shown ? 'Hide password' : 'Show password'}
            style={styles.toggle}
            hitSlop={8}>
            <Icon name={shown ? 'eyeOff' : 'eye'} size={22} color={colors.muted} />
          </Pressable>
        )}
      </View>
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Text style={type.small}>{hint}</Text> : null}
    </View>
  );
}

/** A message box: red for errors, green for success, plain for information. */
export function Notice({ tone = 'info', children }: { tone?: 'error' | 'success' | 'info'; children: ReactNode }) {
  return (
    <View
      accessibilityLiveRegion="polite"
      style={[styles.notice, tone === 'error' && styles.noticeError, tone === 'success' && styles.noticeSuccess]}>
      <Text style={[type.body, { fontSize: 16 }, tone === 'error' && { color: colors.error }, tone === 'success' && { color: colors.safe }]}>
        {children}
      </Text>
    </View>
  );
}

export function Checkbox({ checked, onChange, children, error }: { checked: boolean; onChange: (value: boolean) => void; children: ReactNode; error?: string }) {
  return (
    <View style={styles.field}>
      <Pressable
        onPress={() => onChange(!checked)}
        accessibilityRole="checkbox"
        accessibilityState={{ checked }}
        style={styles.checkRow}>
        <View style={[styles.box, checked && styles.boxOn, !!error && !checked && styles.inputError]}>
          {checked && <Icon name="check" size={18} color={colors.white} strokeWidth={3} />}
        </View>
        <Text style={[type.body, styles.flex, { fontSize: 16, lineHeight: 23 }]}>{children}</Text>
      </Pressable>
      {error && <Text style={styles.error}>{error}</Text>}
    </View>
  );
}

/** A read-only detail (student ID, college…). */
export function Detail({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.detail}>
      <Text style={type.small}>{label}</Text>
      <Text style={[type.body, { fontFamily: fonts.semibold }]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  scroll: { padding: space.lg, paddingBottom: space.xl * 2, gap: space.md },
  back: { flexDirection: 'row', alignItems: 'center', gap: 2, alignSelf: 'flex-start', minHeight: 44, marginLeft: -6 },
  backText: { fontFamily: fonts.semibold, fontSize: 17, color: colors.primary },
  button: {
    minHeight: 56,
    borderRadius: radius.md,
    paddingHorizontal: space.lg,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.sm,
  },
  primary: { backgroundColor: colors.primary },
  primaryPressed: { backgroundColor: colors.primaryHover },
  quiet: { borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface },
  danger: { backgroundColor: colors.alarm },
  dangerPressed: { backgroundColor: colors.alarmDeep },
  pressed: { opacity: 0.7 },
  disabled: { opacity: 0.55 },
  buttonText: { fontFamily: fonts.bold, fontSize: 17 },
  link: { minHeight: 44, justifyContent: 'center', alignSelf: 'flex-start' },
  linkCenter: { alignSelf: 'center' },
  linkText: { fontFamily: fonts.semibold, fontSize: 16, textDecorationLine: 'underline' },
  field: { gap: 6 },
  input: {
    minHeight: 54,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    paddingHorizontal: space.md,
    fontFamily: fonts.regular,
    fontSize: 17,
    color: colors.text,
  },
  inputWithToggle: { paddingRight: 52 },
  code: { fontFamily: fonts.semibold, fontSize: 28, letterSpacing: 10, textAlign: 'center', minHeight: 64 },
  inputError: { borderColor: colors.error },
  toggle: { position: 'absolute', right: 0, top: 0, bottom: 0, width: 52, alignItems: 'center', justifyContent: 'center' },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  notice: { borderRadius: radius.md, padding: space.md, backgroundColor: '#F1ECE6' },
  noticeError: { backgroundColor: colors.errorTint },
  noticeSuccess: { backgroundColor: colors.safeTint },
  checkRow: { flexDirection: 'row', gap: 12, alignItems: 'flex-start', minHeight: 44 },
  box: {
    width: 28,
    height: 28,
    borderRadius: 7,
    borderWidth: 2,
    borderColor: colors.muted,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  boxOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  detail: { gap: 2 },
});
