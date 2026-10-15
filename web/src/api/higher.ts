// Appels de l'application Supérieur (IPES), lot I1 : /api/v1/higher/** avec l'en-tête X-Establishment-Id.
// Contrats : specs/001-socle-plateforme/contracts/openapi.d/higher-*.yaml. Toute écriture passe par WriteGate côté serveur :
// abonnement suspendu = 423 (lecture et export seulement), application fermée = 403 (écriture) ou 404 (lecture).
// Sélecteur « École » (RG-ORG-3) : paramètre `school_id` sur les listes ; absent = toutes les écoles du périmètre.
import { ApiError, http, type QueryValue } from './http';
import { ulid } from '@/lib/ulid';

export type Q = Record<string, QueryValue>;
export type Regime = 'lmd' | 'bts' | 'hnd' | 'certificate';
export type StudyMode = 'day' | 'evening' | 'work_study';
export type TeachingLanguage = 'fr' | 'en' | 'bilingual';
export type CalendarKind = 'semester' | 'exam_period' | 'resit_period' | 'deliberation' | 'holiday' | 'enrolment_period' | 'other';
export type UnitType = 'fundamental' | 'transversal' | 'optional' | 'internship' | 'project';
export type CampaignStatus = 'draft' | 'open' | 'closed' | 'cancelled';
export type SelectionMode = 'file' | 'contest' | 'mixed';
export type ApplicationStatus = 'draft' | 'submitted' | 'complete' | 'admitted' | 'conditional' | 'waitlisted' | 'rejected' | 'withdrawn' | 'enrolled';
export type Decision = 'admitted' | 'conditional' | 'waitlisted' | 'rejected';
export type Diploma = 'bac' | 'gce_al' | 'bts' | 'hnd' | 'licence' | 'master1' | 'master' | 'other';
export type Mention = 'passable' | 'assez_bien' | 'bien' | 'tres_bien';
export type EnrolmentStatus = 'pending' | 'enrolled' | 'financial_hold' | 'suspended' | 'withdrawn' | 'excluded' | 'transferred' | 'graduated';
export type ProgressionDecision = 'admitted' | 'admitted_with_debts' | 'repeat' | 'excluded';
export type PaymentChannel = 'cash' | 'orange_money' | 'mtn_momo' | 'transfer' | 'card';
export type ScholarshipKind = 'merit' | 'staff' | 'sibling' | 'partnership';
export type DocStatus = 'valid' | 'cancelled' | 'replaced';

export const REGIMES: Regime[] = ['lmd', 'bts', 'hnd', 'certificate'];
export const STUDY_MODES: StudyMode[] = ['day', 'evening', 'work_study'];
export const LANGUAGES: TeachingLanguage[] = ['fr', 'en', 'bilingual'];
export const CALENDAR_KINDS: CalendarKind[] = ['semester', 'exam_period', 'resit_period', 'deliberation', 'holiday', 'enrolment_period', 'other'];
export const UNIT_TYPES: UnitType[] = ['fundamental', 'transversal', 'optional', 'internship', 'project'];
export const SELECTION_MODES: SelectionMode[] = ['file', 'contest', 'mixed'];
export const APPLICATION_STATUSES: ApplicationStatus[] = ['draft', 'submitted', 'complete', 'admitted', 'conditional', 'waitlisted', 'rejected', 'withdrawn', 'enrolled'];
export const DECISIONS: Decision[] = ['admitted', 'conditional', 'waitlisted', 'rejected'];
export const DIPLOMAS: Diploma[] = ['bac', 'gce_al', 'bts', 'hnd', 'licence', 'master1', 'master', 'other'];
export const MENTIONS: Mention[] = ['passable', 'assez_bien', 'bien', 'tres_bien'];
export const ENROLMENT_STATUSES: EnrolmentStatus[] = ['pending', 'enrolled', 'financial_hold', 'suspended', 'withdrawn', 'excluded', 'transferred', 'graduated'];
/** Statuts qu'un changement de statut (EX-INS-04) peut viser. */
export const STATUS_TARGETS: EnrolmentStatus[] = ['enrolled', 'suspended', 'withdrawn', 'excluded', 'transferred', 'graduated'];
export const PROGRESSION_DECISIONS: ProgressionDecision[] = ['admitted', 'admitted_with_debts', 'repeat', 'excluded'];
export const PAYMENT_CHANNELS: PaymentChannel[] = ['cash', 'orange_money', 'mtn_momo', 'transfer', 'card'];
export const MOBILE_CHANNELS = ['orange_money', 'mtn_momo'] as const;
export const SCHOLARSHIP_KINDS: ScholarshipKind[] = ['merit', 'staff', 'sibling', 'partnership'];
/** Catalogue du lot I1 (DOC-01 à 06 et 27 à 30). */
export const I1_DOCUMENTS = ['DOC-01', 'DOC-02', 'DOC-03', 'DOC-04', 'DOC-05', 'DOC-06', 'DOC-27', 'DOC-28', 'DOC-29', 'DOC-30'];
/** Documents jamais bloqués pour impayés (RG-DOC-7, RG-FIN-5) : certificat de scolarité, reçu, relevé de compte, attestation de paiement. */
export const NEVER_BLOCKED_DOCUMENTS = ['DOC-06', 'DOC-27', 'DOC-28', 'DOC-29'];
/** Seuils d'alerte d'expiration des autorisations et conventions (jours). */
export const EXPIRY_THRESHOLDS = [90, 30, 7] as const;

