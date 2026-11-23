import { router } from 'expo-router';
import { useEffect, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { savedAtLabel, type Resource } from '@/lib/portal-data';
import { formatTime, inProgress, roomOf, type RoomKind } from '@/lib/schedule';
import type { ClassItem } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon, type IconName } from './icon';

// Pieces shared by the portal screens (Home, My schedule, Saved places, Help & contact, Account).

/** Re-render every minute, so "Now" and the next class move on while a screen is open. */
export function useMinute() {
  const [, setTick] = useState(0);
  useEffect(() => {
    const timer = setInterval(() => setTick((tick) => tick + 1), 60 * 1000);
    return () => clearInterval(timer);
  }, []);
}

export type MenuItem = { title: string; detail?: string; icon: IconName; onPress: () => void; badge?: number };

/** A list of in-app pages, one row each with a chevron (the in-app version of WebLinks). */
export function MenuList({ items }: { items: MenuItem[] }) {
  return (
    <View style={styles.list}>
      {items.map((item, index) => (
        <Pressable
          key={item.title}
          onPress={item.onPress}
          accessibilityRole="button"
          accessibilityLabel={item.badge ? `${item.title}, ${item.badge} new` : item.title}
          style={({ pressed }) => [styles.row, index > 0 && styles.divider, pressed && styles.pressed]}>
          <View style={styles.rowIcon}>
            <Icon name={item.icon} size={20} color={colors.primary} />
          </View>
          <View style={styles.flex}>
            <Text style={styles.rowTitle}>{item.title}</Text>
            {item.detail ? <Text style={styles.rowDetail}>{item.detail}</Text> : null}
          </View>
          {item.badge ? <Badge count={item.badge} /> : null}
          <Icon name="chevron" size={18} color={colors.muted} />
        </Pressable>
      ))}
    </View>
  );
}

/** The gold count badge (red is for emergencies only, like the web's bell). */
export function Badge({ count }: { count: number }) {
  return (
    <View style={styles.badge}>
      <Text style={styles.badgeText}>{count > 9 ? '9+' : count}</Text>
    </View>
  );
}

/** Offline or the last load failed: say which copy is on screen, with Try again. */
export function LoadState({ resource, onRetry, what }: { resource: Resource<unknown>; onRetry: () => void; what: string }) {
  if (resource.data === null && resource.loading) return <ActivityIndicator color={colors.primary} style={styles.spinner} />;
  if (!resource.error) return null;
  return (
    <View style={styles.offline} accessibilityLiveRegion="polite">
      <Icon name="history" size={20} color={colors.primary} />
      <Text style={[type.small, styles.flex, { color: colors.text }]}>
        {resource.data !== null
          ? `Showing your ${what} from ${savedAtLabel(resource.savedAt)}. ${resource.error}`
          : resource.error}
      </Text>
      <Pressable onPress={onRetry} accessibilityRole="button" hitSlop={8} style={styles.retry}>
        <Text style={styles.retryText}>Try again</Text>
      </Pressable>
    </View>
  );
}

const ROOM_ICONS: Record<RoomKind, IconName> = { room: 'pin', unmapped: 'pin', online: 'screen', tba: 'clock' };

/** Where a class meets, with the web's icon per kind (a room on the map is maroon, the rest muted). */
export function RoomLine({ item }: { item: ClassItem }) {
  const room = roomOf(item);
  return (
    <View style={styles.roomLine}>
      <Icon name={ROOM_ICONS[room.kind]} size={17} color={room.kind === 'room' ? colors.primary : colors.muted} />
      <Text style={[styles.roomText, room.kind !== 'room' && { color: colors.muted, fontFamily: fonts.regular }]}>{room.label}</Text>
    </View>
  );
}

/** "Directions": walking directions to the class's room (Navigate, ?to=<room code>). */
export function DirectionsButton({ code, compact }: { code: string; compact?: boolean }) {
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/navigate', params: { to: code } })}
      accessibilityRole="button"
      accessibilityLabel="Directions"
      style={({ pressed }) => [styles.go, compact && styles.goCompact, pressed && styles.goPressed]}>
      <Icon name="walk" size={18} color={colors.white} strokeWidth={2.2} />
      <Text style={styles.goText}>Directions</Text>
    </Pressable>
  );
}

/**
 * One class: time, subject and section, room, then Directions. Tap the class to edit it. "Now" when it's going on.
 * Directions sits outside the tappable part (a button can't hold another button).
 */
