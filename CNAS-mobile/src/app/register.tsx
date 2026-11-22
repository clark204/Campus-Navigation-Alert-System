import * as DocumentPicker from 'expo-document-picker';
import { router } from 'expo-router';
import { useState } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/icon';
import { BackLink, Body, Button, Checkbox, Detail, Field, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { CorDraft } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

// Same texts and limits as the web registration (resources/views/pages/register-page, RegistrationService).
const FILE_MESSAGE = 'Please upload your COR as a PDF file (up to 5 MB).';
const CONSENT_MESSAGE = 'Please agree to the data privacy notice to continue.';
const MAX_BYTES = 5 * 1024 * 1024;
const SIGN_IN_RETENTION_DAYS = 90;

type Picked = DocumentPicker.DocumentPickerAsset;

/**
 * Create account: step 1 uploads the COR with the privacy consent (POST /api/register/cor), step 2 confirms the
 * details and sets the email and password (POST /api/register), then signs in.
 */
export default function Register() {
  const [draft, setDraft] = useState<CorDraft | null>(null);
  const [restartMessage, setRestartMessage] = useState<string | null>(null);

  if (!draft) {
    return (
      <CorStep
        restartMessage={restartMessage}
        onUploaded={(next) => {
          setRestartMessage(null);
          setDraft(next);
        }}
      />
    );
  }

  return (
    <ConfirmStep
      draft={draft}
      onRestart={(message) => {
        setRestartMessage(message);
        setDraft(null);
      }}
    />
  );
}

function CorStep({ restartMessage, onUploaded }: { restartMessage: string | null; onUploaded: (draft: CorDraft) => void }) {
  const [consent, setConsent] = useState(false);
  const [file, setFile] = useState<Picked | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<{ cor?: string; consent?: string; general?: string }>({});

  const pick = async () => {
    const result = await DocumentPicker.getDocumentAsync({ type: 'application/pdf', copyToCacheDirectory: true });
    if (result.canceled) return;
    const asset = result.assets[0];
    setFile(asset);
    setErrors((current) => ({ ...current, cor: asset.size && asset.size > MAX_BYTES ? FILE_MESSAGE : undefined }));
  };

  const upload = async () => {
    // Checked here first so a PDF isn't sent for nothing; the server checks the same things.
    const local = {
      consent: consent ? undefined : CONSENT_MESSAGE,
      cor: !file || (file.size !== undefined && file.size > MAX_BYTES) ? FILE_MESSAGE : undefined,
    };
    if (local.consent || local.cor) {
      setErrors(local);
      return;
    }

    const form = new FormData();
    if (Platform.OS === 'web' && file!.file) {
      form.append('cor', file!.file);
    } else {
      // React Native's FormData sends a file from its uri.
      form.append('cor', { uri: file!.uri, name: file!.name || 'cor.pdf', type: file!.mimeType ?? 'application/pdf' } as unknown as Blob);
    }
    form.append('consent', '1');

    setBusy(true);
    setErrors({});
    try {
      onUploaded(await api<CorDraft>('/register/cor', { method: 'POST', body: form, timeoutMs: 60000 }));
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) {
        setErrors({ cor: failure.field('cor'), consent: failure.field('consent'), general: failure.field('cor') || failure.field('consent') ? undefined : failure.message });
      } else {
        setErrors({ general: errorMessage(failure) });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <BackLink />
      <Text style={styles.step}>Step 1 of 2</Text>
      <Title>Create your account</Title>
      <Body muted>Upload the PDF of your Certificate of Registration (COR) for this term. We use it to check that you are enrolled.</Body>

      {restartMessage ? <Notice tone="error">{restartMessage}</Notice> : null}
      {errors.general ? <Notice tone="error">{errors.general}</Notice> : null}

      <View style={styles.privacy}>
        <Text style={type.label}>Data privacy notice</Text>
        <Text style={styles.privacyText}>
          Under the Data Privacy Act of 2012 (RA 10173), your COR is used only to verify that you are enrolled at Tagoloan
          Community College for this term. The file is deleted as soon as it has been read. We keep only your student ID,
          name, college and email.
        </Text>
        <Text style={styles.privacyText}>
          Your COR also lists your classes. On the next step you choose whether to save them (subject, section, day, time
          and room) as your class schedule. Only you can see your schedule, and you can change or delete it at any time.
        </Text>
        <Text style={styles.privacyText}>
          Each time you sign in, we record the time, your IP address and your device (for example &quot;Chrome on
          Android&quot;) for security. These records are kept for {SIGN_IN_RETENTION_DAYS} days.
        </Text>
      </View>

      <Checkbox checked={consent} onChange={setConsent} error={errors.consent}>
        I have read the data privacy notice and agree to my COR being used to verify my enrollment.
      </Checkbox>

      <View style={{ gap: 6 }}>
        <Text style={type.label}>Your COR</Text>
        <Button title={file ? 'Choose a different file' : 'Choose your COR (PDF)'} variant="quiet" onPress={pick} />
        {file && (
          <View style={styles.file}>
            <Icon name="file" size={22} color={colors.primary} />
            <Text style={[type.body, styles.fileName]} numberOfLines={1}>
              {file.name}
            </Text>
          </View>
        )}
        {errors.cor ? <Text style={styles.error}>{errors.cor}</Text> : null}
      </View>

      <Button title="Upload and continue" loadingTitle="Checking your COR…" loading={busy} onPress={upload} />
      <Button title="I already have an account" variant="link" onPress={() => router.replace('/sign-in')} />
    </Screen>
  );
}

function ConfirmStep({ draft, onRestart }: { draft: CorDraft; onRestart: (message: string | null) => void }) {
  const { signIn } = useSession();
  const [editName, setEditName] = useState(false);
  const [names, setNames] = useState({
    first_name: draft.first_name ?? '',
    middle_name: draft.middle_name ?? '',
    last_name: draft.last_name ?? '',
  });
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saveSchedule, setSaveSchedule] = useState(draft.meeting_count > 0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [general, setGeneral] = useState<string | null>(null);

  const displayName = [names.first_name, names.middle_name, names.last_name].filter((part) => part.trim() !== '').join(' ');

  const submit = async () => {
    setBusy(true);
    setError(null);
    setGeneral(null);
    try {
      await api('/register', {
        method: 'POST',
        body: {
          draft_token: draft.draft_token,
          // Left out unless edited: the server then keeps the COR's own split.
          ...(editName ? names : {}),
          email: email.trim(),
          password,
          password_confirmation: confirmation,
          save_schedule: saveSchedule,
        },
      });
    } catch (failure) {
      setBusy(false);
      if (failure instanceof ApiError && failure.status === 422) {
        if (failure.field('draft_token')) return onRestart(failure.field('draft_token')!);
        if (failure.field('first_name')) setEditName(true);
        setError(failure);
        // Student ID taken after the upload: no field to show it on.
        if (failure.field('student_id')) setGeneral(failure.field('student_id')!);
      } else {
        setGeneral(errorMessage(failure));
      }
      return;
    }

    try {
      // The account exists now; signing in moves on to "Verify your email" (root layout).
      await signIn(email, password);
    } catch {
      router.replace({ pathname: '/sign-in', params: { login: email.trim(), notice: 'Your account is ready. Sign in to continue.' } });
    }
  };

  return (
    <Screen>
      <BackLink label="Upload a different COR" onPress={() => onRestart(null)} />
      <Text style={styles.step}>Step 2 of 2</Text>
      <Title>Check your details</Title>

      <View style={styles.verified}>
        <Icon name="check" size={20} color={colors.safe} strokeWidth={2.6} />
        <Text style={[type.label, { color: colors.safe }]}>COR verified</Text>
      </View>

      {general ? <Notice tone="error">{general}</Notice> : null}

      <View style={styles.details}>
        <Detail label="Name" value={displayName} />
        <Detail label="Student ID" value={draft.student_id} />
        <Detail label="College" value={draft.college_name ?? '—'} />
        <Detail label="Program" value={draft.program_name ?? draft.program_code} />
      </View>
      <Text style={type.small}>These come from your COR and can’t be changed here.</Text>

      {!editName ? (
        <Button title="Edit name" variant="link" onPress={() => setEditName(true)} />
      ) : (
        <View style={styles.nameBox}>
          <Text style={type.label}>Your name</Text>
          <Text style={type.small}>
            You can only change how your name is split into first, middle and last name, and write your middle initial out
            in full.
          </Text>
          {error?.field('first_name') ? <Text style={styles.error}>{error.field('first_name')}</Text> : null}
          <Field label="First name" value={names.first_name} onChangeText={(first_name) => setNames({ ...names, first_name })} autoCapitalize="words" />
          <Field label="Middle name" value={names.middle_name} onChangeText={(middle_name) => setNames({ ...names, middle_name })} autoCapitalize="words" />
          <Field label="Last name" value={names.last_name} onChangeText={(last_name) => setNames({ ...names, last_name })} autoCapitalize="words" error={error?.field('last_name')} />
        </View>
      )}

      <Field
        label="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
        autoComplete="email"
        textContentType="emailAddress"
        placeholder="example@gmail.com"
        hint="We’ll send a code to this address to confirm it’s yours."
        error={error?.field('email')}
      />
      <Field
        label="Password"
        password
        value={password}
        onChangeText={setPassword}
        autoCapitalize="none"
        autoComplete="new-password"
        textContentType="newPassword"
        placeholder="Create a password"
        hint="At least 8 characters, with letters and numbers."
        error={error?.field('password')}
      />
      <Field
        label="Confirm password"
        password
        value={confirmation}
        onChangeText={setConfirmation}
        autoCapitalize="none"
        autoComplete="new-password"
        textContentType="newPassword"
        placeholder="Re-enter your password"
      />

      {draft.meeting_count > 0 && (
        <Checkbox checked={saveSchedule} onChange={setSaveSchedule}>
          <Text style={{ fontFamily: fonts.semibold }}>Also save my class schedule.</Text> The {draft.meeting_count} class
          meetings on your COR (subject, day, time and room) go to &quot;My schedule&quot;, where you can change or delete them.
        </Checkbox>
      )}

      <Button title="Create account" loadingTitle="Creating your account…" loading={busy} onPress={submit} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  step: { fontFamily: fonts.semibold, fontSize: 15, color: colors.secondary },
  privacy: { backgroundColor: '#F3ECEA', borderRadius: radius.md, padding: space.md, gap: space.sm },
  privacyText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text },
  file: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingVertical: 4 },
  fileName: { flex: 1 },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  verified: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  details: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space.md, gap: space.md },
  nameBox: { gap: space.md },
});