export const H = '/higher';
const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

// --- Profil Higher -----------------------------------------------------------------------------------------------------------
export interface HigherMe {
  application: { key: 'higher'; state: 'trial' | 'active' | 'past_due' | 'suspended' | 'closed' | null; writable: boolean };
  establishment_id: string;
  roles: string[];
  all_schools: boolean;
  school_ids: string[];
  school_selector: boolean;
}
export const fetchHigherMe = (): Promise<HigherMe> => unwrap(http.get<{ data: HigherMe }>(`${H}/me`));

/** Écriture refusée parce que l'abonnement est suspendu (lecture et export seulement). */
export function isSuspended(error: unknown): boolean {
  return error instanceof ApiError && error.status === 423;
}
/** Modification d'un règlement figé refusée faute de double authentification récente (RG-ACA-3). */
export function isSecondFactorRequired(error: unknown): boolean {
  return error instanceof ApiError && error.status === 403 && ['second-factor-required', 'two-factor-recent-required', 'two-factor-required'].includes(error.slug);
}

// --- Académique (ACA) --------------------------------------------------------------------------------------------------------
export interface School { id: string; code: string; name: string; director_person_id: string | null; created_at?: string; updated_at?: string }
export interface Department { id: string; school_id: string; code: string; name: string; head_person_id: string | null }
export interface Program {
  id: string; school_id: string; department_id: string; parent_program_id: string | null; code: string; name: string; regime: Regime;
  teaching_language: TeachingLanguage; study_mode: StudyMode; duration_years: number; status: string | null; requires_tutelage: boolean;
}
export interface Level { id: string; school_id: string; department_id: string; program_id: string; ordinal: number; code: string; name: string }
export interface Group { id: string; school_id: string; program_id: string; level_id: string; code: string; name: string; capacity: number | null }
export interface Authorization {
  id: string; school_id: string; department_id: string; program_id: string; decree_date: string | null; decree_number?: string; authority?: string | null;
  valid_from?: string; valid_to?: string; level_ids?: string[] | null; document_id?: string | null; status: 'valid' | 'expired' | 'upcoming' | 'missing'; days_left?: number;
}
export interface Contact { name: string; role?: string; email?: string; phone?: string }
export interface Tutelage {
  id: string; school_id: string; program_id: string; academic_year_id: string; university_name: string; convention_reference: string | null;
  convention_document_id: string | null; convention_starts_on: string | null; convention_ends_on: string | null; approved_programme_ref: string | null; contacts: Contact[];
}
export interface Regulation {
  id: string; school_id: string; regime: Regime; academic_year_id: string; version: number; status: 'draft' | 'frozen';
  frozen_at: string | null; frozen_by: string | null; parameters: Record<string, number | boolean | string | null>; updated_at?: string;
}
export interface RegulationRevision {
  id: string; regulation_id: string; version: number; reason: string; amended_by: string; created_at: string;
  previous_parameters: Record<string, unknown>; new_parameters: Record<string, unknown>;
}
export interface CalendarEntry { id: string; school_id: string; academic_year_id: string; entry_kind: CalendarKind; label: string; starts_on: string; ends_on: string; semester_ordinal: number | null }

