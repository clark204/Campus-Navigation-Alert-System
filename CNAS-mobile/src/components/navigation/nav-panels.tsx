import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { ordinal } from '@/lib/navigation/engine';
import { floorButton, type NavigationSession, type SessionView } from '@/lib/navigation/session';
import { colors, fonts, radius, space } from '@/theme';
import { Icon } from '../icon';
import { TurnIcon } from './turn-icon';

/** The instruction at the top, like a turn-by-turn banner: what to do next, how far, and what comes after. */
export function InstructionBanner({ view, topInset }: { view: SessionView; topInset: number }) {
  return (
    <View>
      <View style={[styles.banner, { paddingTop: topInset + space.md }]}>
        <View style={styles.bannerRow}>
          <View style={styles.turn}>
            <TurnIcon maneuver={view.next} size={40} color={colors.secondary} />
            <Text style={styles.distance}>{view.distance}</Text>
          </View>
          <Text style={styles.instruction} accessibilityLiveRegion="polite" accessibilityRole="header">
            {view.next.text}
          </Text>
        </View>
      </View>
      {view.after ? (
        <View style={styles.then}>
          <Text style={styles.thenText} numberOfLines={2}>
            Then: {view.after}
          </Text>
        </View>
      ) : null}
    </View>
  );
}

/** Wrong way, rerouting, and why the dot can't follow (manual mode), under the banner. */
export function NavNotes({ view }: { view: SessionView }) {
  return (
    <View style={styles.notes} pointerEvents="none">
      {view.wrongWay && (
        <View style={[styles.noteBar, styles.wrong]} accessibilityLiveRegion="assertive">
          <Icon name="back" size={20} color={colors.white} strokeWidth={2.6} />
          <Text style={styles.wrongText}>You’re going the wrong way. Turn around.</Text>
        </View>
      )}
      {view.rerouteNote && (
        <View style={[styles.noteBar, styles.reroute]} accessibilityLiveRegion="polite">
          <Text style={styles.rerouteText}>{view.rerouteNote}</Text>
        </View>
      )}
      {view.note && (
        <View style={[styles.noteBar, styles.manual]}>
          <Text style={styles.manualText}>{view.note}</Text>
        </View>
      )}
      {view.floor > 1 && (
        <View style={styles.floor}>
          <Text style={styles.floorText}>{ordinal(view.floor)} floor</Text>
        </View>
      )}
    </View>
  );
}

/** Round buttons on the map's right edge. */
export function MapButton({ label, onPress, children, active = false }: { label: string; onPress: () => void; children: React.ReactNode; active?: boolean }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ selected: active }}
      hitSlop={4}
      style={({ pressed }) => [styles.mapButton, pressed && styles.pressed]}>
      {children}
    </Pressable>
  );
}

type BottomProps = {
  view: SessionView;
  session: NavigationSession;
  bottomInset: number;
  onEnd: () => void;
};

/** Time and distance left, where this leg leads, and the one button that fits the moment. */
export function NavBottom({ view, session, bottomInset, onEnd }: BottomProps) {
  const stairs = view.waitingAt;
  return (
    <View style={[styles.sheet, { paddingBottom: bottomInset + space.md }]}>
      <View style={styles.tripRow}>
        <View style={styles.tripText}>
          <Text style={styles.eta}>
            {view.eta}
            <Text style={styles.remaining}>{`   ${view.remaining}`}</Text>
          </Text>
          <Text style={styles.leg} numberOfLines={2}>
            {view.legLabel}
          </Text>
        </View>
        <Pressable onPress={onEnd} accessibilityRole="button" accessibilityLabel="End navigation" style={({ pressed }) => [styles.end, pressed && styles.pressed]}>
          <Text style={styles.endText}>End</Text>
        </Pressable>
      </View>
      {stairs ? (
        <Pressable onPress={() => session.confirmFloor()} accessibilityRole="button" style={({ pressed }) => [styles.action, styles.gold, pressed && styles.pressed]}>
          <Text style={[styles.actionText, styles.goldText]}>{floorButton(stairs)}</Text>
        </Pressable>
      ) : view.manual ? (
        <Pressable onPress={() => session.imHere()} accessibilityRole="button" style={({ pressed }) => [styles.action, styles.primary, pressed && styles.pressed]}>
          <Text style={[styles.actionText, styles.primaryText]}>Next</Text>
        </Pressable>
      ) : (
        <Pressable
          onPress={() => session.imHere()}
          accessibilityRole="button"
          accessibilityHint="Moves the dot to the instruction shown, if it is behind"
          style={({ pressed }) => [styles.action, styles.outline, pressed && styles.pressed]}>
          <Text style={[styles.actionText, styles.outlineText]}>I’m here</Text>
        </Pressable>
      )}
    </View>
  );
}

