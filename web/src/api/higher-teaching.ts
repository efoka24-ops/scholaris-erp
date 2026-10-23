// Appels de l'application Supérieur (IPES), lot I2 : enseignement (EDT, ENS) et présences/discipline (PRE).
// Contrats : specs/001-socle-plateforme/contracts/openapi.d/higher-teaching.yaml et higher-attendance.yaml.
// Les listes rendent { data, meta.next_cursor } ; les ressources isolées sont rendues telles quelles (sans enveloppe « data »).
// L'appel lui-même (séances, QR, hors ligne) et les justificatifs restent aux routes du socle (/attendance-sessions*).
import { http } from './http';
import { H, type Q } from './higher';

const seg = (v: string): string => encodeURIComponent(v);
const data = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export type SessionKind = 'cm' | 'td' | 'tp' | 'other';
export type TeacherStatus = 'permanent' | 'vacataire' | 'invite';
export type ChangeKind = 'postponed' | 'cancelled' | 'replaced' | 'room_changed';
export type LogStatus = 'draft' | 'signed' | 'countersigned' | 'validated';
export type ExamSession = 'normal' | 'retake' | 'replacement';
export type AssiduityStatus = 'ok' | 'alert' | 'barred' | 'lifted';
export type SanctionKind = 'warning' | 'reprimand' | 'temporary_exclusion' | 'permanent_exclusion';
export type CouncilDecision = 'none' | SanctionKind;

export const SESSION_KINDS: SessionKind[] = ['cm', 'td', 'tp', 'other'];
export const TEACHER_STATUSES: TeacherStatus[] = ['permanent', 'vacataire', 'invite'];
export const CHANGE_KINDS: ChangeKind[] = ['postponed', 'cancelled', 'replaced', 'room_changed'];
export const LOG_STATUSES: LogStatus[] = ['draft', 'signed', 'countersigned', 'validated'];
export const EXAM_SESSIONS: ExamSession[] = ['normal', 'retake', 'replacement'];
export const SANCTION_KINDS: SanctionKind[] = ['warning', 'reprimand', 'temporary_exclusion', 'permanent_exclusion'];
/** L'exclusion définitive n'est prononcée que par le conseil de discipline (RG-PRE-2). */
export const DIRECT_SANCTIONS: SanctionKind[] = ['warning', 'reprimand', 'temporary_exclusion'];
export const COUNCIL_DECISIONS: CouncilDecision[] = ['none', 'warning', 'reprimand', 'temporary_exclusion', 'permanent_exclusion'];
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;
export const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

// --- Enseignants et salles ---------------------------------------------------------------------------------------------------
export interface Teacher {
  id: string; person_id: string; staff_id: string | null; school_id: string | null; department_id: string | null; teacher_status: TeacherStatus;
  grade: string | null; diplomas: string[]; origin_establishment: string | null; public_servant: boolean; employer_authorization_ref: string | null;
  employer_authorization_document: string | null; employer_authorization_until: string | null; assignments?: Assignment[];
}
export type TeacherInput = Partial<Pick<Teacher, 'department_id' | 'teacher_status' | 'grade' | 'diplomas' | 'origin_establishment' | 'public_servant' | 'employer_authorization_ref' | 'employer_authorization_until'>>;
export interface Room { id: string; school_id: string; code: string; name: string; capacity: number; active: boolean }

// --- Affectations ------------------------------------------------------------------------------------------------------------
export interface Assignment {
  id: string; school_id: string; department_id: string; program_id: string; level_id: string; group_id: string; academic_year_id: string; curriculum_id: string;
  element_id: string; element_code: string; element_title: string; person_id: string; staff_id: string | null; session_kind: SessionKind; planned_minutes: number;
  status: 'active' | 'ended' | string; overrun_approvals?: Overrun[];
}
export interface Overrun { id: string; assignment_id: string; extra_minutes: number; document_ref: string; reason: string; approved_by: string; approved_at: string }

