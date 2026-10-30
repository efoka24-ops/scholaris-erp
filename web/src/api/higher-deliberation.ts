// Délibérations de l'application Supérieur (DEL) : /api/v1/higher/deliberation/** (contrat openapi.d/higher-deliberation.yaml).
// La séance elle-même passe par le moteur du socle : signature du PV (API-14, POST /deliberations/{id}/sign) et publication
// (API-15, POST /results/publish), gardées côté serveur (double authentification récente, RG-DEL-3, empreinte du PV).
import { ApiError, http } from './http';
import { documents, H, type IssuedDoc, type Q } from './higher';

const D = `${H}/deliberation`;
const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export type ExamSession = 'normal' | 'resit';
export type JuryStatus = 'prepared' | 'simulated' | 'in_session' | 'closed' | 'signed' | 'published' | 'reopened';
export type JuryDocCode = 'DOC-14' | 'DOC-15' | 'DOC-16';
export const JURY_DOCS: JuryDocCode[] = ['DOC-14', 'DOC-15', 'DOC-16'];
export const SEMESTERS = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'];

export interface JuryMember { person_id: string; name: string | null; member_role: 'president' | 'member' | string; present: boolean; signed: boolean }
export interface JuryPoint { person_id: string; name: string | null; points_requested: number; points_granted: number; reason: string; created_at: string }
export interface Jury {
  id: string; school_id: string; program_id: string; level_id: string; structure_id: string; academic_year_id: string; period_id: string;
  period_label: string; exam_session: ExamSession; regime: string; president_person_id: string; quorum_min: number; agenda: string | null;
  status: JuryStatus; deliberation_ref: string | null; minutes_fingerprint: string | null; minutes_version: number; signed_at: string | null;
  published_at: string | null; reopen_count: number; created_at: string; updated_at: string;
  members?: JuryMember[]; present_count?: number; quorum_reached?: boolean; jury_points?: JuryPoint[];
  signatures?: Array<{ person_id: string; member_role: string; fingerprint: string | null; signed_at: string }>;
  my_role?: string; my_signature?: boolean;
}
export interface Borderline { kind: string; unit: string | null; value: number | null; threshold: number; gap: number }
export interface SimStudent {
  person_id: string; name: string | null; matricule: string | null; average: number | null; raw_average?: number | null; jury_points?: number;
  gpa: number | null; credits_earned: number; credits_total: number; mention: string | null; proposal: string | null; proposal_code?: string | null;
  borderline: Borderline[]; rank?: number | null; units?: Array<Record<string, unknown> & { subject_id: string }>;
}
export interface Simulation {
  jury_id: string; rules: { strategy: string; version: number; options: Record<string, unknown> }; students: SimStudent[];
  summary: { students: number; by_proposal: Record<string, number>; borderline: number }; unlocked: boolean; margin: number;
}
export interface PointsResult { person_id: string; points_requested: number; points_granted: number; cap: number; capped: boolean }
export interface ResitElement { element_code: string; unit_code: string; students: Array<{ person_id: string; name: string | null; note: number | null; notified_at: string | null }> }
export interface DocLine { person_id: string; average: number | null; credits_earned: number; credits_total: number; proposal?: string | null; decision: string | null; reason: string | null }
export interface IssuedJuryDoc { id?: string; number?: string; verification_token?: string; code?: string; [k: string]: unknown }
export interface YearDecision {
  id?: string; person_id: string; matricule?: string | null; average: number | null; credits_earned: number; credits_total: number; cumulative_credits: number;
  decision_code: string; decision_label: string; mention: string | null; diploma_file: boolean; derogation: boolean;
  acquired_units: string[]; missing_units: string[]; decided_at?: string;
}
export interface TranscriptUnit { code: string; name: string; credits: number; average: number | null; letter: string; points: number | null; status: string; result_label: string; session_label: string }
export interface Transcript {
  jury_id: string; period_id: string; period_label: string; session_label: string; academic_year_id: string;
  program: { name: string | null; code: string | null }; level: { name: string | null; code: string | null };
  units: TranscriptUnit[];
  synthesis: { average: number | null; gpa: number | null; credits_earned: number; credits_total: number; decision: string | null; mention: string | null; rank: number | null; class_size: number | null };
}

