// Shapes of the CNAS-web API responses (app/Http/Resources). Keep in sync with the server.

/** UserResource: /api/login (under "user"), /api/me, /api/register, /api/email/verify. Not wrapped in "data". */
export type User = {
  id: number;
  school_id: string | null;
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  email: string;
  email_verified: boolean;
  must_change_password: boolean;
  role: 'admin' | 'staff' | 'faculty' | 'student';
  college: { id: string; name: string } | null;
  program: { code: string; name: string } | null;
};

/** CorDraftResource: POST /api/register/cor. Not wrapped in "data". */
export type CorDraft = {
  draft_token: string;
  student_id: string;
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  program_code: string;
  program_name: string | null;
  college_id: string | null;
  college_name: string | null;
  meeting_count: number;
};

/** EmergencyContactResource: GET /api/emergency-contacts → { data: EmergencyContact[] }. */
export type EmergencyContact = {
  id: number;
  name: string;
  phone: string;
  /** "tel:+63…" */
  tel: string;
  note: string | null;
};

export type AlertSeverity = 'advisory' | 'warning' | 'emergency';

/** AlertResource: GET /api/alerts/active → { data: Alert | null }. */
export type Alert = {
  id: number;
  /** Changes when the alert is edited, its level changes or it ends. */
  version: string;
  type: 'fire' | 'earthquake' | 'flood' | 'typhoon' | 'security' | 'other';
  type_label: string;
  severity: AlertSeverity;
  severity_label: string;
  title: string;
  message: string;
  instructions: string | null;
  is_drill: boolean;
  status: 'active' | 'resolved';
  location: string | null;
  latitude: number | null;
  longitude: number | null;
  danger_radius_m: number | null;
  building_codes: string[];
  has_location: boolean;
  sent_at: string;
  resolution: 'all_clear' | 'false_alarm' | null;
  resolution_label: string | null;
  resolved_at: string | null;
  resolved_message: string | null;
  banner_expires_at: string | null;
  /** Only when signed in: "safe" when this user already tapped "I'm safe" (any device). Null outside an emergency. */
  my_safety_status?: 'safe' | null;
};
