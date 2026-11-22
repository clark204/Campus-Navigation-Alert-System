import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import { api, errorMessage } from '@/lib/api';
import { readJson, writeJson } from '@/lib/storage';
import type { EmergencyContact } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';
import { Icon } from './icon';

// The last list, so the numbers still show without internet (when they matter most).
const CACHE_KEY = 'cnas.emergency-contacts';

type Loaded = { contacts: EmergencyContact[] | null; error: string | null };

async function fetchContacts(): Promise<Loaded> {
  try {
    const { data } = await api<{ data: EmergencyContact[] }>('/emergency-contacts');
    writeJson(CACHE_KEY, data);
    return { contacts: data, error: null };
  } catch (failure) {
    return { contacts: await readJson<EmergencyContact[] | null>(CACHE_KEY, null), error: errorMessage(failure) };
  }
}

/** The emergency numbers from GET /api/emergency-contacts. Tap one to call it. */
export function EmergencyContacts() {
  const [contacts, setContacts] = useState<EmergencyContact[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const apply = useCallback((result: Loaded) => {
    // A failed load keeps what is already on screen, else the saved copy.
    setContacts((current) => (result.error ? (current ?? result.contacts) : result.contacts));
    setError(result.error);
    setLoading(false);
  }, []);

  useEffect(() => {
    fetchContacts().then(apply);
  }, [apply]);

  const retry = () => {
    setLoading(true);
    setError(null);
    fetchContacts().then(apply);
  };

  return (
    <View style={styles.wrap}>
      <Text style={type.heading} accessibilityRole="header">
        Emergency numbers
      </Text>

      {contacts === null && loading && <ActivityIndicator color={colors.primary} style={styles.spinner} />}

      {contacts?.length === 0 && <Text style={type.small}>No emergency numbers have been added yet.</Text>}

      {contacts?.map((contact) => (
        <Pressable
          key={contact.id}
          onPress={() => Linking.openURL(contact.tel)}
          accessibilityRole="button"
          accessibilityLabel={`Call ${contact.name}, ${contact.phone}`}
          style={({ pressed }) => [styles.row, pressed && styles.pressed]}>
          <View style={styles.text}>
            <Text style={styles.name}>{contact.name}</Text>
            <Text style={styles.phone}>{contact.phone}</Text>
            {contact.note ? <Text style={type.small}>{contact.note}</Text> : null}
          </View>
          <View style={styles.call}>
            <Icon name="phone" size={22} color={colors.white} />
          </View>
        </Pressable>
      ))}

      {error && (
        <View style={styles.failed}>
          <Text style={[type.small, styles.text]}>
            {contacts ? 'These may be out of date. ' : 'Can’t load the emergency numbers. '}
            {error}
          </Text>
          <Pressable onPress={retry} accessibilityRole="button" hitSlop={8} style={styles.retry}>
            <Text style={styles.retryText}>{loading ? 'Loading…' : 'Try again'}</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: space.sm },
  spinner: { alignSelf: 'flex-start', marginVertical: space.sm },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderLeftWidth: 5,
    borderLeftColor: colors.secondary,
    borderRadius: radius.md,
    padding: space.md,
    minHeight: 72,
  },
  pressed: { backgroundColor: '#F3EEE8' },
  text: { flex: 1 },
  name: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  phone: { fontFamily: fonts.bold, fontSize: 24, lineHeight: 30, color: colors.primary, fontVariant: ['tabular-nums'] },
  call: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.safe, alignItems: 'center', justifyContent: 'center' },
  failed: { flexDirection: 'row', alignItems: 'center', gap: space.md },
  retry: { minHeight: 44, justifyContent: 'center' },
  retryText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.primary, textDecorationLine: 'underline' },
});
