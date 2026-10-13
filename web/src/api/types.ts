// Types des réponses de l'API /api/v1 (voir specs/001-socle-plateforme/contracts et les ressources du serveur).
export interface Problem {
  type: string;
  title: string;
  status: number;
  detail: string;
  trace_id?: string;
  errors?: Record<string, string[]>;
  attempts_left?: number;
  locked_until?: string;
  server_version?: Record<string, unknown>;
  client_version?: Record<string, unknown>;
  results?: SyncResult[];
  [extra: string]: unknown;
}

export interface Page<T> {
  data: T[];
  links?: { next: string | null };
  meta?: { next_cursor: string | null };
}

export interface UserSummary {
  id: string;
  person_id: string | null;
  first_name: string | null;
  last_name: string | null;
  locale: string;
  status: string;
}

export interface LoginResponse {
  token_type: 'Bearer';
  expires_at: string;
  new_device: boolean;
  two_factor_required: boolean;
  two_factor_setup_required: boolean;
  user: UserSummary;
  token?: string;
}

export interface Me extends UserSummary {
  greeting: string | null;
  two_factor: { enabled: boolean; required: boolean; verified: boolean };
  context: { establishment_id: string | null; role: string | null };
}

export interface RoleRef {
  code: string;
  label: string;
}

export interface ContextTile {
  kind: 'establishment' | 'group';
  id: string;
  name: string;
  type?: string;
  accent?: string | null;
  logo?: string | null;
  roles: RoleRef[];
  /** Fournis plus tard par les applications ; absents tant qu'aucune application ne les fournit. */
  period?: string | null;
  kpis?: Array<{ label: string; value: string }>;
  permissions?: string[];
}

export interface CodeChallenge {
  channel: 'sms' | 'email';
  masked_to: string;
  expires_in: number;
  resend_in: number;
}

export interface TwoFactorSetup {
  secret: string;
  secret_grouped: string;
  otpauth_uri: string;
  required_for?: Array<string | { code?: string; label?: string }>;
}

export interface Device {
  id: string;
  name: string;
  location: string | null;
  last_seen_at: string;
  trusted_until: string | null;
  current: boolean;
}

export interface TodoItem {
  kind: string;
  title: string;
  due_at?: string | null;
  link?: string | null;
}

export interface Indicator {
  key: string;
  label: string;
  available: boolean;
  value: number | null;
  unit: string;
  reason?: string;
  detail?: Record<string, string | number | boolean | null>;
}

export interface Dashboard {
  kind: 'direction' | 'censeur' | 'groupe';
  scope: { type: string; establishment_id?: string | null };
  generated_at: string;
  indicators?: Indicator[];
  consolidated?: Indicator[];
  establishments?: Array<{ establishment_id: string; name: string; indicators: Indicator[] }>;
  meta?: { degraded_providers: string[] };
}

export type AttendanceStatus = 'P' | 'A' | 'R';

export interface AttendanceLine {
  person_id: string;
  status: AttendanceStatus;
  arrived_at?: string;
  client_uuid: string;
  note?: string;
}

export interface AttendanceRecord {
  id: string;
  session_id: string;
  person_id: string;
  status: AttendanceStatus;
  arrived_at: string | null;
  justified: boolean | null;
  note: string | null;
}

export interface AttendanceSession {
  id: string;
  slot_id: string;
  structure_id: string;
  subject_label: string | null;
  session_date: string;
  start_time: string;
  end_time: string;
  status: string;
  client_uuid: string | null;
  expected_count: number;
  present_count: number;
  absent_count: number;
  late_count: number;
  recorded_count: number;
  records: AttendanceRecord[];
}

export interface SyncResult {
  client_uuid: string;
  status: string;
  message?: string;
  replayed?: boolean;
  session?: AttendanceSession;
  grade?: Grade;
  server?: Grade;
  client?: Record<string, unknown>;
  problem?: Partial<Problem>;
}

export interface Grade {
  id: string;
  assessment_id: string;
  person_id: string;
  score: number | null;
  absence: 'justified' | 'unjustified' | null;
  qualitative: string | null;
  remark: string | null;
  version: number;
  updated_at?: string;
}