export function ClassRow({ item, onPress, first }: { item: ClassItem; onPress?: () => void; first?: boolean }) {
  const now = inProgress(item);
  return (
    <View style={[!first && styles.divider, now && styles.classNow]}>
      <Pressable
        onPress={onPress}
        disabled={!onPress}
        accessibilityRole={onPress ? 'button' : undefined}
        accessibilityHint={onPress ? 'Edit this class' : undefined}
        style={({ pressed }) => [styles.classRow, now && styles.classRowNow, pressed && styles.pressed]}>
        <View style={styles.time}>
          <Text style={[styles.timeStart, now && { color: colors.primary }]}>{formatTime(item.starts_at)}</Text>
          <Text style={styles.timeEnd}>{formatTime(item.ends_at)}</Text>
          {now && (
            <View style={styles.nowPill}>
              <Text style={styles.nowText}>Now</Text>
            </View>
          )}
        </View>
        <View style={[styles.flex, styles.classBody]}>
          <Text style={styles.subject}>
            {item.subject}
            {item.section ? <Text style={styles.section}>{`  ${item.section}`}</Text> : null}
          </Text>
          <RoomLine item={item} />
        </View>
      </Pressable>
      {item.room_code ? (
        <View style={[styles.classGo, now && styles.classGoNow]}>
          <DirectionsButton code={item.room_code} compact />
        </View>
      ) : null}
    </View>
  );
}

/** A white card with a heading row (and an optional action on the right). */
export function Card({ title, action, children }: { title?: string; action?: ReactNode; children: ReactNode }) {
  return (
    <View style={styles.card}>
      {title || action ? (
        <View style={styles.cardHead}>
          {title ? (
            <Text style={[type.label, styles.flex]} accessibilityRole="header">
              {title}
            </Text>
          ) : null}
          {action}
        </View>
      ) : null}
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, minHeight: 60, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  pressed: { backgroundColor: '#F3EEE8' },
  rowIcon: { width: 36, height: 36, borderRadius: 10, backgroundColor: '#F3ECEA', alignItems: 'center', justifyContent: 'center' },
  rowTitle: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  rowDetail: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 19, color: colors.muted },
  badge: { minWidth: 24, height: 24, borderRadius: 12, paddingHorizontal: 6, backgroundColor: colors.secondary, alignItems: 'center', justifyContent: 'center' },
  badgeText: { fontFamily: fonts.bold, fontSize: 13, color: colors.primaryHover },
  spinner: { marginVertical: space.lg },
  offline: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: '#F1ECE6' },
  retry: { minHeight: 40, justifyContent: 'center' },
  retryText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.primary, textDecorationLine: 'underline' },
  roomLine: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  roomText: { flexShrink: 1, fontFamily: fonts.medium, fontSize: 15, lineHeight: 20, color: colors.text },
  go: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44, paddingHorizontal: 14, borderRadius: radius.sm, backgroundColor: colors.primary, alignSelf: 'flex-start' },
  goCompact: { minHeight: 40 },
  goPressed: { backgroundColor: colors.primaryHover },
  goText: { fontFamily: fonts.bold, fontSize: 15, color: colors.white },
  classRow: { flexDirection: 'row', gap: space.md, paddingHorizontal: space.md, paddingVertical: 14 },
  classNow: { backgroundColor: '#FBF6E9', borderLeftWidth: 4, borderLeftColor: colors.secondary },
  classRowNow: { paddingLeft: space.md - 4 },
  // Lined up under the subject: the row's padding, the time column and the gap.
  classGo: { paddingLeft: space.md + 74 + space.md, paddingBottom: 14, marginTop: -8 },
  classGoNow: { paddingLeft: space.md - 4 + 74 + space.md },
  time: { width: 74, gap: 1 },
  timeStart: { fontFamily: fonts.bold, fontSize: 16, color: colors.text },
  timeEnd: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted },
  nowPill: { alignSelf: 'flex-start', marginTop: 4, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, backgroundColor: colors.secondary },
  nowText: { fontFamily: fonts.bold, fontSize: 12, color: colors.primaryHover },
  classBody: { gap: 4 },
  subject: { fontFamily: fonts.semibold, fontSize: 17, lineHeight: 22, color: colors.text },
  section: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted },
  card: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, overflow: 'hidden' },
  cardHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md, paddingTop: space.md, paddingBottom: space.sm },
});