export function ArrivedSheet({ text, bottomInset, onDone }: { text: string; bottomInset: number; onDone: () => void }) {
  return (
    <View style={[styles.sheet, styles.arrived, { paddingBottom: bottomInset + space.md }]}>
      <View style={styles.arrivedBadge}>
        <Icon name="check" size={34} color={colors.primary} strokeWidth={3} />
      </View>
      <Text style={styles.arrivedText} accessibilityLiveRegion="polite">
        {text}
      </Text>
      <Pressable onPress={onDone} accessibilityRole="button" style={({ pressed }) => [styles.action, styles.primary, styles.done, pressed && styles.pressed]}>
        <Text style={[styles.actionText, styles.primaryText]}>Done</Text>
      </Pressable>
    </View>
  );
}

/** Development builds only: the web's ?debug=1 rows and the fake GPS walk, for testing away from campus. */
export function DebugPanel({ view, session }: { view: SessionView; session: NavigationSession }) {
  const [open, setOpen] = useState(false);
  const d = view.debug;
  if (!open) {
    return (
      <Pressable onPress={() => setOpen(true)} style={styles.debugToggle} accessibilityRole="button" accessibilityLabel="Show the debug panel">
        <Text style={styles.debugToggleText}>debug</Text>
      </Pressable>
    );
  }
  const rows = [
    ['location', d.location],
    ['accuracy', d.accuracy === null ? '-' : `${Math.round(d.accuracy)} m (best ${d.best === null ? '-' : Math.round(d.best)} m, limit 25)`],
    ['gps', d.gps ? `${d.gps.used ? 'used' : d.gps.reason || 'not used'}${d.gps.speed !== null ? `, ${d.gps.speed.toFixed(1)} m/s` : ', no speed'}` : '-'],
    ['heading', view.heading.value === null ? 'unknown' : `${Math.round(view.heading.value)}° (${view.heading.source})`],
    ['dot from', d.source],
    ['off route', `${d.offRoute.toFixed(1)} m (limit 10), ${d.offRouteStreak} fixes, ${d.reroutes} reroutes`],
    ['steps', `${d.steps}${d.stepSensor ? '' : ' (no sensor yet)'}`],
  ];
  return (
    <View style={styles.debug}>
      {rows.map(([key, value]) => (
        <Text key={key} style={styles.debugRow}>
          <Text style={styles.debugKey}>{key}: </Text>
          {value}
        </Text>
      ))}
      <View style={styles.debugButtons}>
        <DebugButton label={d.simulating ? 'Real GPS' : 'Simulate'} onPress={() => session.setSimulating(!d.simulating)} />
        {d.simulating && <DebugButton label="Walk route" onPress={() => session.simulateWalk()} />}
        {d.simulating && <DebugButton label="Walk off" onPress={() => session.simulateWalkOff()} />}
        <DebugButton label="+1 step" onPress={() => session.applyStep()} />
        <DebugButton label="Hide" onPress={() => setOpen(false)} />
      </View>
    </View>
  );
}