export const academic = {
  schools: (q: Q = {}): Promise<School[]> => http.all<School>(`${H}/schools`, q),
  createSchool: (b: { code: string; name: string; director_person_id?: string | null }): Promise<School> => unwrap(http.post(`${H}/schools`, b)),
  updateSchool: (id: string, b: Partial<Pick<School, 'code' | 'name' | 'director_person_id'>>): Promise<School> => unwrap(http.patch(`${H}/schools/${seg(id)}`, b)),
  departments: (q: Q = {}): Promise<Department[]> => http.all<Department>(`${H}/departments`, q),
  createDepartment: (b: { school_id: string; code: string; name: string }): Promise<Department> => unwrap(http.post(`${H}/departments`, b)),
  updateDepartment: (id: string, b: Partial<Pick<Department, 'code' | 'name'>>): Promise<Department> => unwrap(http.patch(`${H}/departments/${seg(id)}`, b)),
  programs: (q: Q = {}): Promise<Program[]> => http.all<Program>(`${H}/programs`, q),
  createProgram: (b: { department_id: string; code: string; name: string; regime: Regime; teaching_language: TeachingLanguage; study_mode: StudyMode; duration_years: number; parent_program_id?: string | null }): Promise<Program> =>
    unwrap(http.post(`${H}/programs`, b)),
  /** Le régime n'est jamais envoyé : il ne change pas après la création (RG-ORG-7). */
  updateProgram: (id: string, b: Partial<Pick<Program, 'code' | 'name' | 'teaching_language' | 'study_mode' | 'duration_years'>>): Promise<Program> => unwrap(http.patch(`${H}/programs/${seg(id)}`, b)),
  levels: (q: Q = {}): Promise<Level[]> => http.all<Level>(`${H}/levels`, q),
  createLevel: (b: { program_id: string; ordinal: number; code: string; name: string }): Promise<Level> => unwrap(http.post(`${H}/levels`, b)),
  updateLevel: (id: string, b: Partial<Pick<Level, 'code' | 'name'>>): Promise<Level> => unwrap(http.patch(`${H}/levels/${seg(id)}`, b)),
  groups: (q: Q = {}): Promise<Group[]> => http.all<Group>(`${H}/groups`, q),
  createGroup: (b: { level_id: string; code: string; name: string; capacity?: number | null }): Promise<Group> => unwrap(http.post(`${H}/groups`, b)),
  updateGroup: (id: string, b: Partial<Pick<Group, 'code' | 'name' | 'capacity'>>): Promise<Group> => unwrap(http.patch(`${H}/groups/${seg(id)}`, b)),
  authorizations: (q: Q = {}): Promise<Authorization[]> => http.all<Authorization>(`${H}/authorizations`, q),
  expiring: (days = 90): Promise<Authorization[]> => unwrap(http.get(`${H}/authorizations/expiring`, { days })),
  createAuthorization: (b: { program_id: string; decree_number: string; decree_date?: string | null; authority?: string | null; valid_from: string; valid_to: string; level_ids?: string[] | null; document_id?: string | null }): Promise<Authorization> =>
    unwrap(http.post(`${H}/authorizations`, b)),
  updateAuthorization: (id: string, b: Record<string, unknown>): Promise<Authorization> => unwrap(http.patch(`${H}/authorizations/${seg(id)}`, b)),
  deleteAuthorization: (id: string): Promise<unknown> => http.delete(`${H}/authorizations/${seg(id)}`),
  tutelages: (q: Q = {}): Promise<Tutelage[]> => http.all<Tutelage>(`${H}/tutelages`, q),
  createTutelage: (b: Omit<Tutelage, 'id' | 'school_id'>): Promise<Tutelage> => unwrap(http.post(`${H}/tutelages`, b)),
  updateTutelage: (id: string, b: Partial<Tutelage>): Promise<Tutelage> => unwrap(http.patch(`${H}/tutelages/${seg(id)}`, b)),
  regulations: (q: Q = {}): Promise<Regulation[]> => http.all<Regulation>(`${H}/regulations`, q),
  createRegulation: (b: { school_id: string; regime: Regime; academic_year_id: string; parameters?: Record<string, unknown> }): Promise<Regulation> => unwrap(http.post(`${H}/regulations`, b)),
  updateRegulation: (id: string, b: { parameters: Record<string, unknown> }): Promise<Regulation> => unwrap(http.patch(`${H}/regulations/${seg(id)}`, b)),
  freezeRegulation: (id: string): Promise<Regulation> => unwrap(http.post(`${H}/regulations/${seg(id)}/freeze`)),
  amendRegulation: (id: string, b: { parameters: Record<string, unknown>; reason: string }): Promise<Regulation> => unwrap(http.post(`${H}/regulations/${seg(id)}/amend`, b)),
  revisions: (id: string): Promise<RegulationRevision[]> => unwrap(http.get(`${H}/regulations/${seg(id)}/revisions`)),
  calendar: (q: Q = {}): Promise<CalendarEntry[]> => http.all<CalendarEntry>(`${H}/calendar-entries`, q),
  createCalendarEntry: (b: Omit<CalendarEntry, 'id'>): Promise<CalendarEntry> => unwrap(http.post(`${H}/calendar-entries`, b)),
  deleteCalendarEntry: (id: string): Promise<unknown> => http.delete(`${H}/calendar-entries/${seg(id)}`),
};

// --- Maquettes (MAQ) ---------------------------------------------------------------------------------------------------------
export interface Curriculum {
  id: string; school_id: string; department_id: string; program_id: string; level_id: string; academic_year_id: string; regime: Regime; version: number;
  status: 'draft' | 'published' | 'superseded' | string; title: string | null; approved_programme_ref: string | null; previous_version_id: string | null;
  copied_from_id: string | null; published_at: string | null; grades_recorded_at: string | null;
}
export interface CurriculumElement {
  id: string; unit_id: string; code: string; title: string; credits: number; coefficient: number; cm_hours: number; td_hours: number; tp_hours: number;
  personal_hours: number; cc_weight_percent: number | null; sort_index: number;
}
export interface CurriculumUnit {
  id: string; curriculum_id: string; semester_id: string; code: string; title: string; unit_type: UnitType; credits: number; coefficient: number;
  choice_group: string | null; choice_count: number | null; national_exam_paper: string | null; cc_weight_percent: number | null; sort_index: number;
  volume_hours?: number; elements: CurriculumElement[];
}
export interface CurriculumSemester { id: string; ordinal: number; label: string; units: CurriculumUnit[] }
export interface CoherenceCheck { errors: string[]; warnings: string[]; semesters: Array<{ ordinal: number; label: string; credits: number; volume_minutes: number }>; publishable?: boolean }
export interface CurriculumTree extends Curriculum { semesters: CurriculumSemester[]; check: CoherenceCheck }
export type UnitInput = Partial<Pick<CurriculumUnit, 'code' | 'title' | 'unit_type' | 'credits' | 'coefficient' | 'choice_group' | 'choice_count' | 'national_exam_paper' | 'cc_weight_percent'>> & { semester_ordinal?: number };
export type ElementInput = Partial<Pick<CurriculumElement, 'code' | 'title' | 'credits' | 'coefficient' | 'cm_hours' | 'td_hours' | 'tp_hours' | 'personal_hours' | 'cc_weight_percent'>>;

