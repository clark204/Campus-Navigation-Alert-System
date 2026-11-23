import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { api, ApiError, errorMessage } from '@/lib/api';
import { SITE_URL } from '@/lib/config';
import { useSession } from '@/lib/session';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';
import { SignOutButton } from '../account-screen';
import { Chips } from '../alert-admin/parts';
import { Icon } from '../icon';
import { MenuList } from '../portal-parts';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from '../ui';
import { WebLinks } from '../web-links';

// The admin's Settings in the app (CNAS-web Api\Admin\SettingsController, docs/settings.md): the same rules and
// messages as the website's Settings page. Map data, photos and QR codes stay on the website.

type Message = { tone: 'success' | 'error'; text: string } | null;

export type TermWarning = { kind: 'closed' | 'outdated'; message: string } | null;
type Term = { id: number; label: string; academic_year: string; semester: string; is_current: boolean; set_current_at: string | null };
type Terms = { current: Term | null; terms: Term[]; semesters: { value: string; label: string }[]; warning: TermWarning };

/** The first 422 message, or the error's own. */
const failureText = (failure: unknown) => (failure instanceof ApiError ? (Object.values(failure.fields)[0] ?? failure.message) : errorMessage(failure));

/** The Settings tab: each section opens its own page, My account, the website links, and Sign out at the bottom. */
export function SettingsTab() {
  const { user } = useSession();
  return (
    <Screen>
      <Title>Settings</Title>
      <MenuList
        items={[
          { title: 'Academic term', detail: 'The term students register for', icon: 'calendar', onPress: () => router.push('/admin/academic-term') },
          { title: 'Emergency numbers', detail: 'The numbers students see and can call', icon: 'phone', onPress: () => router.push('/admin/emergency-contacts') },
          { title: 'Contact details', detail: 'Email, phone, Facebook and address on the website', icon: 'mail', onPress: () => router.push('/admin/contact-details') },
          { title: 'System status', detail: 'Whether alerts can reach phones', icon: 'shield', onPress: () => router.push('/admin/system-status') },
          { title: 'My account', detail: user ? `${user.email}. Password, email, alerts on this phone, sign out` : undefined, icon: 'user', onPress: () => router.push('/admin/account') },
        ]}
      />
      <Text style={type.heading}>Open on the website</Text>
      <WebLinks
        links={[
          { title: 'Campus map data', detail: 'Import and export the map', icon: 'map', url: `${SITE_URL}/admin/map` },
          { title: 'Buildings and rooms', detail: 'Names and photos', icon: 'home', url: `${SITE_URL}/admin/buildings` },
          { title: '360° photos', detail: 'Photo points along the routes', icon: 'eye', url: `${SITE_URL}/admin/panoramas` },
          { title: 'QR codes', detail: 'Print a building’s or room’s code', icon: 'pin', url: `${SITE_URL}/admin/rooms` },
        ]}
      />
      <Text style={type.small}>Sign in on the website with the same account if it asks.</Text>

      <SignOutButton />
    </Screen>
  );
}

/** The dashboard's card when the admin should act on the term (the web banner's words). */
export function TermWarningCard({ warning }: { warning: TermWarning }) {
  if (!warning) return null;
  const closed = warning.kind === 'closed';
  return (
    <Pressable
      onPress={() => router.push('/admin/academic-term')}
      accessibilityRole="button"
      style={({ pressed }) => [styles.warning, closed ? styles.warningClosed : styles.warningOld, pressed && { opacity: 0.85 }]}>
      <Icon name="calendar" size={22} color={closed ? colors.error : colors.warning} />
      <View style={styles.flex}>
        <Text style={[styles.warningText, { color: closed ? colors.error : '#9A3412' }]}>{warning.message}</Text>
        <Text style={[styles.warningLink, { color: closed ? colors.error : '#9A3412' }]}>{closed ? 'Set the current term' : 'Review academic term'}</Text>
      </View>
    </Pressable>
  );
}