export const deliberation = {
  juries: (q: Q = {}): Promise<Jury[]> => unwrap(http.get(`${D}/juries`, { per_page: 100, ...q })),
  jury: (id: string): Promise<Jury> => unwrap(http.get(`${D}/juries/${seg(id)}`)),
  createJury: (b: { structure_id: string; period_id: string; period_label?: string; academic_year_id: string; exam_session: ExamSession; president_person_id: string; members: string[]; quorum_min?: number; agenda?: string | null }): Promise<Jury> =>
    unwrap(http.post(`${D}/juries`, b)),
  attendance: (id: string, present: string[]): Promise<Jury> => unwrap(http.post(`${D}/juries/${seg(id)}/attendance`, { present })),
  simulate: (id: string, b: { overrides?: Record<string, number>; margin?: number } = {}): Promise<Simulation> => unwrap(http.post(`${D}/juries/${seg(id)}/simulate`, b)),
  points: (id: string, b: { person_id: string; points: number; reason: string }): Promise<PointsResult> => unwrap(http.post(`${D}/juries/${seg(id)}/points`, b)),
  resitList: (id: string): Promise<ResitElement[]> => unwrap(http.get(`${D}/juries/${seg(id)}/resit-list`)),
  convokeResit: (id: string, notify: boolean): Promise<{ elements: ResitElement[]; notified: number }> => unwrap(http.post(`${D}/juries/${seg(id)}/resit-list`, { notify })),
  issueDocument: (id: string, b: { code: JuryDocCode; lines: DocLine[]; locale?: 'fr' | 'en' }): Promise<IssuedJuryDoc> => unwrap(http.post(`${D}/juries/${seg(id)}/documents`, b)),
  portal: (): Promise<Jury[]> => unwrap(http.get(`${D}/jury-portal`)),
  yearDecisions: (q: Q = {}): Promise<YearDecision[]> => unwrap(http.get(`${D}/year-decisions`, { per_page: 100, ...q })),
  previewYear: (b: { structure_id: string; academic_year_id: string; period_ids: string[]; derogations?: string[] }): Promise<YearDecision[]> => unwrap(http.post(`${D}/year-decisions/preview`, b)),
  recordYear: (b: { structure_id: string; academic_year_id: string; period_ids: string[]; derogations?: string[] }): Promise<YearDecision[]> => unwrap(http.post(`${D}/year-decisions`, b)),
  transcript: (personId: string, periodId: string, locale: 'fr' | 'en' = 'fr'): Promise<Transcript> => unwrap(http.get(`${D}/transcripts`, { person_id: personId, period_id: periodId, locale })),
  issueTranscript: (b: { person_id: string; period_id: string; locale?: 'fr' | 'en' }): Promise<IssuedJuryDoc> => unwrap(http.post(`${D}/transcripts`, b)),
  issuedTranscripts: (q: Q = {}): Promise<IssuedDoc[]> => documents.list({ code: 'DOC-09', ...q }),
  myResults: (locale: 'fr' | 'en' = 'fr'): Promise<Transcript[]> => unwrap(http.get(`${D}/my-results`, { locale })),
  /** Séance du socle (décisions du jury, motifs) : alimente le PV et la liste des résultats une fois la séance tenue. */
  session: (deliberationId: string): Promise<{ id: string; status: string; lines?: Array<DocLine & { name?: string | null }> }> =>
    unwrap(http.get(`/deliberations/${seg(deliberationId)}`)),
  /** API-14 (socle) : signature du PV par un membre présent. */
  sign: (deliberationId: string): Promise<unknown> => unwrap(http.post(`/deliberations/${seg(deliberationId)}/sign`)),
  /** API-15 (socle) : publication des résultats du groupe, du semestre et de la session. */
  publish: (j: Pick<Jury, 'structure_id' | 'period_id' | 'period_label' | 'exam_session'>): Promise<unknown> =>
    unwrap(http.post('/results/publish', { structure_id: j.structure_id, period_id: j.period_id, period_label: j.period_label, exam_session: j.exam_session })),
};

/**
 * Double authentification récente exigée : 403 « second-factor-required » (ou variantes), ou 403 de la garde de délibération
 * dont le détail demande de confirmer la double authentification.
 */
export function needsSecondFactor(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 403) return false;
  if (['second-factor-required', 'two-factor-recent-required', 'two-factor-required'].includes(error.slug)) return true;
  return /double authentification|two-factor|second factor/i.test(error.problem?.detail ?? '');
}

/** Refus 409 RG-DEL-3 : PV non signé par le président (liste des résultats, relevés, publication, décisions annuelles). */
export function isUnsignedMinutes(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && /RG-DEL-3/.test(error.problem?.detail ?? '');
}
