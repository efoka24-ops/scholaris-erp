// API du paramétrage du secondaire (préfixe /api/v1/secondary/setup). Contrat : openapi.d/secondary-setup.yaml. Rien n'est codé ici :
// niveaux, matières, coefficients et volumes sont saisis par l'établissement (valeurs d'exemple marquées « à confirmer »).
import { http, type QueryValue } from '@/api/http';
import { listYears, type AcademicYear } from '@/api/admin';

const ROOT = '/secondary/setup';

const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const put = async <T>(path: string, body?: unknown): Promise<T> => (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});

export type Cycle = 'first' | 'second';
export type Teaching = 'general' | 'technical';
export type Section = 'fr' | 'en';
export type SubjectRole = 'official' | 'project' | 'practical' | 'internship';
export type Origin = 'example' | 'official' | 'custom';
export type RoomKind = 'classroom' | 'lab' | 'workshop';

export const SUBJECT_ROLES: SubjectRole[] = ['official', 'project', 'practical', 'internship'];
export const ORIGINS: Origin[] = ['example', 'custom', 'official'];
export const ROOM_KINDS: RoomKind[] = ['classroom', 'lab', 'workshop'];

export interface Level {
  id: string;
  code: string;
  label: string;
  cycle_code: Cycle;
  section_code: Section;
  teaching_kind: Teaching;
  sort_order: number;
  exam_code: string | null;
  structure_id: string;
  origin: 'example' | 'custom';
  confirmation: string;
  active: boolean;
}
export interface OpenedStream {
  id: string;
  stream_ref_id: string;
  stream_kind: string;
  teaching_kind: Teaching;
  code: string;
  label: string;
  cycle_code: Cycle;
  section_code: Section;
  authorization_id: string | null;
  active: boolean;
}
export interface CatalogueStream {
  id: string;
  code: string;
  label: string;
  cycle_code: Cycle;
  teaching_kind: Teaching;
  section_code: Section;
  hidden?: boolean;
}
export interface SchoolClass {
  id: string;
  structure_id: string;
  academic_year_id: string;
  label: string;
  section_code: Section;
  teaching_kind: Teaching;
  cycle_code: Cycle;
  level_id: string;
  level_code: string;
  stream_id: string | null;
  exam_code: string | null;
  capacity: number | null;
  head_teacher_person_id: string | null;
}
export interface Subject {
  id: string;
  code: string;
  label: string;
  label_en: string | null;
  subject_id: string;
  active: boolean;
}
export interface Coefficient {
  id: string;
  academic_year_id: string;
  subject_ref: string;
  subject_code: string;
  subject_label: string;
  level_code: string;
  stream_id: string | null;
  coefficient: number;
  weekly_hours: number;
  subject_role: SubjectRole;
  counts_in_average: boolean;
  origin: Origin;
  confirmation: string;
  locked: boolean;
  source_ref: string | null;
  version_no: number;
  valid_from: string;
  change_reason: string;
  changed_by: string;
  created_at: string;
  superseded_at: string | null;
}
export interface CoefficientInput {
  academic_year_id: string;
  level_code: string;
  stream_id?: string | null;
  subject_ref: string;
  coefficient: number;
  weekly_hours: number;
  subject_role: SubjectRole;
  origin: Origin;
  source_ref?: string | null;
  valid_from: string;
  reason: string;
}
export interface Freeze {
  id: string;
  academic_year_id: string;
  reason: string;
  frozen_by: string;
  frozen_at: string;
}
export interface Room {
  id: string;
  room_id: string;
  label: string;
  room_kind: RoomKind;
  capacity: number | null;
  specialty_stream_id: string | null;
  equipment: string | null;
  safety_notes: string | null;
}
export interface Rule {
  key: string;
  shape: 'list' | 'object' | 'scalar';
  value: unknown;
  source: 'establishment' | 'setting' | 'unset';
  confirmation: string;
  version_no: number | null;
  valid_from: string | null;
  change_reason: string | null;
  changed_by: string | null;
  changed_at: string | null;
}

