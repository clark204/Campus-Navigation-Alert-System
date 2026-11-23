import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { EmergencyContacts } from '@/components/emergency-contacts';
import { Icon } from '@/components/icon';
import { BackLink, Body, Button, Field, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { bellStore } from '@/lib/portal-data';
import type { Concern, ConcernTopic } from '@/lib/types';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';

// StoreConcernRequest::MESSAGE_MAX and the web's message rules.
const MESSAGE_MAX = 2000;

type Loaded = { concerns: Concern[]; topics: ConcernTopic[]; email: string };

const campusDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' }) : '';

/**
 * "Help & contact", like the portal page: send a concern to Admin > Concerns (POST /api/concerns; the reply goes to the
 * account email), then "My messages" with Open or Resolved and the administrator's replies (GET /api/concerns, which
 * also marks the reply notifications read). ?topic= picks a topic; ?concern= (from a notification) highlights one.
 */
export default function Help() {
  const params = useLocalSearchParams<{ topic?: string; concern?: string }>();
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [topic, setTopic] = useState<string | null>(params.topic ?? null);
  const [message, setMessage] = useState('');
  const [errors, setErrors] = useState<{ topic?: string; message?: string }>({});
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
  const toast = useToast();
  const [sending, setSending] = useState(false);

  // State is only set when the answer comes (the effect below runs it on open; pull to refresh and Try again too).
  const fetchConcerns = useCallback(
    () =>
      api<{ data: Concern[]; meta: { email: string; topics: ConcernTopic[] } }>('/concerns').then(
        (result) => {
          setLoaded({ concerns: result.data, topics: result.meta.topics, email: result.meta.email });
          setLoadError(null);
          setLoading(false);
          // Opening My messages marked the reply notifications read on the server.
          bellStore.load(true);
        },
        (failure) => {
          setLoadError(errorMessage(failure));
          setLoading(false);
        },
      ),
    [],
  );
  const load = () => {
    setLoading(true);
    fetchConcerns();
  };

  useEffect(() => {
    fetchConcerns();
  }, [fetchConcerns]);

  const send = async () => {
    setSending(true);
    setErrors({});
    setNotice(null);
    try {
      const result = await api<{ message: string }>('/concerns', { method: 'POST', body: { topic, message } });
      toast(result.message);
      setMessage('');
      setTopic(null);
      load();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 422) setErrors({ topic: failure.field('topic'), message: failure.field('message') });
      else setNotice({ tone: 'error', text: errorMessage(failure) });
    } finally {
      setSending(false);
    }
  };

  return (
    <Screen onRefresh={load} refreshing={loading && loaded !== null}>
      <BackLink label="Home" />
      <Title>Help & contact</Title>
      <Body muted>A problem with your account, your schedule or the campus map? Tell the CNAS administrator here.</Body>

      {loaded === null && loading && <ActivityIndicator color={colors.primary} style={styles.spinner} />}
      {loadError && (
        <Notice tone="error">
          {loadError}
          {'  '}
          <Text onPress={load} style={styles.inlineLink} accessibilityRole="button">
            Try again
          </Text>
        </Notice>
      )}

      {loaded && (
        <View style={styles.card}>
          <Text style={type.heading} accessibilityRole="header">
            Send a concern or suggestion
          </Text>
          <Text style={type.small}>
            The administrator replies to <Text style={styles.strong}>{loaded.email}</Text>.
          </Text>

          <View style={styles.field}>
            <Text style={type.label}>What is it about?</Text>
            <View style={styles.chips} accessibilityRole="radiogroup">
              {loaded.topics.map((option) => {
                const on = topic === option.value;
                return (
                  <Pressable
                    key={option.value}
                    onPress={() => {
                      setTopic(option.value);
                      setErrors(({ topic: _, ...rest }) => rest);
                    }}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: on }}
                    style={[styles.chip, on && styles.chipOn]}>
                    <Text style={[styles.chipText, on && styles.chipTextOn]}>{option.label}</Text>
                  </Pressable>
                );
              })}
            </View>
            {errors.topic ? <Text style={styles.error}>{errors.topic}</Text> : null}
          </View>

          <Field
            label="Message"
            value={message}
            onChangeText={(text) => {
              setMessage(text);
              setErrors(({ message: _, ...rest }) => rest);
            }}
            multiline
            maxLength={MESSAGE_MAX}
            autoCorrect
            placeholder="For example: I can’t sign in on my phone, or ComLab 3 is in the wrong place on the map."
            style={styles.message}
            error={errors.message}
            hint={message.length > MESSAGE_MAX - 200 ? `${MESSAGE_MAX - message.length} characters left` : undefined}
          />

          {/* Next to the button, where the eye is after tapping Send. */}
          {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
          <Button title="Send message" loadingTitle="Sending…" loading={sending} onPress={send} />
        </View>
      )}

      {loaded && (
        <>
          <View style={styles.head}>
            <Text style={type.heading} accessibilityRole="header">
              My messages
            </Text>
            <Text style={type.small}>The last 5 you sent. The administrator’s replies show here and are emailed to you.</Text>
          </View>
          {loaded.concerns.length === 0 ? (
            <Body muted>Nothing sent yet. Messages you send show up here with their status.</Body>
          ) : (
            loaded.concerns.map((concern) => <ConcernCard key={concern.id} concern={concern} highlighted={params.concern === String(concern.id)} />)
          )}
        </>
      )}

      <EmergencyContacts />
    </Screen>
  );
}