export const curricula = {
  list: (q: Q = {}): Promise<Curriculum[]> => http.all<Curriculum>(`${H}/curricula`, q),
  create: (b: { program_id: string; level_id: string; academic_year_id: string; title?: string; approved_programme_ref?: string | null; semester_ordinals?: number[] }): Promise<Curriculum> =>
    unwrap(http.post(`${H}/curricula`, b)),
  show: (id: string): Promise<CurriculumTree> => unwrap(http.get(`${H}/curricula/${seg(id)}`)),
  update: (id: string, b: { title?: string; approved_programme_ref?: string | null }): Promise<Curriculum> => unwrap(http.patch(`${H}/curricula/${seg(id)}`, b)),
  check: (id: string): Promise<CoherenceCheck> => unwrap(http.get(`${H}/curricula/${seg(id)}/check`)),
  publish: (id: string): Promise<Curriculum> => unwrap(http.post(`${H}/curricula/${seg(id)}/publish`)),
  unpublish: (id: string): Promise<Curriculum> => unwrap(http.post(`${H}/curricula/${seg(id)}/unpublish`)),
  newVersion: (id: string): Promise<Curriculum> => unwrap(http.post(`${H}/curricula/${seg(id)}/versions`)),
  copy: (id: string, academicYearId: string): Promise<Curriculum> => unwrap(http.post(`${H}/curricula/${seg(id)}/copy`, { academic_year_id: academicYearId })),
  addUnit: (id: string, b: UnitInput): Promise<CurriculumUnit> => unwrap(http.post(`${H}/curricula/${seg(id)}/units`, b)),
  updateUnit: (id: string, b: UnitInput): Promise<CurriculumUnit> => unwrap(http.patch(`${H}/curriculum-units/${seg(id)}`, b)),
  removeUnit: (id: string): Promise<unknown> => http.delete(`${H}/curriculum-units/${seg(id)}`),
  addElement: (unitId: string, b: ElementInput): Promise<CurriculumElement> => unwrap(http.post(`${H}/curriculum-units/${seg(unitId)}/elements`, b)),
  updateElement: (id: string, b: ElementInput): Promise<CurriculumElement> => unwrap(http.patch(`${H}/curriculum-elements/${seg(id)}`, b)),
  removeElement: (id: string): Promise<unknown> => http.delete(`${H}/curriculum-elements/${seg(id)}`),
  exportPath: (id: string): string => `${H}/curricula/${seg(id)}/export`,
};

// --- Admissions (ADM) --------------------------------------------------------------------------------------------------------
export interface RequiredDocument { code: string; label: string; blocking?: boolean; proves_prerequisite?: boolean }
export interface Prerequisites { diplomas?: Diploma[]; series?: string[]; min_year?: number; min_mention?: Mention }
export interface Campaign {
  id: string; school_id: string; department_id: string; program_id: string; level_id: string; academic_year_id: string; title: string | null;
  opens_on: string; closes_on: string; seats: number; application_fee_xaf: number; required_documents: RequiredDocument[]; prerequisites: Prerequisites;
  selection_mode: SelectionMode; jury: { president_person_id: string; member_person_ids: string[] } | null; status: CampaignStatus; cancelled_reason: string | null;
}
export interface ApplicationDocument {
  id: string; requirement_code: string; label: string; blocking: boolean; proves_prerequisite: boolean; document_ref: string | null;
  status: 'missing' | 'submitted' | 'verified' | 'rejected' | string; rejection_reason: string | null; verified_by: string | null; verified_at: string | null;
}
export interface AdmissionDecisionRecord { id: string; decision: Decision; reason: string; over_capacity: boolean; over_capacity_reason: string | null; conditional_until: string | null; source: string; decided_by: string; decided_at: string }
export interface Application {
  id: string; reference: string; campaign_id: string; person_id: string; school_id: string; program_id: string; level_id: string; academic_year_id: string;
  channel: 'online' | 'counter'; application_kind: 'new' | 'transfer'; origin_institution: string | null; diploma: Diploma; diploma_series: string | null;
  diploma_year: number | null; diploma_mention: Mention | null; status: ApplicationStatus;
  fee: { status: 'unpaid' | 'pending' | 'paid' | 'none'; invoice_id: string | null; payment_id: string | null; receipt_number: string | null; refundable: boolean };
  score_hundredths: number | null; rank_position: number | null; list_kind: 'main' | 'waiting' | string | null; decision_reason: string | null;
  conditional_until: string | null; over_capacity: boolean; enrolment_id: string | null; submitted_at: string | null; completed_at: string | null; created_at: string;
  documents?: ApplicationDocument[]; decisions?: AdmissionDecisionRecord[];
}
export interface CampaignStatistics {
  campaign_id: string; seats: number; applications: number; by_status: Record<string, number>; by_channel: Record<string, number>; seats_taken: number;
  seats_left: number; over_capacity_admissions: number; fees_collected_xaf: number; enrolment_rate_percent: number;
}
export interface ContestSeat { id: string; application_id: string; seat_number: string; convocation_document_id: string | null }
export interface ContestSession { id: string; campaign_id: string; contest_date: string; center_name: string; room_name: string; room_capacity: number; seats?: ContestSeat[] }
export interface RankingRow { application_id: string; reference: string; score_hundredths: number; rank_position: number; list_kind: string }
export interface CampaignInput {
  level_id: string; academic_year_id: string; title?: string; opens_on: string; closes_on: string; seats: number; application_fee_xaf: number;
  selection_mode?: SelectionMode; required_documents?: RequiredDocument[]; prerequisites?: Prerequisites;
}