export const secondarySetup = {
  years: (): Promise<AcademicYear[]> => listYears(),
  levels: (): Promise<Level[]> => all<Level>('/levels'),
  createLevel: (input: { code: string; label: string; cycle_code: Cycle; section_code: Section; teaching_kind: Teaching; sort_order?: number }): Promise<Level> => post('/levels', input),
  loadExampleLevels: (): Promise<Level[]> => post('/levels/examples'),
  updateLevel: (id: string, changes: Partial<Pick<Level, 'label' | 'sort_order' | 'active'>> & { exam_code?: string | null }): Promise<Level> => patch(`/levels/${encodeURIComponent(id)}`, changes),
  streams: (): Promise<OpenedStream[]> => all<OpenedStream>('/streams'),
  catalogue: (): Promise<CatalogueStream[]> => http.all<CatalogueStream>('/secondary/catalogue/streams', {}),
  openStream: (streamRefId: string): Promise<OpenedStream> => post('/streams', { stream_ref_id: streamRefId }),
  updateStream: (id: string, changes: { active?: boolean; authorization_id?: string | null }): Promise<OpenedStream> => patch(`/streams/${encodeURIComponent(id)}`, changes),
  classes: (academicYearId: string): Promise<SchoolClass[]> => all<SchoolClass>('/classes', { academic_year_id: academicYearId }),
  createClass: (input: { label: string; level_id: string; academic_year_id: string; stream_id?: string | null; capacity?: number | null }): Promise<SchoolClass> => post('/classes', input),
  updateClass: (id: string, changes: { label?: string; capacity?: number | null }): Promise<SchoolClass> => patch(`/classes/${encodeURIComponent(id)}`, changes),
  subjects: (): Promise<Subject[]> => all<Subject>('/subjects'),
  createSubject: (input: { code: string; label: string; label_en?: string | null }): Promise<Subject> => post('/subjects', input),
  updateSubject: (id: string, changes: { label?: string; label_en?: string | null; active?: boolean }): Promise<Subject> => patch(`/subjects/${encodeURIComponent(id)}`, changes),
  coefficients: (academicYearId: string, history = false): Promise<Coefficient[]> => all<Coefficient>('/coefficients', { academic_year_id: academicYearId, history: history ? '1' : '0' }),
  setCoefficient: (input: CoefficientInput): Promise<Coefficient> => post('/coefficients', input),
  freezes: (): Promise<Freeze[]> => all<Freeze>('/coefficients/freezes'),
  freeze: (academicYearId: string, reason: string): Promise<Freeze> => post('/coefficients/freeze', { academic_year_id: academicYearId, reason }),
  copy: (from: string, to: string, validFrom: string, reason: string): Promise<{ copied: number; skipped: number }> =>
    post('/coefficients/copy', { from_academic_year_id: from, to_academic_year_id: to, valid_from: validFrom, reason }),
  rooms: (): Promise<Room[]> => all<Room>('/rooms'),
  saveRoom: (input: Omit<Room, 'id'>): Promise<Room> => post('/rooms', input),
  rules: async (): Promise<Record<string, Rule>> => (await http.get<{ data: Record<string, Rule> }>(`${ROOT}/rules`)).data,
  setRule: (key: string, value: unknown, validFrom: string, reason: string): Promise<Rule> => put(`/rules/${encodeURIComponent(key)}`, { value, valid_from: validFrom, reason }),
};

/** Lignes de coefficients regroupées par niveau (puis série), pour l'affichage sur téléphone. */
export function groupByLevel(lines: Coefficient[]): { key: string; level: string; streamId: string | null; lines: Coefficient[] }[] {
  const groups = new Map<string, { key: string; level: string; streamId: string | null; lines: Coefficient[] }>();
  for (const line of lines) {
    const key = `${line.level_code}|${line.stream_id ?? ''}`;
    const group = groups.get(key) ?? { key, level: line.level_code, streamId: line.stream_id, lines: [] };
    group.lines.push(line);
    groups.set(key, group);
  }
  return [...groups.values()];
}

/** Somme des coefficients qui comptent dans la moyenne officielle (les matières de projet en sont exclues, RG-ORG-4). */
export function officialTotal(lines: Coefficient[]): number {
  return Math.round(lines.filter((l) => l.counts_in_average).reduce((sum, l) => sum + l.coefficient, 0) * 100) / 100;
}

/** Lecture d'une valeur de règle saisie en JSON ; null si le texte n'est pas du JSON valide. */
export function parseRuleValue(text: string): { ok: true; value: unknown } | { ok: false } {
  try {
    return { ok: true, value: JSON.parse(text) as unknown };
  } catch {
    return { ok: false };
  }
}
