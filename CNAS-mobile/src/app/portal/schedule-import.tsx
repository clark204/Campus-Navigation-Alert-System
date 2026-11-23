import * as DocumentPicker from 'expo-document-picker';
import { router } from 'expo-router';
import { useState } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';

import { Icon } from '@/components/icon';
import { BackLink, Body, Button, Notice, Screen, Title } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { scheduleStore } from '@/lib/portal-data';
import type { ClassItem } from '@/lib/types';
import { colors, fonts, radius, space, type } from '@/theme';

// Same texts and limit as the web (portal/schedule-import-page, RegistrationService::COR_RULES).
const FILE_MESSAGE = 'Please upload your COR as a PDF file (up to 5 MB).';
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * "Import from COR" (students): POST /api/schedule/import. The COR is checked like at registration and must be the
 * student's own; it replaces the classes from an earlier COR and keeps the ones changed by hand. The server deletes the
 * PDF as soon as it has been read.
 */
export default function ScheduleImport() {
  const term = scheduleStore.get().data?.term ?? null;
  const [file, setFile] = useState<DocumentPicker.DocumentPickerAsset | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  const pick = async () => {
    const result = await DocumentPicker.getDocumentAsync({ type: 'application/pdf', copyToCacheDirectory: true });
    if (result.canceled) return;
    const asset = result.assets[0];
    setFile(asset);
    setDone(null);
    setError(asset.size && asset.size > MAX_BYTES ? FILE_MESSAGE : null);
  };

  const upload = async () => {
    if (!file || (file.size !== undefined && file.size > MAX_BYTES)) {
      setError(FILE_MESSAGE);
      return;
    }
    const form = new FormData();
    if (Platform.OS === 'web' && file.file) form.append('cor', file.file);
    else form.append('cor', { uri: file.uri, name: file.name || 'cor.pdf', type: file.mimeType ?? 'application/pdf' } as unknown as Blob);

    setBusy(true);
    setError(null);
    try {
      const result = await api<{ data: ClassItem[]; meta: { term: string | null }; message: string }>('/schedule/import', { method: 'POST', body: form, timeoutMs: 60000 });
      scheduleStore.set({ classes: result.data, term: result.meta.term });
      setDone(result.message);
      setFile(null);
    } catch (failure) {
      setError(failure instanceof ApiError ? (failure.field('cor') ?? failure.message) : errorMessage(failure));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <BackLink label="My schedule" />
      <Title>Import from COR</Title>
      <Body muted>
        Upload your own COR{term ? ` for ${term}` : ''}. Use the PDF you downloaded; your classes are read from it.
      </Body>

      {done ? (
        <>
          <Notice tone="success">{done}</Notice>
          <Button title="See my schedule" onPress={() => router.back()} />
        </>
      ) : (
        <>
          <View style={styles.notes}>
            <Text style={styles.note}>This replaces the classes that came from an earlier COR.</Text>
            <Text style={[styles.note, styles.strong]}>Classes you changed by hand are kept.</Text>
            <Text style={styles.note}>The file is deleted as soon as it has been read. Only your classes are saved.</Text>
          </View>

          <View style={{ gap: 6 }}>
            <Text style={type.label}>Your COR (PDF, up to 5 MB)</Text>
            <Button title={file ? 'Choose a different file' : 'Choose your COR (PDF)'} variant="quiet" onPress={pick} />
            {file && (
              <View style={styles.file}>
                <Icon name="file" size={22} color={colors.primary} />
                <Text style={[type.body, styles.fileName]} numberOfLines={1}>
                  {file.name}
                </Text>
              </View>
            )}
            {error ? <Text style={styles.error}>{error}</Text> : null}
          </View>

          <Button title="Import my classes" loadingTitle="Reading your COR…" loading={busy} disabled={!file} onPress={upload} />
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  notes: { gap: 6, padding: space.md, borderRadius: radius.md, backgroundColor: '#F3ECEA' },
  note: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted },
  strong: { fontFamily: fonts.semibold, color: colors.text },
  file: { flexDirection: 'row', alignItems: 'center', gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  fileName: { flex: 1 },
  error: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.error },
});