export const admissions = {
  campaigns: (q: Q = {}): Promise<Campaign[]> => http.all<Campaign>(`${H}/admissions/campaigns`, q),
  campaign: (id: string): Promise<Campaign> => unwrap(http.get(`${H}/admissions/campaigns/${seg(id)}`)),
  createCampaign: (b: CampaignInput): Promise<Campaign> => unwrap(http.post(`${H}/admissions/campaigns`, b)),
  openCampaign: (id: string): Promise<Campaign> => unwrap(http.post(`${H}/admissions/campaigns/${seg(id)}/open`)),
  closeCampaign: (id: string): Promise<Campaign> => unwrap(http.post(`${H}/admissions/campaigns/${seg(id)}/close`)),
  cancelCampaign: (id: string, reason: string): Promise<Campaign> => unwrap(http.post(`${H}/admissions/campaigns/${seg(id)}/cancel`, { reason })),
  setJury: (id: string, b: { president_person_id: string; member_person_ids: string[] }): Promise<Campaign> => unwrap(http.put(`${H}/admissions/campaigns/${seg(id)}/jury`, b)),
  rank: (id: string): Promise<RankingRow[]> => unwrap(http.post(`${H}/admissions/campaigns/${seg(id)}/ranking`)),
  statistics: (id: string): Promise<CampaignStatistics> => unwrap(http.get(`${H}/admissions/campaigns/${seg(id)}/statistics`)),
  sessions: (id: string): Promise<ContestSession[]> => unwrap(http.get(`${H}/admissions/campaigns/${seg(id)}/contest-sessions`)),
  createSession: (id: string, b: { contest_date: string; center_name: string; room_name: string; room_capacity: number }): Promise<ContestSession> =>
    unwrap(http.post(`${H}/admissions/campaigns/${seg(id)}/contest-sessions`, b)),
  convoke: (sessionId: string): Promise<unknown> => unwrap(http.post(`${H}/admissions/contest-sessions/${seg(sessionId)}/convocations`)),
  applications: (q: Q = {}): Promise<Application[]> => http.all<Application>(`${H}/admissions/applications`, q),
  application: (id: string): Promise<Application> => unwrap(http.get(`${H}/admissions/applications/${seg(id)}`)),
  createApplication: (b: Record<string, unknown>): Promise<Application> => unwrap(http.post(`${H}/admissions/applications`, b)),
  attachDocument: (id: string, b: { requirement_code: string; document_ref: string }): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/documents`, b)),
  verifyDocument: (id: string, docId: string): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/documents/${seg(docId)}/verify`)),
  rejectDocument: (id: string, docId: string, reason: string): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/documents/${seg(docId)}/reject`, { reason })),
  submit: (id: string): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/submit`)),
  payFee: (id: string, b: { channel?: string; payer_phone?: string; external_reference?: string }, key: string): Promise<Application> =>
    unwrap(http.post(`${H}/admissions/applications/${seg(id)}/fee-payment`, b, { idempotencyKey: key })),
  score: (id: string, scoreHundredths: number): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/score`, { score_hundredths: scoreHundredths })),
  decide: (id: string, b: { decision: Decision; reason: string; over_capacity_reason?: string }): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/decision`, b)),
  withdraw: (id: string, reason: string): Promise<Application> => unwrap(http.post(`${H}/admissions/applications/${seg(id)}/withdraw`, { reason })),
};

