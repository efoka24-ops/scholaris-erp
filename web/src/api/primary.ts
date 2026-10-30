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

export interface SheetFollowUp {
  current: { period_id: string; expected: number; entered: number; status: string; deadline: string | null; late: boolean } | null;
  overdue_periods: string[];
  late: boolean;
}
export interface DashboardClass {
  class_id: string;
  name: string;
  level_id: string;
  capacity: number | null;
  learners: number;
  over_capacity: boolean;
  absent_morning: number;
  absent_afternoon: number;
  late_payers: number;
  late_balance_xaf: number;
  sheet: SheetFollowUp;
}
export interface Dashboard {
  date: string;
  period: { id: string; kind: string; starts_on: string; ends_on: string; academic_year_id: string } | null;
  perimeter: 'establishment' | 'classes';
  totals: { classes: number; learners: number; absent_morning: number; absent_afternoon: number; late_payers: number; late_balance_xaf: number; sheets_late: number };
  classes: DashboardClass[];
}
export interface ClosureCheckItem {
  key: string;
  label: string;
  status: 'ok' | 'blocking' | 'not_evaluable';
  blocking_count: number;
  detail: string | null;
}
export interface ClosureReport {
  academic_year_id: string;
  ready: boolean;
  blocking_count: number;
  checks: ClosureCheckItem[];
  sheets_to_lock: { class_id: string; name: string; periods: string[] }[];
  promotion_map_errors: { code: string; class_structure_id: string; target_structure_id: string | null }[];
  councils_missing?: { class_id: string; name: string; validated: number; expected: number; open: number }[];
  report_cards_missing?: { class_id: string; period_label: string; missing: number }[];
}

// --- P-002 : conseil des maîtres, bulletins, remédiation, examen, tutelle, clôture, portail parent ---
export type Decision = 'admitted' | 'adjourned' | 'cycle_end';
export interface ScaleLevel {
  code: string;
  label_fr: string;
  label_en: string;
  sort_order: number;
}
export interface CouncilLine {
  id: string;
  person_id: string;
  name: string;
  average: number | null;
  rank: number | null;
  class_size: number | null;
  subjects: { subject_id: string; code: string | null; name: string | null; average: number | null }[];
  annual_average: number | null;
  annual_rank: number | null;
  terms: { period_id: string; label: string; average: number | null }[];
  absences_half_days: number;
  unjustified_half_days: number;
  lates: number;
  conduct_code: string | null;
  competencies: Record<string, number>;
  appraisal: string | null;
  remediation_flag: boolean;
  remediation_note: string | null;
  repeater: boolean;
  proposal: Decision | null;
  decision: Decision | null;
  reason: string | null;
}
export interface CouncilSummary {
  id: string;
  class_structure_id: string;
  level_structure_id: string | null;
  academic_year_id: string;
  year_label: string | null;
  period_id: string;
  period_label: string;
  term_ordinal: number;
  is_final_term: boolean;
  starts_on: string;
  ends_on: string;
  held_on: string | null;
  status: 'open' | 'validated';
  numeric_enabled: boolean;
  summary: { learners: number; with_average: number; class_average: number | null; decisions: Record<string, number>; remediation: number; absences_half_days: number } | null;
  minutes: string | null;
  validated_at: string | null;
  lines_count?: number;
}
export interface Council extends CouncilSummary {
  class_name: string;
  show_rank: boolean;
  conduct_scale: ScaleLevel[];
  competency_scale: ScaleLevel[];
  decisions: { code: Decision; label_fr: string; label_en: string }[];
  lines: CouncilLine[];
}
export type LineChanges = Partial<{ appraisal: string | null; conduct_code: string | null; decision: Decision; reason: string | null; remediation_flag: boolean; remediation_note: string | null }>;

