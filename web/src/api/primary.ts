// API de l'application Primaire privée (préfixe /api/v1/primary). Contrats : specs/001-socle-plateforme/contracts/openapi.d/primary-*.yaml.
// L'en-tête X-Establishment-Id est posé par le client HTTP (établissement actif). Aucune règle d'évaluation n'est codée ici.
import { http, type QueryValue } from '@/api/http';

const ROOT = '/primary';

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query)).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const put = async <T>(path: string, body?: unknown): Promise<T> => (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});

export type EvaluationMode = 'numeric' | 'competency' | 'both';

export interface LevelProfile {
  id: string;
  level_structure_id: string;
  section_org_unit_id: string | null;
  cycle_code: string | null;
  sort_order: number;
  is_final_level: boolean;
  is_exam_level: boolean;
  language: 'fr' | 'en';
}
export interface Domain {
  id: string;
  academic_year_id: string;
  code: string;
  label_fr: string;
  label_en: string | null;
  sort_order: number;
  active: boolean;
}
export interface LevelSubject {
  id: string;
  academic_year_id: string;
  level_structure_id: string;
  subject_id: string;
  subject_code: string;
  subject_name: string;
  domain_id: string;
  evaluation_mode: EvaluationMode;
  sort_order: number;
  active: boolean;
}
export interface Competency {
  id: string;
  academic_year_id: string;
  level_structure_id: string;
  domain_id: string;
  subject_id: string | null;
  code: string;
  label_fr: string;
  label_en: string | null;
  sort_order: number;
  active: boolean;
}
export interface ReferentialTemplate {
  code: string;
  label: string;
  origin: 'example' | 'official' | 'custom';
  language: string;
  version: number;
  levels: number;
  platform: boolean;
}
export interface ApplyResult {
  academic_year_id: string;
  template: string;
  origin: string;
  created: Record<string, number>;
  existing: Record<string, number>;
}
export interface PromotionCheck {
  ok: boolean;
  errors: { code: string; class_structure_id: string; target_structure_id: string | null }[];
  classes: number;
}
export interface LearnerProfile {
  person_id: string;
  enrollment_id: string;
  academic_year_id: string;
  status: string;
  class_structure_id: string | null;
  level_structure_id: string | null;
  internal_number: string | null;
  repeater: boolean;
  is_final_level: boolean;
  is_exam_level: boolean;
  language: string | null;
}
export interface EnrolmentInvoice {
  id: string;
  enrollment_id: string | null;
  status: string;
  gross_xaf: number;
  discount_xaf: number;
  net_xaf: number;
  paid_xaf: number;
  balance_xaf: number;
  due_at_enrolment_xaf: number;
}
export interface IssuedDocument {
  id: string;
  number: string;
  kind: string;
  type: string;
  status: string;
  verification_token: string;
  enrollment_id: string;
  enrollment_status?: string;
}
export interface SetupStatus {
  presets: { key: string; recommended: unknown; current: unknown; ok: boolean }[];
  ready: boolean;
  day: string;
  absent_today: number;
  applied?: string[];
}

export interface Correction {
  id: string;
  mark_id: string;
  class_structure_id: string;
  new_code: string;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  requested_by: string;
  decided_by: string | null;
  decided_at: string | null;
}
export interface ConductSheet {
  class: { id: string; name: string };
  period_id: string;
  scale: { code: string; label_fr: string; label_en: string; sort_order: number }[];
  learners: { person_id: string; name: string }[];
  marks: { person_id: string; scale_code: string; remark: string | null }[];
}

export type DocumentKind = 'certificate' | 'attendance' | 'school_card' | 'info_sheet';
export const DOCUMENT_KINDS: readonly DocumentKind[] = ['certificate', 'attendance', 'school_card', 'info_sheet'];

