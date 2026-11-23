import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { campusDateTime, generatePassword, type AccountDetails, type AccountOptions, type AccountPage, type AccountRow, type Role } from '@/lib/admin';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';
import { Chips } from '../alert-admin/parts';
import { Icon } from '../icon';
import { BackLink, Body, Button, Detail, Field, Notice, Screen, Title } from '../ui';

const ROLE_FILTERS: { value: Role | ''; label: string }[] = [
  { value: '', label: 'Everyone' },
  { value: 'student', label: 'Students' },
  { value: 'faculty', label: 'Faculty' },
  { value: 'staff', label: 'Staff' },
  { value: 'admin', label: 'Admins' },
];

/**
 * Admin > Accounts (GET /api/admin/accounts): search on the server, filter by role or "Can send alerts", newest first,
 * 10 at a time. Create account at the top.
 */
export function AccountsTab() {
  const [query, setQuery] = useState('');
  const [role, setRole] = useState<Role | ''>('');
  const [trusted, setTrusted] = useState(false);
  const [rows, setRows] = useState<AccountRow[] | null>(null);
  const [meta, setMeta] = useState<AccountPage['meta'] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const request = useRef(0);

  const url = useCallback(
    (page: number) => {
      const params = new URLSearchParams();
      if (query.trim()) params.set('q', query.trim());
      if (role) params.set('role', role);
      if (trusted) params.set('trusted', '1');
      if (page > 1) params.set('page', String(page));
      const text = params.toString();
      return `/admin/accounts${text ? `?${text}` : ''}`;
    },
    [query, role, trusted],
  );

  const load = useCallback(() => {
    const mine = ++request.current;
    return api<AccountPage>(url(1)).then(
      (page) => {
        if (mine !== request.current) return;
        setRows(page.data);
        setMeta(page.meta);
        setError(null);
      },
      (failure) => mine === request.current && setError(errorMessage(failure)),
    );
  }, [url]);

  // Typing waits a moment before asking the server (like the web's live search).
  useFocusEffect(
    useCallback(() => {
      const timer = setTimeout(load, 300);
      return () => clearTimeout(timer);
    }, [load]),
  );

  const more = async () => {
    if (!meta) return;
    setLoadingMore(true);
    try {
      const page = await api<AccountPage>(url(meta.current_page + 1));
      setRows((current) => [...(current ?? []), ...page.data]);
      setMeta(page.meta);
    } catch (failure) {
      setError(errorMessage(failure));
    } finally {
      setLoadingMore(false);
    }
  };

  const count = (value: Role | '') => (value ? (meta?.counts[value] ?? 0) : Object.values(meta?.counts ?? {}).reduce((a, b) => a + (b ?? 0), 0));

  return (
    <Screen onRefresh={load} refreshing={false}>
      <View style={styles.titleRow}>
        <View style={styles.flex}>
          <Title>Accounts</Title>
        </View>
        <Pressable onPress={() => router.push('/admin/account-form')} accessibilityRole="button" style={({ pressed }) => [styles.create, pressed && { opacity: 0.85 }]}>
          <Icon name="plus" size={20} color={colors.white} strokeWidth={2.4} />
          <Text style={styles.createText}>Create</Text>
        </Pressable>
      </View>
      <TextInput
        value={query}
        onChangeText={setQuery}
        placeholder="Search by name, email or ID"
        placeholderTextColor="#9AA0A6"
        autoCorrect={false}
        autoCapitalize="none"
        accessibilityLabel="Search accounts"
        style={styles.search}
      />
      <Chips label="Role" value={role} onChange={setRole} options={ROLE_FILTERS.map((f) => ({ value: f.value, label: meta ? `${f.label} ${count(f.value)}` : f.label }))} />
      <Pressable onPress={() => setTrusted(!trusted)} accessibilityRole="checkbox" accessibilityState={{ checked: trusted }} style={[styles.trustedFilter, trusted && styles.trustedFilterOn]}>
        <Icon name="megaphone" size={18} color={trusted ? colors.white : colors.primary} />
        <Text style={[styles.trustedText, trusted && { color: colors.white }]}>Can send alerts{meta ? ` ${meta.trusted_count}` : ''}</Text>
      </Pressable>

      {error && <Notice tone="error">{error}</Notice>}
      {rows === null && !error && <ActivityIndicator color={colors.primary} />}
      {rows && rows.length === 0 && <Body muted>No accounts match.</Body>}
      {rows && rows.length > 0 && (
        <View style={styles.list}>
          {rows.map((row, index) => (
            <Pressable
              key={row.id}
              onPress={() => router.push({ pathname: '/admin/account-view', params: { id: String(row.id) } })}
              accessibilityRole="button"
              style={({ pressed }) => [styles.row, index > 0 && styles.divider, pressed && styles.pressed]}>
              <View style={[styles.avatar, row.role === 'admin' && { backgroundColor: colors.primary }]}>
                <Text style={[styles.avatarText, row.role === 'admin' && { color: colors.white }]}>{initials(row.name)}</Text>
              </View>
              <View style={styles.flex}>
                <Text style={styles.name} numberOfLines={1}>
                  {row.name}
                </Text>
                <Text style={type.small} numberOfLines={1}>
                  {[row.role_label, row.school_id, row.college].filter(Boolean).join(', ')}
                </Text>
              </View>
              {row.trusted_for_alerts && <Icon name="megaphone" size={18} color={colors.alarm} />}
              <Icon name="chevron" size={18} color={colors.muted} />
            </Pressable>
          ))}
        </View>
      )}
      {meta && meta.current_page < meta.last_page && <Button title="Show more" variant="quiet" loading={loadingMore} loadingTitle="Loading…" onPress={more} />}
    </Screen>
  );
}

