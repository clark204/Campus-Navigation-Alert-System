import { router } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import { ResendButton } from './resend-button';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from './ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { EmailState, User } from '@/lib/types';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';

/** "Please wait 42 seconds before asking for a new code." → 42 (EmailCodes::send). */
const waitFrom = (message: string | undefined) => Number(message?.match(/wait (\d+) second/)?.[1] ?? 0);

type Message = { tone: 'success' | 'error'; text: string };

/**
 * Change email, exactly like Settings → My account on the web (/api/account/email): the code goes to the NEW address
 * and the email only changes after it's entered, so a typo can't lock anyone out. Until then the old email still signs
 * in. While a change waits, this screen asks for the code, with Resend and Cancel.
 */
export function ChangeEmailScreen() {
  const { setUser } = useSession();
  const [state, setState] = useState<EmailState | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [notice, setNotice] = useState<Message | null>(null);
  const [busy, setBusy] = useState<'send' | 'confirm' | 'resend' | 'cancel' | null>(null);
  const [resend, setResend] = useState({ key: 0, wait: 0 });
  const toast = useToast();

  const apply = (next: EmailState) => {
    setState(next);
    if (next.pending) {
      const wait = next.pending.resend_in;
      setResend(({ key }) => ({ key: key + 1, wait }));
    }
  };

  // State is only set when the answer comes (the effect below runs it on open).
  const fetchState = useCallback(
    () =>
      api<EmailState>('/account/email').then(
        (next) => {
          setLoadError(null);
          setState(next);
          if (next.pending) {
            const wait = next.pending.resend_in;
            setResend(({ key }) => ({ key: key + 1, wait }));
          }
        },
        (failure) => setLoadError(errorMessage(failure)),
      ),
    [],
  );
  const load = () => {
    setLoadError(null);
    fetchState();
  };

  useEffect(() => {
    fetchState();
  }, [fetchState]);

  /** Errors are on "code" (wrong, expired, wait) or "email" (taken, invalid). */
  const fail = (failure: unknown) => {
    const message = failure instanceof ApiError ? (failure.field('code') ?? failure.field('email') ?? failure.message) : errorMessage(failure);
    const wait = waitFrom(failure instanceof ApiError ? failure.field('code') : undefined);
    if (wait > 0) setResend(({ key }) => ({ key: key + 1, wait }));
    return message;
  };

  const sendCode = async () => {
    setBusy('send');
    setFieldError(null);
    setNotice(null);
    try {
      const result = await api<EmailState & { message: string }>('/account/email', { method: 'PUT', body: { email } });
      apply(result);
      toast(result.message);
      setCode('');
    } catch (failure) {
      setFieldError(fail(failure));
    } finally {
      setBusy(null);
    }
  };

  const confirm = async () => {
    setBusy('confirm');
    setFieldError(null);
    setNotice(null);
    try {
      const result = await api<{ message: string; user: User }>('/account/email/confirm', { method: 'PUT', body: { code } });
      setUser(result.user);
      toast(result.message);
      router.back();
    } catch (failure) {
      setFieldError(fail(failure));
    } finally {
      setBusy(null);
    }
  };

  const sendAgain = async () => {
    setBusy('resend');
    setFieldError(null);
    setNotice(null);
    try {
      const result = await api<EmailState & { message: string }>('/account/email/resend', { method: 'POST' });
      apply(result);
      toast(result.message);
      setCode('');
    } catch (failure) {
      setNotice({ tone: 'error', text: fail(failure) });
      // No change waiting any more (cancelled on the website): back to the email step.
      if (failure instanceof ApiError && failure.field('email')) load();
    } finally {
      setBusy(null);
    }
  };

  const cancel = async () => {
    setBusy('cancel');
    setFieldError(null);
    try {
      const result = await api<EmailState & { message: string }>('/account/email', { method: 'DELETE' });
      setState(result);
      toast(result.message);
      setEmail('');
    } catch (failure) {
      setNotice({ tone: 'error', text: errorMessage(failure) });
    } finally {
      setBusy(null);
    }
  };

  const pending = state?.pending ?? null;
  const verifyingCurrent = pending !== null && pending.email === state?.email;
  const typedSame = state !== null && email.trim().toLowerCase() === state.email && state.email_verified;

  return (
    <Screen>
      <BackLink label="Account" />
      <Title>{pending ? 'Enter the code' : 'Change email'}</Title>

      {state === null && !loadError && <ActivityIndicator color={colors.primary} style={styles.spinner} />}
      {loadError && (
        <>
          <Notice tone="error">{loadError}</Notice>
          <Button title="Try again" variant="quiet" onPress={load} />
        </>
      )}

      {state && (
        <View style={styles.current}>
          <View style={styles.flex}>
            <Text style={type.small}>You sign in with</Text>
            <Text style={styles.currentEmail}>{state.email}</Text>
          </View>
          <View style={[styles.badge, state.email_verified ? styles.badgeOk : styles.badgeWarn]}>
            <Text style={[styles.badgeText, { color: state.email_verified ? colors.safe : colors.alarm }]}>{state.email_verified ? 'Verified' : 'Not verified'}</Text>
          </View>
        </View>
      )}

      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}

      {state && pending && (
        <>
          <Body>
            We sent a 6-digit code to <Text style={styles.strong}>{pending.email}</Text>.{' '}
            {verifyingCurrent ? 'Enter it to verify your email.' : 'Your email changes once you enter it. Until then you still sign in with your old email.'}
          </Body>
          <Body muted>It works for 10 minutes. Check your spam folder if it isn’t there.</Body>
          <Field
            label="Code from the email"
            code
            value={code}
            onChangeText={(text) => setCode(text.replace(/[^\d]/g, '').slice(0, 6))}
            keyboardType="number-pad"
            autoComplete="one-time-code"
            textContentType="oneTimeCode"
            placeholder="000000"
            maxLength={6}
            returnKeyType="done"
            onSubmitEditing={confirm}
            error={fieldError ?? undefined}
          />
          <Button title={verifyingCurrent ? 'Verify email' : 'Change email'} loadingTitle="Checking…" loading={busy === 'confirm'} disabled={busy !== null} onPress={confirm} />
          <ResendButton key={resend.key} waitSeconds={resend.wait} busy={busy === 'resend'} onPress={sendAgain} />
          <Button title={verifyingCurrent ? 'Not now' : 'Cancel the change'} variant="link" center onPress={cancel} />
        </>
      )}

      {state && !pending && (
        <>
          <Body muted>We’ll send a code to the new address. Your email only changes after you enter it, so a typo can’t lock you out.</Body>
          <Field
            label="New email"
            value={email}
            onChangeText={(text) => {
              setEmail(text);
              setFieldError(null);
            }}
            keyboardType="email-address"
            autoComplete="email"
            textContentType="emailAddress"
            autoCapitalize="none"
            placeholder="name@example.com"
            returnKeyType="send"
            onSubmitEditing={sendCode}
            error={fieldError ?? undefined}
          />
          <Button title="Send code" loadingTitle="Sending…" loading={busy === 'send'} disabled={email.trim() === '' || typedSame} onPress={sendCode} />
          {!state.email_verified && (
            <Button
              title="Verify my current email instead"
              variant="link"
              center
              onPress={() => setEmail(state.email)}
            />
          )}
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  spinner: { marginVertical: space.lg },
  current: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  currentEmail: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  badge: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  badgeOk: { backgroundColor: colors.safeTint },
  badgeWarn: { backgroundColor: colors.errorTint },
  badgeText: { fontFamily: fonts.semibold, fontSize: 13 },
  strong: { fontFamily: fonts.semibold },
});
