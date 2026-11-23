import { useMemo, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { formatTime, WEEKDAYS } from '@/lib/schedule';
import type { RoomChoices, Weekday } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';

// The class form's pickers (app/class-form.tsx): day, time and room. Big tap targets, no typing of times.

function FieldLabel({ label, error }: { label: string; error?: string }) {
  return (
    <>
      <Text style={type.label}>{label}</Text>
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </>
  );
}

/** Monday to Sunday as chips. */
export function DayPicker({ value, onChange, error }: { value: Weekday | null; onChange: (day: Weekday) => void; error?: string }) {
  return (
    <View style={styles.field}>
      <FieldLabel label="Day" error={error} />
      <View style={styles.chips} accessibilityRole="radiogroup">
        {WEEKDAYS.map((day) => {
          const on = day.value === value;
          return (
            <Pressable
              key={day.value}
              onPress={() => onChange(day.value)}
              accessibilityRole="radio"
              accessibilityLabel={day.label}
              accessibilityState={{ selected: on }}
              style={[styles.chip, styles.dayChip, on && styles.chipOn]}>
              <Text style={[styles.chipText, on && styles.chipTextOn]}>{day.short}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const HOURS = Array.from({ length: 17 }, (_, i) => i + 6); // 6 AM to 10 PM
const MINUTES = Array.from({ length: 12 }, (_, i) => i * 5);
const pad = (n: number) => String(n).padStart(2, '0');
const hourLabel = (h: number) => `${h % 12 === 0 ? 12 : h % 12} ${h < 12 ? 'AM' : 'PM'}`;

/**
 * A time as "4:00 PM". Tap to open the hour and minute chips (5-minute steps, like the times on a COR). Answers
 * "16:00".
 */
export function TimePicker({ label, value, onChange, error }: { label: string; value: string | null; onChange: (time: string) => void; error?: string }) {
  const [open, setOpen] = useState(false);
  const [hour, minute] = value ? value.split(':').map(Number) : [null, null];

  return (
    <View style={styles.field}>
      <FieldLabel label={label} error={error} />
      <Pressable
        onPress={() => setOpen(!open)}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${value ? formatTime(value) : 'not chosen'}`}
        accessibilityState={{ expanded: open }}
        style={[styles.timeButton, open && styles.timeButtonOpen, !!error && styles.inputError]}>
        <Icon name="clock" size={20} color={value ? colors.primary : colors.muted} />
        <Text style={[styles.timeValue, !value && { color: colors.muted, fontFamily: fonts.regular }]}>{value ? formatTime(value) : 'Choose'}</Text>
      </Pressable>
      {open && (
        <View style={styles.timePanel}>
          <Text style={type.small}>Hour</Text>
          <View style={styles.chips}>
            {HOURS.map((h) => (
              <Pressable
                key={h}
                onPress={() => onChange(`${pad(h)}:${pad(minute ?? 0)}`)}
                accessibilityRole="button"
                accessibilityState={{ selected: h === hour }}
                style={[styles.chip, styles.timeChip, h === hour && styles.chipOn]}>
                <Text style={[styles.chipText, h === hour && styles.chipTextOn]}>{hourLabel(h)}</Text>
              </Pressable>
            ))}
          </View>
          <Text style={type.small}>Minutes</Text>
          <View style={styles.chips}>
            {MINUTES.map((m) => (
              <Pressable
                key={m}
                onPress={() => {
                  onChange(`${pad(hour ?? 8)}:${pad(m)}`);
                  setOpen(false);
                }}
                accessibilityRole="button"
                accessibilityState={{ selected: m === minute }}
                style={[styles.chip, styles.timeChip, m === minute && styles.chipOn]}>
                <Text style={[styles.chipText, m === minute && styles.chipTextOn]}>:{pad(m)}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      )}
    </View>
  );
}

/** What the room button says for a choice ("Not announced yet", "ComLab 1 · 2nd floor"). */
export function roomChoiceLabel(choices: RoomChoices | null, choice: string): string {
  const fixed = choices?.choices.find((c) => c.value === choice);
  if (fixed) return fixed.value === 'other' ? 'Somewhere not on the map' : fixed.label;
  for (const building of choices?.buildings ?? []) {
    const room = building.rooms.find((r) => r.code === choice);
    if (room) return `${room.label}, ${building.name}`;
  }
  return choice === 'TBA' ? 'Not announced yet' : choice;
}

/** The room list (GET /api/schedule/rooms) in a full-screen sheet with a search box, like the web form's dropdown. */
export function RoomPicker({
  choices,
  value,
  onChange,
  error,
}: {
  choices: RoomChoices | null;
  value: string;
  onChange: (choice: string) => void;
  error?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const words = query.trim().toLowerCase();

  const buildings = useMemo(() => {
    if (!choices) return [];
    if (!words) return choices.buildings;
    return choices.buildings
      .map((b) => ({ ...b, rooms: b.name.toLowerCase().includes(words) ? b.rooms : b.rooms.filter((r) => r.label.toLowerCase().includes(words)) }))
      .filter((b) => b.rooms.length > 0);
  }, [choices, words]);

  const pick = (choice: string) => {
    onChange(choice);
    setOpen(false);
    setQuery('');
  };

  return (
    <View style={styles.field}>
      <FieldLabel label="Room" error={error} />
      <Pressable onPress={() => setOpen(true)} accessibilityRole="button" accessibilityHint="Opens the list of rooms" style={[styles.roomButton, !!error && styles.inputError]}>
        <Icon name="pin" size={20} color={colors.primary} />
        <Text style={styles.roomValue} numberOfLines={2}>
          {roomChoiceLabel(choices, value)}
        </Text>
        <Icon name="chevron" size={18} color={colors.muted} />
      </Pressable>

      <Modal visible={open} animationType="slide" onRequestClose={() => setOpen(false)}>
        <SafeAreaView style={styles.sheet}>
          <View style={styles.sheetHead}>
            <Text style={[type.heading, styles.flex]}>Where does it meet?</Text>
            <Pressable onPress={() => setOpen(false)} accessibilityRole="button" accessibilityLabel="Close" hitSlop={10} style={styles.close}>
              <Icon name="close" size={24} color={colors.text} />
            </Pressable>
          </View>
          <TextInput
            value={query}
            onChangeText={setQuery}
            placeholder="Search rooms or buildings"
            placeholderTextColor="#9AA0A6"
            autoCorrect={false}
            accessibilityLabel="Search rooms or buildings"
            style={styles.search}
          />
          {!choices ? (
            <ActivityIndicator color={colors.primary} style={{ marginTop: space.lg }} />
          ) : (
            <ScrollView contentContainerStyle={styles.sheetList} keyboardShouldPersistTaps="handled">
              {words === '' &&
                choices.choices.map((choice) => <ChoiceRow key={choice.value} label={choice.label} on={value === choice.value} onPress={() => pick(choice.value)} />)}
              {buildings.map((building) => (
                <View key={building.name}>
                  <Text style={styles.group}>{building.name}</Text>
                  {building.rooms.map((room) => (
                    <ChoiceRow key={room.code} label={room.label} on={value === room.code} onPress={() => pick(room.code)} />
                  ))}
                </View>
              ))}
              {words !== '' && buildings.length === 0 && (
                <View style={{ gap: space.sm, padding: space.md }}>
                  <Text style={type.body}>No room matches “{query.trim()}”.</Text>
                  <ChoiceRow label="Somewhere not on the map…" on={false} onPress={() => pick('other')} />
                </View>
              )}
            </ScrollView>
          )}
        </SafeAreaView>
      </Modal>
    </View>
  );
}

function ChoiceRow({ label, on, onPress }: { label: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="radio" accessibilityState={{ selected: on }} style={({ pressed }) => [styles.choice, pressed && styles.pressed]}>
      <Text style={[styles.choiceText, on && { fontFamily: fonts.semibold, color: colors.primary }]}>{label}</Text>
      {on && <Icon name="check" size={20} color={colors.primary} strokeWidth={2.6} />}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  field: { gap: 6 },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 44, borderRadius: 22, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  dayChip: { minWidth: 58 },
  timeChip: { minWidth: 64 },
  chipOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  chipTextOn: { color: colors.white },
  timeButton: { minHeight: 54, borderWidth: 1.5, borderColor: colors.border, borderRadius: radius.md, backgroundColor: colors.surface, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: space.md },
  timeButtonOpen: { borderColor: colors.primary },
  timeValue: { fontFamily: fonts.semibold, fontSize: 18, color: colors.text },
  timePanel: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: '#F6F1EC' },
  inputError: { borderColor: colors.error },
  roomButton: { minHeight: 54, borderWidth: 1.5, borderColor: colors.border, borderRadius: radius.md, backgroundColor: colors.surface, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: space.md, paddingVertical: 10 },
  roomValue: { flex: 1, fontFamily: fonts.medium, fontSize: 17, color: colors.text },
  sheet: { flex: 1, backgroundColor: colors.bg },
  sheetHead: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, paddingTop: space.md, paddingBottom: space.sm },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  search: {
    marginHorizontal: space.lg,
    minHeight: 50,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    paddingHorizontal: space.md,
    fontFamily: fonts.regular,
    fontSize: 17,
    color: colors.text,
  },
  sheetList: { paddingVertical: space.md, paddingBottom: space.xl * 2 },
  group: { fontFamily: fonts.semibold, fontSize: 14, color: colors.muted, paddingHorizontal: space.lg, paddingTop: space.md, paddingBottom: 4 },
  choice: { minHeight: 52, flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.lg, gap: space.sm },
  choiceText: { flex: 1, fontFamily: fonts.regular, fontSize: 17, color: colors.text },
  pressed: { backgroundColor: '#F3EEE8' },
});