const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?';

type Message = { tone: 'success' | 'error'; text: string } | null;

/**
 * One account (GET /api/admin/accounts/{id}): details, Can send alerts (faculty and staff), Faculty or staff, Set
 * temporary password, Edit details, and the Danger zone (type the email to delete). Admin accounts are read-only here,
 * like the web.
 */
export function AccountViewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [account, setAccount] = useState<AccountDetails | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<Message>(null);
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      api<{ data: AccountDetails }>(`/admin/accounts/${id}`).then(
        (result) => setAccount(result.data),
        (failure) => setError(errorMessage(failure)),
      );
    }, [id]),
  );

  const act = async (key: string, path: string, body: Record<string, unknown>, method: 'PATCH' | 'DELETE' = 'PATCH') => {
    setBusy(key);
    setNotice(null);
    try {
      const result = await api<{ message: string; data?: AccountDetails }>(`/admin/accounts/${id}${path}`, { method, body });
      if (result.data) setAccount(result.data);
      toast(result.message);
      return result;
    } catch (failure) {
      setNotice({ tone: 'error', text: failure instanceof ApiError ? (Object.values(failure.fields)[0] ?? failure.message) : errorMessage(failure) });
      return null;
    } finally {
      setBusy(null);
    }
  };

  if (!account) {
    return (
      <Screen>
        <BackLink label="Accounts" />
        {error ? <Notice tone="error">{error}</Notice> : <ActivityIndicator color={colors.primary} />}
      </Screen>
    );
  }

  return (
    <Screen>
      <BackLink label="Accounts" />
      <View style={styles.titleRow}>
        <View style={[styles.avatar, styles.avatarBig]}>
          <Text style={[styles.avatarText, { fontSize: 22 }]}>{initials(account.name)}</Text>
        </View>
        <View style={styles.flex}>
          <Text style={styles.bigName}>{account.name}</Text>
          <Text style={type.small}>{account.role_label}</Text>
        </View>
      </View>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}

      <View style={styles.card}>
        <Detail label="Email" value={`${account.email}${account.email_verified ? '' : ' (not verified yet)'}`} />
        {account.school_id ? <Detail label="ID" value={account.school_id} /> : null}
        {account.program ? <Detail label="Program" value={account.program} /> : null}
        {account.college ? <Detail label="College" value={account.college} /> : null}
        <Detail label="Last sign-in" value={account.last_sign_in_at ? campusDateTime(account.last_sign_in_at) : 'Never'} />
        <Detail label="Sign-ins kept (90 days)" value={String(account.sign_in_count)} />
        {account.alerts_sent > 0 ? <Detail label="Alerts sent" value={String(account.alerts_sent)} /> : null}
        {account.must_change_password && <Text style={styles.waiting}>Waiting for them to change the temporary password.</Text>}
        {!account.is_admin && <Button title="Edit details" variant="link" onPress={() => router.push({ pathname: '/admin/account-form', params: { id: String(account.id) } })} />}
      </View>

      {account.is_admin && <Body muted>Administrator accounts are changed in their own Settings, never here.</Body>}

      {account.can_be_trusted && (
        <View style={[styles.card, styles.switchRow]}>
          <View style={styles.flex}>
            <Text style={type.label}>Can send emergency alerts</Text>
            <Text style={type.small}>From their portal, straight away (no approval). Every alert shows who sent it.</Text>
          </View>
          {busy === 'trust' ? (
            <ActivityIndicator color={colors.primary} />
          ) : (
            <Switch
              value={account.trusted_for_alerts}
              onValueChange={(value) => {
                act('trust', '/trust', { trusted: value });
              }}
              trackColor={{ true: colors.alarm, false: colors.border }}
              accessibilityLabel="Can send emergency alerts"
            />
          )}
        </View>
      )}

      {account.can_switch_role && (
        <View style={styles.card}>
          <Text style={type.label}>Faculty or staff</Text>
          <Text style={type.small}>Faculty get My classes; staff don’t. Classes and alert access stay as they are.</Text>
          <Chips
            label="Faculty or staff"
            value={account.role}
            onChange={(role) => role !== account.role && act('role', '/role', { role })}
            options={[
              { value: 'faculty' as Role, label: 'Faculty' },
              { value: 'staff' as Role, label: 'Staff' },
            ]}
          />
        </View>
      )}

      {!account.is_admin && <TemporaryPassword name={account.first_name ?? account.name} busy={busy === 'password'} onSet={(password) => act('password', '/password', { password })} />}

      {account.can_delete && <DangerZone account={account} busy={busy === 'delete'} onDelete={(confirm) => act('delete', '', { confirm_email: confirm }, 'DELETE')} />}
    </Screen>
  );
}

