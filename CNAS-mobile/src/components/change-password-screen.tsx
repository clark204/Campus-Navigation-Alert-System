import { router } from 'expo-router';
import { useState } from 'react';

import { BackLink, Body, Button, Field, Notice, Screen, Title } from './ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { User } from '@/lib/types';
import { useToast } from '@/lib/toasts';

type Errors = { current_password?: string; password?: string; general?: string };

/**
 * Change password, like Settings → My account on the web (PUT /api/account/password, same rules and messages): the
 * current password, then the new one twice. Every other device and the website are signed out; this phone stays signed
 * in. Also how a temporary password from the administrator is replaced.
 */
export function ChangePasswordScreen() {
  const { user, setUser } = useSession();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);
  const toast = useToast();

  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const result = await api<{ message: string; user: User }>('/account/password', {
        method: 'PUT',
        body: { current_password: current, password, password_confirmation: confirmation },
      });
      setUser(result.user);
      toast(result.message);
      router.back();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) {
        setErrors({ current_password: failure.field('current_password'), password: failure.field('password') });
      } else {
        setErrors({ general: errorMessage(failure) });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <BackLink label="Account" />
      <Title>Change password</Title>
      <Body muted>
        {user?.must_change_password
          ? 'You signed in with a temporary password from the administrator. Enter it below, then choose your own.'
          : 'You’ll be signed out everywhere else, including the website. This phone stays signed in.'}
      </Body>

      {errors.general ? <Notice tone="error">{errors.general}</Notice> : null}

      <Field
        label={user?.must_change_password ? 'Temporary password' : 'Current password'}
        password
        value={current}
        onChangeText={setCurrent}
        autoComplete="current-password"
        textContentType="password"
        autoCapitalize="none"
        error={errors.current_password}
      />
      <Field
        label="New password"
        password
        value={password}
        onChangeText={setPassword}
        autoComplete="new-password"
        textContentType="newPassword"
        autoCapitalize="none"
        hint="At least 8 characters, with letters and numbers."
        error={errors.password}
      />
      <Field
        label="New password again"
        password
        value={confirmation}
        onChangeText={setConfirmation}
        autoComplete="new-password"
        textContentType="newPassword"
        autoCapitalize="none"
        returnKeyType="done"
        onSubmitEditing={save}
      />
      <Button title="Change password" loadingTitle="Changing…" loading={busy} onPress={save} />
    </Screen>
  );
}
