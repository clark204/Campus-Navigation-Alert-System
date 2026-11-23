import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import { alertForm, alertsStore, campusDateTime, type AlertFormData, type ManagedAlert, type SafetyView } from '@/lib/admin';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useResource } from '@/lib/portal-data';
import type { AlertSeverity } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from '../icon';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from '../ui';
import { Chips, DrillBadge, LevelBadge, LevelPicker } from './parts';

const multiline = { minHeight: 96, textAlignVertical: 'top' as const, paddingTop: 14, paddingBottom: 14, lineHeight: 24 };

/** The active alert with this id from the shared overview (null while it loads; undefined when it isn't active). */
function useActiveAlert(id: string | undefined): ManagedAlert | null | undefined {
  const overview = useResource(alertsStore);
  if (!overview.data) return null;
  const active = overview.data.active;
  return active && String(active.id) === id ? active : undefined;
}

/**
 * End the alert: "All clear" or "False alarm" (?resolution=), with the message everyone sees, like the web's resolve
 * form (ResolveAlertRequest). The starting text is AlertResolution::defaultMessage (the drill one for a drill).
 */
export function EndAlertScreen({ backLabel }: { backLabel: string }) {
  const { id, resolution } = useLocalSearchParams<{ id: string; resolution: 'all_clear' | 'false_alarm' }>();
  const alert = useActiveAlert(id);
  const [form, setForm] = useState<AlertFormData | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    alertForm().then(setForm, (failure) => setError(errorMessage(failure)));
  }, []);

  const option = form?.resolutions.find((r) => r.value === resolution) ?? null;
  const text = message ?? (option && alert ? (alert.is_drill ? option.drill_message : option.message) : '');

  if (done) {
    return (
      <Screen>
        <Title>{option?.label ?? 'Ended'}</Title>
        <Notice tone="success">{done}</Notice>
        <Button title="Back" onPress={() => router.back()} />
      </Screen>
    );
  }

  if (alert === undefined) {
    return (
      <Screen>
        <BackLink label={backLabel} />
        <Title>This alert has already ended</Title>
        <Body muted>Someone ended it, or it changed. Go back to see the latest.</Body>
      </Screen>
    );
  }

  const end = async () => {
    if (!alert || !option) return;
    setBusy(true);
    setError(null);
    try {
      const result = await api<{ message: string }>(`/alerts/${alert.id}/resolve`, { method: 'POST', body: { resolution: option.value, resolved_message: text } });
      alertsStore.load(true);
      setDone(result.message);
    } catch (failure) {
      setError(failure instanceof ApiError ? (failure.field('resolved_message') ?? failure.message) : errorMessage(failure));
    } finally {
      setBusy(false);
    }
  };

  const allClear = resolution === 'all_clear';

  return (
    <Screen>
      <BackLink label={backLabel} />
      <Title>{allClear ? 'All clear' : 'False alarm'}</Title>
      {!alert || !option ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <>
          <Body muted>
            {allClear
              ? 'The emergency is over. Everyone gets a notification, and the banner turns green for 15 minutes.'
              : 'There was no emergency. Everyone gets a notification that it was a false alarm.'}
          </Body>
          <View style={styles.ending}>
            <View style={styles.row}>
              <LevelBadge level={alert.severity} label={alert.severity_label} />
              {alert.is_drill && <DrillBadge />}
            </View>
            <Text style={styles.endingTitle}>{alert.title}</Text>
            <Text style={type.small}>{alert.location}</Text>
          </View>
          <Field label="What everyone sees now" value={text} onChangeText={setMessage} multiline maxLength={500} style={multiline} error={error ?? undefined} />
          <Button title={allClear ? 'Send All clear to everyone' : 'Tell everyone it was a false alarm'} loadingTitle="Sending…" loading={busy} onPress={end} />
        </>
      )}
    </Screen>
  );
}

