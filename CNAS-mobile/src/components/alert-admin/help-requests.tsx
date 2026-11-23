import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { handleHelpRequest, type HelpRequest, type ManagedAlert } from '@/lib/admin';
import { ApiError, errorMessage } from '@/lib/api';
import { useSession } from '@/lib/session';
import { useToast } from '@/lib/toasts';
import { colors, fonts, radius, space, type } from '@/theme';
import { Button } from '../ui';
import { HELP_COLORS, HelpMap, isStale } from './help-map';

const ago = (iso: string, now = Date.now()) => {
  const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
  return `${Math.floor(seconds / 3600)} h`;
};

/**
 * The help queue on the "Who is safe" screen, like the web's (admin/alert-help-panel): the map, then a card per open
 * request, the longest waiting first: name, note, how fresh their location is, "I'm responding" (they see "<you> is on
 * the way") and "Mark helped". The helped ones follow, folded into one line each.
 */
export function HelpRequestsSection({ alert, requests, onChanged }: { alert: ManagedAlert | null | undefined; requests: HelpRequest[]; onChanged: () => void }) {
  const open = requests.filter((r) => r.status !== 'helped');
  const helped = requests.filter((r) => r.status === 'helped');
  if (requests.length === 0) return null;

  return (
    <View style={styles.section}>
      <Text style={styles.heading} accessibilityRole="header">
        Help requests{open.length > 0 ? `: ${open.length} waiting` : ''}
      </Text>
      <HelpMap
        incident={alert?.latitude != null && alert.longitude != null ? [alert.longitude, alert.latitude] : null}
        radius={alert?.danger_radius_m ?? null}
        buildingCodes={alert?.building_codes ?? []}
        requests={requests}
      />
      <View style={styles.legend} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <Legend color={HELP_COLORS.new} label="Waiting" />
        <Legend color={HELP_COLORS.responding} label="On the way" />
      </View>
      {open.length === 0 && <Text style={styles.allHelped}>Everyone who asked has been helped.</Text>}
      {open.map((request) => (
        <HelpCard key={request.id} alertId={alert?.id ?? null} request={request} onChanged={onChanged} />
      ))}
      {helped.length > 0 && (
        <View style={styles.helpedList}>
          <Text style={styles.helpedTitle}>Helped ({helped.length})</Text>
          {helped.map((request) => (
            <Text key={request.id} style={type.small}>
              {request.name}, by {request.helped_by_name ?? 'a deleted account'}
            </Text>
          ))}
        </View>
      )}
    </View>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <View style={styles.legendItem}>
      <View style={[styles.legendDot, { backgroundColor: color }]} />
      <Text style={type.small}>{label}</Text>
    </View>
  );
}

function HelpCard({ alertId, request, onChanged }: { alertId: number | null; request: HelpRequest; onChanged: () => void }) {
  const { user } = useSession();
  const toast = useToast();
  const [busy, setBusy] = useState<'respond' | 'helped' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const responding = request.status === 'responding';
  const mine = responding && request.responder_id === user?.id;

  const act = async (action: 'respond' | 'helped') => {
    if (alertId === null) return;
    setBusy(action);
    setError(null);
    try {
      await handleHelpRequest(alertId, request.id, action);
      toast(action === 'respond' ? `You are responding to ${request.name}.` : `${request.name} is marked as helped.`);
    } catch (failure) {
      setError(failure instanceof ApiError ? failure.message : errorMessage(failure));
    } finally {
      setBusy(null);
      onChanged();
    }
  };

  const location =
    request.latitude === null
      ? 'No location shared. Check the note.'
      : isStale(request)
        ? `Last seen ${ago(request.located_at!)} ago${request.accuracy_m ? `, within ${request.accuracy_m} m` : ''}`
        : `Live, updated ${ago(request.located_at!)} ago${request.accuracy_m ? `, within ${request.accuracy_m} m` : ''}`;

  return (
    <View style={[styles.card, { borderLeftColor: HELP_COLORS[request.status] }]}>
      <View style={styles.cardTop}>
        <View style={styles.flex}>
          <Text style={styles.name}>{request.name}</Text>
          <Text style={type.small}>{[request.role, request.college].filter(Boolean).join(', ')}</Text>
        </View>
        <View style={[styles.status, responding ? styles.statusResponding : styles.statusNew]}>
          <Text style={[styles.statusText, responding ? styles.statusTextResponding : styles.statusTextNew]}>
            {responding ? (mine ? 'You are on the way' : `${request.responder_name ?? 'An admin'} is on the way`) : `Waiting ${ago(request.asked_at)}`}
          </Text>
        </View>
      </View>
      {request.note ? <Text style={styles.note}>“{request.note}”</Text> : null}
      <Text style={[type.small, isStale(request) && request.latitude !== null && styles.stale]}>{location}</Text>
      {error && <Text style={styles.error}>{error}</Text>}
      <View style={styles.actions}>
        {(!responding || !mine) && (
          <View style={styles.flex}>
            <Button title={responding ? 'Take over' : 'I’m responding'} variant={responding ? 'quiet' : 'danger'} loading={busy === 'respond'} disabled={busy !== null} onPress={() => act('respond')} />
          </View>
        )}
        <View style={styles.flex}>
          <Button title="Mark helped" variant={responding ? 'primary' : 'quiet'} loading={busy === 'helped'} disabled={busy !== null} onPress={() => act('helped')} />
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  section: { gap: space.sm },
  heading: { fontFamily: fonts.bold, fontSize: 20, color: colors.primary },
  legend: { flexDirection: 'row', gap: space.md },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendDot: { width: 10, height: 10, borderRadius: 5 },
  allHelped: { fontFamily: fonts.semibold, fontSize: 15, color: colors.safe, backgroundColor: colors.safeTint, borderRadius: radius.md, padding: space.md },
  card: { gap: space.sm, padding: space.md, borderRadius: radius.md, borderWidth: 1, borderLeftWidth: 5, borderColor: colors.border, backgroundColor: colors.surface },
  cardTop: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm, flexWrap: 'wrap' },
  flex: { flex: 1 },
  name: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  status: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  statusNew: { backgroundColor: colors.alarm },
  statusResponding: { backgroundColor: '#FEF3C7' },
  statusText: { fontFamily: fonts.semibold, fontSize: 13 },
  statusTextNew: { color: colors.white },
  statusTextResponding: { color: '#78350F' },
  note: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.text, backgroundColor: colors.bg, borderRadius: radius.sm, padding: space.sm, overflow: 'hidden' },
  stale: { color: '#92400E', fontFamily: fonts.semibold },
  error: { fontFamily: fonts.medium, fontSize: 14, color: colors.alarm },
  actions: { flexDirection: 'row', gap: space.sm },
  helpedList: { gap: 4, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  helpedTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.safe },
});