function TemporaryPassword({ name, busy, onSet }: { name: string; busy: boolean; onSet: (password: string) => Promise<unknown> }) {
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  if (!open) return <Button title="Set a temporary password" variant="quiet" onPress={() => setOpen(true)} />;
  return (
    <View style={styles.card}>
      <Text style={type.label}>Set a temporary password</Text>
      <Text style={type.small}>For someone who forgot theirs and can’t reset it by email. Check who they are in person. They are signed out everywhere and choose their own at their next sign-in.</Text>
      <Field label="Temporary password" value={password} onChangeText={setPassword} autoCapitalize="none" hint="At least 8 characters, with letters and numbers." />
      <Button title="Generate" variant="link" onPress={() => setPassword(generatePassword())} />
      <Button
        title={`Set it and sign ${name} out`}
        loadingTitle="Saving…"
        loading={busy}
        disabled={password.length === 0}
        onPress={async () => {
          await onSet(password);
        }}
      />
      <Text style={type.small}>Write it down or read it out to them now: it is never shown again.</Text>
    </View>
  );
}

function DangerZone({ account, busy, onDelete }: { account: AccountDetails; busy: boolean; onDelete: (confirm: string) => Promise<unknown> }) {
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState('');
  const matches = typed.trim().toLowerCase() === account.email.trim().toLowerCase();
  const deletes = Object.entries(account.deletes);

  return (
    <View style={[styles.card, styles.danger]}>
      <Text style={[type.label, { color: colors.alarm }]}>Danger zone</Text>
      {!open ? (
        <Button title="Delete this account" variant="link" onPress={() => setOpen(true)} />
      ) : (
        <>
          <Text style={styles.dangerText}>This deletes {account.name}’s account for good{deletes.length ? ', with:' : '.'}</Text>
          {deletes.map(([what, count]) => (
            <Text key={what} style={type.small}>
              {`• ${count} ${what}`}
            </Text>
          ))}
          <Text style={type.small}>Alerts they sent and messages they wrote are kept without their name. Their phone keeps getting emergency alerts.</Text>
          <Field label={`Type ${account.email} to confirm`} value={typed} onChangeText={setTyped} autoCapitalize="none" keyboardType="email-address" />
          <Button
            title="Delete the account"
            variant="danger"
            loading={busy}
            loadingTitle="Deleting…"
            disabled={!matches}
            onPress={async () => {
              if (await onDelete(typed)) router.back();
            }}
          />
        </>
      )}
    </View>
  );
}

