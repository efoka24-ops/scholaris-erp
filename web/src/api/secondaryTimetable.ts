// API de l'emploi du temps et du cahier de textes du secondaire (lot E2-A, préfixe /api/v1/secondary). Contrat :
// openapi.d/secondary-timetable.yaml. Volumes horaires, plages et seuils viennent de l'établissement : rien n'est codé ici.
// Les séances du cahier de textes saisies hors ligne gardent leur client_uuid : un renvoi est idempotent (rejeu 200).
import { http, type QueryValue } from '@/api/http';

const ROOT = '/secondary';

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query)).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const put = async <T>(path: string, body?: unknown): Promise<T> => (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});

export type TeacherKind = 'permanent' | 'vacataire';
export type RoomKind = 'classroom' | 'lab' | 'workshop';
export type GroupCode = 'G1' | 'G2';
export type AvailabilityKind = 'available' | 'unavailable';
export type ChangeKind = 'replacement' | 'permutation';
export type DocView = 'class' | 'teacher' | 'room';

export const TEACHER_KINDS: TeacherKind[] = ['permanent', 'vacataire'];
export const ROOM_KINDS: RoomKind[] = ['classroom', 'lab', 'workshop'];
export const GROUPS: GroupCode[] = ['G1', 'G2'];
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

export interface Assignment {
  id: string;
  academic_year_id: string;
  class_id: string;
  group_code: GroupCode | null;
  subject_ref: string;
  subject_code: string;
  subject_label: string;
  teacher_person_id: string;
  teacher_kind: TeacherKind;
  room_kind: RoomKind;
  room_id: string | null;
  status: 'active' | 'ended';
}
export interface TeacherOption {
  person_id: string;
  name: string;
  grade: string | null;
  teaches: boolean;
  assigned: boolean;
}
export interface Band {
  id?: string;
  weekday: number;
  start_time: string;
  end_time: string;
  label?: string | null;
}
export interface Window {
  weekday: number;
  start_time: string;
  end_time: string;
  availability_kind: AvailabilityKind;
}
export interface Entry {
  id: string;
  academic_year_id: string;
  slot_id: string;
  assignment_id: string;
  class_id: string;
  group_code: GroupCode | null;
  subject_ref: string;
  subject_label: string;
  teacher_person_id: string;
  teacher_name?: string;
  class_label?: string;
  room_id: string | null;
  weekday: number;
  start_time: string;
  end_time: string;
  valid_from: string;
  valid_to: string | null;
  origin: string;
}
export interface Conflict {
  kind: string;
  message: string;
}
export interface Placement {
  assignment_id: string;
  class_id: string;
  group_code: GroupCode | null;
  subject_label: string;
  teacher_person_id: string;
  room_id: string | null;
  weekday: number;
  start_time: string;
  end_time: string;
}
export interface Unplaced {
  assignment_id: string;
  class_id: string;
  subject_label: string;
  missing_bands: number;
  reason: 'no_free_band' | 'no_weekly_hours';
}
export interface Generation {
  run_id: string;
  applied: boolean;
  classes: number;
  placed: Placement[];
  unplaced: Unplaced[];
  removed: number;
  max_same_subject_per_day: number;
}
export interface Change {
  id: string;
  change_kind: ChangeKind;
  entry_id: string;
  other_entry_id: string | null;
  class_id: string;
  from_date: string;
  to_date: string | null;
  replacement_person_id: string | null;
  reason: string;
  notified_count: number;
}
export interface TimetableDoc {
  doc: 'DOC-07';
  title: string;
  view: DocView;
  holder_label: string;
  on: string;
  days: { weekday: number; label: string; entries: Entry[] }[];
  entries_count: number;
  notice: string;
}

