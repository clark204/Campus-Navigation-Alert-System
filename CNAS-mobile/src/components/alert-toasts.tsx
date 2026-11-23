import { router } from 'expo-router';
import { useEffect } from 'react';

import { chime } from '@/lib/alarm';
import { alertKind } from '@/lib/alert-rules';
import { useAlerts } from '@/lib/alerts';
import { useToasts, type ToastTone } from '@/lib/toasts';
import { useAlertsHref } from './alerts-screen';
import type { Alert } from '@/lib/types';

function toneOf(alert: Alert): ToastTone {
  if (alert.status === 'resolved') return 'alertClear';
  return alert.severity === 'warning' ? 'alertWarning' : 'alertAdvisory';
}

/**
 * Turns alerts into toasts (lib/alert-rules.ts): a warning (orange, with the chime, stays until closed), an advisory
 * (yellow, closes by itself) and the end of any alert (green, closes by itself; it also takes the red pill's place).
 * Once per alert version on this phone. One alert toast at a time: a newer version replaces the older toast. Tapping
 * opens the Alerts screen, where every alert stays listed.
 */
export function AlertToasts() {
  const { toastAlert, markToasted } = useAlerts();
  const { show } = useToasts();
  const alertsHref = useAlertsHref();

  const version = toastAlert?.version ?? null;
  useEffect(() => {
    if (!toastAlert) return;
    const resolved = toastAlert.status === 'resolved';
    show({
      tone: toneOf(toastAlert),
      key: 'alert',
      icon: toastAlert.type,
      kind: alertKind(toastAlert),
      title: toastAlert.title,
      body: resolved ? toastAlert.resolved_message : toastAlert.what_to_do || toastAlert.message,
      onPress: () => router.push(alertsHref),
    });
    if (!resolved && toastAlert.severity === 'warning') chime();
    markToasted(toastAlert);
    // Only a new version shows a toast.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [version]);

  return null;
}
