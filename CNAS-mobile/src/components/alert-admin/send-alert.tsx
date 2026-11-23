import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { alertForm, alertsStore, type AlertFormData, type AlertTypeOption } from '@/lib/admin';
import { api, ApiError, errorMessage } from '@/lib/api';
import type { CampusMap } from '@/lib/campus-map';
import { buildingAt, locationLabel } from '@/lib/incident';
import { useToast } from '@/lib/toasts';
import type { AlertSeverity } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from '../icon';
import { BackLink, Button, Notice, Screen, Title } from '../ui';
import { Chips, DrillBadge, DrillSwitch, LevelBadge, LevelPicker, TypePicker } from './parts';
import { PinMap } from './pin-map';
import { PhonePreview, WordingFields, wordingReady } from './wording';

type Position = [number, number];
type Step = 'what' | 'where' | 'confirm';

type Draft = {
  type: AlertTypeOption['value'];
  severity: AlertSeverity;
  title: string;
  message: string;
  what_to_do: string;
  instructions: string;
  is_drill: boolean;
  pin: Position | null;
  radius: number;
  buildings: string[];
};

const SHEET = 300;

/**
 * "Send an alert" for the admin and trusted senders, like the web form (partials/alert-send-form, alert-picker.js):
 * 1. what: the type (its level and wording are filled in), the level, drill, the wording;
 * 2. where: tap the map to drop the incident pin (the building under it is ticked), the danger zone, more buildings, or
 *    the whole campus;
 * 3. confirm: "This will alert everyone" with a phone preview of what people get, then POST /api/alerts; a toast says it
 *    went out. The server checks everything again (StoreAlertRequest).
 */
