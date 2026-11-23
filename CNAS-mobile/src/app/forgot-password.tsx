import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { ResendButton } from '@/components/resend-button';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { colors, fonts, radius, space, type } from '@/theme';

type Step = 'login' | 'code' | 'password';

/**
 * Forgot password, the three API steps (docs/registration.md, "Password recovery"):
 * POST /api/password/forgot {login} → /verify-code {login, code} → {reset_token} → /reset {reset_token, password…}.
 * The answers never say whether an account exists, like the web.
 */
export default function ForgotPassword() {
  const params = useLocalSearchParams<{ login?: string }>();
  const [step, setStep] = useState<Step>('login');
  const [login, setLogin] = useState(params.login ?? '');
  const [code, setCode] = useState('');
  const [resetToken, setResetToken] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);
  const [resend, setResend] = useState({ key: 0, wait: 60 });

  const fail = (failure: unknown) => {
    if (failure instanceof ApiError && failure.status === 422) {
      setError(failure);
    } else {
      setError(null);
      setNotice({ tone: 'error', text: errorMessage(failure) });
    }
  };

  const sendCode = async (again = false) => {
    if (again) setSending(true);
    else setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const result = await api<{ message: string; resend_in: number }>('/password/forgot', { method: 'POST', body: { login: login.trim() } });
      setNotice({ tone: 'info', text: result.message });
      setResend(({ key }) => ({ key: key + 1, wait: result.resend_in }));
      setCode('');
      setStep('code');
    } catch (failure) {
      fail(failure);
    } finally {
      setBusy(false);
      setSending(false);
    }
  };

  const checkCode = async () => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const result = await api<{ reset_token: string; expires_in: number }>('/password/verify-code', {
        method: 'POST',
        body: { login: login.trim(), code },
      });
      setResetToken(result.reset_token);
      setStep('password');
    } catch (failure) {
      fail(failure);
    } finally {
      setBusy(false);
    }
  };

  const save = async () => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const result = await api<{ message: string }>('/password/reset', {
        method: 'POST',
        body: { reset_token: resetToken, password, password_confirmation: confirmation },
      });
      router.replace({ pathname: '/sign-in', params: { login: login.trim(), notice: result.message } });
    } catch (failure) {
      // The grant expired (15 minutes) or was used: back to the code step, like the web.
      if (failure instanceof ApiError && failure.field('reset_token')) {
        setStep('code');
        setCode('');
        setError(null);
        setNotice({ tone: 'error', text: failure.field('reset_token')! });
      } else {
        fail(failure);
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <BackLink onPress={step === 'login' ? undefined : () => setStep(step === 'password' ? 'code' : 'login')} />

      {step === 'login' && (
        <>
          <Title>Forgot your password?</Title>
          <Body muted>Enter the email or student ID you sign in with. We’ll send a 6-digit code to the email on that account.</Body>
          {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
          <Field
            label="ID number or email"
            value={login}
            onChangeText={setLogin}
            autoCapitalize="none"
            autoComplete="username"
            placeholder="e.g. 20240001"
            returnKeyType="send"
            onSubmitEditing={() => sendCode()}
            error={error?.field('login')}
          />
          <Button title="Send code" loadingTitle="Sending…" loading={busy} onPress={() => sendCode()} />
          <NoEmailHelp />
        </>
      )}

      {step === 'code' && (
        <>
          <Text style={styles.step}>Step 1 of 2</Text>
          <Title>Enter your code</Title>
          {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
          <Body muted>If it isn’t in your inbox, look in spam, or send a new one.</Body>
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
            onSubmitEditing={checkCode}
            error={error?.field('code') ?? error?.field('login')}
          />
          <Button title="Continue" loadingTitle="Checking…" loading={busy} onPress={checkCode} />
          <ResendButton key={resend.key} waitSeconds={resend.wait} busy={sending} onPress={() => sendCode(true)} />
          <Button title="Use a different email or ID" variant="link" center onPress={() => setStep('login')} />
          <NoEmailHelp />
        </>
      )}

      {step === 'password' && (
        <>
          <Text style={styles.step}>Step 2 of 2</Text>
          <Title>Choose a new password</Title>
          <Body muted>Your code was right. Choose a password only you know, within 15 minutes.</Body>
          {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
          <Field
            label="New password"
            password
            value={password}
            onChangeText={setPassword}
            autoCapitalize="none"
            autoComplete="new-password"
            textContentType="newPassword"
            hint="At least 8 characters, with letters and numbers."
            error={error?.field('password')}
          />
          <Field
            label="New password again"
            password
            value={confirmation}
            onChangeText={setConfirmation}
            autoCapitalize="none"
            autoComplete="new-password"
            textContentType="newPassword"
            onSubmitEditing={save}
          />
          <Button title="Save new password" loadingTitle="Saving…" loading={busy} onPress={save} />
          <Body muted>You’ll be signed out everywhere, including this app.</Body>
        </>
      )}
    </Screen>
  );
}

/** "Can't access your email?" (web: pages/no-email-access-page). There is no self-service way without email. */
function NoEmailHelp() {
  return (
    <View style={styles.help}>
      <Text style={type.label}>Can’t access your email?</Text>
      <Text style={styles.helpText}>
        Go to the CNAS administrator and bring your school ID. They can change the email on your account and give you a
        temporary password.
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  step: { fontFamily: fonts.semibold, fontSize: 15, color: colors.secondary },
  help: { borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.md, marginTop: space.sm, gap: 4, borderRadius: radius.sm },
  helpText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.muted },
});