// --- Emploi du temps ---------------------------------------------------------------------------------------------------------
export interface TimetableEntry {
  id: string; slot_id: string | null; assignment_id: string; school_id: string; group_id: string; group_ids: string[]; element_id: string; element_title: string;
  person_id: string; room_id: string | null; weekday: number; start_time: string; end_time: string; day_period: string | null; session_kind: SessionKind;
  valid_from: string; valid_to: string | null; headcount: number | null;
}
export interface TimetableChange {
  id: string; entry_id: string; group_id: string | null; change_kind: ChangeKind; occurs_on: string; new_date: string | null; new_start_time: string | null;
  new_end_time: string | null; replacement_person_id: string | null; new_room_id: string | null; reason: string; notified_count: number | null;
}
export interface Conflict { kind: 'teacher' | 'room' | 'group' | 'capacity' | string; entry_ids?: string[]; message: string }
export interface SlotInput { assignment_id: string; weekday: number; start_time: string; end_time: string; valid_from: string; valid_to?: string | null; room_id?: string | null; extra_group_ids?: string[] }
export interface SlotCheck { conflicts: Conflict[]; headcount: number; room_capacity: number | null }
export interface ChangeInput { entry_id: string; change_kind: ChangeKind; occurs_on: string; new_date?: string | null; new_start_time?: string | null; new_end_time?: string | null; replacement_person_id?: string | null; new_room_id?: string | null; reason: string }
export interface VolumeRow { planned: number; scheduled: number; held: number; logged: number; remaining: number; overrun: number }
export interface Consumption { element_id: string; element_code: string; element_title: string; group_id: string; curriculum_id: string; by_kind: Record<string, VolumeRow>; total: VolumeRow }

// --- Cahier de textes et heures ----------------------------------------------------------------------------------------------
export interface SessionLog {
  id: string; assignment_id: string; entry_id: string | null; group_id: string; element_id: string; person_id: string; session_kind: SessionKind; held_on: string;
  start_time: string; end_time: string; minutes: number; content: string | null; homework: string | null; attendance_session_id: string | null;
  teacher_signed_at: string | null; countersigned_by: string | null; countersigner_role: string | null; countersigned_at: string | null; status: LogStatus;
  validated_at: string | null;
}
export interface LogInput { assignment_id: string; entry_id?: string | null; held_on: string; start_time: string; end_time: string; content?: string | null; homework?: string | null; attendance_session_id?: string | null }
export interface HoursRow { person_id: string; month: string; logs: number; minutes: Record<string, number>; by_status: Record<string, number>; hetd_milli: number }
export interface HoursValidation { id: string; person_id: string; period_month: string; log_count: number; minutes_total: number; cm_minutes: number; td_minutes: number; tp_minutes: number; hetd_milli: number; validated_by: string; validated_at: string }
export interface ValidateResult { validations: HoursValidation[]; pending: Array<{ id: string; held_on: string; status: string }> }

// --- Examens -----------------------------------------------------------------------------------------------------------------
export interface ExamSchedule {
  id: string; school_id: string; group_id: string; academic_year_id: string; element_id: string; element_title: string; exam_session: ExamSession; exam_date: string;
  start_time: string; end_time: string; room_id: string | null; supervisors: string[]; paper_ref: string | null; status: 'planned' | 'published' | 'cancelled' | string;
  convocations_count: number | null;
}
export interface ExamInput { curriculum_id: string; element_id: string; group_id: string; exam_session: ExamSession; exam_date: string; start_time: string; end_time: string; room_id?: string | null; supervisors?: string[]; paper_ref?: string | null }

