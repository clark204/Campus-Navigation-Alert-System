import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { alertForm, alertsStore, campusDateTime, type AlertFormData, type AlertsOverview } from '@/lib/admin';
import { api, errorMessage } from '@/lib/api';
import type { AlertSeverity } from '@/lib/types';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from '../icon';
import { Button, Notice } from '../ui';
import { DrillBadge, LEVEL_STYLE, LevelBadge } from './parts';

/** While the dashboard is open the active alert and its "I'm safe" count refresh this often. */
const POLL_MS = 10 * 1000;

/** Refresh the alerts overview now and every POLL_MS while the screen is focused. */
export function useLiveAlerts() {
  useFocusEffect(
    useCallback(() => {
      alertsStore.load(true);
      const timer = setInterval(() => alertsStore.load(true), POLL_MS);
      return () => clearInterval(timer);
    }, []),
  );
}

/**
 * The active alert for the people who send them: level, wording, place, who sent it; for the admin the live "I'm safe"
 * count, Change level and Edit wording; for everyone who can send, All clear and False alarm. With no active alert:
 * Send an alert. `area` is where the pages live: /admin or /portal (trusted faculty and staff).
 */
export function ActiveAlertPanel({ overview, area }: { overview: AlertsOverview | null; area: '/admin' | '/portal' }) {
  const active = overview?.active ?? null;

  if (!overview) return <ActivityIndicator color={colors.primary} style={{ marginVertical: space.lg }} />;

  if (!active) {
    return (
      <View style={styles.wrap}>
        <View style={styles.quiet}>
          <View style={styles.quietIcon}>
            <Icon name="check" size={24} color={colors.white} strokeWidth={2.6} />
          </View>
          <View style={styles.flex}>
            <Text style={type.label}>No active alert</Text>
            <Text style={type.small}>Everyone sees a normal home screen.</Text>
          </View>
        </View>
        <Pressable onPress={() => router.push(`${area}/send-alert`)} accessibilityRole="button" style={({ pressed }) => [styles.send, pressed && styles.sendPressed]}>
          <Icon name="megaphone" size={26} color={colors.white} />
          <View style={styles.flex}>
            <Text style={styles.sendTitle}>Send an alert</Text>
            <Text style={styles.sendDetail}>Fire, earthquake, flood, typhoon, security, or a drill</Text>
          </View>
          <Icon name="chevron" size={22} color={colors.white} />
        </Pressable>
      </View>
    );
  }

  const level = LEVEL_STYLE[active.severity];
  const safety = overview.safety;

  return (
    <View style={[styles.card, { borderColor: level.bg }]}>
      <View style={[styles.head, { backgroundColor: level.bg }]}>
        <Icon name={active.type} size={22} color={level.text} />
        <Text style={[styles.headText, { color: level.text }]}>Active now: {active.type_label}</Text>
        {active.is_drill && <DrillBadge />}
      </View>
      <View style={styles.body}>
        <LevelBadge level={active.severity} label={active.severity_label} />
        <Text style={styles.title}>{active.title}</Text>
        <View style={styles.placeRow}>
          <Icon name="pin" size={17} color={colors.alarm} />
          <Text style={styles.place}>
            {active.location}
            {active.danger_radius_m ? <Text style={type.small}>{`  (${active.danger_radius_m} m danger zone)`}</Text> : null}
          </Text>
        </View>
        <Text style={type.body}>{active.message}</Text>
        {active.instructions ? <Text style={[type.body, { fontFamily: fonts.semibold }]}>{active.instructions}</Text> : null}
        <Text style={type.small}>
          Sent {campusDateTime(active.sent_at)} by {active.sent_by ?? 'a deleted account'}
        </Text>

        {safety && (
          <Pressable
            onPress={() => router.push({ pathname: '/admin/safety', params: { id: String(active.id) } })}
            accessibilityRole="button"
            accessibilityLabel={`${safety.need_help ? `${safety.need_help} need help, ` : ''}${safety.safe} safe, ${safety.not_answered} not answered yet. See who`}
            style={({ pressed }) => [styles.safety, pressed && { opacity: 0.85 }]}>
            <View style={styles.flex}>
              <Text style={styles.safetyLabel}>I’m safe</Text>
              {safety.need_help > 0 && (
                <Text style={styles.needHelp}>
                  {safety.need_help} {safety.need_help === 1 ? 'person needs help' : 'people need help'}
                </Text>
              )}
              <Text style={styles.safetyNumbers}>
                <Text style={styles.safetyBig}>{safety.safe}</Text> safe
                <Text style={styles.safetyMuted}>{`   ${safety.not_answered} not answered yet`}</Text>
              </Text>
              {active.severity !== 'emergency' && <Text style={type.small}>Only an emergency asks “I’m safe”.</Text>}
            </View>
            <Text style={styles.safetyLink}>See who</Text>
            <Icon name="chevron" size={18} color={colors.safe} />
          </Pressable>
        )}

        {overview.can_edit && <ChangeLevel alertId={active.id} current={active.severity} wording={{ title: active.title, message: active.message, what_to_do: active.what_to_do || null, instructions: active.instructions }} />}
        {overview.can_edit && (
          <Button title="Edit wording" variant="quiet" onPress={() => router.push({ pathname: '/admin/edit-alert', params: { id: String(active.id) } })} />
        )}

        <View style={styles.endRow}>
          <Pressable
            onPress={() => router.push({ pathname: `${area}/end-alert`, params: { id: String(active.id), resolution: 'all_clear' } })}
            accessibilityRole="button"
            style={({ pressed }) => [styles.end, styles.allClear, pressed && { opacity: 0.85 }]}>
            <Icon name="clear" size={20} color={colors.white} />
            <Text style={styles.endText}>All clear</Text>
          </Pressable>
          <Pressable
            onPress={() => router.push({ pathname: `${area}/end-alert`, params: { id: String(active.id), resolution: 'false_alarm' } })}
            accessibilityRole="button"
            style={({ pressed }) => [styles.end, styles.falseAlarm, pressed && { opacity: 0.85 }]}>
            <Text style={[styles.endText, { color: colors.text }]}>False alarm</Text>
          </Pressable>
        </View>
      </View>
    </View>
  );
}