/** Settings → Academic term: the current term, every term with "Set as current" (confirmed), and Add a term. */
export function AcademicTermScreen() {
  const [data, setData] = useState<Terms | null>(null);
  const [notice, setNotice] = useState<Message>(null);
  const toast = useToast();
  const [year, setYear] = useState('');
  const [semester, setSemester] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<Term | null>(null);

  const load = useCallback(
    () =>
      api<Terms>('/admin/academic-terms').then(
        (next) => setData(next),
        (failure) => setNotice({ tone: 'error', text: errorMessage(failure) }),
      ),
    [],
  );
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  const add = async () => {
    setBusy('add');
    setErrors({});
    setNotice(null);
    try {
      const result = await api<{ message: string }>('/admin/academic-terms', { method: 'POST', body: { academic_year: year, semester } });
      toast(result.message);
      setYear('');
      setSemester(null);
      load();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors(failure.fields);
      else setNotice({ tone: 'error', text: errorMessage(failure) });
    } finally {
      setBusy(null);
    }
  };

  const makeCurrent = async (term: Term) => {
    setBusy(`current-${term.id}`);
    setNotice(null);
    try {
      const result = await api<{ message: string }>(`/admin/academic-terms/${term.id}/current`, { method: 'POST' });
      toast(result.message);
      setConfirming(null);
      load();
    } catch (failure) {
      setNotice({ tone: 'error', text: failureText(failure) });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen onRefresh={load} refreshing={false}>
      <BackLink label="Settings" />
      <Title>Academic term</Title>
      <Body muted>Students can only register with a COR for the current term. Change it at the start of each semester.</Body>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {!data ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <>
          <View style={styles.current}>
            <Text style={type.small}>Current term</Text>
            {data.current ? (
              <Text style={styles.currentLabel}>{data.current.label}</Text>
            ) : (
              <Text style={[styles.currentLabel, { color: colors.error, fontSize: 17 }]}>None. Registration is closed until you set one.</Text>
            )}
          </View>

          <View style={styles.card}>
            <Text style={type.label}>All terms</Text>
            {data.terms.length === 0 && <Text style={type.small}>No terms yet.</Text>}
            {data.terms.map((term) => (
              <View key={term.id} style={styles.termRow}>
                <Text style={[styles.flex, styles.termLabel]}>{term.label}</Text>
                {term.is_current ? (
                  <View style={styles.currentPill}>
                    <Text style={styles.currentPillText}>Current</Text>
                  </View>
                ) : (
                  <Pressable onPress={() => setConfirming(term)} accessibilityRole="button" style={styles.small}>
                    <Text style={styles.smallText}>Set as current</Text>
                  </Pressable>
                )}
              </View>
            ))}
            {confirming && (
              <View style={styles.confirm}>
                <Text style={styles.confirmText}>Set {confirming.label} as current? Students can then only register with a COR for this term.</Text>
                <View style={styles.row}>
                  <View style={styles.flex}>
                    <Button title="Cancel" variant="quiet" disabled={busy !== null} onPress={() => setConfirming(null)} />
                  </View>
                  <View style={styles.flex}>
                    <Button title="Yes, set it" loadingTitle="Saving…" loading={busy === `current-${confirming.id}`} onPress={() => makeCurrent(confirming)} />
                  </View>
                </View>
              </View>
            )}
          </View>

          <View style={styles.card}>
            <Text style={type.label}>Add a term</Text>
            <Field label="Academic year" value={year} onChangeText={setYear} placeholder="e.g. 2026-2027" keyboardType="numbers-and-punctuation" maxLength={11} error={errors.academic_year} />
            <Text style={type.label}>Semester</Text>
            <Chips label="Semester" value={semester} onChange={setSemester} options={data.semesters} />
            {errors.semester ? <Text style={styles.error}>{errors.semester}</Text> : null}
            <Button title="Add term" loadingTitle="Adding…" loading={busy === 'add'} onPress={add} />
            <Text style={type.small}>A new term isn’t current until you set it.</Text>
          </View>
        </>
      )}
    </Screen>
  );
}

type Contact = { id: number; name: string; phone: string | null; tel: string | null; note: string | null; is_visible: boolean };
type ContactForm = { name: string; phone: string; note: string; is_visible: boolean };

/**
 * Settings → Emergency numbers: the list in order (hidden ones too), move up or down, show or hide, edit, delete,
 * and Add. A contact can only be shown with a number. Students' apps get the change on their next load.
 */
