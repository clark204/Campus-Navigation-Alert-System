import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import type { AlertTypeOption, SeverityOption } from '@/lib/admin';
import type { AlertSeverity } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from '../icon';

// Pieces of the alert screens for the admin and trusted senders (send, edit, end, the active alert panel).

/** The level colors of the admin console (AlertSeverity::color) with readable text on each. */
export const LEVEL_STYLE: Record<AlertSeverity, { bg: string; text: string }> = {
  advisory: { bg: colors.advisory, text: colors.advisoryText },
  warning: { bg: colors.warning, text: colors.white },
  emergency: { bg: colors.alarm, text: colors.white },
};

export function LevelBadge({ level, label }: { level: AlertSeverity; label: string }) {
  return (
    <View style={[styles.badge, { backgroundColor: LEVEL_STYLE[level].bg }]}>
      <Text style={[styles.badgeText, { color: LEVEL_STYLE[level].text }]}>{label}</Text>
    </View>
  );
}

export function DrillBadge() {
  return (
    <View style={styles.drill}>
      <Text style={styles.drillText}>DRILL</Text>
    </View>
  );
}

/** The six types as big tiles with their icon. */
export function TypePicker({ types, value, onChange }: { types: AlertTypeOption[]; value: string; onChange: (type: AlertTypeOption) => void }) {
  return (
    <View style={styles.types} accessibilityRole="radiogroup">
      {types.map((option) => {
        const on = option.value === value;
        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option)}
            accessibilityRole="radio"
            accessibilityState={{ selected: on }}
            accessibilityLabel={option.label}
            style={({ pressed }) => [styles.type, on && styles.typeOn, pressed && !on && styles.pressed]}>
            <Icon name={option.value} size={26} color={on ? colors.white : colors.alarm} strokeWidth={2} />
            <Text style={[styles.typeText, on && { color: colors.white }]} numberOfLines={2}>
              {option.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** The three levels, each with when to choose it and what students get (AlertSeverity's own wording). */
export function LevelPicker({ levels, value, onChange }: { levels: SeverityOption[]; value: AlertSeverity; onChange: (level: AlertSeverity) => void }) {
  return (
    <View style={styles.levels} accessibilityRole="radiogroup">
      {levels.map((level) => {
        const on = level.value === value;
        return (
          <Pressable
            key={level.value}
            onPress={() => onChange(level.value)}
            accessibilityRole="radio"
            accessibilityState={{ selected: on }}
            accessibilityLabel={`${level.label}. ${level.description}`}
            style={({ pressed }) => [styles.level, on && { borderColor: level.color, backgroundColor: '#FFFDF8' }, pressed && styles.pressed]}>
            <View style={[styles.levelStripe, { backgroundColor: level.color }]} />
            <View style={styles.levelBody}>
              <View style={styles.levelHead}>
                <Text style={styles.levelName}>{level.label}</Text>
                <View style={[styles.radio, on && { borderColor: level.color }]}>{on && <View style={[styles.radioDot, { backgroundColor: level.color }]} />}</View>
              </View>
              <Text style={styles.levelDescription}>{level.description}</Text>
              {on && (
                <>
                  <Text style={type.small}>e.g. {level.example}</Text>
                  <Text style={styles.levelGets}>{level.students_get}</Text>
                </>
              )}
            </View>
          </Pressable>
        );
      })}
    </View>
  );
}

/** "This is a drill": every level can be a drill; it says DRILL everywhere and uses a softer sound. */
export function DrillSwitch({ value, onChange }: { value: boolean; onChange: (drill: boolean) => void }) {
  return (
    <View style={[styles.drillRow, value && styles.drillRowOn]}>
      <View style={styles.flex}>
        <Text style={type.label}>This is a drill</Text>
        <Text style={type.small}>Everyone sees DRILL, and phones play the softer drill sound.</Text>
      </View>
      <Switch value={value} onValueChange={onChange} trackColor={{ true: colors.hazard, false: colors.border }} accessibilityLabel="This is a drill" />
    </View>
  );
}

/** A row of choice chips (radius, folder, role filter…). */
export function Chips<T extends string | number>({ options, value, onChange, label }: { options: { value: T; label: string }[]; value: T | null; onChange: (value: T) => void; label?: string }) {
  return (
    <View style={styles.chips} accessibilityRole="radiogroup" accessibilityLabel={label}>
      {options.map((option) => {
        const on = option.value === value;
        return (
          <Pressable
            key={String(option.value)}
            onPress={() => onChange(option.value)}
            accessibilityRole="radio"
            accessibilityState={{ selected: on }}
            style={[styles.chip, on && styles.chipOn]}>
            <Text style={[styles.chipText, on && styles.chipTextOn]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.8 },
  badge: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText: { fontFamily: fonts.bold, fontSize: 13 },
  drill: { alignSelf: 'flex-start', borderRadius: 6, paddingHorizontal: 8, paddingVertical: 3, backgroundColor: colors.hazard },
  drillText: { fontFamily: fonts.bold, fontSize: 12, letterSpacing: 1.5, color: colors.advisoryText },
  types: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  type: {
    width: '31.5%',
    minHeight: 84,
    borderRadius: radius.md,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    padding: space.sm,
  },
  typeOn: { backgroundColor: colors.alarm, borderColor: colors.alarm },
  typeText: { fontFamily: fonts.semibold, fontSize: 14, lineHeight: 18, color: colors.text, textAlign: 'center' },
  levels: { gap: space.sm },
  level: { flexDirection: 'row', borderRadius: radius.md, borderWidth: 2, borderColor: colors.border, backgroundColor: colors.surface, overflow: 'hidden' },
  levelStripe: { width: 8 },
  levelBody: { flex: 1, padding: space.md, gap: 4 },
  levelHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  levelName: { fontFamily: fonts.bold, fontSize: 17, color: colors.text },
  levelDescription: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.text },
  levelGets: { fontFamily: fonts.semibold, fontSize: 14, lineHeight: 20, color: colors.primary },
  radio: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  radioDot: { width: 11, height: 11, borderRadius: 6 },
  drillRow: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.md, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface },
  drillRowOn: { borderColor: colors.hazard, backgroundColor: '#FFF9E0' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 42, borderRadius: 21, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface, justifyContent: 'center', paddingHorizontal: 14 },
  chipOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  chipTextOn: { color: colors.white },
});
