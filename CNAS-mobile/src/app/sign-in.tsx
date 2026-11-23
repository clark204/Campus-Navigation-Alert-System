import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import { BackLink, Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';

/** Sign in with an ID number or email (POST /api/login). Signing in moves on by itself (root layout). */
export default function SignIn() {
  const params = useLocalSearchParams<{ login?: string; notice?: string }>();
  const { signIn } = useSession();
  const [login, setLogin] = useState(params.login ?? '');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await signIn(login, password);
    } catch (failure) {
      if (failure instanceof ApiError) setError(failure);
      else setMessage(errorMessage(failure));
    } finally {
      setBusy(false);
    }
  };

  // Wrong ID or password comes back on "password" (LoginService); other problems (429, offline) as one message.
  const general = error && error.status !== 422 ? error.message : message;

  return (
    <Screen>
      <BackLink />
      <Title>Sign in</Title>
      <Body muted>Use your school ID number or email address.</Body>

      {params.notice ? <Notice tone="success">{params.notice}</Notice> : null}
      {general ? <Notice tone="error">{general}</Notice> : null}

      <Field
        label="ID number or email"
        value={login}
        onChangeText={setLogin}
        autoCapitalize="none"
        autoComplete="username"
        textContentType="username"
        placeholder="e.g. 20240001"
        returnKeyType="next"
        error={error?.field('login')}
      />
      <Field
        label="Password"
        password
        value={password}
        onChangeText={setPassword}
        autoCapitalize="none"
        autoComplete="current-password"
        textContentType="password"
        placeholder="Enter your password"
        returnKeyType="go"
        onSubmitEditing={submit}
        error={error?.field('password') ?? error?.field('device_name')}
      />
      <Button title="Forgot password?" variant="link" onPress={() => router.push({ pathname: '/forgot-password', params: { login } })} />

      <Button title="Sign in" loadingTitle="Signing in…" loading={busy} onPress={submit} />
      <Button title="Create an account" variant="link" center onPress={() => router.replace('/register')} />
    </Screen>
  );
}