// --- Inscriptions (INS) ------------------------------------------------------------------------------------------------------
export interface Enrolment {
  id: string; person_id: string; student_id: string | null; matricule: string | null; enrollment_id: string | null; application_id: string | null;
  academic_year_id: string; school_id: string; department_id: string; program_id: string; level_id: string; group_id: string | null; study_mode: StudyMode;
  kind: string; curriculum_id: string | null; curriculum_version: number | null; attempt_number: number; derogation_id: string | null; status: EnrolmentStatus;
  status_reason: string | null; financial_hold: boolean; financial_hold_reason: string | null; validated_by: string | null; validated_at: string | null;
  refund_percent: number | null; refund_xaf: number | null; card_document_id: string | null; form_document_id: string | null; created_at: string;
  fees_recalculation_required?: boolean;
}
export interface ContractItem { curriculum_unit_id: string | null; unit_code: string; unit_title: string; credits: number; semester_ordinal: number | null; origin: 'normal' | 'optional' | 'debt' | 'capitalized'; choice_group: string | null }
export interface ContractChoice { curriculum_unit_id: string; code: string; title: string; credits: number; choice_group: string; choice_count: number | null }
export interface LearningContract {
  id: string; enrolment_id: string; curriculum_id: string | null; curriculum_version: number | null; status: 'draft' | 'final'; finalized_at: string | null;
  items: ContractItem[]; credits_by_origin: Record<string, number>; credits_to_take: number; choices: ContractChoice[];
}
export interface EnrolmentEvent { event_type: string; from_value: string | null; to_value: string | null; reason: string | null; actor_id: string | null; occurred_at: string }
export interface StudentRecord {
  person_id: string; full_name: string | null; matricule: string | null; matricule_assigned_at: string | null; photo_ref: string | null;
  guarantor_person_id: string | null; share_with_guarantor: boolean; consent_recorded_at: string | null;
  tracks: Array<{ enrolment_id: string; academic_year_id: string; school_id: string; program_id: string; level_id: string; group_id: string | null; status: EnrolmentStatus; attempt_number: number }>;
}
export interface ProgressionRecord { id: string; person_id: string; program_id: string; level_id: string; academic_year_id: string; decision: ProgressionDecision; acquired_units: string[] | null; missing_units: string[] | null; recorded_at: string }
export interface IssuedDoc {
  id: string; document_id: string; code: string; number: string; verification_token: string; person_id: string; school_id: string; program_id: string | null;
  level_id: string | null; locale: string; profile_version: number; status: DocStatus; cancel_reason: string | null; replaced_by: string | null; duplicate_count: number; issued_at: string;
}

export const enrolments = {
  list: (q: Q = {}): Promise<Enrolment[]> => http.all<Enrolment>(`${H}/enrolments`, q),
  show: (id: string): Promise<Enrolment> => unwrap(http.get(`${H}/enrolments/${seg(id)}`)),
  create: (b: { application_id?: string; person_id?: string; level_id?: string; academic_year_id?: string; group_id?: string; study_mode?: StudyMode }): Promise<Enrolment> =>
    unwrap(http.post(`${H}/enrolments`, b)),
  validate: (id: string, b: { financial_hold?: boolean; reason?: string } = {}): Promise<Enrolment> => unwrap(http.post(`${H}/enrolments/${seg(id)}/validate`, b)),
  setStatus: (id: string, b: { status: EnrolmentStatus; reason: string }): Promise<Enrolment> => unwrap(http.post(`${H}/enrolments/${seg(id)}/status`, b)),
  change: (id: string, b: { level_id?: string; group_id?: string; study_mode?: StudyMode; reason: string }): Promise<Enrolment> => unwrap(http.post(`${H}/enrolments/${seg(id)}/change`, b)),
  contract: (id: string): Promise<LearningContract> => unwrap(http.get(`${H}/enrolments/${seg(id)}/learning-contract`)),
  chooseUnits: (id: string, b: { optional_unit_ids?: string[]; group_id?: string; finalize?: boolean }): Promise<LearningContract> =>
    unwrap(http.put(`${H}/enrolments/${seg(id)}/learning-contract`, b)),
  studentCard: (id: string): Promise<IssuedDoc> => unwrap(http.post(`${H}/enrolments/${seg(id)}/student-card`)),
  enrolmentForm: (id: string): Promise<IssuedDoc> => unwrap(http.post(`${H}/enrolments/${seg(id)}/enrolment-form`)),
  events: (id: string): Promise<EnrolmentEvent[]> => unwrap(http.get(`${H}/enrolments/${seg(id)}/events`)),
  student: (personId: string): Promise<StudentRecord> => unwrap(http.get(`${H}/students/${seg(personId)}`)),
  consent: (personId: string, share: boolean): Promise<StudentRecord> => unwrap(http.put(`${H}/students/${seg(personId)}/guarantor-consent`, { share_with_guarantor: share })),
  derogate: (b: { person_id: string; level_id: string; academic_year_id: string; reason: string }): Promise<unknown> => unwrap(http.post(`${H}/enrolment-derogations`, b)),
  decisions: (q: Q = {}): Promise<ProgressionRecord[]> => unwrap(http.get(`${H}/progression-decisions`, q)),
  recordDecision: (b: { person_id: string; level_id: string; academic_year_id: string; decision: ProgressionDecision; acquired_units?: string[]; missing_units?: string[] }): Promise<ProgressionRecord> =>
    unwrap(http.post(`${H}/progression-decisions`, b)),
};