/** Edit wording and level (admin), like the web's edit form: PUT /api/alerts/{id}. A new level is pushed again. */
export function EditAlertScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const alert = useActiveAlert(id);
  const [form, setForm] = useState<AlertFormData | null>(null);
  const [draft, setDraft] = useState<{ title: string; message: string; instructions: string; severity: AlertSeverity } | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    alertForm().then(setForm, (failure) => setNotice(errorMessage(failure)));
  }, []);
  const current = draft ?? (alert ? { title: alert.title, message: alert.message, instructions: alert.instructions ?? '', severity: alert.severity } : null);

  if (alert === undefined) {
    return (
      <Screen>
        <BackLink label="Dashboard" />
        <Title>This alert has ended</Title>
        <Body muted>Only an active alert can be edited.</Body>
      </Screen>
    );
  }

  const save = async () => {
    if (!alert || !current) return;
    setBusy(true);
    setErrors({});
    setNotice(null);
    try {
      await api(`/alerts/${alert.id}`, { method: 'PUT', body: { ...current, instructions: current.instructions || null } });
      alertsStore.load(true);
      router.back();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors(failure.fields);
      else setNotice(errorMessage(failure));
      setBusy(false);
    }
  };
  const set = (patch: Partial<NonNullable<typeof current>>) => setDraft({ ...current!, ...patch });

  return (
    <Screen>
      <BackLink label="Dashboard" />
      <Title>Edit the alert</Title>
      <Body muted>New wording shows everywhere within 15 seconds. A new level also sends everyone a new notification.</Body>
      {notice && <Notice tone="error">{notice}</Notice>}
      {!current || !form ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <>
          <Field label="Title" value={current.title} onChangeText={(title) => set({ title })} maxLength={120} error={errors.title} />
          <Field label="What is happening" value={current.message} onChangeText={(message) => set({ message })} multiline maxLength={1000} style={multiline} error={errors.message} />
          <Field label="What to do (optional)" value={current.instructions} onChangeText={(instructions) => set({ instructions })} multiline maxLength={2000} style={multiline} error={errors.instructions} />
          <Text style={type.label}>Level</Text>
          <LevelPicker levels={form.severities} value={current.severity} onChange={(severity) => set({ severity })} />
          <Button title="Save changes" loadingTitle="Saving…" loading={busy} onPress={save} />
        </>
      )}
    </Screen>
  );
}

const SAFETY_POLL_MS = 5000;

/**
 * "I'm safe" live view (admin): the counts, by college, and who is safe, newest first (GET /api/alerts/{id}/safety).
 * Refreshes every 5 seconds while open.
 */
export function SafetyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [college, setCollege] = useState<string>('');
  const [view, setView] = useState<SafetyView | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<number | null>(null);

  useFocusEffect(
    useCallback(() => {
      let stopped = false;
      const load = () =>
        api<SafetyView>(`/alerts/${id}/safety${college ? `?college=${encodeURIComponent(college)}` : ''}`).then(
          (next) => {
            if (stopped) return;
            setView(next);
            setError(null);
            setUpdatedAt(Date.now());
          },
          (failure) => !stopped && setError(errorMessage(failure)),
        );
      load();
      const timer = setInterval(load, SAFETY_POLL_MS);
      return () => {
        stopped = true;
        clearInterval(timer);
      };
    }, [id, college]),
  );

  const share = view && view.total > 0 ? view.safe / view.total : 0;

  return (
    <Screen>
      <BackLink label="Dashboard" />
      <Title>Who is safe</Title>
      {error && <Notice tone="error">{error}</Notice>}
      {!view ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <>
          <View style={styles.counts} accessibilityLiveRegion="polite">
            <Text style={styles.countBig}>{view.safe}</Text>
            <View style={styles.flex}>
              <Text style={styles.countLabel}>safe of {view.total} accounts</Text>
              <Text style={type.small}>{view.not_answered} not answered yet</Text>
            </View>
          </View>
          <View style={styles.bar} accessibilityElementsHidden>
            <View style={[styles.barFill, { width: `${Math.round(share * 100)}%` }]} />
          </View>
          <Text style={type.small}>
            Updates every few seconds{updatedAt ? `. Last ${new Date(updatedAt).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' })}` : ''}.
          </Text>

          <Chips
            label="College"
            value={college}
            onChange={setCollege}
            options={[{ value: '', label: 'Everyone' }, ...view.colleges.map((c) => ({ value: c.id, label: `${c.name}  ${c.safe}/${c.total}` }))]}
          />

          <View style={styles.list}>
            {view.people.length === 0 ? (
              <Text style={[type.small, { padding: space.md }]}>Nobody here has answered yet.</Text>
            ) : (
              view.people.map((person, index) => (
                <View key={`${person.name}-${person.responded_at}-${index}`} style={[styles.person, index > 0 && styles.divider]}>
                  <Icon name="check" size={18} color={colors.safe} strokeWidth={2.6} />
                  <View style={styles.flex}>
                    <Text style={styles.personName}>{person.name}</Text>
                    <Text style={type.small}>{[person.role, person.college].filter(Boolean).join(', ')}</Text>
                  </View>
                  <Text style={type.small}>{campusDateTime(person.responded_at)}</Text>
                </View>
              ))
            )}
          </View>
          <Text style={type.small}>Answers are seen only by administrators and deleted 30 days after the alert ends.</Text>
        </>
      )}
    </Screen>
  );
}

