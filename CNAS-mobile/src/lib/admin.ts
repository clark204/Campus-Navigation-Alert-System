import { api } from './api';
import { createStore } from './portal-data';
import { removeKeys } from './storage';
import type { Alert, AlertSeverity } from './types';

// The admin side of the app (and trusted alert senders), against CNAS-web's /api/alerts and /api/admin/* (docs/alerts.md,
// docs/accounts.md, docs/portal.md). The rules all live on the server; these are only the shapes.

export type AlertTypeOption = {
  value: Alert['type'];
  label: string;
  severity: AlertSeverity;
  /** Storms and earthquakes hit the whole campus, so the form starts without a pin for them. */
  campus_wide: boolean;
  template: { title: string; message: string; instructions: string };
};

export type SeverityOption = { value: AlertSeverity; label: string; color: string; description: string; example: string; students_get: string };

export type ResolutionOption = { value: 'all_clear' | 'false_alarm'; label: string; message: string; drill_message: string };

/** GET /api/alerts/form */
export type AlertFormData = {
  types: AlertTypeOption[];
  severities: SeverityOption[];
  radii: number[];
  default_radius: number;
  buildings: { code: string; name: string }[];
  resolutions: ResolutionOption[];
};

export type AlertLogEntry = { action: string; action_label: string; detail: string | null; by: string | null; at: string | null };

/** ManagedAlertResource */
export type ManagedAlert = Alert & { sent_by: string | null; resolved_by: string | null; logs?: AlertLogEntry[] };

export type SafetyCounts = {
  safe: number;
  total: number;
  not_answered: number;
  colleges: { id: string; name: string; safe: number; total: number }[];
};

export type SafetyView = SafetyCounts & { people: { name: string; role: string; college: string | null; responded_at: string }[] };

/** GET /api/alerts */
export type AlertsOverview = {
  active: ManagedAlert | null;
  /** Admin only. */
  safety: SafetyCounts | null;
  history: ManagedAlert[];
  meta: { current_page: number; last_page: number; total: number } | null;
  /** The admin edits the wording and level; trusted senders only send and end. */
  can_edit: boolean;
};

let formData: Promise<AlertFormData> | null = null;

/** The send form's options, asked once per run of the app (they only change with a new server version or map). */
export function alertForm(): Promise<AlertFormData> {
  formData ??= api<AlertFormData>('/alerts/form').catch((failure) => {
    formData = null;
    throw failure;
  });
  return formData;
}

/** The active alert and history, shared by the dashboard, the alerts page and the tab. Refreshed while they're open. */
export const alertsStore = createStore<AlertsOverview>('cnas.admin.alerts', () => api<AlertsOverview>('/alerts'));

// ---------- Concerns

export type ConcernRow = {
  id: number;
  topic: string;
  topic_label: string;
  topic_short: string;
  message: string;
  status: 'open' | 'resolved';
  sender: string | null;
  email: string;
  reply_count: number;
  sent_at: string | null;
  resolved_at: string | null;
  unread?: boolean;
};

export type ConcernConversation = ConcernRow & {
  account: { id: number; role: string; school_id: string | null; college: string | null } | null;
  replies: { id: number; message: string; by: string | null; sent_at: string | null }[];
};

export type ConcernPage = {
  data: ConcernRow[];
  meta: { status: 'open' | 'resolved'; current_page: number; last_page: number; total: number; open_count: number; resolved_count: number; unread_count: number };
};

/** The Open folder's first page: the Concerns tab badge (unread_count). */
export const concernsStore = createStore<ConcernPage>('cnas.admin.concerns', () => api<ConcernPage>('/admin/concerns'));

// ---------- Accounts

export type Role = 'admin' | 'staff' | 'faculty' | 'student';

export type AccountRow = {
  id: number;
  name: string;
  email: string;
  school_id: string | null;
  role: Role;
  role_label: string;
  college: string | null;
  program: string | null;
  trusted_for_alerts: boolean;
  created_at: string | null;
};

export type AccountDetails = AccountRow & {
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  program_code: string | null;
  email_verified: boolean;
  must_change_password: boolean;
  can_be_trusted: boolean;
  can_switch_role: boolean;
  is_admin: boolean;
  last_sign_in_at: string | null;
  sign_in_count: number;
  alerts_sent: number;
  /** What deleting removes ("saved places": 3), only the kinds they have. */
  deletes: Record<string, number>;
  can_delete: boolean;
};

export type AccountPage = {
  data: AccountRow[];
  meta: { current_page: number; last_page: number; total: number; counts: Partial<Record<Role, number>>; trusted_count: number };
};

export type AccountOptions = {
  roles: { value: Role; label: string }[];
  switchable_roles: { value: Role; label: string }[];
  colleges: { name: string; programs: { code: string; name: string }[] }[];
};

// No look-alike characters (0/O, 1/l/I), so the password can be read out or written down: the same alphabet as the
// web's Generate (CNAS-web resources/js/accounts.js). The server checks Password::defaults() either way.
const LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';

function randomIndex(max: number): number {
  const crypto = (globalThis as { crypto?: { getRandomValues?: (array: Uint32Array) => Uint32Array } }).crypto;
  if (crypto?.getRandomValues) return crypto.getRandomValues(new Uint32Array(1))[0] % max;
  return Math.floor(Math.random() * max);
}

/** A temporary password: 12 characters with at least two letters and two digits, in a random order. */
export function generatePassword(length = 12): string {
  const all = LETTERS + DIGITS;
  const chars = [
    LETTERS[randomIndex(LETTERS.length)],
    LETTERS[randomIndex(LETTERS.length)],
    DIGITS[randomIndex(DIGITS.length)],
    DIGITS[randomIndex(DIGITS.length)],
    ...Array.from({ length: Math.max(0, length - 4) }, () => all[randomIndex(all.length)]),
  ];
  for (let i = chars.length - 1; i > 0; i--) {
    const j = randomIndex(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
}

/** "Nov 23, 3:04 PM" in campus time. */
export function campusDateTime(iso: string | null): string {
  if (!iso) return '';
  return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' });
}

/** Sign-out: forget the admin copies on this phone. */
export async function clearAdminData() {
  alertsStore.reset();
  concernsStore.reset();
  await removeKeys([alertsStore.key, concernsStore.key]);
}