// --- Finances (FIN) ----------------------------------------------------------------------------------------------------------
export interface FeeKind { code: string; kind: string; label_fr: string; label_en: string; refundable: boolean }
export interface GridFee { fee_code: string; amount_xaf: number; due_on: string | null }
export interface GridTerm { due_on: string; weight: number }
export interface FeeGrid { id: string; academic_year_id: string; school_id: string; program_id: string | null; level_id: string | null; study_mode: 'day' | 'evening' | null; label: string; fees: GridFee[]; terms: GridTerm[]; status: 'active' | 'archived'; created_at: string }
export interface Installment { id: string; label: string; due_on: string; amount_xaf: number; paid_xaf: number; remaining_xaf: number; status: 'paid' | 'partial' | 'pending' | 'overdue' | string; overdue: boolean }
export interface Receipt { payment_id: string; receipt_number: string | null; amount_xaf: number; channel: string; status: string; recorded_at: string; account_id?: string }
export interface StudentAccount {
  id: string; academic_year_id: string; person_id: string; school_id: string; program_id: string | null; level_id: string | null; invoice_id: string;
  payer_name: string | null; net_xaf: number; paid_xaf: number; balance_xaf: number; up_to_date: boolean; installments: Installment[]; receipts: Receipt[];
  ledger?: { today: string; overdue_xaf: number; next_due: { id: string; label: string; due_on: string; remaining_xaf: number } | null; gross_xaf: number; discount_xaf: number; lines: Array<{ fee_code: string; label: string; gross_xaf: number; discount_xaf: number; net_xaf: number; paid_xaf: number }> };
}
export interface PaymentResult { payment: { id: string; status: 'pending' | 'confirmed' | string; amount_xaf: number; channel: string; receipt_id: string | null; receipt_number: string | null; replayed: boolean }; account: StudentAccount }
export interface Scholarship { id: string; account_id: string; person_id: string; scholarship_kind: ScholarshipKind; calc_mode: 'percent' | 'fixed'; rate_value: number; applies_to: string; amount_xaf: number; reason: string; status: 'pending' | 'approved' | 'rejected'; requested_by: string; decided_by: string | null; decided_at: string | null; created_at: string }
export interface PaymentPlan { id: string; person_id: string; academic_year_id: string; school_id: string; program_id: string | null; terms: GridTerm[]; reason: string; status: 'pending' | 'approved' | 'rejected'; requested_by: string; decided_by: string | null; decided_at: string | null }
export interface Sponsor { id: string; sponsor_kind: 'person' | 'organisation'; name: string; reference: string | null; contact: string | null }
export interface SponsorStatement extends Sponsor { accounts: Array<{ account_id: string; person_id: string; invoice_id: string; net_xaf: number; balance_xaf: number }>; balance_xaf: number }
export interface Holds { person_id: string; balance_xaf: number; blocks: Record<string, boolean> }

export const billing = {
  feeKinds: (): Promise<FeeKind[]> => unwrap(http.get(`${H}/billing/fee-kinds`)),
  grids: (q: Q = {}): Promise<FeeGrid[]> => unwrap(http.get(`${H}/billing/fee-grids`, q)),
  createGrid: (b: { academic_year_id: string; school_id: string; program_id?: string | null; level_id?: string | null; study_mode?: 'day' | 'evening' | null; label: string; fees: GridFee[]; terms?: GridTerm[] | null; start_year?: number | null }): Promise<FeeGrid> =>
    unwrap(http.post(`${H}/billing/fee-grids`, b)),
  archiveGrid: (id: string): Promise<FeeGrid> => unwrap(http.post(`${H}/billing/fee-grids/${seg(id)}/archive`)),
  accounts: (q: Q = {}): Promise<StudentAccount[]> => unwrap(http.get(`${H}/billing/accounts`, q)),
  account: (id: string): Promise<StudentAccount> => unwrap(http.get(`${H}/billing/accounts/${seg(id)}`)),
  openAccount: (b: { person_id: string; academic_year_id: string; school_id: string; program_id?: string | null; level_id?: string | null; study_mode?: 'day' | 'evening' | null; enrolment_ref?: string | null; plan_id?: string | null; sponsor_id?: string | null }): Promise<unknown> =>
    unwrap(http.post(`${H}/billing/accounts`, b)),
  /** La clé d'idempotence est conservée par l'appelant entre deux tentatives du même encaissement (jamais deux reçus). */
  pay: (id: string, b: { amount_xaf: number; channel: PaymentChannel; payer_phone?: string | null; external_reference?: string | null }, key: string): Promise<PaymentResult> =>
    unwrap(http.post(`${H}/billing/accounts/${seg(id)}/payments`, b, { idempotencyKey: key })),
  scholarships: (accountId: string): Promise<Scholarship[]> => unwrap(http.get(`${H}/billing/accounts/${seg(accountId)}/scholarships`)),
  requestScholarship: (accountId: string, b: { scholarship_kind: ScholarshipKind; calc_mode?: 'percent' | 'fixed'; rate_value?: number; reason: string }): Promise<Scholarship> =>
    unwrap(http.post(`${H}/billing/accounts/${seg(accountId)}/scholarships`, b)),
  approveScholarship: (id: string): Promise<Scholarship> => unwrap(http.post(`${H}/billing/scholarships/${seg(id)}/approve`)),
  rejectScholarship: (id: string, note?: string): Promise<Scholarship> => unwrap(http.post(`${H}/billing/scholarships/${seg(id)}/reject`, note ? { note } : {})),
  requestPlan: (b: { person_id: string; academic_year_id: string; school_id: string; program_id?: string | null; terms: GridTerm[]; reason: string }): Promise<PaymentPlan> =>
    unwrap(http.post(`${H}/billing/payment-plans`, b)),
  approvePlan: (id: string): Promise<PaymentPlan> => unwrap(http.post(`${H}/billing/payment-plans/${seg(id)}/approve`)),
  rejectPlan: (id: string): Promise<PaymentPlan> => unwrap(http.post(`${H}/billing/payment-plans/${seg(id)}/reject`)),
  sponsors: (): Promise<Sponsor[]> => unwrap(http.get(`${H}/billing/sponsors`)),
  createSponsor: (b: { sponsor_kind: 'person' | 'organisation'; name: string; reference?: string; contact?: string }): Promise<Sponsor> => unwrap(http.post(`${H}/billing/sponsors`, b)),
  sponsor: (id: string): Promise<SponsorStatement> => unwrap(http.get(`${H}/billing/sponsors/${seg(id)}`)),
  holds: (personId: string): Promise<Holds> => unwrap(http.get(`${H}/billing/holds/${seg(personId)}`)),
};

