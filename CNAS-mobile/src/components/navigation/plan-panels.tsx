import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import type { Navigation } from '@/lib/navigation/engine';
import { browsePlaces, searchPlaces, suggestedPlaces, type SearchResult } from '@/lib/navigation/places';
import { formatDistance, formatMinutes, type Plan } from '@/lib/navigation/session';
import { colors, fonts, radius, space } from '@/theme';
import { Icon } from '../icon';

/** The search field at the top: "Where to?", or where the trip starts. */
export function SearchField({
  value,
  placeholder,
  onChangeText,
  onFocus,
  onClear,
  autoFocus,
}: {
  value: string;
  placeholder: string;
  onChangeText: (text: string) => void;
  onFocus: () => void;
  onClear: () => void;
  autoFocus?: boolean;
}) {
  return (
    <View style={styles.field}>
      <Icon name="pin" size={22} color={colors.secondary} strokeWidth={2.2} />
      <TextInput
        value={value}
        onChangeText={onChangeText}
        onFocus={onFocus}
        autoFocus={autoFocus}
        placeholder={placeholder}
        placeholderTextColor={colors.muted}
        style={styles.input}
        returnKeyType="search"
        autoCorrect={false}
        autoCapitalize="none"
        accessibilityLabel={placeholder}
      />
      {value.length > 0 && (
        <Pressable onPress={onClear} accessibilityRole="button" accessibilityLabel="Clear" hitSlop={10} style={styles.clear}>
          <Icon name="close" size={20} color={colors.muted} strokeWidth={2.4} />
        </Pressable>
      )}
    </View>
  );
}

/** Where the trip starts, and the QR button beside it. */
export function StartChip({ label, detail, onPress, onScan }: { label: string; detail: string | null; onPress: () => void; onScan: () => void }) {
  return (
    <View style={styles.chips}>
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={`Starting from ${label}. Change where you start.`}
        style={({ pressed }) => [styles.chip, styles.chipWide, pressed && styles.pressed]}>
        <View style={styles.fromDot} />
        <Text style={styles.chipLabel} numberOfLines={1}>
          <Text style={styles.chipMuted}>From </Text>
          {label}
        </Text>
        {detail ? <Text style={styles.chipDetail}>{detail}</Text> : null}
      </Pressable>
      <Pressable onPress={onScan} accessibilityRole="button" accessibilityLabel="Scan a CNAS QR code to start where it is posted" style={({ pressed }) => [styles.chip, pressed && styles.pressed]}>
        <QrGlyph />
        <Text style={styles.chipLabel}>Scan QR</Text>
      </Pressable>
    </View>
  );
}

export function QrGlyph() {
  return (
    <View style={styles.qr}>
      <View style={[styles.qrCell, { left: 0, top: 0 }]} />
      <View style={[styles.qrCell, { right: 0, top: 0 }]} />
      <View style={[styles.qrCell, { left: 0, bottom: 0 }]} />
      <View style={styles.qrDot} />
    </View>
  );
}

export type HereOption = { label: string; detail: string; available: boolean };