export interface Lesson {
  id: string;
  sort_order: number;
  title: string;
  planned_hours: number;
  planned_week: number;
}
export interface LessonDraft {
  title: string;
  planned_hours: number;
  planned_week: number;
}
export interface Progression {
  id: string;
  academic_year_id: string;
  subject_ref: string;
  subject_label: string;
  level_code: string;
  stream_id: string | null;
  total_hours: number;
  status: 'draft' | 'approved';
  lessons?: Lesson[];
}
export interface LessonLog {
  id: string;
  class_id: string;
  subject_ref: string;
  subject_label: string;
  teacher_person_id: string;
  teacher_name?: string;
  session_date: string;
  lesson_id: string | null;
  content: string;
  homework: string | null;
  hours: number;
  signed_at: string;
  client_uuid: string;
  replayed?: boolean;
}
export interface LessonLogDraft {
  client_uuid: string;
  class_id: string;
  subject_ref: string;
  session_date: string;
  hours: number;
  content: string;
  homework?: string | null;
  lesson_id?: string | null;
  entry_id?: string | null;
}
export interface CoverageRow {
  assignment_id: string;
  group_code: GroupCode | null;
  subject_ref: string;
  subject_label: string;
  teacher_person_id: string;
  planned_hours: number | null;
  done_hours: number;
  rate: number | null;
  rate_percent: number | null;
  expected_hours_to_date: number | null;
  lessons_total: number;
  lessons_done: number;
  last_log_on: string | null;
  visas: number;
}
export interface ClassCoverage {
  class_id: string;
  class_label: string;
  week: number | null;
  lines: CoverageRow[];
}
export interface CoverageReport {
  doc: 'DOC-26';
  title: string;
  classes: ClassCoverage[];
  planned_hours: number;
  done_hours: number;
  rate: number | null;
}
export interface Visa {
  id: string;
  class_id: string;
  subject_ref: string;
  visa_role: 'censor' | 'head_of_dept';
  visa_on: string;
  remark: string | null;
}
export interface Logbook {
  doc: 'DOC-25';
  title: string;
  class_label: string;
  entries: LessonLog[];
  visas: Visa[];
}
export interface Reminders {
  reminder_days: number;
  late: { assignment_id: string; class_label: string; subject_label: string; teacher_person_id: string; since: string; days: number }[];
  notified: number;
}