export function EmergencyContactsScreen() {
  const [contacts, setContacts] = useState<Contact[] | null>(null);
  const [notice, setNotice] = useState<Message>(null);
  const toast = useToast();
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(
    () =>
      api<{ data: Contact[] }>('/admin/emergency-contacts').then(
        (result) => setContacts(result.data),
        (failure) => setNotice({ tone: 'error', text: errorMessage(failure) }),
      ),
    [],
  );
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  const act = async (key: string, path: string, method: 'PATCH' | 'DELETE', body?: Record<string, unknown>) => {
    setBusy(key);
    setNotice(null);
    try {
      const result = await api<{ message: string }>(`/admin/emergency-contacts/${path}`, { method, body });
      toast(result.message);
      await load();
    } catch (failure) {
      setNotice({ tone: 'error', text: failureText(failure) });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen onRefresh={load} refreshing={false}>
      <BackLink label="Settings" />
      <Title>Emergency numbers</Title>
      <Body muted>Shown on the students’ and faculty’s home, in order. Hidden ones stay here until you show them.</Body>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {!contacts && <ActivityIndicator color={colors.primary} />}
      {contacts?.map((contact, index) =>
        editing === contact.id ? (
          <ContactEditor
            key={contact.id}
            contact={contact}
            onDone={(message) => {
              setEditing(null);
              if (message) toast(message);
              load();
            }}
          />
        ) : (
          <View key={contact.id} style={[styles.card, !contact.is_visible && styles.hiddenCard]}>
            <View style={styles.row}>
              <View style={styles.flex}>
                <Text style={styles.contactName}>{contact.name}</Text>
                <Text style={type.small}>{[contact.phone ?? 'No number yet', contact.note].filter(Boolean).join(', ')}</Text>
              </View>
              <Switch
                value={contact.is_visible}
                disabled={busy !== null}
                onValueChange={(value) => act(`visible-${contact.id}`, `${contact.id}/visibility`, 'PATCH', { is_visible: value })}
                trackColor={{ true: colors.safe, false: colors.border }}
                accessibilityLabel={`Show ${contact.name} to students`}
              />
            </View>
            <Text style={type.small}>{contact.is_visible ? 'Shown to students' : 'Hidden'}</Text>
            <View style={styles.actions}>
              <SmallButton label="Up" icon="back" rotate={90} disabled={index === 0 || busy !== null} onPress={() => act(`up-${contact.id}`, `${contact.id}/move`, 'PATCH', { direction: 'up' })} />
              <SmallButton label="Down" icon="back" rotate={-90} disabled={index === contacts.length - 1 || busy !== null} onPress={() => act(`down-${contact.id}`, `${contact.id}/move`, 'PATCH', { direction: 'down' })} />
              <SmallButton label="Edit" icon="edit" disabled={busy !== null} onPress={() => setEditing(contact.id)} />
              <DeleteButton name={contact.name} busy={busy === `delete-${contact.id}`} onDelete={() => act(`delete-${contact.id}`, String(contact.id), 'DELETE')} />
            </View>
          </View>
        ),
      )}
      {editing === 'new' ? (
        <ContactEditor
          contact={null}
          onDone={(message) => {
            setEditing(null);
            if (message) toast(message);
            load();
          }}
        />
      ) : (
        <Button title="Add a number" variant="quiet" onPress={() => setEditing('new')} />
      )}
    </Screen>
  );
}

function SmallButton({ label, icon, rotate, disabled, onPress }: { label: string; icon: 'back' | 'edit'; rotate?: number; disabled?: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} disabled={disabled} accessibilityRole="button" accessibilityLabel={label} style={({ pressed }) => [styles.small, disabled && styles.disabled, pressed && styles.pressed]}>
      <View style={rotate ? { transform: [{ rotate: `${rotate}deg` }] } : undefined}>
        <Icon name={icon} size={16} color={colors.primary} strokeWidth={2.4} />
      </View>
      <Text style={styles.smallText}>{label}</Text>
    </Pressable>
  );
}