function ConcernCard({ concern, highlighted }: { concern: Concern; highlighted: boolean }) {
  const resolved = concern.status === 'resolved';
  return (
    <View style={[styles.concern, highlighted && styles.concernHighlighted]}>
      <View style={styles.concernHead}>
        <Text style={[type.label, styles.flex]}>{concern.topic_label}</Text>
        <View style={[styles.status, resolved ? styles.statusResolved : styles.statusOpen]}>
          <View style={[styles.statusDot, { backgroundColor: resolved ? colors.safe : colors.secondary }]} />
          <Text style={[styles.statusText, { color: resolved ? colors.safe : colors.primary }]}>{resolved ? 'Resolved' : 'Open'}</Text>
        </View>
      </View>
      <Text style={styles.concernMessage}>{concern.message}</Text>
      <Text style={type.small}>Sent {campusDate(concern.sent_at)}</Text>
      {concern.replies.map((reply) => (
        <View key={reply.id} style={styles.reply}>
          <View style={styles.replyHead}>
            <Icon name="mail" size={16} color={colors.primary} />
            <Text style={styles.replyFrom}>The administrator replied on {campusDate(reply.sent_at)}</Text>
          </View>
          <Text style={styles.replyText}>{reply.message}</Text>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  spinner: { marginVertical: space.lg },
  inlineLink: { fontFamily: fonts.semibold, textDecorationLine: 'underline', color: colors.primary },
  card: { gap: space.md, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  strong: { fontFamily: fonts.semibold, color: colors.text },
  field: { gap: 8 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 42, borderRadius: 21, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.bg, justifyContent: 'center', paddingHorizontal: 14 },
  chipOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  chipTextOn: { color: colors.white, fontFamily: fonts.semibold },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  message: { minHeight: 140, textAlignVertical: 'top', paddingTop: 14, paddingBottom: 14, lineHeight: 24 },
  head: { gap: 2, marginTop: space.sm },
  concern: { gap: 6, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  concernHighlighted: { borderColor: colors.secondary, borderWidth: 2, backgroundColor: '#FFFCF3' },
  concernHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  status: { flexDirection: 'row', alignItems: 'center', gap: 6, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  statusOpen: { backgroundColor: '#F6EDD3' },
  statusResolved: { backgroundColor: colors.safeTint },
  statusDot: { width: 7, height: 7, borderRadius: 4 },
  statusText: { fontFamily: fonts.semibold, fontSize: 13 },
  concernMessage: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
  reply: { marginTop: 6, gap: 4, paddingVertical: 10, paddingHorizontal: 12, borderLeftWidth: 4, borderLeftColor: colors.secondary, backgroundColor: colors.bg, borderRadius: radius.sm },
  replyHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  replyFrom: { fontFamily: fonts.semibold, fontSize: 13, color: colors.primary },
  replyText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 23, color: colors.text },
});
