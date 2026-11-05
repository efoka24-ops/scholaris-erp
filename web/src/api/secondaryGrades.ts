// API des notes du secondaire (lot E2-C). Contrat : openapi.d/secondary-grades.yaml. Les épreuves, les notes, l'import, le cycle et
// les corrections passent par les routes du socle Assessment (D4) ; /secondary/grades ajoute le contrôle d'affectation journalisé
// (TST-09), la grille (coefficients de Setup), le relevé DOC-08, la fraude, les épreuves communes et les notifications.
import { http, type QueryValue } from '@/api/http';
import type { GradeGrid } from '@/api/assessment';

const ROOT = '/secondary/grades';

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query ?? {})).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});

export interface AbsenceCode {
  code: string;
  label: string;
  effect: string | null;
}
export interface LetterBand {
  min: number;
  letter: string;
}
export interface GradesSettings {
  values: Record<string, unknown>;
  configured: Record<string, boolean>;
  origin_of_examples: string;
  step: number | null;
  absence_codes: AbsenceCode[];
  offline_entry: boolean;
  letter_scale: LetterBand[];
  lock_requires_second_factor: boolean;
  applied?: string[];
  kept?: string[];
}
export interface SheetSubject {
  subject_id: string;
  local_subject_id: string;
  code: string;
  label: string;
  coefficient: number;
  role: string;
  counts_in_average: boolean;
  can_enter: boolean;
}
export interface GradeSheet {
  class: { id: string; label: string; academic_year_id: string; level_code: string; stream_id: string | null; section_code: string };
  period_id: string;
  learners: { person_id: string; name: string }[];
  subjects: SheetSubject[];
  settings: GradesSettings;
}
export interface StatementRow {
  person_id: string;
  name: string;
  average: number | null;
  rank: number | null;
  subjects: { subject_id: string; code: string | null; name: string | null; average: number | null; letter: string | null }[];
}
export interface Statement {
  class: GradeSheet['class'];
  period_id: string;
  letters_enabled: boolean;
  rows: StatementRow[];
  subjects: { subject_id: string; count: number; class_avg: number; min: number; max: number }[];
}
export interface FraudReport {
  id: string;
  class_id: string;
  subject_id: string;
  period_id: string | null;
  assessment_id: string;
  person_id: string;
  reported_by: string;
  note: string | null;
  status: 'open' | 'handled';
  handled_by: string | null;
  handled_at: string | null;
  outcome: string | null;
  created_at: string;
}
export interface CommonTest {
  id: string;
  academic_year_id: string;
  level_code: string;
  subject_id: string;
  period_id: string;
  title: string;
  held_on: string | null;
  organised_by: string;
  assessment_ids: string[];
  status: 'planned' | 'held' | 'closed';
}
export interface Correction {
  id: string;
  grade_id: string;
  assessment_id: string;
  person_id: string;
  new_score: number | null;
  new_absence: string | null;
  reason: string;
  status: string;
  approval_step: number;
  steps_total: number;
  current_step: string | null;
}
export interface Period {
  id: string;
  kind?: string;
  label?: string;
  name?: string;
  ordinal?: number;
  parent_id?: string | null;
}

/** Étapes du cycle du secondaire (préréglage d'exemple), dans l'ordre d'affichage. */
export const STAGES = ['draft', 'submitted', 'validated', 'locked', 'published', 'not_evaluated'] as const;
export type Stage = (typeof STAGES)[number];

/** Étape suivante proposée par l'écran (le serveur reste juge du cycle et des droits). */
export function nextStage(status: string): Stage | null {
  if (status === 'draft') return 'submitted';
  if (status === 'submitted') return 'validated';
  if (status === 'validated') return 'locked';
  if (status === 'locked') return 'published';
  return null;
}

/** Note au pas de l'établissement (quart de point par exemple) : contrôle identique au serveur. */
export function onStep(value: number, step: number | null): boolean {
  if (step === null || step <= 0) return Math.abs(value * 100 - Math.round(value * 100)) < 1e-6;
  return Math.abs(value / step - Math.round(value / step)) < 1e-6;
}

/** Lettre [EN] de la première tranche atteinte (table vide : aucune lettre). */
export function letterFor(score: number | null, bands: LetterBand[]): string | null {
  if (score === null) return null;
  const sorted = [...bands].sort((a, b) => b.min - a.min);
  return sorted.find((b) => score >= b.min)?.letter ?? null;
}