/** The alert history with what happened to each (the admin's has the log). */
export function AlertHistoryScreen({ backLabel }: { backLabel: string }) {
  const overview = useResource(alertsStore);
  const [more, setMore] = useState<ManagedAlert[]>([]);
  const [page, setPage] = useState(1);
  const [loadingMore, setLoadingMore] = useState(false);
  const meta = overview.data?.meta ?? null;
  const items = [...(overview.data?.history ?? []), ...more];

  const loadMore = async () => {
    setLoadingMore(true);
    try {
      const next = await api<{ history: ManagedAlert[] }>(`/alerts?page=${page + 1}`);
      setMore((current) => [...current, ...next.history]);
      setPage(page + 1);
    } finally {
      setLoadingMore(false);
    }
  };

  return (
    <Screen onRefresh={() => alertsStore.load(true)} refreshing={false}>
      <BackLink label={backLabel} />
      <Title>Alert history</Title>
      {!overview.data && <ActivityIndicator color={colors.primary} />}
      {overview.data && items.length === 0 && <Body muted>No alerts have been sent yet.</Body>}
      {items.map((item) => (
        <View key={item.id} style={styles.history}>
          <View style={styles.row}>
            <Text style={[type.label, styles.flex]}>{item.title}</Text>
            {item.status === 'active' ? (
              <View style={[styles.pill, { backgroundColor: colors.alarm }]}>
                <Text style={[styles.pillText, { color: colors.white }]}>Active</Text>
              </View>
            ) : (
              <View style={[styles.pill, { backgroundColor: colors.safeTint }]}>
                <Text style={[styles.pillText, { color: colors.safe }]}>{item.resolution_label ?? 'Resolved'}</Text>
              </View>
            )}
          </View>
          <View style={styles.row}>
            <LevelBadge level={item.severity} label={item.severity_label} />
            {item.is_drill && <DrillBadge />}
            <Text style={type.small}>{item.type_label}</Text>
          </View>
          <Text style={type.small}>{item.location}</Text>
          {item.resolved_message ? <Text style={type.small}>{`${item.resolution_label ?? 'All clear'}: ${item.resolved_message}`}</Text> : null}
          {item.logs ? (
            item.logs.map((log, index) => (
              <Text key={index} style={styles.log}>
                {log.action_label}
                {log.detail ? ` (${log.detail})` : ''} {campusDateTime(log.at)} by {log.by ?? 'a deleted account'}
              </Text>
            ))
          ) : (
            <Text style={styles.log}>
              Sent {campusDateTime(item.sent_at)} by {item.sent_by ?? 'a deleted account'}
            </Text>
          )}
        </View>
      ))}
      {meta && page < meta.last_page && <Button title="Show older alerts" variant="quiet" loading={loadingMore} loadingTitle="Loading…" onPress={loadMore} />}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm, flexWrap: 'wrap' },
  ending: { gap: 6, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  endingTitle: { fontFamily: fonts.bold, fontSize: 18, color: colors.text },
  counts: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.md, borderRadius: radius.md, backgroundColor: colors.safeTint },
  countBig: { fontFamily: fonts.bold, fontSize: 48, lineHeight: 54, color: colors.safe },
  countLabel: { fontFamily: fonts.semibold, fontSize: 17, color: colors.safe },
  bar: { height: 10, borderRadius: 5, backgroundColor: colors.border, overflow: 'hidden' },
  barFill: { height: 10, backgroundColor: colors.safe },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  person: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md, paddingVertical: 12 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  personName: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  history: { gap: 6, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  pillText: { fontFamily: fonts.semibold, fontSize: 13 },
  log: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: colors.muted },
});

