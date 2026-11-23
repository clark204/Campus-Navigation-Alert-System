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
  /** The admin, or faculty and staff the admin trusts: the app shows Send an alert. */
  can_send_alerts: boolean;
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
  /** "What to do now": the bold line (older alerts: the first sentence of their instructions; can be empty). */
  what_to_do: string;
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
  my_safety_status?: 'safe' | 'need_help' | null;
  /** Only when signed in and this user asked for help: where the request is ("Rita Reyes is on the way."). */
  my_help?: HelpState | null;
};

/** HelpPingController::helpState: this user's own help request. */
export type HelpState = {
  status: 'new' | 'responding' | 'helped';
  label: string;
  responder_name: string | null;
};

export type Weekday = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

/** ClassScheduleResource: GET /api/schedule → { data: ClassItem[], meta: { term } }. Times are 24-hour campus time. */
export type ClassItem = {
  id: number;
  subject: string;
  section: string | null;
  day: Weekday;
  starts_at: string;
  ends_at: string;
  /** As written ("CompLab1", "Online", "TBA"). */
  room_text: string;
  /** The room on the map (its place id for directions), or null. */
  room_code: string | null;
  room_name: string | null;
  /** What the edit form shows picked: "TBA", "Online", "other" or a room code. */
  room_choice: string;
  room_other: string | null;
  source: 'cor' | 'manual';
};

/** GET /api/schedule/rooms: the class form's room list. */
export type RoomChoices = {
  choices: { value: string; label: string }[];
  buildings: { name: string; rooms: { code: string; label: string }[] }[];
};

/** PlaceResource: GET /api/places → { saved, recent }. */
export type SavedPlace = { code: string; name: string; location: string };

/** ConcernResource: GET /api/concerns → { data: Concern[], meta: { email, topics } }. */
export type Concern = {
  id: number;
  topic: string;
  topic_label: string;
  message: string;
  status: 'open' | 'resolved';
  sent_at: string | null;
  resolved_at: string | null;
  replies: { id: number; message: string; sent_at: string | null }[];
};

export type ConcernTopic = { value: string; label: string };

/** GET /api/notifications → { unread_count, data: BellNotification[] }. */
export type BellNotification = {
  id: string;
  kind: string | null;
  concern_id: number | null;
  title: string;
  body: string;
  read: boolean;
  created_at: string | null;
};

/** GET /api/account/email (and the email change answers): a change waits for its code while `pending` is set. */
export type EmailState = {
  email: string;
  email_verified: boolean;
  pending: { email: string; resend_in: number } | null;
};