export const primary = {
  setup: (): Promise<SetupStatus> => get('/setup'),
  applySetup: (): Promise<SetupStatus> => post('/setup/apply'),

  profiles: (): Promise<LevelProfile[]> => all('/level-profiles', { sort: 'sort_order' }),
  createProfile: (body: { level_structure_id?: string; name?: string; sort_order?: number; cycle_code?: string | null; is_final_level?: boolean; is_exam_level?: boolean; language?: 'fr' | 'en' }): Promise<LevelProfile> => post('/level-profiles', body),
  updateProfile: (id: string, body: Partial<Pick<LevelProfile, 'sort_order' | 'cycle_code' | 'is_final_level' | 'is_exam_level' | 'language'>>): Promise<LevelProfile> => patch(`/level-profiles/${id}`, body),
  promotionCheck: (): Promise<PromotionCheck> => get('/promotion-map/check'),

  domains: (yearId: string): Promise<Domain[]> => all('/domains', { 'filter[academic_year_id]': yearId, sort: 'sort_order' }),
  createDomain: (body: { academic_year_id: string; code: string; label_fr: string; label_en?: string | null; sort_order?: number }): Promise<Domain> => post('/domains', body),
  updateDomain: (id: string, body: Partial<Pick<Domain, 'label_fr' | 'label_en' | 'sort_order' | 'active'>>): Promise<Domain> => patch(`/domains/${id}`, body),
  levelSubjects: (yearId: string, levelId?: string): Promise<LevelSubject[]> => all('/level-subjects', { 'filter[academic_year_id]': yearId, 'filter[level_structure_id]': levelId, sort: 'sort_order' }),
  createLevelSubject: (body: { academic_year_id: string; level_structure_id: string; domain_id: string; subject_code: string; subject_name: string; evaluation_mode?: EvaluationMode; sort_order?: number }): Promise<LevelSubject> => post('/level-subjects', body),
  updateLevelSubject: (id: string, body: Partial<Pick<LevelSubject, 'domain_id' | 'evaluation_mode' | 'sort_order' | 'active'>>): Promise<LevelSubject> => patch(`/level-subjects/${id}`, body),
  competencies: (yearId: string, levelId?: string): Promise<Competency[]> => all('/competencies', { 'filter[academic_year_id]': yearId, 'filter[level_structure_id]': levelId, sort: 'sort_order' }),
  createCompetency: (body: { academic_year_id: string; level_structure_id: string; domain_id: string; subject_id?: string; code: string; label_fr: string; label_en?: string | null; sort_order?: number }): Promise<Competency> => post('/competencies', body),
  updateCompetency: (id: string, body: Partial<Pick<Competency, 'label_fr' | 'label_en' | 'sort_order' | 'active' | 'domain_id'>>): Promise<Competency> => patch(`/competencies/${id}`, body),
  templates: async (): Promise<ReferentialTemplate[]> => (await get<{ items: ReferentialTemplate[] }>('/referentials')).items,
  applyTemplate: (code: string, yearId: string): Promise<ApplyResult> => post(`/referentials/${encodeURIComponent(code)}/apply`, { academic_year_id: yearId }),
  copyYear: (from: string, to: string): Promise<{ created: Record<string, number>; existing: Record<string, number> }> => post('/referentials/copy-year', { from_year_id: from, to_year_id: to }),
  exportUrl: (kind: 'levels' | 'domains' | 'subjects' | 'competencies', yearId: string, format: 'csv' | 'xlsx' = 'csv'): string => `/api/v1${ROOT}/referential/export?kind=${kind}&academic_year_id=${encodeURIComponent(yearId)}&format=${format}`,

  lockSheet: (classId: string, periodId: string): Promise<{ status: string }> => post(`/classes/${classId}/competency-sheet/lock?period_id=${encodeURIComponent(periodId)}`),
  publishSheet: (classId: string, periodId: string): Promise<{ status: string }> => post(`/classes/${classId}/competency-sheet/publish?period_id=${encodeURIComponent(periodId)}`),
  requestCorrection: (markId: string, newCode: string, reason: string): Promise<{ id: string; status: string }> => post(`/competency-marks/${markId}/corrections`, { new_code: newCode, reason }),
  corrections: (status?: string): Promise<{ items: Correction[] }> => get('/competency-corrections', { status }),
  decideCorrection: (id: string, approve: boolean): Promise<Correction> => post(`/competency-corrections/${id}/${approve ? 'approve' : 'reject'}`),
  conduct: (classId: string, periodId: string): Promise<ConductSheet> => get(`/classes/${classId}/conduct`, { period_id: periodId }),
  saveConduct: (classId: string, periodId: string, entries: { person_id: string; scale_code: string; remark?: string | null }[]): Promise<{ saved: number }> => put(`/classes/${classId}/conduct?period_id=${encodeURIComponent(periodId)}`, { entries }),

  learnerProfile: (personId: string, yearId?: string): Promise<LearnerProfile> => get(`/learners/${personId}/profile`, { academic_year_id: yearId }),
  invoiceEnrolment: (enrollmentId: string): Promise<EnrolmentInvoice> => post(`/enrolments/${enrollmentId}/invoice`),
  issueDocument: (kind: DocumentKind, enrollmentId: string, locale?: 'fr' | 'en'): Promise<IssuedDocument> => post('/documents', { kind, enrollment_id: enrollmentId, locale }),
  transfer: (enrollmentId: string, body: { destination: string; reason: string; effective_on: string }): Promise<IssuedDocument> => post(`/enrolments/${enrollmentId}/transfer`, body),
};

// put reste exposé pour les écrans de saisie par lot (feuille de compétences, conduite).
export const primaryRaw = { get, post, put, patch, all };