// --- Documents (DOC) ---------------------------------------------------------------------------------------------------------
export interface DocType { code: string; prefix: string; label_fr: string; label_en: string; module: string; signer_role: string | null; qr: boolean; blockable: boolean; issued_by_socle: boolean; number_template: string | null; variables: string[] }
export interface SchoolProfile { school_id: string; version: number; school_code: string; display_name: string; header_text: string | null; logo_url: string | null; primary_color: string; default_locale: 'fr' | 'en'; created_at: string; versions?: SchoolProfile[] }

export const documents = {
  catalog: (): Promise<DocType[]> => unwrap(http.get(`${H}/documents/catalog`)),
  profile: (schoolId: string): Promise<SchoolProfile> => unwrap(http.get(`${H}/documents/schools/${seg(schoolId)}/profile`)),
  putProfile: (schoolId: string, b: { school_code: string; display_name: string; header_text?: string | null; logo_url?: string | null; primary_color?: string | null; default_locale?: 'fr' | 'en' | null }): Promise<SchoolProfile> =>
    unwrap(http.put(`${H}/documents/schools/${seg(schoolId)}/profile`, b)),
  issue: (b: { code: string; person_id: string; school_id: string; program_id?: string | null; level_id?: string | null; locale?: 'fr' | 'en' | null; data?: Record<string, string | number | boolean | null> }): Promise<IssuedDoc> =>
    unwrap(http.post(`${H}/documents/issue`, b, { idempotencyKey: ulid() })),
  list: (q: Q = {}): Promise<IssuedDoc[]> => unwrap(http.get(`${H}/documents`, q)),
  show: (id: string): Promise<IssuedDoc> => unwrap(http.get(`${H}/documents/${seg(id)}`)),
  cancel: (id: string, b: { reason: string; replaced_by?: string | null }): Promise<IssuedDoc> => unwrap(http.post(`${H}/documents/${seg(id)}/cancel`, b)),
  duplicate: (id: string): Promise<{ duplicate?: { mention: string; original_number: string; render_path: string } } & Partial<IssuedDoc>> => unwrap(http.post(`${H}/documents/${seg(id)}/duplicate`)),
};

// --- Portails étudiant et garant (POR) ---------------------------------------------------------------------------------------
export interface PortalFinance { account_id: string; academic_year_id: string; school_id: string; program_id: string | null; net_xaf: number; paid_xaf: number; balance_xaf: number; up_to_date: boolean; payer_name: string | null; installments: Installment[] }
export interface PortalEnrolment { id: string; establishment_id: string; structure_id: string; academic_year_id: string; status: string; matricule: string | null }
export interface PortalSection { available: boolean; reason?: string }
export interface StudentPortal {
  person_id: string; full_name: string; application: PortalSection; enrolments: PortalEnrolment[]; finance: PortalFinance[]; receipts: Receipt[]; documents: IssuedDoc[];
  document_requests: { path: string; always_available: string[] }; timetable: PortalSection;
}
export interface GuarantorStudent { person_id: string; full_name: string; enrolments: PortalEnrolment[]; finance: PortalFinance[]; receipts: Receipt[]; consent: boolean; attendance: PortalSection; grades: PortalSection }
export interface GuarantorPortal { guarantor_id: string; students: GuarantorStudent[] }

export const portal = {
  student: (): Promise<StudentPortal> => unwrap(http.get(`${H}/portal/student`)),
  guarantor: (): Promise<GuarantorPortal> => unwrap(http.get(`${H}/portal/guarantor`)),
  pay: (b: { student_id?: string | null; account_id: string; amount_xaf: number; channel: 'orange_money' | 'mtn_momo'; payer_phone: string }, key: string): Promise<Record<string, unknown>> =>
    unwrap(http.post(`${H}/portal/student/payments`, b, { idempotencyKey: key })),
};

/** Montant en FCFA saisi (« 150 000 », « 150000 ») : entier positif, sinon null. */
export function parseXaf(text: string | number | null | undefined): number | null {
  if (typeof text === 'number') return Number.isInteger(text) && text > 0 ? text : null;
  const clean = String(text ?? '').replace(/[\s\u00a0\u202f.]/g, '');
  if (!/^\d+$/.test(clean)) return null;
  const n = parseInt(clean, 10);
  return n > 0 ? n : null;
}

/** Numéro mobile camerounais ou international (8 à 15 chiffres, « + » facultatif), comme le serveur. */
export const PHONE = /^\+?[0-9]{8,15}$/;
