import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { DayPicker, RoomPicker, TimePicker } from '@/components/class-form-parts';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { scheduleStore } from '@/lib/portal-data';
import { scheduleName } from '@/lib/schedule';
import { useSession } from '@/lib/session';
import type { ClassItem, RoomChoices, Weekday } from '@/lib/types';
import { colors, fonts, radius, space } from '@/theme';

type Form = { subject: string; section: string; day: Weekday | null; starts_at: string | null; ends_at: string | null; room_choice: string; room_other: string };

const fromClass = (item: ClassItem | undefined): Form => ({
  subject: item?.subject ?? '',
  section: item?.section ?? '',
  day: item?.day ?? null,
  starts_at: item?.starts_at ?? null,
  ends_at: item?.ends_at ?? null,
  room_choice: item?.room_choice ?? 'TBA',
  room_other: item?.room_other ?? '',
});

/** "16:00" plus an hour, for a new class's end time. */
const anHourLater = (time: string) => {
  const [h, m] = time.split(':').map(Number);
  return `${String(Math.min(h + 1, 23)).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};

/**
 * Add a class, or edit or delete one (?id=), with the web form's fields and rules (SaveClassScheduleRequest): POST
 * or PUT /api/schedule, DELETE /api/schedule/{id}. The room is picked from the map's rooms, like the web.
 */
export default function ClassForm() {
  const { user } = useSession();
  const { id } = useLocalSearchParams<{ id?: string }>();
  const existing = id ? scheduleStore.get().data?.classes.find((c) => String(c.id) === id) : undefined;
  const [form, setForm] = useState<Form>(() => fromClass(existing));
  const [choices, setChoices] = useState<RoomChoices | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  useEffect(() => {
    api<RoomChoices>('/schedule/rooms')
      .then(setChoices)
      .catch((failure) => setNotice(`The room list didn’t load. ${errorMessage(failure)}`));
  }, []);

  const set = <K extends keyof Form>(key: K, value: Form[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
    setErrors(({ [key]: _, ...rest }) => rest);
  };

  if (id && !existing) {
    return (
      <Screen>
        <BackLink />
        <Title>Class not found</Title>
        <Body muted>It may have been deleted. Go back and pull down to refresh your schedule.</Body>
      </Screen>
    );
  }

  const apply = (classes: ClassItem[]) => {
    const current = scheduleStore.get().data;
    scheduleStore.set({ classes, term: current?.term ?? null });
  };

  const save = async () => {
    setBusy(true);
    setErrors({});
    setNotice(null);
    try {
      const body = { ...form, room_other: form.room_choice === 'other' ? form.room_other : '' };
      const result = await api<{ data: ClassItem }>(existing ? `/schedule/${existing.id}` : '/schedule', { method: existing ? 'PUT' : 'POST', body });
      const others = (scheduleStore.get().data?.classes ?? []).filter((c) => c.id !== result.data.id);
      apply([...others, result.data]);
      router.back();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors(failure.fields);
      else setNotice(errorMessage(failure));
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!existing) return;
    setBusy(true);
    setNotice(null);
    try {
      await api(`/schedule/${existing.id}`, { method: 'DELETE' });
      apply((scheduleStore.get().data?.classes ?? []).filter((c) => c.id !== existing.id));
      router.back();
    } catch (failure) {
      setNotice(errorMessage(failure));
      setBusy(false);
      setConfirmDelete(false);
    }
  };

  return (
    <Screen>
      <BackLink label={scheduleName(user) ?? 'Back'} />
      <Title>{existing ? `Edit ${existing.subject}` : 'Add a class'}</Title>
      {!existing && <Body muted>One meeting at a time: a class that meets twice a week is added twice.</Body>}
      {existing?.source === 'cor' && <Body muted>This class came from your COR. If you change it, importing your COR again keeps your change.</Body>}

      {notice ? <Notice tone="error">{notice}</Notice> : null}

      <Field label="Subject" value={form.subject} onChangeText={(text) => set('subject', text)} placeholder="IT 119" autoCapitalize="characters" maxLength={50} error={errors.subject} />
      <Field label="Section (optional)" value={form.section} onChangeText={(text) => set('section', text)} placeholder="BSIT3B" autoCapitalize="characters" maxLength={30} error={errors.section} />
      <DayPicker value={form.day} onChange={(day) => set('day', day)} error={errors.day} />
      <View style={styles.times}>
        <TimePicker
          label="Starts"
          value={form.starts_at}
          onChange={(time) => {
            set('starts_at', time);
            if (!form.ends_at || form.ends_at <= time) set('ends_at', anHourLater(time));
          }}
          error={errors.starts_at}
        />
        <TimePicker label="Ends" value={form.ends_at} onChange={(time) => set('ends_at', time)} error={errors.ends_at} />
      </View>
      <RoomPicker choices={choices} value={form.room_choice} onChange={(choice) => set('room_choice', choice)} error={errors.room_choice} />
      {form.room_choice === 'other' && (
        <Field label="Where does it meet?" value={form.room_other} onChangeText={(text) => set('room_other', text)} placeholder="Gym" maxLength={100} error={errors.room_other} />
      )}

      <Button title={existing ? 'Save changes' : 'Add class'} loadingTitle="Saving…" loading={busy && !confirmDelete} disabled={busy} onPress={save} />

      {existing &&
        (confirmDelete ? (
          <View style={styles.confirm} accessibilityLiveRegion="polite">
            <Text style={styles.confirmText}>Delete {existing.subject} on this day? This can’t be undone.</Text>
            <View style={styles.confirmButtons}>
              <View style={styles.flex}>
                <Button title="Keep it" variant="quiet" disabled={busy} onPress={() => setConfirmDelete(false)} />
              </View>
              <View style={styles.flex}>
                <Button title="Delete" variant="danger" loadingTitle="Deleting…" loading={busy} onPress={remove} />
              </View>
            </View>
          </View>
        ) : (
          <Button title="Delete this class" variant="link" center onPress={() => setConfirmDelete(true)} />
        ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  times: { gap: space.md },
  confirm: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.errorTint },
  confirmText: { fontFamily: fonts.medium, fontSize: 16, lineHeight: 22, color: colors.error },
  confirmButtons: { flexDirection: 'row', gap: space.sm },
});