type FormState = {
  role: Role;
  first_name: string;
  middle_name: string;
  last_name: string;
  email: string;
  school_id: string;
  program_code: string;
  password: string;
  trusted_for_alerts: boolean;
};

/**
 * Create account (no ?id) or Edit details (?id=), with the web's rules (StoreAccountRequest, UpdateAccountRequest):
 * students need the 8-digit ID and a program; staff have no program. A new account gets a temporary password.
 */
export function AccountFormScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const editing = id !== undefined;
  const [options, setOptions] = useState<AccountOptions | null>(null);
  const [form, setForm] = useState<FormState>({ role: 'student', first_name: '', middle_name: '', last_name: '', email: '', school_id: '', program_code: '', password: '', trusted_for_alerts: false });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [picking, setPicking] = useState(false);
  const [done, setDone] = useState<{ message: string; id: number } | null>(null);
  const toast = useToast();

  useEffect(() => {
    api<AccountOptions>('/admin/accounts/options').then(setOptions, (failure) => setNotice(errorMessage(failure)));
    if (editing) {
      api<{ data: AccountDetails }>(`/admin/accounts/${id}`).then(
        ({ data }) =>
          setForm((current) => ({
            ...current,
            role: data.role,
            first_name: data.first_name ?? '',
            middle_name: data.middle_name ?? '',
            last_name: data.last_name ?? '',
            email: data.email,
            school_id: data.school_id ?? '',
            program_code: data.program_code ?? '',
          })),
        (failure) => setNotice(errorMessage(failure)),
      );
    }
  }, [editing, id]);

  const set = (patch: Partial<FormState>) => {
    setForm((current) => ({ ...current, ...patch }));
    setErrors((current) => {
      const next = { ...current };
      Object.keys(patch).forEach((key) => delete next[key]);
      return next;
    });
  };
  const isStudent = form.role === 'student';
  const hasProgram = form.role !== 'staff';
  const program = options?.colleges.flatMap((c) => c.programs).find((p) => p.code === form.program_code);

  const save = async () => {
    setBusy(true);
    setErrors({});
    setNotice(null);
    const details = {
      first_name: form.first_name,
      middle_name: form.middle_name,
      last_name: form.last_name,
      email: form.email,
      school_id: form.school_id,
      ...(hasProgram ? { program_code: form.program_code || null } : {}),
    };
    try {
      const result = editing
        ? await api<{ message: string; data: AccountDetails }>(`/admin/accounts/${id}`, { method: 'PUT', body: details })
        : await api<{ message: string; data: AccountDetails }>('/admin/accounts', {
            method: 'POST',
            body: { ...details, role: form.role, password: form.password, trusted_for_alerts: form.role !== 'student' && form.trusted_for_alerts },
          });
      toast(result.message);
      if (editing) router.back();
      else setDone({ message: result.message, id: result.data.id });
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors(failure.fields);
      else setNotice(errorMessage(failure));
    } finally {
      setBusy(false);
    }
  };

  if (done) {
    return (
      <Screen>
        <Title>Account ready</Title>
        <View style={styles.card}>
          <Detail label="Email" value={form.email.trim().toLowerCase()} />
          <Detail label="Temporary password" value={form.password} />
        </View>
        <Body muted>Give these to them in person. The password is never shown again.</Body>
        <Button title="Open the account" onPress={() => router.replace({ pathname: '/admin/account-view', params: { id: String(done.id) } })} />
      </Screen>
    );
  }

  return (
    <Screen>
      <BackLink label={editing ? 'Account' : 'Accounts'} />
      <Title>{editing ? 'Edit details' : 'Create account'}</Title>
      {!editing && <Body muted>For faculty and staff (they can’t sign up with a COR), or a student who can’t register.</Body>}
      {notice && <Notice tone="error">{notice}</Notice>}
      {!editing && options && (
        <>
          <Text style={type.label}>Kind of account</Text>
          <Chips label="Kind of account" value={form.role} onChange={(role) => set({ role, ...(role === 'staff' ? { program_code: '' } : {}) })} options={options.roles} />
          {errors.role ? <Text style={styles.error}>{errors.role}</Text> : null}
        </>
      )}
      <Field label="First name" value={form.first_name} onChangeText={(first_name) => set({ first_name })} error={errors.first_name} autoCapitalize="words" />
      <Field label="Middle name (optional)" value={form.middle_name} onChangeText={(middle_name) => set({ middle_name })} error={errors.middle_name} autoCapitalize="words" />
      <Field label="Last name" value={form.last_name} onChangeText={(last_name) => set({ last_name })} error={errors.last_name} autoCapitalize="words" />
      <Field label="Email they sign in with" value={form.email} onChangeText={(email) => set({ email })} error={errors.email} keyboardType="email-address" autoCapitalize="none" />
      {editing && <Text style={type.small}>A new email must be confirmed with a code at their next sign-in.</Text>}
      <Field
        label={isStudent ? 'Student ID (8 digits, from the COR)' : 'Employee ID (optional)'}
        value={form.school_id}
        onChangeText={(school_id) => set({ school_id })}
        error={errors.school_id}
        keyboardType={isStudent ? 'number-pad' : 'default'}
        autoCapitalize="characters"
      />
      {hasProgram && (
        <View style={{ gap: 6 }}>
          <Text style={type.label}>{isStudent ? 'Program' : 'Program (optional)'}</Text>
          <Pressable onPress={() => setPicking(true)} accessibilityRole="button" style={[styles.pick, !!errors.program_code && { borderColor: colors.error }]}>
            <Text style={[styles.pickText, !program && { color: colors.muted }]} numberOfLines={2}>
              {program?.name ?? 'Choose a program'}
            </Text>
            <Icon name="chevron" size={18} color={colors.muted} />
          </Pressable>
          {errors.program_code ? <Text style={styles.error}>{errors.program_code}</Text> : null}
        </View>
      )}
      {!editing && (
        <>
          <Field label="Temporary password" value={form.password} onChangeText={(password) => set({ password })} error={errors.password} autoCapitalize="none" hint="They choose their own at their first sign-in." />
          <Button title="Generate" variant="link" onPress={() => set({ password: generatePassword() })} />
          {form.role !== 'student' && (
            <View style={[styles.card, styles.switchRow]}>
              <View style={styles.flex}>
                <Text style={type.label}>Can send emergency alerts</Text>
                <Text style={type.small}>You can change this later on their account.</Text>
              </View>
              <Switch value={form.trusted_for_alerts} onValueChange={(trusted_for_alerts) => set({ trusted_for_alerts })} trackColor={{ true: colors.alarm, false: colors.border }} />
            </View>
          )}
        </>
      )}
      <Button title={editing ? 'Save details' : 'Create account'} loadingTitle="Saving…" loading={busy} onPress={save} />

      <Modal visible={picking} animationType="slide" onRequestClose={() => setPicking(false)}>
        <SafeAreaView style={styles.sheet}>
          <View style={styles.sheetHead}>
            <Text style={[type.heading, styles.flex]}>Program</Text>
            <Pressable onPress={() => setPicking(false)} accessibilityRole="button" accessibilityLabel="Close" hitSlop={10} style={styles.close}>
              <Icon name="close" size={24} color={colors.text} />
            </Pressable>
          </View>
          <ScrollView contentContainerStyle={{ paddingBottom: space.xl * 2 }}>
            {!isStudent && (
              <Pressable
                onPress={() => {
                  set({ program_code: '' });
                  setPicking(false);
                }}
                style={styles.choice}>
                <Text style={styles.choiceText}>No program</Text>
              </Pressable>
            )}
            {options?.colleges.map((college) => (
              <View key={college.name}>
                <Text style={styles.group}>{college.name}</Text>
                {college.programs.map((p) => (
                  <Pressable
                    key={p.code}
                    onPress={() => {
                      set({ program_code: p.code });
                      setPicking(false);
                    }}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: p.code === form.program_code }}
                    style={({ pressed }) => [styles.choice, pressed && styles.pressed]}>
                    <Text style={[styles.choiceText, p.code === form.program_code && { color: colors.primary, fontFamily: fonts.semibold }]}>{p.name}</Text>
                    {p.code === form.program_code && <Icon name="check" size={20} color={colors.primary} strokeWidth={2.6} />}
                  </Pressable>
                ))}
              </View>
            ))}
          </ScrollView>
        </SafeAreaView>
      </Modal>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  create: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44, paddingHorizontal: 14, borderRadius: radius.sm, backgroundColor: colors.primary },
  createText: { fontFamily: fonts.bold, fontSize: 15, color: colors.white },
  search: { minHeight: 52, borderWidth: 1.5, borderColor: colors.border, borderRadius: radius.md, backgroundColor: colors.surface, paddingHorizontal: space.md, fontFamily: fonts.regular, fontSize: 17, color: colors.text },
  trustedFilter: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', minHeight: 42, borderRadius: 21, borderWidth: 1.5, borderColor: colors.border, paddingHorizontal: 14, backgroundColor: colors.surface },
  trustedFilterOn: { backgroundColor: colors.alarm, borderColor: colors.alarm },
  trustedText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.primary },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, minHeight: 64, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  pressed: { backgroundColor: '#F3EEE8' },
  avatar: { width: 40, height: 40, borderRadius: 20, backgroundColor: '#F3ECEA', alignItems: 'center', justifyContent: 'center' },
  avatarBig: { width: 60, height: 60, borderRadius: 30, borderWidth: 3, borderColor: colors.secondary },
  avatarText: { fontFamily: fonts.bold, fontSize: 15, color: colors.primary },
  name: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  bigName: { fontFamily: fonts.bold, fontSize: 22, lineHeight: 28, color: colors.text },
  card: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  switchRow: { flexDirection: 'row', alignItems: 'center' },
  waiting: { fontFamily: fonts.medium, fontSize: 14, color: colors.warning },
  danger: { borderColor: '#F3C4C7' },
  dangerText: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.text },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  pick: { minHeight: 54, borderWidth: 1.5, borderColor: colors.border, borderRadius: radius.md, backgroundColor: colors.surface, flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.md, gap: space.sm },
  pickText: { flex: 1, fontFamily: fonts.medium, fontSize: 17, color: colors.text },
  sheet: { flex: 1, backgroundColor: colors.bg },
  sheetHead: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, paddingVertical: space.md },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  group: { fontFamily: fonts.semibold, fontSize: 14, color: colors.muted, paddingHorizontal: space.lg, paddingTop: space.md, paddingBottom: 4 },
  choice: { minHeight: 52, flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, gap: space.sm },
  choiceText: { flex: 1, fontFamily: fonts.regular, fontSize: 17, color: colors.text },
});
