import { router } from 'expo-router';
import { useState } from 'react';
import { Text } from 'react-native';

import { ResendButton } from '@/components/resend-button';
import { Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { User } from '@/lib/types';
import { fonts } from '@/theme';

/** "Please wait 42 seconds before asking for a new code." → 42 (EmailCodes::send). */
const waitFrom = (message: string | undefined) => Number(message?.match(/wait (\d+) second/)?.[1] ?? 0);

/**
 * "Verify your email": shown after sign-in while email_verified is false (root layout). The code from the email
 * (POST /api/email/verify), "Resend code" (POST /api/email/resend), or "Skip for now", like the web lets an unverified
 * student use the map and alerts.
 */
export default function VerifyEmail() {
  const { user, setUser, signOut } = useSession();
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [sending, setSending] = useState(false);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const [resend, setResend] = useState({ key: 0, wait: 0 });

  const verify = async () => {
    setBusy(true);
    setCodeError(null);
    setNotice(null);
    try {
      // Answers with the user, email_verified true; the root layout then moves on to Home.
      setUser(await api<User>('/email/verify', { method: 'POST', body: { code } }));
    } catch (failure) {
      if (failure instanceof ApiError && failure.field('code')) setCodeError(failure.field('code')!);
      else setNotice({ tone: 'error', text: errorMessage(failure) });
      setBusy(false);
    }
  };

  const sendAgain = async () => {
    setSending(true);
    setCodeError(null);
    setNotice(null);
    try {
      const result = await api<{ message: string; resend_in: number }>('/email/resend', { method: 'POST' });
      setNotice({ tone: 'success', text: result.message });
      setResend(({ key }) => ({ key: key + 1, wait: result.resend_in }));
      setCode('');
    } catch (failure) {
      const message = failure instanceof ApiError ? failure.field('code') : undefined;
      setNotice({ tone: 'error', text: message ?? errorMessage(failure) });
      const wait = waitFrom(message);
      if (wait > 0) setResend(({ key }) => ({ key: key + 1, wait }));
    } finally {
      setSending(false);
    }
  };

  return (
    <Screen>
      <Title>Verify your email</Title>
      <Body>
        Enter the 6-digit code we sent to <Text style={{ fontFamily: fonts.semibold }}>{user?.email}</Text>.
      </Body>
      <Body muted>One quick step so we know this address is really yours. It works for 10 minutes. Check your spam folder if it isn’t there.</Body>

      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}

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
        onSubmitEditing={verify}
        error={codeError ?? undefined}
      />
      <Button title="Verify email" loadingTitle="Checking…" loading={busy} onPress={verify} />
      <ResendButton key={resend.key} waitSeconds={resend.wait} busy={sending} onPress={sendAgain} />

      <Button title="Skip for now" variant="link" onPress={() => router.replace('/home')} />
      <Body muted>Emergency alerts work while you wait. Wrong email address? Sign in on the CNAS website to fix it.</Body>
      <Button title="Not your account? Sign out" variant="link" onPress={signOut} />
    </Screen>
  );
}
