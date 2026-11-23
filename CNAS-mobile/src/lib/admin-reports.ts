import type * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useEffect } from 'react';

import { alertsStore, concernsStore } from './admin';

// Reports for admins (CNAS-web App\Services\AdminReport): a new concern or "I need help", pushed on the "admin"
// channel. When one arrives the admin's lists refresh at once; tapping it opens the concern or the "Who is safe" view.

export type Report = { screen: 'concern'; concern_id: number } | { screen: 'safety'; alert_id: number };

/** The report a notification is about, or null (an alert push, or something else). */
export function reportOf(notification: Notifications.Notification): Report | null {
  const data = notification.request.content.data ?? {};
  const id = (key: string) => {
    const value = Number(data[key]);
    return Number.isFinite(value) && value > 0 ? value : null;
  };
  if (data.screen === 'concern' && id('concern_id')) return { screen: 'concern', concern_id: id('concern_id')! };
  if (data.screen === 'safety' && id('alert_id')) return { screen: 'safety', alert_id: id('alert_id')! };
  return null;
}

const arrivalListeners = new Set<() => void>();

/** A report arrived while the app is open: the badge and the open screen catch up now, not at the next poll. */
export function reportArrived() {
  concernsStore.load(true);
  alertsStore.load(true);
  arrivalListeners.forEach((listener) => listener());
}

/** Run `refresh` when a report arrives (the Concerns list, the "Who is safe" view). */
export function useReportArrivals(refresh: () => void) {
  useEffect(() => {
    arrivalListeners.add(refresh);
    return () => {
      arrivalListeners.delete(refresh);
    };
  }, [refresh]);
}

// A tapped report waits here until the admin area is on screen (the app may be starting, behind the intro).
let pending: Report | null = null;
const openListeners = new Set<() => void>();

export function openReport(report: Report) {
  pending = report;
  openListeners.forEach((listener) => listener());
}

/** In the admin area: open a tapped report's screen now, or as soon as it is tapped. */
export function useOpenReports() {
  useEffect(() => {
    const go = () => {
      const report = pending;
      if (!report) return;
      pending = null;
      if (report.screen === 'concern') router.push({ pathname: '/admin/concern', params: { id: String(report.concern_id) } });
      else router.push({ pathname: '/admin/safety', params: { id: String(report.alert_id) } });
    };
    go();
    openListeners.add(go);
    return () => {
      openListeners.delete(go);
    };
  }, []);
}