function DeleteButton({ name, busy, onDelete }: { name: string; busy: boolean; onDelete: () => void }) {
  const [asking, setAsking] = useState(false);
  if (!asking) {
    return (
      <Pressable onPress={() => setAsking(true)} accessibilityRole="button" accessibilityLabel={`Delete ${name}`} style={styles.small}>
        <Icon name="trash" size={16} color={colors.alarm} />
        <Text style={[styles.smallText, { color: colors.alarm }]}>Delete</Text>
      </Pressable>
    );
  }
  return (
    <Pressable onPress={onDelete} accessibilityRole="button" style={[styles.small, styles.deleteNow]}>
      {busy ? <ActivityIndicator color={colors.white} /> : <Text style={[styles.smallText, { color: colors.white }]}>Delete {name}?</Text>}
    </Pressable>
  );
}

function ContactEditor({ contact, onDone }: { contact: Contact | null; onDone: (message: string | null) => void }) {
  const [form, setForm] = useState<ContactForm>({ name: contact?.name ?? '', phone: contact?.phone ?? '', note: contact?.note ?? '', is_visible: contact?.is_visible ?? true });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const body = { ...form, phone: form.phone.trim() || null, note: form.note.trim() || null };
      const result = await api<{ message: string }>(contact ? `/admin/emergency-contacts/${contact.id}` : '/admin/emergency-contacts', { method: contact ? 'PUT' : 'POST', body });
      onDone(result.message);
    } catch (failure) {
      setErrors(failure instanceof ApiError && failure.status === 422 ? failure.fields : { name: errorMessage(failure) });
      setBusy(false);
    }
  };

  return (
    <View style={[styles.card, styles.editor]}>
      <Text style={type.label}>{contact ? `Edit ${contact.name}` : 'Add a number'}</Text>
      <Field label="Who students are calling" value={form.name} onChangeText={(name) => setForm({ ...form, name })} placeholder="Campus security" maxLength={100} error={errors.name} />
      <Field label="Phone number" value={form.phone} onChangeText={(phone) => setForm({ ...form, phone })} placeholder="0917 123 4567" keyboardType="phone-pad" maxLength={30} error={errors.phone} />
      <Field label="Note (optional)" value={form.note} onChangeText={(note) => setForm({ ...form, note })} placeholder="Main gate, 24 hours" maxLength={60} error={errors.note} />
      <View style={styles.row}>
        <Text style={[type.label, styles.flex]}>Show to students</Text>
        <Switch value={form.is_visible} onValueChange={(is_visible) => setForm({ ...form, is_visible })} trackColor={{ true: colors.safe, false: colors.border }} />
      </View>
      <View style={styles.row}>
        <View style={styles.flex}>
          <Button title="Cancel" variant="quiet" disabled={busy} onPress={() => onDone(null)} />
        </View>
        <View style={styles.flex}>
          <Button title={contact ? 'Save' : 'Add'} loadingTitle="Saving…" loading={busy} onPress={save} />
        </View>
      </View>
    </View>
  );
}

type Details = { email: string; phone: string; facebook: string; address: string };

/** Settings → Contact details: shown in the website's footer and on Help & contact. */
export function ContactDetailsScreen() {
  const [form, setForm] = useState<Details | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<Message>(null);
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  useFocusEffect(
    useCallback(() => {
      api<{ data: Details }>('/admin/contact-details').then(
        (result) => setForm(result.data),
        (failure) => setNotice({ tone: 'error', text: errorMessage(failure) }),
      );
    }, []),
  );

  const save = async () => {
    if (!form) return;
    setBusy(true);
    setErrors({});
    setNotice(null);
    try {
      const result = await api<{ message: string; data: Details }>('/admin/contact-details', { method: 'PUT', body: form });
      setForm(result.data);
      toast(result.message);
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors(failure.fields);
      else setNotice({ tone: 'error', text: errorMessage(failure) });
    } finally {
      setBusy(false);
    }
  };

  const set = (patch: Partial<Details>) => setForm((current) => (current ? { ...current, ...patch } : current));

  return (
    <Screen>
      <BackLink label="Settings" />
      <Title>Contact details</Title>
      <Body muted>Shown in the website’s footer and on the Help & contact page of the student and faculty portals.</Body>
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {!form ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <>
          <Field label="Email" value={form.email} onChangeText={(email) => set({ email })} keyboardType="email-address" autoCapitalize="none" error={errors.email} />
          <Field label="Phone" value={form.phone} onChangeText={(phone) => set({ phone })} keyboardType="phone-pad" error={errors.phone} />
          <Field label="Facebook page" value={form.facebook} onChangeText={(facebook) => set({ facebook })} autoCapitalize="none" keyboardType="url" error={errors.facebook} />
          <Field label="Address" value={form.address} onChangeText={(address) => set({ address })} error={errors.address} />
          <Button title="Save contact details" loadingTitle="Saving…" loading={busy} onPress={save} />
        </>
      )}
    </Screen>
  );
}