/** Change level (admin): pick another level, confirm, PUT /api/alerts/{id} with the same wording. Logged and pushed again. */
function ChangeLevel({ alertId, current, wording }: { alertId: number; current: AlertSeverity; wording: { title: string; message: string; what_to_do: string | null; instructions: string | null } }) {
  const [form, setForm] = useState<AlertFormData | null>(null);
  const [asking, setAsking] = useState<AlertSeverity | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const toast = useToast();

  useFocusEffect(
    useCallback(() => {
      alertForm().then(setForm, () => {});
    }, []),
  );
  if (!form) return null;

  const change = async (to: AlertSeverity) => {
    setBusy(true);
    setNotice(null);
    try {
      await api(`/alerts/${alertId}`, { method: 'PUT', body: { ...wording, severity: to } });
      toast(`Changed to ${form.severities.find((s) => s.value === to)?.label}. Everyone gets a new notification.`);
      setAsking(null);
      alertsStore.load(true);
    } catch (failure) {
      setNotice({ tone: 'error', text: errorMessage(failure) });
    } finally {
      setBusy(false);
    }
  };

  const target = asking ? form.severities.find((s) => s.value === asking) : null;

  return (
    <View style={styles.levelBox}>
      <Text style={type.label}>Level</Text>
      <View style={styles.levelRow} accessibilityRole="radiogroup">
        {form.severities.map((s) => {
          const on = s.value === current;
          return (
            <Pressable
              key={s.value}
              onPress={() => (on ? setAsking(null) : setAsking(s.value))}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              style={[styles.levelChip, on && { backgroundColor: LEVEL_STYLE[s.value].bg, borderColor: LEVEL_STYLE[s.value].bg }, asking === s.value && { borderColor: s.color }]}>
              <Text style={[styles.levelChipText, on && { color: LEVEL_STYLE[s.value].text }]}>{s.label}</Text>
            </Pressable>
          );
        })}
      </View>
      {target && (
        <View style={styles.ask}>
          <Text style={styles.askText}>Change to {target.label}? Everyone gets a new notification.</Text>
          <Text style={type.small}>{target.students_get}</Text>
          <View style={styles.askButtons}>
            <View style={styles.flex}>
              <Button title="Keep it" variant="quiet" disabled={busy} onPress={() => setAsking(null)} />
            </View>
            <View style={styles.flex}>
              <Button title="Change" loadingTitle="Changing…" loading={busy} onPress={() => change(target.value)} />
            </View>
          </View>
        </View>
      )}
      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  wrap: { gap: space.md },
  quiet: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: space.md, borderRadius: radius.md, backgroundColor: colors.safeTint },
  quietIcon: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.safe, alignItems: 'center', justifyContent: 'center' },
  send: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 72, padding: space.md, borderRadius: radius.md, backgroundColor: colors.alarm },
  sendPressed: { backgroundColor: colors.alarmDeep },
  sendTitle: { fontFamily: fonts.bold, fontSize: 19, color: colors.white },
  sendDetail: { fontFamily: fonts.regular, fontSize: 14, color: 'rgba(255,255,255,0.88)' },
  card: { borderRadius: radius.lg, borderWidth: 2, overflow: 'hidden', backgroundColor: colors.surface },
  head: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md, paddingVertical: 12 },
  headText: { flex: 1, fontFamily: fonts.bold, fontSize: 17 },
  body: { padding: space.md, gap: space.sm },
  title: { fontFamily: fonts.bold, fontSize: 21, lineHeight: 27, color: colors.text },
  placeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  place: { flex: 1, fontFamily: fonts.semibold, fontSize: 15, color: colors.alarm },
  safety: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.safeTint },
  needHelp: { fontFamily: fonts.bold, fontSize: 17, color: colors.alarm },
  safetyLabel: { fontFamily: fonts.semibold, fontSize: 14, color: colors.safe },
  safetyNumbers: { fontFamily: fonts.medium, fontSize: 16, color: colors.safe },
  safetyBig: { fontFamily: fonts.bold, fontSize: 28, color: colors.safe },
  safetyMuted: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted },
  safetyLink: { fontFamily: fonts.semibold, fontSize: 15, color: colors.safe },
  levelBox: { gap: 8 },
  levelRow: { flexDirection: 'row', gap: 8 },
  levelChip: { flex: 1, minHeight: 44, borderRadius: radius.sm, borderWidth: 2, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  levelChipText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  ask: { gap: 6, padding: space.md, borderRadius: radius.md, backgroundColor: '#F6F1EC' },
  askText: { fontFamily: fonts.semibold, fontSize: 16, lineHeight: 22, color: colors.text },
  askButtons: { flexDirection: 'row', gap: space.sm, marginTop: 4 },
  endRow: { flexDirection: 'row', gap: space.sm, marginTop: space.xs },
  end: { flex: 1, minHeight: 56, borderRadius: radius.md, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 },
  allClear: { backgroundColor: colors.safe },
  falseAlarm: { backgroundColor: colors.surface, borderWidth: 1.5, borderColor: colors.border },
  endText: { fontFamily: fonts.bold, fontSize: 17, color: colors.white },
});