export interface ReportSnapshot {
  kind: 'term' | 'annual';
  locale: 'fr' | 'en';
  school: string | null;
  class_name: string;
  level_name: string;
  academic_year: string;
  period_label: string;
  learner: { person_id: string; name: string };
  repeater: boolean;
  numeric: { scale: number; average: number | null; rank: number | null; class_size: number | null; subjects: { subject_id: string; name: string | null; code: string | null; average: number | null }[] };
  show_rank: boolean;
  competencies: { domain_id: string; label: string; items: { competency_id: string; code: string; label: string; value: string | null; value_label: string | null }[] }[];
  scale: { code: string; label: string }[];
  attendance: { absences_half_days: number; unjustified_half_days: number; lates: number };
  conduct: { code: string; label: string } | null;
  appraisal: string | null;
  remediation: { flag: boolean; note: string | null; sessions: number; minutes: number; open_plans: string[] };
  decision: { code: string; label: string } | null;
  is_final_term: boolean;
  annual?: { average: number | null; rank: number | null; terms: { period_id: string; label: string; average: number | null }[]; absences_half_days: number };
  number?: string;
  verification_token?: string;
  rectified?: boolean;
}
export interface ReportCard {
  id: string;
  council_id: string;
  person_id: string;
  name?: string;
  class_structure_id: string;
  period_id: string;
  kind: 'term' | 'annual';
  locale: string;
  revision: number;
  status: 'issued' | 'failed' | 'published' | 'superseded';
  document_id: string | null;
  number: string | null;
  verification_token: string | null;
  error_message: string | null;
  issued_at: string | null;
  published_at: string | null;
  superseded_by: string | null;
  snapshot?: ReportSnapshot | null;
}

export interface RemediationSession {
  id: string;
  subject_code: string | null;
  subject_label: string;
  held_on: string;
  duration_minutes: number;
  remediation_form: string;
  objective: string;
  notes: string | null;
  participants: { person_id: string; name?: string }[];
}
export interface RemediationRegister {
  class: { id: string; name: string };
  weekly_minutes_target: number;
  fundamental_subjects: string[];
  forms: Record<string, { fr: string; en: string }>;
  learners: { person_id: string; name: string }[];
  sessions: RemediationSession[];
  weekly: { week: string; subjects: { subject: string; minutes: number; fundamental: boolean; meets_target: boolean }[] }[];
}
export interface RemediationPlan {
  id: string;
  person_id: string;
  name: string;
  class_structure_id: string;
  subject_label: string;
  difficulties: string;
  actions: string;
  status: 'open' | 'in_progress' | 'resolved';
  opened_on: string;
  closed_on: string | null;
  reviews?: { id: string; reviewed_on: string; progress_note: string; status_after: string }[];
  sessions_attended?: number;
  minutes_attended?: number;
}

export interface ExamStats {
  registered: number;
  withdrawn: number;
  passed: number;
  failed: number;
  absent: number;
  pending: number;
  pass_rate: number | null;
}
export interface ExamSession {
  id: string;
  academic_year_id: string;
  exam_code: string;
  label: string;
  level_structure_id: string;
  centre_label: string | null;
  sub_centre_label: string | null;
  registration_opens_on: string | null;
  registration_closes_on: string | null;
  written_on: string | null;
  fee_code: string | null;
  status: 'draft' | 'open' | 'closed';
  results_published_at: string | null;
  stats: ExamStats;
}
export interface ExamCandidate {
  id: string;
  person_id: string;
  name: string;
  class_structure_id: string | null;
  candidate_number: string | null;
  sub_centre_label: string | null;
  registration_status: 'registered' | 'withdrawn';
  fields: Record<string, string | null>;
  outcome: 'pending' | 'passed' | 'failed' | 'absent';
  outcome_detail: string | null;
}
export interface ExamCatalogue {
  kinds: Record<string, { fr: string; en: string; subsystem?: string }>;
  fields: { code: string; fr: string; en: string; required: boolean }[];
  outcomes: string[];
}
export interface ExamSheet {
  session: Omit<ExamSession, 'stats'>;
  candidate: Omit<ExamCandidate, 'name'>;
  learner: { last_name: string; first_name: string; gender: string | null; birth_date: string | null };
  class_name: string | null;
  fields: { code: string; fr: string; en: string; required: boolean; value: string | null }[];
  missing_required: string[];
}