/** Taux de couverture affiché (pourcentage à une décimale), ou « — » sans progression. */
export function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${(Math.round(rate * 1000) / 10).toLocaleString('fr-FR')} %`;
}

/** Créneaux groupés par jour (lundi d'abord), triés par heure. */
export function byDay(entries: Entry[]): { weekday: number; entries: Entry[] }[] {
  const days = new Map<number, Entry[]>();
  for (const e of [...entries].sort((a, b) => a.weekday - b.weekday || a.start_time.localeCompare(b.start_time))) {
    days.set(e.weekday, [...(days.get(e.weekday) ?? []), e]);
  }
  return [...days.entries()].map(([weekday, list]) => ({ weekday, entries: list }));
}

/** Identifiant de séance hors ligne (conservé tant que l'envoi n'a pas abouti). */
export function newClientUuid(): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return random.replace(/[^A-Za-z0-9-]/g, '').slice(0, 40);
}

export const secondaryTimetable = {
  view: (query: { academic_year_id?: string; class_id?: string; teacher_person_id?: string; room_id?: string; on?: string }) => get<{ entries: Entry[] }>('/timetable', query),
  assignments: (year: string) => all<Assignment>('/timetable/assignments', { 'filter[academic_year_id]': year, 'filter[status]': 'active' }),
  /** C-118 : enseignants proposés à l'affectation, par nom (personnel de l'établissement et enseignants déjà affectés). */
  teachers: () => get<TeacherOption[]>('/timetable/teachers'),
  createAssignment: (body: { academic_year_id: string; class_id: string; subject_ref: string; teacher_person_id: string; teacher_kind: TeacherKind; room_kind: RoomKind; group_code?: GroupCode; room_id?: string | null }) =>
    post<Assignment>('/timetable/assignments', body),
  endAssignment: (id: string) => patch<Assignment>(`/timetable/assignments/${id}`, { status: 'ended' }),
  bands: (year: string) => get<{ bands: Band[] }>('/timetable/bands', { academic_year_id: year }),
  setBands: (year: string, bands: Band[]) => put<{ bands: Band[] }>('/timetable/bands', { academic_year_id: year, bands }),
  availabilities: (year: string, teacher: string) => get<{ windows: Window[] }>('/timetable/availabilities', { academic_year_id: year, teacher_person_id: teacher }),
  setAvailabilities: (year: string, teacher: string, windows: Window[]) => put<{ windows: Window[] }>('/timetable/availabilities', { academic_year_id: year, teacher_person_id: teacher, windows }),
  setClassRoom: (year: string, classId: string, roomId: string) => put<unknown>('/timetable/class-rooms', { academic_year_id: year, class_id: classId, room_id: roomId }),
  createEntry: (body: { assignment_id: string; weekday: number; start_time: string; end_time: string; valid_from: string; valid_to?: string | null; room_id?: string | null }) => post<Entry>('/timetable/entries', body),
  checkEntry: (body: { assignment_id: string; weekday: number; start_time: string; end_time: string; valid_from: string }) => post<{ conflicts: Conflict[] }>('/timetable/entries/check', body),
  removeEntry: async (id: string): Promise<void> => {
    await http.delete(`${ROOT}/timetable/entries/${id}`);
  },
  conflicts: (year: string) => get<{ count: number; conflicts: Conflict[] }>('/timetable/conflicts', { academic_year_id: year }),
  generate: (body: { academic_year_id: string; valid_from: string; valid_to?: string | null; class_ids?: string[]; apply: boolean; reason?: string }) => post<Generation>('/timetable/generate', body),
  changes: (year: string) => all<Change>('/timetable/changes', { 'filter[academic_year_id]': year }),
  change: (body: { change_kind: ChangeKind; entry_id: string; from_date: string; to_date?: string | null; reason: string; replacement_person_id?: string; other_entry_id?: string }) =>
    post<Change>('/timetable/changes', body),
  document: (view: DocView, id: string, on: string) => get<TimetableDoc>('/timetable/document', { view, id, on }),
};

export const secondarySyllabus = {
  log: (draft: LessonLogDraft) => post<LessonLog>('/lesson-logs', draft),
  logs: (year: string, classId?: string) => all<LessonLog>('/syllabus/lesson-logs', { 'filter[academic_year_id]': year, 'filter[structure_id]': classId }),
  progressions: (year: string) => all<Progression>('/syllabus/progressions', { 'filter[academic_year_id]': year }),
  progression: (id: string) => get<Progression>(`/syllabus/progressions/${id}`),
  createProgression: (body: { academic_year_id: string; subject_ref: string; level_code: string; stream_id?: string | null; lessons: LessonDraft[] }) => post<Progression>('/syllabus/progressions', body),
  replaceLessons: (id: string, lessons: LessonDraft[]) => put<Progression>(`/syllabus/progressions/${id}/lessons`, { lessons }),
  approve: (id: string) => post<Progression>(`/syllabus/progressions/${id}/approve`),
  coverage: (year: string, classId: string, yearStart?: string) => get<ClassCoverage>('/syllabus/coverage', { academic_year_id: year, class_id: classId, year_start: yearStart || undefined }),
  report: (year: string, yearStart?: string) => get<CoverageReport>('/syllabus/coverage/report', { academic_year_id: year, year_start: yearStart || undefined }),
  logbook: (year: string, classId: string) => get<Logbook>('/syllabus/logbook', { academic_year_id: year, class_id: classId }),
  visa: (body: { academic_year_id: string; class_id: string; subject_ref: string; remark?: string }) => post<Visa>('/syllabus/visas', body),
  remind: (year: string) => post<Reminders>('/syllabus/reminders', { academic_year_id: year }),
};