export const secondaryGrades = {
  settings: (): Promise<GradesSettings> => get('/settings'),
  preset: (keys?: string[]): Promise<GradesSettings> => post('/settings/preset', keys ? { keys } : {}),
  sheet: (classId: string, periodId: string): Promise<GradeSheet> => get('/sheet', { class_id: classId, period_id: periodId }),
  entryCheck: (classId: string, subjectId: string): Promise<{ allowed: boolean; role: string; offline_entry: boolean }> => post('/entry-checks', { class_id: classId, subject_id: subjectId }),
  statement: (classId: string, periodId: string, subjectId?: string): Promise<Statement> => get('/statement', { class_id: classId, period_id: periodId, subject_id: subjectId }),
  fraudReports: (status?: string): Promise<FraudReport[]> => all('/fraud-reports', { 'filter[status]': status }),
  reportFraud: (body: { class_id: string; subject_id: string; assessment_id: string; person_id: string; period_id?: string | null; note?: string | null }): Promise<FraudReport> => post('/fraud-reports', body),
  handleFraud: (id: string, outcome: string): Promise<FraudReport> => post(`/fraud-reports/${encodeURIComponent(id)}/handle`, { outcome }),
  commonTests: (query: Record<string, QueryValue> = {}): Promise<CommonTest[]> => all('/common-tests', query),
  createCommonTest: (body: { academic_year_id: string; level_code: string; subject_id: string; period_id: string; title: string; held_on?: string | null }): Promise<CommonTest> => post('/common-tests', body),
  updateCommonTest: (id: string, body: Partial<Pick<CommonTest, 'title' | 'held_on' | 'assessment_ids' | 'status'>>): Promise<CommonTest> => patch(`/common-tests/${encodeURIComponent(id)}`, body),
  published: (body: { class_id: string; assessment_id: string; subject_label: string; label: string }): Promise<{ notified: number; recipients: number }> => post('/publications', body),
  lateReminders: (body: { class_id: string; period_id: string; period_label?: string; due_on: string; subject_ids: string[] }): Promise<{ notified: number; without_teacher: string[] }> => post('/late-reminders', body),

  // Socle Assessment (D4).
  periods: (): Promise<Period[]> => http.all<Period>('/periods', {}, {}, 5),
  grid: async (classId: string, periodId: string): Promise<GradeGrid> => (await http.get<{ data: GradeGrid }>('/grade-sheets', { structure_id: classId, period_id: periodId })).data,
  createAssessment: async (body: { subject_id: string; structure_id: string; period_id: string; academic_year_id: string; kind: string; title: string; weight: number; max_score: number }): Promise<{ id: string }> =>
    (await http.post<{ data: { id: string } }>('/assessments', body)).data,
  transition: async (assessmentId: string, to: string): Promise<{ id: string; status: string }> => (await http.post<{ data: { id: string; status: string } }>(`/assessments/${encodeURIComponent(assessmentId)}/transition`, { to })).data,
  enter: async (body: { assessment_id: string; person_id: string; value?: number | null; absence?: string | null; expected_version?: number }): Promise<unknown> => (await http.post<{ data: unknown }>('/grades', body)).data,
  /** Import par FICHIER JOINT (multipart, champ « file ») : l'hébergeur refuse un corps text/csv. */
  importFile: async (assessmentId: string, file: Blob, name: string): Promise<{ created: number; updated: number; unchanged: number }> => {
    const form = new FormData();
    form.append('file', file, name);
    return (await http.post<{ data: { created: number; updated: number; unchanged: number } }>('/grades/import', undefined, { query: { assessment_id: assessmentId }, form })).data;
  },
  template: (assessmentId: string): Promise<string> => http.get<string>('/grades/import-template', { assessment_id: assessmentId }, { expect: 'text', headers: { Accept: 'text/csv' } }),
  corrections: (status?: string): Promise<Correction[]> => http.all('/grade-corrections', { 'filter[status]': status }),
  requestCorrection: async (gradeId: string, value: number | null, reason: string): Promise<unknown> => (await http.post<{ data: unknown }>('/grade-corrections', { grade_id: gradeId, value, reason })).data,
  approveCorrection: async (id: string): Promise<unknown> => (await http.post<{ data: unknown }>(`/grade-corrections/${encodeURIComponent(id)}/approve`, {})).data,
  rejectCorrection: async (id: string): Promise<unknown> => (await http.post<{ data: unknown }>(`/grade-corrections/${encodeURIComponent(id)}/reject`, {})).data,
};
