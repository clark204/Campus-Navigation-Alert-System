import { SITE_URL } from './config';
import type { User } from './types';

// Where each role belongs, like the website (CNAS-web App\Enums\UserRole::homeRoute): the admin gets the admin
// layout; students, faculty and staff get the portal.

export const isAdmin = (user: User | null) => user?.role === 'admin';

export const ROLE_LABELS: Record<User['role'], string> = {
  admin: 'Administrator',
  staff: 'Staff',
  faculty: 'Faculty',
  student: 'Student',
};

/** The app's home for this user: '/admin' or '/portal'. */
export const appHome = (user: User | null) => (isAdmin(user) ? '/admin' : '/portal');

/** The same person's area on the website: /admin, /faculty (faculty and staff share it) or /student. */
export function webArea(user: User | null): string {
  if (isAdmin(user)) return `${SITE_URL}/admin`;
  if (user?.role === 'faculty' || user?.role === 'staff') return `${SITE_URL}/faculty`;
  return `${SITE_URL}/student`;
}

/** First, middle and last name as one line, or the email when there is no name (accounts the admin created). */
export function fullName(user: User): string {
  return [user.first_name, user.middle_name, user.last_name].filter((part) => part && part.trim() !== '').join(' ') || user.email;
}

export function initials(user: User): string {
  const letters = [user.first_name, user.last_name].map((part) => part?.trim()?.[0] ?? '').join('');
  return (letters || user.email[0] || '?').toUpperCase();
}