type Check = { key: string; name: string; ok: boolean; summary: string; fix: string | null };

/** Settings → System status: the website's checks, read-only. Pull down to check again. */
export function SystemStatusScreen() {
  const [checks, setChecks] = useState<Check[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(
    () =>
      api<{ data: Check[] }>('/admin/system-status').then(
        (result) => {
          setChecks(result.data);
          setError(null);
        },
        (failure) => setError(errorMessage(failure)),
      ),
    [],
  );
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  return (
    <Screen onRefresh={load} refreshing={false}>
      <BackLink label="Settings" />
      <Title>System status</Title>
      <Body muted>Whether alerts can reach browsers and phones. Pull down to check again.</Body>
      {error && <Notice tone="error">{error}</Notice>}
      {!checks && !error && <ActivityIndicator color={colors.primary} />}
      {checks && (
        <View style={styles.list}>
          {checks.map((check, index) => (
            <View key={check.key} style={[styles.check, index > 0 && styles.divider]}>
              <View style={[styles.checkIcon, { backgroundColor: check.ok ? colors.safe : colors.alarm }]}>
                <Icon name={check.ok ? 'check' : 'close'} size={16} color={colors.white} strokeWidth={2.8} />
              </View>
              <View style={styles.flex}>
                <View style={styles.row}>
                  <Text style={[styles.flex, type.label]}>{check.name}</Text>
                  <Text style={[styles.checkState, { color: check.ok ? colors.safe : colors.alarm }]}>{check.ok ? 'OK' : 'Needs attention'}</Text>
                </View>
                <Text style={type.small}>{check.summary}</Text>
                {check.fix ? <Text style={styles.fix}>{check.fix}</Text> : null}
              </View>
            </View>
          ))}
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  warning: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm, padding: space.md, borderRadius: radius.md, borderWidth: 1 },
  warningClosed: { backgroundColor: colors.errorTint, borderColor: '#FECACA' },
  warningOld: { backgroundColor: '#FFF7ED', borderColor: '#FED7AA' },
  warningText: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21 },
  warningLink: { fontFamily: fonts.bold, fontSize: 15, textDecorationLine: 'underline', marginTop: 4 },
  current: { gap: 2, padding: space.md, borderRadius: radius.md, backgroundColor: '#F3ECEA' },
  currentLabel: { fontFamily: fonts.bold, fontSize: 21, color: colors.primary },
  card: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  hiddenCard: { backgroundColor: '#F7F5F2' },
  editor: { borderColor: colors.primary, borderWidth: 1.5 },
  termRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm, minHeight: 48, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: space.sm },
  termLabel: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  currentPill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4, backgroundColor: '#F3ECEA' },
  currentPillText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.primary },
  small: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: 40, paddingHorizontal: 12, borderRadius: radius.sm, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface },
  smallText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.primary },
  deleteNow: { backgroundColor: colors.alarm, borderColor: colors.alarm },
  disabled: { opacity: 0.4 },
  pressed: { opacity: 0.7 },
  confirm: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: '#F6F1EC' },
  confirmText: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.text },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  contactName: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  check: { flexDirection: 'row', gap: space.sm, padding: space.md },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  checkIcon: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginTop: 2 },
  checkState: { fontFamily: fonts.bold, fontSize: 13 },
  fix: { fontFamily: fonts.medium, fontSize: 14, lineHeight: 20, color: colors.alarm, marginTop: 2 },
});