/** Search results under the field. With nothing typed: the buildings (and, for the start, My location). */
export function SearchResults({
  navigation,
  query,
  here,
  onPick,
  onPickHere,
}: {
  navigation: Navigation;
  query: string;
  here?: HereOption;
  onPick: (result: SearchResult) => void;
  onPickHere?: () => void;
}) {
  // A trip starts at a gate or a room (a building has no single spot), so the start list leaves buildings out.
  const startable = (result: SearchResult) => !here || result.destination.place !== undefined;
  const typed = query.trim() !== '';
  const results = typed ? searchPlaces(navigation, query).filter(startable) : [];
  const groups = typed ? [] : browsePlaces(navigation);
  const row = (result: SearchResult) => (
    <Pressable key={result.key} onPress={() => onPick(result)} accessibilityRole="button" style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}>
      <View style={styles.rowIcon}>
        <Icon name={result.icon} size={20} color={colors.primary} strokeWidth={2.2} />
      </View>
      <View style={styles.rowText}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          {result.title}
        </Text>
        <Text style={styles.rowDetail} numberOfLines={1}>
          {result.detail}
        </Text>
      </View>
    </Pressable>
  );
  return (
    <ScrollView style={styles.results} keyboardShouldPersistTaps="handled" contentContainerStyle={styles.resultsContent}>
      {here && onPickHere && (
        <Pressable onPress={onPickHere} accessibilityRole="button" style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}>
          <View style={[styles.rowIcon, styles.hereIcon]}>
            <View style={styles.hereDot} />
          </View>
          <View style={styles.rowText}>
            <Text style={styles.rowTitle}>{here.label}</Text>
            <Text style={[styles.rowDetail, !here.available && styles.rowWarn]}>{here.detail}</Text>
          </View>
        </Pressable>
      )}
      {groups.map((group) => (
        <View key={group.title}>
          <Text style={styles.groupLabel} accessibilityRole="header">
            {group.title}
          </Text>
          {group.results.map(row)}
        </View>
      ))}
      {results.map(row)}
      {typed && results.length === 0 && (
        <View style={styles.none}>
          <Text style={styles.noneTitle}>No place called “{query.trim()}”</Text>
          <Text style={styles.noneText}>Try a room name like the one on its door, a building, or a word like “CR”, “lab” or “gate”.</Text>
        </View>
      )}
    </ScrollView>
  );
}

