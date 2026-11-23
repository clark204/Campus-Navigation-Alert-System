import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { useReportArrivals } from '@/lib/admin-reports';
import { campusDateTime, concernsStore, type ConcernConversation, type ConcernPage, type ConcernRow } from '@/lib/admin';
import { api, ApiError, errorMessage } from '@/lib/api';
import { colors, fonts, radius, space, type } from '@/theme';
import { Chips } from '../alert-admin/parts';
import { BackLink, Body, Button, Checkbox, Field, Notice, Screen, Title } from '../ui';

/** How often the open list asks again (the web's badge waits 30 s). */
const LIVE_MS = 15 * 1000;

/**
 * Admin > Concerns, the app's inbox (GET /api/admin/concerns, the web's ConcernInbox): Open and Resolved, newest first,
 * new ones in bold with a gold dot (this admin hasn't opened them). Tap one to read and reply.
 */
export function ConcernsTab() {
  const [status, setStatus] = useState<'open' | 'resolved'>('open');
  const [page, setPage] = useState<ConcernPage | null>(null);
  const [extra, setExtra] = useState<ConcernRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);

  const load = useCallback(
    (folder: 'open' | 'resolved') =>
      api<ConcernPage>(`/admin/concerns${folder === 'resolved' ? '?status=resolved' : ''}`).then(
        (next) => {
          setPage(next);
          setExtra([]);
          setError(null);
          // The Open folder is also the tab badge.
          if (folder === 'open') concernsStore.set(next);
        },
        (failure) => setError(errorMessage(failure)),
      ),
    [],
  );

  // Every 15 s while open, and at once when a report push arrives (admin-reports.ts).
  useFocusEffect(
    useCallback(() => {
      load(status);
      const timer = setInterval(() => load(status), LIVE_MS);
      return () => clearInterval(timer);
    }, [load, status]),
  );
  useReportArrivals(useCallback(() => load(status), [load, status]));

  const rows = [...(page?.data ?? []), ...extra];
  const current = (page?.meta.current_page ?? 1) + Math.ceil(extra.length / 20);

  const more = async () => {
    setLoadingMore(true);
    try {
      const next = await api<ConcernPage>(`/admin/concerns?page=${current + 1}${status === 'resolved' ? '&status=resolved' : ''}`);
      setExtra((list) => [...list, ...next.data]);
    } catch (failure) {
      setError(errorMessage(failure));
    } finally {
      setLoadingMore(false);
    }
  };

  return (
    <Screen onRefresh={() => load(status)} refreshing={false}>
      <Title>Concerns</Title>
      <Chips
        label="Folder"
        value={status}
        onChange={(folder) => {
          setStatus(folder);
          setPage(null);
        }}
        options={[
          { value: 'open', label: `Open${page ? ` ${page.meta.open_count}` : ''}` },
          { value: 'resolved', label: `Resolved${page ? ` ${page.meta.resolved_count}` : ''}` },
        ]}
      />
      {error && <Notice tone="error">{error}</Notice>}
      {!page && !error && <ActivityIndicator color={colors.primary} />}
      {page && rows.length === 0 && (
        <Body muted>{status === 'open' ? 'Nothing open. New messages from Help & contact and the website footer show here.' : 'Nothing resolved yet.'}</Body>
      )}
      {rows.length > 0 && (
        <View style={styles.list}>
          {rows.map((row, index) => (
            <Pressable
              key={row.id}
              onPress={() => router.push({ pathname: '/admin/concern', params: { id: String(row.id) } })}
              accessibilityRole="button"
              accessibilityLabel={`${row.unread ? 'New. ' : ''}${row.sender ?? 'Visitor'}, ${row.topic_label}. ${row.message}`}
              style={({ pressed }) => [styles.row, index > 0 && styles.divider, row.unread && styles.unread, pressed && styles.pressed]}>
              <View style={[styles.dot, !row.unread && styles.dotRead]} />
              <View style={styles.flex}>
                <View style={styles.rowHead}>
                  <Text style={[styles.sender, row.unread && styles.bold]} numberOfLines={1}>
                    {row.sender ?? 'Visitor'}
                    {row.reply_count > 0 ? <Text style={type.small}>{`  ${row.reply_count + 1}`}</Text> : null}
                  </Text>
                  <Text style={type.small}>{campusDateTime(row.sent_at)}</Text>
                </View>
                <View style={styles.rowHead}>
                  <View style={styles.topic}>
                    <Text style={styles.topicText}>{row.topic_short}</Text>
                  </View>
                  {row.reply_count > 0 && <Text style={type.small}>Replied</Text>}
                </View>
                <Text style={[styles.preview, row.unread && { color: colors.text }]} numberOfLines={2}>
                  {row.message}
                </Text>
              </View>
            </Pressable>
          ))}
        </View>
      )}
      {page && current < page.meta.last_page && <Button title="Show older" variant="quiet" loading={loadingMore} loadingTitle="Loading…" onPress={more} />}
    </Screen>
  );
}