export interface TutelageType {
  key: string;
  label: string;
  columns: Record<string, string>;
}
export interface SettingDefault {
  key: string;
  label: string;
  source: string;
  default: unknown;
  current: unknown;
  customised: boolean;
  to_confirm: boolean;
}
export interface PromotionPreview {
  academic_year_id: string;
  classes: { class_id: string; council_status: string; next_class_id: string | null; missing_next_class: boolean; admitted: number; adjourned: number; cycle_end: number; undecided: number }[];
  totals: { admitted: number; adjourned: number; cycle_end: number; undecided: number; no_next_class: number };
}
export interface FamilyChild {
  person_id: string;
  name: string;
  class: { id: string; name: string } | null;
}
export interface FamilyExam {
  exam: string;
  exam_code: string;
  written_on: string | null;
  sub_centre: string | null;
  candidate_number: string | null;
  outcome: string | null;
  outcome_detail: string | null;
}

export const primaryP2 = {
  councils: async (query: { class_id?: string; academic_year_id?: string } = {}): Promise<CouncilSummary[]> => (await get<{ items: CouncilSummary[] }>('/councils', query)).items,
  openCouncil: (body: { class_id: string; period_id: string; starts_on: string; period_label?: string; held_on?: string | null; sequences?: { id: string; starts_on: string }[] }): Promise<Council> => post('/councils', body),
  council: (id: string): Promise<Council> => get(`/councils/${id}`),
  updateLine: (id: string, lineId: string, changes: LineChanges): Promise<Council> => patch(`/councils/${id}/lines/${lineId}`, changes),
  refreshCouncil: (id: string): Promise<Council> => post(`/councils/${id}/refresh`),
  validateCouncil: (id: string): Promise<Council> => post(`/councils/${id}/validate`),
  reopenCouncil: (id: string, reason: string): Promise<Council> => post(`/councils/${id}/reopen`, { reason }),

  reportCards: async (councilId: string): Promise<ReportCard[]> => (await get<{ items: ReportCard[] }>(`/councils/${councilId}/report-cards`)).items,
  preview: (councilId: string, personId: string, kind: 'term' | 'annual' = 'term'): Promise<{ snapshot: ReportSnapshot; variables: Record<string, string | null>; council_status: string }> => get(`/councils/${councilId}/report-cards/preview`, { person_id: personId, kind }),
  issueCards: (councilId: string): Promise<{ issued: number; failed: number; skipped: number; items: ReportCard[] }> => post(`/councils/${councilId}/report-cards/issue`),
  publishCards: (councilId: string): Promise<{ published: number; notified: number; items: ReportCard[] }> => post(`/councils/${councilId}/report-cards/publish`),
  reportCard: (id: string): Promise<ReportCard> => get(`/report-cards/${id}`),
  rectify: (id: string, body: { reason: string } & LineChanges): Promise<ReportCard> => post(`/report-cards/${id}/rectify`, body),

  remediation: (classId: string, from?: string, to?: string): Promise<RemediationRegister> => get(`/classes/${classId}/remediation`, { from, to }),
  addRemediation: (classId: string, body: { held_on: string; duration_minutes: number; remediation_form: string; subject_label: string; subject_code?: string; objective: string; notes?: string | null; person_ids: string[] }): Promise<RemediationSession> => post(`/classes/${classId}/remediation`, body),
  plans: async (classId: string): Promise<RemediationPlan[]> => (await get<{ items: RemediationPlan[] }>('/remediation-plans', { class_id: classId })).items,
  plan: (id: string): Promise<RemediationPlan> => get(`/remediation-plans/${id}`),
  openPlan: (body: { person_id: string; class_id: string; subject_label: string; difficulties: string; actions: string; opened_on?: string }): Promise<RemediationPlan> => post('/remediation-plans', body),
  reviewPlan: (id: string, body: { progress_note: string; status_after: 'open' | 'in_progress' | 'resolved'; reviewed_on?: string }): Promise<RemediationPlan> => post(`/remediation-plans/${id}/reviews`, body),

  examCatalogue: (): Promise<ExamCatalogue> => get('/exam-catalogue'),
  examSessions: async (yearId?: string): Promise<ExamSession[]> => (await get<{ items: ExamSession[] }>('/exam-sessions', { academic_year_id: yearId })).items,
  createExamSession: (body: { academic_year_id: string; exam_code: string; level_structure_id: string; centre_label?: string | null; sub_centre_label?: string | null; registration_closes_on?: string | null; written_on?: string | null; fee_code?: string | null }): Promise<ExamSession> => post('/exam-sessions', body),
  examStatus: (id: string, status: 'draft' | 'open' | 'closed'): Promise<ExamSession> => post(`/exam-sessions/${id}/status`, { status }),
  candidates: async (id: string): Promise<ExamCandidate[]> => (await get<{ items: ExamCandidate[] }>(`/exam-sessions/${id}/candidates`)).items,
  enrolLevel: (id: string): Promise<{ registered: number; existing: number }> => post(`/exam-sessions/${id}/candidates/enrol-level`),
  assignNumbers: (id: string): Promise<{ assigned: number }> => post(`/exam-sessions/${id}/numbers`),
  updateCandidate: (id: string, body: { candidate_number?: string | null; sub_centre_label?: string | null; fields?: Record<string, string | null> }): Promise<ExamCandidate> => patch(`/exam-candidates/${id}`, body),
  withdraw: (id: string, reason: string): Promise<ExamCandidate> => post(`/exam-candidates/${id}/withdraw`, { reason }),
  examSheet: (id: string): Promise<ExamSheet> => get(`/exam-candidates/${id}/sheet`),
  recordResults: (id: string, entries: { candidate_id: string; outcome: string; detail?: string }[]): Promise<{ recorded: number; rejected: { line: number; reason: string }[] }> => put(`/exam-sessions/${id}/results`, { entries }),
  importResults: (id: string, csv: string): Promise<{ recorded: number; rejected: { line: number; reason: string }[] }> => post(`/exam-sessions/${id}/results/import`, { csv }),
  publishResults: (id: string): Promise<{ notified: number; published_at: string }> => post(`/exam-sessions/${id}/results/publish`),

  tutelageTypes: async (): Promise<TutelageType[]> => (await get<{ items: TutelageType[] }>('/tutelage/types')).items,
  settingsDefaults: async (): Promise<SettingDefault[]> => (await get<{ items: SettingDefault[] }>('/settings/defaults')).items,
  promotionPreview: (yearId: string): Promise<PromotionPreview> => get('/closure/promotion-preview', { academic_year_id: yearId }),

  familyChildren: async (): Promise<FamilyChild[]> => (await get<{ items: FamilyChild[] }>('/family/children')).items,
  familyReportCards: async (childId: string): Promise<ReportCard[]> => (await get<{ items: ReportCard[] }>(`/family/children/${childId}/report-cards`)).items,
  familyExams: async (childId: string): Promise<FamilyExam[]> => (await get<{ items: FamilyExam[] }>(`/family/children/${childId}/exams`)).items,
};

export const primary = {
  setup: (): Promise<SetupStatus> => get('/setup'),
  applySetup: (): Promise<SetupStatus> => post('/setup/apply'),
  dashboard: (): Promise<Dashboard> => get('/dashboard'),
  closureCheck: (yearId?: string): Promise<ClosureReport> => get('/closure/check', { academic_year_id: yearId }),

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
