import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/icon';
import { LoadState } from '@/components/portal-parts';
import { BackLink, Body, Notice, Screen, Title } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { bellStore, useResource } from '@/lib/portal-data';
import type { BellNotification } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' }) : '';

/**
 * The notification bell, like the web's: the newest 8, unread ones marked. Opening "The administrator replied" marks
 * it read and shows the message on Help & contact.
 */
export default function Notifications() {
  const bell = useResource(bellStore);
  const [error, setError] = useState<string | null>(null);
  const items = bell.data?.data ?? [];
  const unread = bell.data?.unread_count ?? 0;

  const markRead = (ids: string[] | 'all', count: number) => {
    const current = bellStore.get().data;
    if (!current) return;
    bellStore.set({
      unread_count: count,
      data: current.data.map((item) => (ids === 'all' || ids.includes(item.id) ? { ...item, read: true } : item)),
    });
  };

  const open = async (item: BellNotification) => {
    setError(null);
    if (!item.read) {
      try {
        const result = await api<{ unread_count: number }>(`/notifications/${item.id}/read`, { method: 'POST' });
        markRead([item.id], result.unread_count);
      } catch {
        // Opening still works; it stays unread until the next try.
      }
    }
    if (item.kind === 'concern_reply') router.push({ pathname: '/portal/help', params: item.concern_id ? { concern: String(item.concern_id) } : {} });
  };

  const readAll = async () => {
    setError(null);
    try {
      await api('/notifications/read', { method: 'POST' });
      markRead('all', 0);
    } catch (failure) {
      setError(errorMessage(failure));
    }
  };

  return (
    <Screen onRefresh={() => bellStore.load(true)} refreshing={bell.loading && bell.data !== null}>
      <BackLink label="Home" />
      <View style={styles.head}>
        <View style={styles.flex}>
          <Title>Notifications</Title>
        </View>
        {unread > 0 && (
          <Pressable onPress={readAll} accessibilityRole="button" hitSlop={8} style={styles.readAll}>
            <Text style={styles.readAllText}>Mark all as read</Text>
          </Pressable>
        )}
      </View>
      <LoadState resource={bell} what="notifications" onRetry={() => bellStore.load(true)} />
      {error ? <Notice tone="error">{error}</Notice> : null}

      {bell.data && items.length === 0 && (
        <View style={styles.empty}>
          <Icon name="bell" size={32} color={colors.muted} />
          <Text style={type.heading}>You’re all caught up</Text>
          <Body muted>When the administrator replies to a message you sent, it shows here.</Body>
        </View>
      )}

      {items.length > 0 && (
        <View style={styles.list}>
          {items.map((item, index) => (
            <Pressable
              key={item.id}
              onPress={() => open(item)}
              accessibilityRole="button"
              accessibilityLabel={`${item.read ? '' : 'Unread. '}${item.title}. ${item.body}`}
              style={({ pressed }) => [styles.row, index > 0 && styles.divider, !item.read && styles.unread, pressed && styles.pressed]}>
              <View style={[styles.dot, item.read && styles.dotRead]} />
              <View style={styles.flex}>
                <Text style={[styles.title, !item.read && { fontFamily: fonts.bold }]}>{item.title}</Text>
                <Text style={styles.body} numberOfLines={3}>
                  {item.body}
                </Text>
                <Text style={type.small}>{when(item.created_at)}</Text>
              </View>
              <Icon name="chevron" size={18} color={colors.muted} />
            </Pressable>
          ))}
        </View>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  head: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  readAll: { minHeight: 44, justifyContent: 'center' },
  readAllText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.primary, textDecorationLine: 'underline' },
  empty: { alignItems: 'center', gap: space.sm, paddingVertical: space.xl },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, paddingVertical: 14 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  unread: { backgroundColor: '#FFFCF3' },
  pressed: { backgroundColor: '#F3EEE8' },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.secondary },
  dotRead: { backgroundColor: 'transparent' },
  title: { fontFamily: fonts.semibold, fontSize: 16, lineHeight: 21, color: colors.text },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text, marginVertical: 2 },
});