export function SendAlertScreen({ backLabel }: { backLabel: string }) {
  const [form, setForm] = useState<AlertFormData | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  // The wording the type filled in, so picking another type only swaps it while the sender hasn't changed it (web rule).
  const [template, setTemplate] = useState<AlertTypeOption['template'] | null>(null);
  const [autoBuilding, setAutoBuilding] = useState<string | null>(null);
  const [step, setStep] = useState<Step>('what');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const toast = useToast();

  useEffect(() => {
    alertForm().then(
      (data) => {
        setForm(data);
        const first = data.types[0];
        setDraft({
          type: first.value,
          severity: first.severity,
          ...first.template,
          is_drill: false,
          pin: null,
          radius: data.default_radius,
          buildings: [],
        });
        setTemplate(first.template);
      },
      (failure) => setLoadError(errorMessage(failure)),
    );
  }, []);

  if (loadError) {
    return (
      <Screen>
        <BackLink label={backLabel} />
        <Title>Send an alert</Title>
        <Notice tone="error">{loadError}</Notice>
      </Screen>
    );
  }
  if (!form || !draft || !template) {
    return (
      <Screen>
        <BackLink label={backLabel} />
        <ActivityIndicator color={colors.primary} style={{ marginTop: space.xl }} />
      </Screen>
    );
  }

  const set = (patch: Partial<Draft>) => setDraft((current) => (current ? { ...current, ...patch } : current));
  const pickType = (option: AlertTypeOption) => {
    const untouched = (['title', 'message', 'what_to_do', 'instructions'] as const).every((field) => draft[field] === template[field]);
    set({ type: option.value, severity: option.severity, ...(untouched ? option.template : {}) });
    setTemplate(option.template);
  };
  const names = draft.buildings.map((code) => form.buildings.find((b) => b.code === code)?.name ?? code);
  const place = locationLabel(names, draft.pin !== null);
  const level = form.severities.find((s) => s.value === draft.severity)!;
  const typeOption = form.types.find((t) => t.value === draft.type)!;

  const dropPin = (position: Position, map: CampusMap) => {
    const under = buildingAt(position, map.buildings);
    let buildings = draft.buildings;
    // The building the last pin ticked is unticked when the pin moves off it (the web picker's autoBuilding).
    if (autoBuilding && autoBuilding !== under) buildings = buildings.filter((code) => code !== autoBuilding);
    let auto: string | null = autoBuilding === under ? autoBuilding : null;
    if (under && !buildings.includes(under) && form.buildings.some((b) => b.code === under)) {
      buildings = [...buildings, under];
      auto = under;
    }
    setAutoBuilding(auto);
    set({ pin: position, buildings });
  };
  const wholeCampus = () => {
    set({ pin: null, buildings: autoBuilding ? draft.buildings.filter((code) => code !== autoBuilding) : draft.buildings });
    setAutoBuilding(null);
  };
  const toggleBuilding = (code: string) => {
    if (code === autoBuilding) setAutoBuilding(null);
    set({ buildings: draft.buildings.includes(code) ? draft.buildings.filter((c) => c !== code) : [...draft.buildings, code] });
  };

  const send = async () => {
    setBusy(true);
    setErrors([]);
    try {
      const result = await api<{ message: string }>('/alerts', {
        method: 'POST',
        body: {
          type: draft.type,
          severity: draft.severity,
          title: draft.title,
          message: draft.message,
          what_to_do: draft.what_to_do || null,
          instructions: draft.instructions || null,
          is_drill: draft.is_drill,
          latitude: draft.pin?.[1] ?? null,
          longitude: draft.pin?.[0] ?? null,
          danger_radius_m: draft.pin ? draft.radius : null,
          building_codes: draft.buildings,
        },
      });
      alertsStore.load(true);
      toast(result.message);
      router.back();
    } catch (failure) {
      setErrors(failure instanceof ApiError && failure.status === 422 ? Object.values(failure.fields) : [errorMessage(failure)]);
    } finally {
      setBusy(false);
    }
  };

  if (step === 'where') {
    return (
      <WhereStep
        draft={draft}
        form={form}
        place={place}
        campusWide={typeOption.campus_wide}
        onTap={dropPin}
        onWholeCampus={wholeCampus}
        onRadius={(radius) => set({ radius })}
        onToggleBuilding={toggleBuilding}
        onBack={() => setStep('what')}
        onNext={() => setStep('confirm')}
      />
    );
  }

  if (step === 'confirm') {
    return (
      <Screen>
        <BackLink label="Change something" onPress={() => setStep('where')} />
        <View style={styles.confirm}>
          <View style={styles.confirmHead}>
            <Icon name="megaphone" size={26} color={colors.white} />
            <Text style={styles.confirmTitle}>This will alert everyone</Text>
          </View>
          <View style={styles.confirmBody}>
            <View style={styles.badges}>
              <LevelBadge level={draft.severity} label={level.label} />
              {draft.is_drill && <DrillBadge />}
              <Text style={type.small}>{typeOption.label}</Text>
            </View>
            <PhonePreview
              wording={draft}
              severity={draft.severity}
              severityLabel={level.label}
              typeLabel={typeOption.label}
              icon={draft.type}
              isDrill={draft.is_drill}
              location={place}
            />
            <View style={styles.placeRow}>
              <Icon name="pin" size={18} color={colors.alarm} />
              <Text style={styles.place}>
                {place}
                {draft.pin ? `  (${draft.radius} m danger zone)` : ''}
              </Text>
            </View>
            <Text style={styles.gets}>{level.students_get}</Text>
          </View>
        </View>
        {errors.length > 0 && <Notice tone="error">{errors.join('\n')}</Notice>}
        <Button title={draft.is_drill ? 'Send the drill to everyone' : 'Send to everyone'} variant="danger" loadingTitle="Sending…" loading={busy} onPress={send} />
        <Button title="Back to the map" variant="quiet" disabled={busy} onPress={() => setStep('where')} />
      </Screen>
    );
  }

  return (
    <Screen>
      <BackLink label={backLabel} />
      <Title>Send an alert</Title>
      <Text style={type.label}>What is happening?</Text>
      <TypePicker types={form.types} value={draft.type} onChange={pickType} />
      <Text style={type.label}>How serious is it?</Text>
      <LevelPicker levels={form.severities} value={draft.severity} onChange={(severity) => set({ severity })} />
      <DrillSwitch value={draft.is_drill} onChange={(is_drill) => set({ is_drill })} />
      <WordingFields value={draft} onChange={set} severity={draft.severity} limits={form.limits} />
      <Button title="Next: where is it?" disabled={!wordingReady(draft, draft.severity)} onPress={() => setStep('where')} />
    </Screen>
  );
}

type WhereProps = {
  draft: Draft;
  form: AlertFormData;
  place: string;
  /** Storms and earthquakes hit the whole campus: the sheet says so while there is no pin. */
  campusWide: boolean;
  onTap: (position: Position, map: CampusMap) => void;
  onWholeCampus: () => void;
  onRadius: (radius: number) => void;
  onToggleBuilding: (code: string) => void;
  onBack: () => void;
  onNext: () => void;
};

