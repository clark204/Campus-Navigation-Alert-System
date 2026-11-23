import { router } from 'expo-router';
import { useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/icon';
import { Card, LoadState } from '@/components/portal-parts';
import { BackLink, Body, Notice, Screen, Title } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { placesStore, toggleSavedPlace, useResource } from '@/lib/portal-data';
import type { SavedPlace } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

const directions = (place: SavedPlace) => router.push({ pathname: '/navigate', params: { to: place.code } });

/**
 * "Saved places", like the portal page: the rooms the user saved (the star on the navigation card) and the ones they
 * last started directions to. Tap one for directions. The last copy shows offline.
 */
export default function SavedPlaces() {
  const places = useResource(placesStore);
  const [error, setError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<string | null>(null);
  const [clearing, setClearing] = useState(false);
  const saved = places.data?.saved ?? [];
  const recent = places.data?.recent ?? [];

  const remove = async (place: SavedPlace) => {
    setRemoving(place.code);
    setError(null);
    try {
      await toggleSavedPlace(place);
    } catch (failure) {
      setError(`${place.name} wasn’t removed. ${errorMessage(failure)}`);
    } finally {
      setRemoving(null);
    }
  };

  const clearRecent = async () => {
    setClearing(true);
    setError(null);
    try {
      await api('/places/recent', { method: 'DELETE' });
      placesStore.set({ saved, recent: [] });
    } catch (failure) {
      setError(errorMessage(failure));
    } finally {
      setClearing(false);
    }
  };

  return (
    <Screen onRefresh={() => placesStore.load(true)} refreshing={places.loading && places.data !== null}>
      <BackLink label="Home" />
      <Title>Saved places</Title>
      <LoadState resource={places} what="saved places" onRetry={() => placesStore.load(true)} />
      {error ? <Notice tone="error">{error}</Notice> : null}

      {places.data && (
        <>
          <Card title="Saved">
            {saved.length === 0 ? (
              <View style={styles.empty}>
                <Body muted>Nothing saved yet. Open a room on the map and tap the star to keep it here.</Body>
                <Pressable onPress={() => router.push('/navigate')} accessibilityRole="button" style={styles.link}>
                  <Text style={styles.linkText}>Find a room</Text>
                </Pressable>
              </View>
            ) : (
              saved.map((place, index) => (
                <PlaceRow
                  key={place.code}
                  place={place}
                  first={index === 0}
                  action={
                    <Pressable
                      onPress={() => remove(place)}
                      disabled={removing !== null}
                      accessibilityRole="button"
                      accessibilityLabel={`Remove ${place.name} from saved places`}
                      hitSlop={6}
                      style={({ pressed }) => [styles.remove, pressed && styles.removePressed]}>
                      {removing === place.code ? <ActivityIndicator color={colors.alarm} /> : <Icon name="trash" size={20} color={colors.alarm} />}
                    </Pressable>
                  }
                />
              ))
            )}
          </Card>

          <Card
            title="Recent"
            action={
              recent.length > 0 ? (
                <Pressable onPress={clearRecent} disabled={clearing} accessibilityRole="button" hitSlop={8} style={styles.link}>
                  <Text style={styles.linkText}>{clearing ? 'Clearing…' : 'Clear'}</Text>
                </Pressable>
              ) : undefined
            }>
            {recent.length === 0 ? (
              <View style={styles.empty}>
                <Body muted>Rooms you start directions to show here. Only the last 8 are kept.</Body>
              </View>
            ) : (
              recent.map((place, index) => <PlaceRow key={place.code} place={place} first={index === 0} />)
            )}
          </Card>
        </>
      )}
    </Screen>
  );
}

function PlaceRow({ place, first, action }: { place: SavedPlace; first: boolean; action?: ReactNode }) {
  return (
    <View style={[styles.row, !first && styles.divider]}>
      <Pressable
        onPress={() => directions(place)}
        accessibilityRole="button"
        accessibilityLabel={`Directions to ${place.name}`}
        style={({ pressed }) => [styles.main, pressed && styles.pressed]}>
        <View style={styles.pin}>
          <Icon name="pin" size={20} color={colors.primary} />
        </View>
        <View style={styles.text}>
          <Text style={styles.name}>{place.name}</Text>
          {place.location ? <Text style={type.small}>{place.location}</Text> : null}
        </View>
        <Icon name="walk" size={20} color={colors.primary} />
      </Pressable>
      {action}
    </View>
  );
}

const styles = StyleSheet.create({
  empty: { paddingHorizontal: space.md, paddingBottom: space.md, gap: space.xs },
  link: { minHeight: 40, justifyContent: 'center', alignSelf: 'flex-start' },
  linkText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.primary, textDecorationLine: 'underline' },
  row: { flexDirection: 'row', alignItems: 'center' },
  divider: { borderTopWidth: 1, borderTopColor: colors.border },
  main: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, minHeight: 64, paddingVertical: 10 },
  pressed: { backgroundColor: '#F3EEE8' },
  pin: { width: 38, height: 38, borderRadius: 19, backgroundColor: '#F3ECEA', alignItems: 'center', justifyContent: 'center' },
  text: { flex: 1, gap: 1 },
  name: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  remove: { width: 52, height: 52, marginRight: space.xs, alignItems: 'center', justifyContent: 'center', borderRadius: radius.sm },
  removePressed: { backgroundColor: colors.errorTint },
});