export interface TimetableEntry {
  slot_id: string;
  structure_ids: string[];
  subject_label: string | null;
  teacher_person_id: string | null;
  original_teacher_person_id: string | null;
  room_id: string | null;
  start_time: string;
  end_time: string;
  status: string;
  change_id: string | null;
}

export interface TimetableWeek {
  view: { type: string; id: string };
  week_start: string;
  week_end: string;
  days: Array<{ date: string; weekday: number; entries: TimetableEntry[] }>;
}

export interface Person {
  id: string;
  first_name: string;
  last_name: string;
  birth_date: string | null;
  birth_place: string | null;
  gender: string | null;
  nationality: string | null;
  phone: string | null;
  email: string | null;
  locale: string | null;
  national_id: string | null;
  has_photo: boolean;
}

export interface Enrollment {
  id: string;
  person_id: string;
  establishment_id: string;
  structure_id: string | null;
  academic_year_id: string | null;
  status: string;
  internal_number: string | null;
  completeness: unknown;
  documents_due_on: string | null;
}

export interface Unavailable {
  available: false;
  reason: string;
}

export interface NextDue {
  available?: boolean;
  reason?: string;
  due_on?: string;
  amount_xaf?: number;
}

export interface PublishedResult {
  id: string;
  establishment_id: string;
  period_id: string | null;
  period_label: string | null;
  average: number | null;
  rank: number | null;
  class_size: number | null;
  decision: string | null;
}

export interface FamilyChild {
  person: { id: string; name: string };
  access: 'full' | 'restricted';
  restricted_reason?: string;
  enrollments: Array<{ id: string; establishment_id: string; establishment_name: string | null; structure_id: string | null }>;
  balance_xaf: number;
  next_due: NextDue;
  attendance?: { available: boolean; reason?: string; from?: string; to?: string; sessions?: number; absences?: number; unjustified_absences?: number; lates?: number };
  results?: { blocked: boolean; latest: PublishedResult | null; items: PublishedResult[] };
  documents?: { blocked: boolean; items: Array<{ id: string; number: string; type: string; verification_token: string }> };
  life_book?: Unavailable;
}

export interface FamilyPortal {
  guarantor_id: string;
  family_balance_xaf: number;
  next_due: NextDue;
  children: FamilyChild[];
}

export interface LearnerPortal extends FamilyChild {
  today: string;
  timetable_today: Array<{ subject: string | null; start: string; end: string; establishment_id: string }>;
}

export interface Payment {
  id: string;
  channel: string;
  amount_xaf: number;
  status: string;
  journal_status?: string;
  journal_amount_xaf?: number;
  receipt_number: string | null;
  external_reference?: string | null;
  received_at?: string;
  receipt?: { id: string; number: string; amount_xaf: number } | null;
}

export interface Invoice {
  id: string;
  learner_id: string;
  status: string;
  gross_xaf: number;
  discount_xaf: number;
  net_xaf: number;
  paid_xaf: number;
  balance_xaf: number;
}

export interface PaymentReference {
  id: string;
  guarantor_id: string;
  reference: string;
}

export interface FamilyBalance {
  reference?: string;
  total_xaf?: number;
  [extra: string]: unknown;
}

export interface Announcement {
  id: string;
  title: string;
  body: string;
  published_at: string | null;
  expires_at: string | null;
}

export interface IssuedDocument {
  id: string;
  type_code: string;
  number: string;
  status: string;
  holder_name: string | null;
  issued_at: string | null;
  issued_via: string | null;
  verification_url?: string;
}

export interface DataRequest {
  id: string;
  kind: string;
  status: string;
  due_at?: string | null;
  overdue?: boolean;
}

export interface PrivacySettings {
  deadline_days?: number;
  requests_retention_months?: number;
  approvals_retention_months?: number;
}

export interface Consent {
  id: string;
  person_id: string;
  purpose: string;
  granted_at?: string;
  revoked_at?: string | null;
}