/** One concern: the message, who sent it, the replies, and the reply box ("Mark as resolved" ticked, like the web). */
export function ConcernScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [concern, setConcern] = useState<ConcernConversation | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reply, setReply] = useState('');
  const [resolve, setResolve] = useState(true);
  const [replyError, setReplyError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<'reply' | 'status' | null>(null);

  useFocusEffect(
    useCallback(() => {
      // Opening it marks it read for this admin, so the badge goes down.
      api<{ data: ConcernConversation }>(`/admin/concerns/${id}`).then(
        (result) => {
          setConcern(result.data);
          concernsStore.load(true);
        },
        (failure) => setError(errorMessage(failure)),
      );
    }, [id]),
  );

  const send = async () => {
    setBusy('reply');
    setReplyError(null);
    setNotice(null);
    try {
      const result = await api<{ message: string; data: ConcernConversation }>(`/admin/concerns/${id}/replies`, { method: 'POST', body: { message: reply, resolve } });
      setConcern(result.data);
      setNotice(result.message);
      setReply('');
      concernsStore.load(true);
    } catch (failure) {
      setReplyError(failure instanceof ApiError ? (failure.field('message') ?? failure.message) : errorMessage(failure));
    } finally {
      setBusy(null);
    }
  };

  const toggle = async () => {
    if (!concern) return;
    setBusy('status');
    setNotice(null);
    try {
      const result = await api<{ message: string; data: ConcernConversation }>(`/admin/concerns/${id}/${concern.status === 'open' ? 'resolve' : 'reopen'}`, { method: 'PATCH' });
      setConcern(result.data);
      setNotice(result.message);
      concernsStore.load(true);
    } catch (failure) {
      setNotice(errorMessage(failure));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen>
      <BackLink label="Concerns" />
      {error && <Notice tone="error">{error}</Notice>}
      {!concern && !error && <ActivityIndicator color={colors.primary} />}
      {concern && (
        <>
          <View style={styles.subjectRow}>
            <View style={styles.flex}>
              <Title>{concern.topic_label}</Title>
            </View>
            <View style={[styles.status, concern.status === 'resolved' ? styles.statusResolved : styles.statusOpen]}>
              <Text style={[styles.statusText, { color: concern.status === 'resolved' ? colors.safe : colors.primary }]}>{concern.status === 'resolved' ? 'Resolved' : 'Open'}</Text>
            </View>
          </View>

          <View style={styles.message}>
            <Text style={styles.from}>{concern.sender ?? 'Visitor'}</Text>
            <Text style={type.small}>
              {[concern.email, concern.account?.role, concern.account?.school_id, concern.account?.college].filter(Boolean).join(', ')}
            </Text>
            <Text style={type.small}>{campusDateTime(concern.sent_at)}</Text>
            <Text style={styles.messageText}>{concern.message}</Text>
          </View>

          {concern.replies.map((item) => (
            <View key={item.id} style={styles.reply}>
              <Text style={styles.replyFrom}>
                {item.by ?? 'An administrator'} to {concern.sender ?? concern.email}
              </Text>
              <Text style={type.small}>{campusDateTime(item.sent_at)}</Text>
              <Text style={styles.messageText}>{item.message}</Text>
            </View>
          ))}

          {notice && <Notice tone="success">{notice}</Notice>}

          <View style={styles.replyBox}>
            <Text style={type.label}>To {concern.email}</Text>
            <Field
              label="Your reply"
              value={reply}
              onChangeText={(text) => {
                setReply(text);
                setReplyError(null);
              }}
              multiline
              maxLength={5000}
              autoCorrect
              placeholder="Write your reply…"
              style={styles.multiline}
              error={replyError ?? undefined}
              hint={concern.account ? 'It is emailed and also shows in their portal.' : 'It is emailed to them.'}
            />
            {concern.status === 'open' && (
              <Checkbox checked={resolve} onChange={setResolve}>
                Mark as resolved
              </Checkbox>
            )}
            <Button title="Send reply" loadingTitle="Sending…" loading={busy === 'reply'} disabled={busy !== null} onPress={send} />
          </View>

          <Button
            title={concern.status === 'open' ? 'Mark as resolved without replying' : 'Reopen'}
            variant="quiet"
            loading={busy === 'status'}
            loadingTitle="Saving…"
            disabled={busy !== null}
            onPress={toggle}
          />
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', gap: 10, paddingHorizontal: space.md, paddingVertical: 12 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  unread: { backgroundColor: '#FFFCF3', borderLeftWidth: 4, borderLeftColor: colors.primary, paddingLeft: space.md - 4 },
  pressed: { backgroundColor: '#F3EEE8' },
  dot: { width: 10, height: 10, borderRadius: 5, marginTop: 6, backgroundColor: colors.secondary },
  dotRead: { backgroundColor: 'transparent' },
  rowHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space.sm },
  sender: { flex: 1, fontFamily: fonts.medium, fontSize: 16, color: colors.text },
  bold: { fontFamily: fonts.bold },
  topic: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, backgroundColor: '#F3ECEA', marginVertical: 3 },
  topicText: { fontFamily: fonts.semibold, fontSize: 12, color: colors.primary },
  preview: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  subjectRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  status: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  statusOpen: { backgroundColor: '#F6EDD3' },
  statusResolved: { backgroundColor: colors.safeTint },
  statusText: { fontFamily: fonts.semibold, fontSize: 13 },
  message: { gap: 4, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  from: { fontFamily: fonts.bold, fontSize: 17, color: colors.text },
  messageText: { fontFamily: fonts.regular, fontSize: 16, lineHeight: 24, color: colors.text, marginTop: 4 },
  reply: { gap: 2, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderLeftWidth: 4, borderLeftColor: colors.secondary },
  replyFrom: { fontFamily: fonts.semibold, fontSize: 14, color: colors.primary },
  replyBox: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  multiline: { minHeight: 120, textAlignVertical: 'top', paddingTop: 14, paddingBottom: 14, lineHeight: 24 },
});