function DebugButton({ label, onPress }: { label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={styles.debugButton} accessibilityRole="button">
      <Text style={styles.debugButtonText}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banner: { backgroundColor: colors.primary, paddingHorizontal: space.md, paddingBottom: space.md },
  bannerRow: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  turn: { width: 72, alignItems: 'center', gap: 2 },
  distance: { fontFamily: fonts.bold, fontSize: 22, color: colors.secondary },
  instruction: { flex: 1, fontFamily: fonts.bold, fontSize: 23, lineHeight: 29, color: colors.white },
  then: { backgroundColor: colors.primaryHover, paddingHorizontal: space.md, paddingVertical: 10, borderBottomLeftRadius: radius.md, borderBottomRightRadius: radius.md },
  thenText: { fontFamily: fonts.medium, fontSize: 15, color: 'rgba(255,255,255,0.88)' },
  notes: { paddingHorizontal: space.md, paddingTop: space.sm, gap: space.sm, alignItems: 'flex-start' },
  noteBar: { flexDirection: 'row', alignItems: 'center', gap: space.sm, borderRadius: radius.md, paddingHorizontal: space.md, paddingVertical: 10, alignSelf: 'stretch' },
  wrong: { backgroundColor: colors.alarm },
  wrongText: { flex: 1, fontFamily: fonts.bold, fontSize: 17, color: colors.white },
  reroute: { alignSelf: 'center', backgroundColor: colors.text, borderRadius: 20 },
  rerouteText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.white },
  manual: { backgroundColor: '#FFF7E0', borderWidth: 1.5, borderColor: colors.secondary },
  manualText: { flex: 1, fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
  floor: { alignSelf: 'flex-end', backgroundColor: colors.surface, borderRadius: 16, paddingHorizontal: 12, paddingVertical: 6, borderWidth: 2, borderColor: colors.primary },
  floorText: { fontFamily: fonts.bold, fontSize: 15, color: colors.primary },
  mapButton: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
    elevation: 5,
  },
  pressed: { opacity: 0.8 },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    paddingHorizontal: space.lg,
    paddingTop: space.md,
    gap: space.md,
    shadowColor: '#000',
    shadowOpacity: 0.12,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: -2 },
    elevation: 8,
  },
  tripRow: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  tripText: { flex: 1 },
  eta: { fontFamily: fonts.bold, fontSize: 26, color: colors.safe },
  remaining: { fontFamily: fonts.medium, fontSize: 18, color: colors.muted },
  leg: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
  end: { minHeight: 44, paddingHorizontal: 18, borderRadius: 22, backgroundColor: colors.errorTint, justifyContent: 'center' },
  endText: { fontFamily: fonts.bold, fontSize: 16, color: colors.alarm },
  action: { minHeight: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center', paddingHorizontal: space.lg },
  actionText: { fontFamily: fonts.bold, fontSize: 18 },
  gold: { backgroundColor: colors.secondary },
  goldText: { color: colors.primary },
  primary: { backgroundColor: colors.primary },
  primaryText: { color: colors.white },
  outline: { borderWidth: 2, borderColor: colors.primary },
  outlineText: { color: colors.primary },
  arrived: { alignItems: 'center', paddingTop: space.lg },
  arrivedBadge: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.secondary, alignItems: 'center', justifyContent: 'center' },
  arrivedText: { fontFamily: fonts.bold, fontSize: 22, lineHeight: 29, color: colors.text, textAlign: 'center' },
  done: { alignSelf: 'stretch' },
  debugToggle: { position: 'absolute', left: space.sm, bottom: 0, paddingHorizontal: 8, paddingVertical: 4, borderRadius: 6, backgroundColor: 'rgba(0,0,0,0.55)' },
  debugToggleText: { fontFamily: fonts.medium, fontSize: 12, color: colors.white },
  debug: { position: 'absolute', left: space.sm, right: space.sm, bottom: 0, padding: space.sm, borderRadius: radius.sm, backgroundColor: 'rgba(0,0,0,0.78)', gap: 2 },
  debugRow: { fontFamily: fonts.regular, fontSize: 12, color: colors.white },
  debugKey: { fontFamily: fonts.semibold, color: colors.secondary },
  debugButtons: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 },
  debugButton: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 6, backgroundColor: colors.secondary },
  debugButtonText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.primary },
});