export const teaching = {
  teachers: (q: Q = {}): Promise<Teacher[]> => http.all<Teacher>(`${H}/teaching/teachers`, q),
  teacher: (personId: string): Promise<Teacher> => http.get(`${H}/teaching/teachers/${seg(personId)}`),
  createTeacher: (b: TeacherInput & { person_id: string }): Promise<Teacher> => http.post(`${H}/teaching/teachers`, b),
  updateTeacher: (personId: string, b: TeacherInput): Promise<Teacher> => http.patch(`${H}/teaching/teachers/${seg(personId)}`, b),
  rooms: (q: Q = {}): Promise<Room[]> => http.all<Room>(`${H}/teaching/rooms`, q),
  createRoom: (b: { school_id: string; code: string; name: string; capacity: number }): Promise<Room> => http.post(`${H}/teaching/rooms`, b),
  updateRoom: (id: string, b: Partial<Pick<Room, 'code' | 'name' | 'capacity' | 'active'>>): Promise<Room> => http.patch(`${H}/teaching/rooms/${seg(id)}`, b),

  assignments: (q: Q = {}): Promise<Assignment[]> => http.all<Assignment>(`${H}/teaching/assignments`, q),
  assignment: (id: string): Promise<Assignment> => http.get(`${H}/teaching/assignments/${seg(id)}`),
  assign: (b: { person_id: string; curriculum_id: string; element_id: string; group_id: string; session_kind: SessionKind; planned_minutes: number }): Promise<Assignment> =>
    http.post(`${H}/teaching/assignments`, b),
  changeAssignment: (id: string, b: { planned_minutes?: number; person_id?: string }): Promise<Assignment> => http.patch(`${H}/teaching/assignments/${seg(id)}`, b),
  endAssignment: (id: string): Promise<Assignment> => http.post(`${H}/teaching/assignments/${seg(id)}/end`),
  approveOverrun: (id: string, b: { extra_minutes: number; document_ref: string; reason: string }): Promise<Overrun> => http.post(`${H}/teaching/assignments/${seg(id)}/overrun-approvals`, b),

  timetable: (q: Q): Promise<{ entries: TimetableEntry[]; changes: TimetableChange[] }> => http.get(`${H}/timetable`, q),
  check: (b: SlotInput): Promise<SlotCheck> => http.post(`${H}/timetable/check`, b),
  createEntry: (b: SlotInput): Promise<TimetableEntry> => http.post(`${H}/timetable/entries`, b),
  deleteEntry: (id: string): Promise<unknown> => http.delete(`${H}/timetable/entries/${seg(id)}`),
  conflicts: (q: Q = {}): Promise<Conflict[]> => data(http.get(`${H}/timetable/conflicts`, q)),
  change: (b: ChangeInput): Promise<TimetableChange> => http.post(`${H}/timetable/changes`, b),
  consumption: (q: Q = {}): Promise<Consumption[]> => data(http.get(`${H}/timetable/consumption`, q)),

  logs: (q: Q = {}): Promise<SessionLog[]> => http.all<SessionLog>(`${H}/session-logs`, q),
  createLog: (b: LogInput): Promise<SessionLog> => http.post(`${H}/session-logs`, b),
  updateLog: (id: string, b: { content?: string | null; homework?: string | null }): Promise<SessionLog> => http.patch(`${H}/session-logs/${seg(id)}`, b),
  signLog: (id: string): Promise<SessionLog> => http.post(`${H}/session-logs/${seg(id)}/sign`),
  countersignLog: (id: string): Promise<SessionLog> => http.post(`${H}/session-logs/${seg(id)}/countersign`),
  hours: (q: Q & { month: string }): Promise<HoursRow[]> => data(http.get(`${H}/teaching/hours`, q)),
  validations: (q: Q = {}): Promise<HoursValidation[]> => http.all<HoursValidation>(`${H}/teaching/hours-validations`, q),
  validateMonth: (b: { person_id: string; month: string }): Promise<ValidateResult> => http.post(`${H}/teaching/hours-validations`, b),

  exams: (q: Q = {}): Promise<ExamSchedule[]> => http.all<ExamSchedule>(`${H}/exam-schedules`, q),
  createExam: (b: ExamInput): Promise<ExamSchedule> => http.post(`${H}/exam-schedules`, b),
  publishExam: (id: string): Promise<ExamSchedule> => http.post(`${H}/exam-schedules/${seg(id)}/publish`),
  cancelExam: (id: string): Promise<ExamSchedule> => http.post(`${H}/exam-schedules/${seg(id)}/cancel`),
  convocations: (id: string): Promise<{ issued: number; barred: unknown[] }> => http.post(`${H}/exam-schedules/${seg(id)}/convocations`),
  attendanceSheet: (id: string): Promise<Record<string, unknown>> => http.post(`${H}/exam-schedules/${seg(id)}/attendance-sheet`),
};

// --- Présences et discipline -------------------------------------------------------------------------------------------------
export interface AssiduityStudent { person_id: string; matricule: string | null; enrolment_id: string; sessions: number; unjustified: number; rate_percent: number; status: AssiduityStatus; absences: number; lates: number }
export interface Assiduity { element_id: string; group_id: string; max_percent: number; alert_percent: number; students: AssiduityStudent[] }
export interface AssiduityTarget { element_id: string; group_id: string; academic_year_id: string }
export interface BarredList {
  id: string; group_id: string; academic_year_id: string; element_id: string; threshold_percent: number; barred_count: number;
  entries: Array<{ person_id: string; matricule: string | null; rate_percent: number }>; transmitted_at: string; received_at: string | null;
}
export interface ReplacementExam {
  id: string; person_id: string; element_id: string; group_id: string; academic_year_id: string; absence_date: string; justification_ref: string; reason: string;
  exam_date: string | null; start_time: string | null; end_time: string | null; room_id: string | null; status: 'requested' | 'scheduled' | 'done' | 'cancelled' | string;
}
export interface Sanction { id: string; person_id: string; sanction_kind: SanctionKind; reason: string; starts_on: string | null; ends_on: string | null; council_id: string | null; status: string; document_number: string | null; decided_at: string }
export interface Council {
  id: string; person_id: string; reason: string; incident_ref: string | null; hearing_date: string; members: string[]; minutes_text: string | null;
  decision: CouncilDecision | null; decision_reason: string | null; exclusion_from: string | null; exclusion_to: string | null; status: 'convened' | 'held' | 'signed' | string;
  enrolment_excluded?: boolean; exclusion_pending?: boolean;
}