function WhereStep({ draft, form, place, campusWide, onTap, onWholeCampus, onRadius, onToggleBuilding, onBack, onNext }: WhereProps) {
  const insets = useSafeAreaInsets();
  const [showBuildings, setShowBuildings] = useState(false);
  const top = insets.top + 64;

  return (
    <View style={styles.where}>
      <PinMap pin={draft.pin} radius={draft.pin ? draft.radius : null} buildingCodes={draft.buildings} onTap={onTap} top={top} bottom={SHEET} />

      <View style={[styles.whereHead, { paddingTop: insets.top + space.sm }]}>
        <Pressable onPress={onBack} accessibilityRole="button" accessibilityLabel="Back" style={styles.round}>
          <Icon name="back" size={24} color={colors.primary} strokeWidth={2.4} />
        </Pressable>
        <View style={styles.hint}>
          <Text style={styles.hintText}>{draft.pin ? 'Drag the map, tap again to move the pin' : 'Tap the map where it is'}</Text>
        </View>
      </View>

      <View style={[styles.sheet, { height: SHEET + insets.bottom, paddingBottom: insets.bottom + space.md }]}>
        <ScrollView contentContainerStyle={styles.sheetContent}>
          <View style={styles.placeRow}>
            <Icon name="pin" size={20} color={draft.pin || draft.buildings.length ? colors.alarm : colors.muted} />
            <Text style={styles.placeBig}>{place}</Text>
          </View>

          {draft.pin ? (
            <>
              <Text style={type.label}>Danger zone</Text>
              <Chips options={form.radii.map((r) => ({ value: r, label: `${r} m` }))} value={draft.radius} onChange={onRadius} label="Danger zone" />
              <Button title="No pin: the whole campus" variant="link" onPress={onWholeCampus} />
            </>
          ) : (
            <Text style={type.small}>
              {campusWide
                ? 'This usually affects the whole campus, so it can go without a pin. Tap the map only if one place is hit.'
                : 'No pin yet: everyone on campus is alerted, with no danger zone. Tap the map to mark the place.'}
            </Text>
          )}

          <Pressable onPress={() => setShowBuildings(!showBuildings)} accessibilityRole="button" accessibilityState={{ expanded: showBuildings }} style={styles.buildingsToggle}>
            <Text style={styles.buildingsToggleText}>Affected buildings{draft.buildings.length ? ` (${draft.buildings.length})` : ''}</Text>
            <View style={{ transform: [{ rotate: showBuildings ? '-90deg' : '90deg' }] }}>
              <Icon name="chevron" size={18} color={colors.primary} strokeWidth={2.4} />
            </View>
          </Pressable>
          {showBuildings && (
            <View style={styles.buildingChips}>
              {form.buildings.map((building) => {
                const on = draft.buildings.includes(building.code);
                return (
                  <Pressable
                    key={building.code}
                    onPress={() => onToggleBuilding(building.code)}
                    accessibilityRole="checkbox"
                    accessibilityState={{ checked: on }}
                    style={[styles.buildingChip, on && styles.buildingChipOn]}>
                    {on && <Icon name="check" size={16} color={colors.white} strokeWidth={2.8} />}
                    <Text style={[styles.buildingChipText, on && { color: colors.white }]}>{building.name}</Text>
                  </Pressable>
                );
              })}
            </View>
          )}
        </ScrollView>
        <View style={styles.sheetButton}>
          <Button title="Next: check and send" onPress={onNext} />
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  confirm: { borderRadius: radius.lg, overflow: 'hidden', borderWidth: 2, borderColor: colors.alarm, backgroundColor: colors.surface },
  confirmHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, backgroundColor: colors.alarm },
  confirmTitle: { fontFamily: fonts.bold, fontSize: 21, color: colors.white },
  confirmBody: { padding: space.md, gap: space.sm },
  badges: { flexDirection: 'row', alignItems: 'center', gap: space.sm, flexWrap: 'wrap' },
  placeRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  place: { flex: 1, fontFamily: fonts.semibold, fontSize: 16, color: colors.alarm },
  placeBig: { flex: 1, fontFamily: fonts.bold, fontSize: 19, color: colors.text },
  gets: { fontFamily: fonts.medium, fontSize: 14, lineHeight: 20, color: colors.primary, marginTop: 4 },
  where: { flex: 1, backgroundColor: colors.bg },
  whereHead: { position: 'absolute', left: 0, right: 0, top: 0, flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md },
  round: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', elevation: 4, shadowColor: '#000', shadowOpacity: 0.15, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } },
  hint: { flex: 1, minHeight: 44, borderRadius: 22, backgroundColor: colors.primary, justifyContent: 'center', paddingHorizontal: space.md },
  hintText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.white },
  sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, backgroundColor: colors.surface, borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg, elevation: 12, shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 12, shadowOffset: { width: 0, height: -2 } },
  sheetContent: { padding: space.md, gap: space.sm },
  sheetButton: { paddingHorizontal: space.md },
  buildingsToggle: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 44 },
  buildingsToggleText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.primary },
  buildingChips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  buildingChip: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: 40, borderRadius: 20, borderWidth: 1.5, borderColor: colors.border, paddingHorizontal: 12 },
  buildingChipOn: { backgroundColor: colors.alarm, borderColor: colors.alarm },
  buildingChipText: { fontFamily: fonts.medium, fontSize: 14, color: colors.text },
});