/** Buildings to tap with nothing searched yet: one tap lists that building's rooms (and the building itself). */
export function SuggestionCard({ navigation, onBrowse, bottomInset }: { navigation: Navigation; onBrowse: (building: string) => void; bottomInset: number }) {
  const suggestions = suggestedPlaces(navigation);
  return (
    <View style={[styles.sheet, { paddingBottom: bottomInset + space.md }]}>
      <Text style={styles.sheetTitle}>Where do you want to go?</Text>
      <Text style={styles.sheetText}>Search a room or building, or tap a building to see its rooms.</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.suggestions}>
        {suggestions.map((result) => (
          <Pressable
            key={result.key}
            onPress={() => onBrowse(result.title)}
            accessibilityRole="button"
            accessibilityHint="Shows the rooms in this building"
            style={({ pressed }) => [styles.suggestion, pressed && styles.pressed]}>
            <Icon name="home" size={18} color={colors.primary} strokeWidth={2.2} />
            <Text style={styles.suggestionText}>{result.title}</Text>
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}

type RouteCardProps = {
  title: string;
  detail: string | null;
  plan: Plan | null;
  error: string | null;
  note: string | null;
  bottomInset: number;
  onStart: () => void;
  onClose: () => void;
  /** Signed in (portal) with a room as destination: the star that saves it (main.js renderSave). */
  save?: { saved: boolean; message: string | null; onToggle: () => void } | null;
};

/** The route before Start: how far, how long, the steps (main.js updatePlan). */
export function RouteCard({ title, detail, plan, error, note, bottomInset, onStart, onClose, save }: RouteCardProps) {
  const [showSteps, setShowSteps] = useState(false);
  const stairs = plan ? plan.maneuvers.some((m) => m.type === 'stairs') : false;
  return (
    <View style={[styles.sheet, { paddingBottom: bottomInset + space.md }]}>
      <View style={styles.cardHead}>
        <View style={styles.cardTitleBox}>
          <Text style={styles.cardTitle} numberOfLines={2}>
            {title}
          </Text>
          {detail ? <Text style={styles.sheetText}>{detail}</Text> : null}
        </View>
        {save && (
          <Pressable
            onPress={save.onToggle}
            accessibilityRole="button"
            accessibilityLabel={save.saved ? `Remove ${title} from saved places` : `Save ${title}`}
            accessibilityState={{ selected: save.saved }}
            hitSlop={6}
            style={({ pressed }) => [styles.save, save.saved && styles.saveOn, pressed && styles.pressed]}>
            <Icon name="star" size={20} color={save.saved ? colors.primaryHover : colors.primary} fill={save.saved ? colors.secondary : 'none'} strokeWidth={2.2} />
            <Text style={styles.saveText}>{save.message ?? (save.saved ? 'Saved' : 'Save')}</Text>
          </Pressable>
        )}
        <Pressable onPress={onClose} accessibilityRole="button" accessibilityLabel="Choose another destination" hitSlop={8} style={styles.closeCard}>
          <Icon name="close" size={22} color={colors.muted} strokeWidth={2.4} />
        </Pressable>
      </View>

      {error ? (
        <Text style={styles.error}>{error}</Text>
      ) : !plan ? (
        <ActivityIndicator color={colors.primary} style={styles.spinner} />
      ) : (
        <>
          <Text style={styles.summary}>
            <Text style={styles.summaryStrong}>{formatDistance(plan.route.length)}</Text>
            {`  ·  about ${formatMinutes(plan.route.length)} walk${stairs ? '  ·  includes stairs' : ''}`}
          </Text>
          {note ? <Text style={styles.note}>{note}</Text> : null}
          <Pressable onPress={() => setShowSteps(!showSteps)} accessibilityRole="button" accessibilityState={{ expanded: showSteps }} style={styles.stepsToggle}>
            <Text style={styles.stepsToggleText}>{showSteps ? 'Hide steps' : `Show ${plan.maneuvers.length} steps`}</Text>
            <View style={{ transform: [{ rotate: showSteps ? '-90deg' : '90deg' }] }}>
              <Icon name="chevron" size={18} color={colors.primary} strokeWidth={2.4} />
            </View>
          </Pressable>
          {showSteps && (
            <ScrollView style={styles.steps} nestedScrollEnabled>
              {plan.maneuvers.map((m, i) => (
                <View key={`${m.index}-${m.type}`} style={styles.step}>
                  <View style={styles.stepRail}>
                    <View style={[styles.stepDot, i === plan.maneuvers.length - 1 && styles.stepDotEnd]} />
                    {i < plan.maneuvers.length - 1 && <View style={styles.stepLine} />}
                  </View>
                  <Text style={styles.stepText}>
                    {m.text}
                    {plan.maneuvers[i + 1] ? <Text style={styles.stepMeters}>{`  ${formatDistance(plan.maneuvers[i + 1].legBefore)}`}</Text> : null}
                  </Text>
                </View>
              ))}
            </ScrollView>
          )}
          <Pressable onPress={onStart} accessibilityRole="button" style={({ pressed }) => [styles.start, pressed && styles.startPressed]}>
            <Text style={styles.startText}>Start</Text>
          </Pressable>
        </>
      )}
    </View>
  );
}

const shadow = {
  shadowColor: '#000',
  shadowOpacity: 0.14,
  shadowRadius: 10,
  shadowOffset: { width: 0, height: 3 },
  elevation: 6,
};

const styles = StyleSheet.create({
  save: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 40, paddingHorizontal: 12, borderRadius: 20, borderWidth: 1.5, borderColor: colors.border, alignSelf: 'flex-start' },
  saveOn: { borderColor: colors.secondary, backgroundColor: '#FBF3DC' },
  saveText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.primary },
  field: { flex: 1, minHeight: 52, borderRadius: 26, backgroundColor: colors.surface, flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingLeft: space.md, paddingRight: space.sm, ...shadow },
  input: { flex: 1, fontFamily: fonts.medium, fontSize: 18, color: colors.text, paddingVertical: 12 },
  clear: { width: 36, height: 36, alignItems: 'center', justifyContent: 'center' },
  chips: { flexDirection: 'row', gap: space.sm },
  chip: { minHeight: 42, borderRadius: 21, backgroundColor: colors.surface, flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, ...shadow, elevation: 3 },
  chipWide: { flex: 1 },
  chipLabel: { flexShrink: 1, fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  chipMuted: { fontFamily: fonts.regular, color: colors.muted },
  chipDetail: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  fromDot: { width: 14, height: 14, borderRadius: 7, borderWidth: 3, borderColor: colors.primary, backgroundColor: colors.white },
  qr: { width: 16, height: 16 },
  qrCell: { position: 'absolute', width: 7, height: 7, borderWidth: 2, borderColor: colors.primary, borderRadius: 1.5 },
  qrDot: { position: 'absolute', right: 1, bottom: 1, width: 4, height: 4, backgroundColor: colors.primary },
  pressed: { opacity: 0.8 },
  results: { maxHeight: 420, marginTop: space.sm, backgroundColor: colors.surface, borderRadius: radius.lg, ...shadow },
  resultsContent: { paddingVertical: space.sm },
  groupLabel: { fontFamily: fonts.semibold, fontSize: 14, color: colors.muted, paddingHorizontal: space.md, paddingTop: space.sm, paddingBottom: 4 },
  row: { minHeight: 60, flexDirection: 'row', alignItems: 'center', gap: space.md, paddingHorizontal: space.md, paddingVertical: 8 },
  rowPressed: { backgroundColor: '#F4EEEA' },
  rowIcon: { width: 38, height: 38, borderRadius: 19, backgroundColor: '#F4EEEA', alignItems: 'center', justifyContent: 'center' },
  hereIcon: { backgroundColor: '#E8F0FE' },
  hereDot: { width: 14, height: 14, borderRadius: 7, backgroundColor: '#1a73e8', borderWidth: 2, borderColor: colors.white },
  rowText: { flex: 1 },
  rowTitle: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  rowDetail: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted },
  rowWarn: { color: colors.warning },
  none: { padding: space.md, gap: 4 },
  noneTitle: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  noneText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    paddingHorizontal: space.lg,
    paddingTop: space.lg,
    gap: space.sm,
    shadowColor: '#000',
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: -2 },
    elevation: 8,
  },
  sheetTitle: { fontFamily: fonts.bold, fontSize: 20, color: colors.text },
  sheetText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  suggestions: { gap: space.sm, paddingVertical: 4 },
  suggestion: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 14, borderRadius: 22, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.bg },
  suggestionText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  cardHead: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm },
  cardTitleBox: { flex: 1, gap: 2 },
  cardTitle: { fontFamily: fonts.bold, fontSize: 24, lineHeight: 30, color: colors.text },
  closeCard: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  spinner: { marginVertical: space.lg },
  error: { fontFamily: fonts.medium, fontSize: 16, lineHeight: 23, color: colors.error, paddingVertical: space.sm },
  summary: { fontFamily: fonts.regular, fontSize: 16, color: colors.muted },
  summaryStrong: { fontFamily: fonts.bold, fontSize: 20, color: colors.text },
  note: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.warning },
  stepsToggle: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: 36, alignSelf: 'flex-start' },
  stepsToggleText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.primary },
  steps: { maxHeight: 200 },
  step: { flexDirection: 'row', gap: space.sm },
  stepRail: { width: 14, alignItems: 'center' },
  stepDot: { width: 10, height: 10, borderRadius: 5, marginTop: 6, backgroundColor: colors.primary },
  stepDotEnd: { backgroundColor: colors.secondary, borderWidth: 2, borderColor: colors.primary },
  stepLine: { flex: 1, width: 2, backgroundColor: colors.border, marginVertical: 2 },
  stepText: { flex: 1, fontFamily: fonts.regular, fontSize: 16, lineHeight: 22, color: colors.text, paddingBottom: space.sm },
  stepMeters: { fontFamily: fonts.medium, fontSize: 14, color: colors.muted },
  start: { minHeight: 56, borderRadius: 28, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', marginTop: 4 },
  startPressed: { backgroundColor: colors.primaryHover },
  startText: { fontFamily: fonts.bold, fontSize: 19, color: colors.white },
});