export const attendance = {
  assiduity: (q: AssiduityTarget): Promise<Assiduity> => http.get(`${H}/attendance/assiduity`, { ...q }),
  barred: (q: AssiduityTarget): Promise<Assiduity> => http.get(`${H}/attendance/barred`, { ...q }),
  alerts: (b: AssiduityTarget & { element_title: string }): Promise<{ alerted: string[]; already: string[] }> => http.post(`${H}/attendance/alerts`, b),
  barredLists: (q: Q = {}): Promise<BarredList[]> => http.all<BarredList>(`${H}/attendance/barred-lists`, q),
  transmit: (b: AssiduityTarget): Promise<BarredList> => http.post(`${H}/attendance/barred-lists`, b),
  receive: (id: string): Promise<BarredList> => http.post(`${H}/attendance/barred-lists/${seg(id)}/receive`),
  lift: (b: { person_id: string; element_id: string; group_id: string; reason: string; document_ref?: string | null }): Promise<unknown> => http.post(`${H}/attendance/barred-lifts`, b),
  replacements: (q: Q = {}): Promise<ReplacementExam[]> => http.all<ReplacementExam>(`${H}/attendance/replacement-exams`, q),
  requestReplacement: (b: AssiduityTarget & { person_id: string; absence_date: string; justification_ref: string; reason: string }): Promise<ReplacementExam> =>
    http.post(`${H}/attendance/replacement-exams`, b),
  scheduleReplacement: (id: string, b: { exam_date: string; start_time: string; end_time: string; room_id?: string | null }): Promise<ReplacementExam> =>
    http.post(`${H}/attendance/replacement-exams/${seg(id)}/schedule`, b),
  closeReplacement: (id: string, status: 'done' | 'cancelled'): Promise<ReplacementExam> => http.post(`${H}/attendance/replacement-exams/${seg(id)}/close`, { status }),

  sanctions: (q: Q = {}): Promise<Sanction[]> => http.all<Sanction>(`${H}/discipline/sanctions`, q),
  sanction: (b: { person_id: string; sanction_kind: SanctionKind; reason: string; starts_on?: string | null; ends_on?: string | null }): Promise<Sanction> => http.post(`${H}/discipline/sanctions`, b),
  notify: (id: string): Promise<Record<string, unknown>> => http.post(`${H}/discipline/sanctions/${seg(id)}/notification`),
  councils: (q: Q = {}): Promise<Council[]> => http.all<Council>(`${H}/discipline/councils`, q),
  convene: (b: { person_id: string; reason: string; incident_ref?: string | null; hearing_date: string; members: string[] }): Promise<Council> => http.post(`${H}/discipline/councils`, b),
  hearing: (id: string, b: { minutes_text: string; decision: CouncilDecision; decision_reason: string; exclusion_from?: string | null; exclusion_to?: string | null }): Promise<Council> =>
    http.post(`${H}/discipline/councils/${seg(id)}/hearing`, b),
  signCouncil: (id: string): Promise<Council> => http.post(`${H}/discipline/councils/${seg(id)}/sign`),
};

/** Minutes → « 1 h 30 » (volumes horaires). */
export function formatMinutes(min: number | null | undefined): string {
  const m = Math.max(0, Math.round(min ?? 0));
  const h = Math.floor(m / 60);
  const r = m % 60;
  return r === 0 ? `${h} h` : `${h} h ${String(r).padStart(2, '0')}`;
}

/** Lundi (AAAA-MM-JJ) de la semaine d'une date. */
export function mondayOf(iso: string): string {
  const d = new Date(`${iso}T00:00:00Z`);
  const day = d.getUTCDay() === 0 ? 7 : d.getUTCDay();
  d.setUTCDate(d.getUTCDate() - (day - 1));
  return d.toISOString().slice(0, 10);
}

export function addDays(iso: string, days: number): string {
  const d = new Date(`${iso}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
