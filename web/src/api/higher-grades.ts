// Notes de l'application Supérieur (NOT, lot I2) : /api/v1/higher/grade-* et /higher/grades/*.
// Contrat : specs/001-socle-plateforme/contracts/openapi.d/higher-grades.yaml. Cycle brouillon → soumise → validée →
// verrouillée (2FA récente) → publiée (2FA récente). Un lot de notes est atomique : 409 « version-conflict » = rien n'est écrit.
import { ApiError, http } from './http';
import { H, type Q } from './higher';

export type AssessmentKind = 'cc' | 'tp' | 'exam' | 'retake';
export type AssessmentStatus = 'draft' | 'submitted' | 'validated' | 'locked' | 'published';
export type CorrectionStatus = 'pending' | 'approved' | 'rejected';
export type QueryStatus = 'open' | 'accepted' | 'rejected';

export const ASSESSMENT_KINDS: AssessmentKind[] = ['cc', 'tp', 'exam', 'retake'];
export const ASSESSMENT_STATUSES: AssessmentStatus[] = ['draft', 'submitted', 'validated', 'locked', 'published'];
/** Poids par défaut (IpesDefaults::WEIGHTS) proposés à la création. */
export const DEFAULT_WEIGHTS: Record<AssessmentKind, number> = { cc: 40, tp: 40, exam: 60, retake: 60 };

const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export interface StatusStep { from?: string; to: string; by?: string; at?: string; reason?: string | null }

export interface Assessment {
  id: string; school_id: string; department_id: string; program_id: string; level_id: string; group_id: string | null;
  academic_year_id: string; curriculum_id: string; unit_id: string; element_id: string; element_code: string; element_title: string;
  semester_ordinal: number; kind: AssessmentKind; exam_session: string; label: string; held_on: string | null; entry_due_on: string | null;
  max_score: number; max_score_label: string; weight_percent: number; score_step: number; anonymous: boolean; anonymity_lifted: boolean;
  status: AssessmentStatus; status_trail: StatusStep[]; version: number; created_by: string; created_at: string; updated_at: string;
}

export interface AssessmentInput {
  curriculum_id: string; element_id: string; group_id?: string | null; kind: AssessmentKind; label?: string;
  held_on?: string | null; entry_due_on?: string | null; max_score?: number; weight_percent?: number; anonymous?: boolean;
}

export interface AbsenceCode { effect: 'zero' | 'excluded' | 'retake'; label: string; aliases: string[]; discipline?: boolean }

export interface GridLine {
  mark_id: string; person_id: string | null; matricule: string | null; last_name: string | null; first_name: string | null;
  group_id: string | null; anonymous_code: string | null; score: number | null; absence: string | null; version: number;
}

export interface Grid {
  assessment: Assessment; editable: boolean; next_states: AssessmentStatus[];
  absence_codes: Record<string, AbsenceCode>; lines: GridLine[]; entered: number; total: number;
}

export interface MarkEntry { person_id?: string; anonymous_code?: string; score?: number | string | null; absence?: string | null; expected_version?: number }
export interface EnterResult { written: number; unchanged: number; discipline_referrals: number; lines?: number }

/** Détail d'un 409 « version-conflict » : notes modifiées depuis la lecture (rien n'a été écrit). */
export interface MarkConflict {
  line: number; mark_id: string; expected_version: number; current_version: number;
  current: { score: number | null; absence: string | null };
}

export interface MarkValueRef { score: number | null; absence: string | null }
export interface MarkEvent {
  id: string; kind: string; old: MarkValueRef; new: MarkValueRef; version: number; actor_id: string;
  reason: string | null; correction_id: string | null; created_at: string;
}

export interface Correction {
  id: string; mark_id: string; assessment_id: string; element_code: string | null; assessment_label: string | null; person_id: string;
  old: MarkValueRef; new: MarkValueRef; reason: string; attachment_ref: string; query_id: string | null; requested_by: string;
  /** Pièce téléversée (null pour une ancienne demande à référence papier). */
  attachment?: { mime: string; size: number; download_path: string } | null;
  status: CorrectionStatus; approval_step: number; next_step: { label: string; role: string; require_recent_2fa: boolean } | null;
  steps: string[]; approval_trail: Array<{ step: number; label: string; decision: 'approved' | 'rejected'; by: string; at: string; reason?: string }>;
  decided_at: string | null; created_at: string;
}
export interface CorrectionInput { score?: number | null; absence?: string | null; reason: string; attachment_ref: string; query_id?: string | null }
/** Demande avec pièce téléversée (multipart, champ `attachment`). */
export interface CorrectionUploadInput { score?: number | null; absence?: string | null; reason: string; file: File | Blob; query_id?: string | null }

/** Pièce acceptée par le serveur (AttachmentStore du socle) : contrôle préalable côté écran, le serveur relit le type réel. */
export const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
export const ATTACHMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
export function attachmentProblem(file: { size: number; type: string } | null): 'missing' | 'size' | 'type' | null {
  if (!file) return 'missing';
  if (file.size === 0 || file.size > ATTACHMENT_MAX_BYTES) return 'size';
  return ATTACHMENT_TYPES.includes(file.type) ? null : 'type';
}

function correctionForm(body: CorrectionUploadInput): FormData {
  const form = new FormData();
  if (body.absence) form.append('absence', body.absence);
  else if (body.score !== null && body.score !== undefined) form.append('score', String(body.score));
  form.append('reason', body.reason);
  if (body.query_id) form.append('query_id', body.query_id);
  form.append('attachment', body.file);
  return form;
}

export interface GradeQuery {
  id: string; mark_id: string; assessment_id: string; element_code: string | null; assessment_label: string | null; person_id: string;
  message: string; status: QueryStatus; response: string | null; responded_by: string | null; responded_at: string | null; created_at: string;
}

export interface Distribution { from: number; to: number; count: number }
export interface StatSeries { count: number; mean: number | null; std_dev: number | null; min: number | null; max: number | null; pass_rate: number | null; distribution: Distribution[] }
export interface Statistics { statistics: StatSeries; groups: Array<StatSeries & { group_id: string | null }> }
export interface AssessmentStatistics extends Statistics { assessment: Assessment; absences: Record<string, number> }
export interface ElementStatistics extends Statistics { element_id: string; element_code: string; element_title: string; assessments: Assessment[]; students: number }

export type ProgressLine = Assessment & { entered: number; total: number; overdue: boolean };
export interface Progress { summary: { assessments: number; complete: number; overdue: number; by_status: Record<string, number> }; lines: ProgressLine[] }

export interface StudentMark {
  assessment_id: string; mark_id: string; kind: AssessmentKind; label: string; exam_session: string; max_score: number; weight_percent: number;
  score: number | null; absence: string | null; published_at: string | null; query_until: string | null; query_open: boolean;
}
export interface StudentElement {
  element_id: string; element_code: string; element_title: string; academic_year_id: string; semester_ordinal: number;
  assessments: StudentMark[]; ec_mark: number | null;
}
export interface StudentSemester {
  id: string; period_id: string; period_label: string; session: string; average: number | null; credits_earned: number | null;
  credits_total: number | null; mention: string | null; decision: string | null;
}
export interface StudentResults { person_id: string; elements: StudentElement[]; semesters: StudentSemester[] }

const A = `${H}/grade-assessments`;

export const grades = {
  settings: (q: Q = {}): Promise<Record<string, unknown>> => unwrap(http.get(`${H}/grades/settings`, q)),
  assessments: (q: Q = {}): Promise<Assessment[]> => unwrap(http.get(A, q)),
  assessment: (id: string): Promise<Assessment> => unwrap(http.get(`${A}/${seg(id)}`)),
  create: (body: AssessmentInput): Promise<Assessment> => unwrap(http.post(A, body)),
  update: (id: string, body: Partial<AssessmentInput>): Promise<Assessment> => unwrap(http.patch(`${A}/${seg(id)}`, body)),
  grid: (id: string): Promise<Grid> => unwrap(http.get(`${A}/${seg(id)}/marks`)),
  enter: (id: string, marks: MarkEntry[]): Promise<EnterResult> => unwrap(http.put(`${A}/${seg(id)}/marks`, { marks })),
  importCsv: (id: string, content: string): Promise<EnterResult> => unwrap(http.post(`${A}/${seg(id)}/marks/import`, { content })),
  template: (id: string): Promise<string> => http.get<string>(`${A}/${seg(id)}/marks/template`, undefined, { expect: 'text' }),
  submit: (id: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/submit`)),
  sendBack: (id: string, reason: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/send-back`, { reason })),
  validate: (id: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/validate`)),
  lock: (id: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/lock`)),
  publish: (id: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/publish`)),
  liftAnonymity: (id: string): Promise<Assessment> => unwrap(http.post(`${A}/${seg(id)}/anonymity/lift`)),
  assessmentStatistics: (id: string): Promise<AssessmentStatistics> => unwrap(http.get(`${A}/${seg(id)}/statistics`)),
  elementStatistics: (elementId: string, groupId?: string): Promise<ElementStatistics> =>
    unwrap(http.get(`${H}/grades/elements/${seg(elementId)}/statistics`, groupId ? { group_id: groupId } : {})),
  progress: (q: Q = {}): Promise<Progress> => unwrap(http.get(`${H}/grades/progress`, q)),
  markEvents: (markId: string): Promise<MarkEvent[]> => unwrap(http.get(`${H}/grade-marks/${seg(markId)}/events`)),
  requestCorrection: (markId: string, body: CorrectionInput): Promise<Correction> => unwrap(http.post(`${H}/grade-marks/${seg(markId)}/changes`, body)),
  requestCorrectionWithFile: (markId: string, body: CorrectionUploadInput): Promise<Correction> =>
    unwrap(http.post(`${H}/grade-marks/${seg(markId)}/changes`, undefined, { form: correctionForm(body) })),
  correctionAttachment: (id: string): Promise<Blob> => http.get<Blob>(`${H}/grade-corrections/${seg(id)}/attachment`, undefined, { expect: 'blob' }),
  corrections: (q: Q = {}): Promise<Correction[]> => unwrap(http.get(`${H}/grade-corrections`, q)),
  correction: (id: string): Promise<Correction> => unwrap(http.get(`${H}/grade-corrections/${seg(id)}`)),
  approveCorrection: (id: string): Promise<Correction> => unwrap(http.post(`${H}/grade-corrections/${seg(id)}/approve`)),
  rejectCorrection: (id: string, reason: string): Promise<Correction> => unwrap(http.post(`${H}/grade-corrections/${seg(id)}/reject`, { reason })),
  queries: (q: Q = {}): Promise<GradeQuery[]> => unwrap(http.get(`${H}/grade-queries`, q)),
  respondQuery: (id: string, decision: 'accepted' | 'rejected', response: string): Promise<GradeQuery> =>
    unwrap(http.post(`${H}/grade-queries/${seg(id)}/respond`, { decision, response })),
  myResults: (): Promise<StudentResults> => unwrap(http.get(`${H}/students/me/results`)),
  myQueries: (): Promise<GradeQuery[]> => unwrap(http.get(`${H}/students/me/grade-queries`)),
  createQuery: (markId: string, message: string): Promise<GradeQuery> => unwrap(http.post(`${H}/students/me/grade-queries`, { mark_id: markId, message })),
};

/** Notes en conflit d'un 409 « version-conflict » (champ errors.conflicts : liste JSON sérialisée), sinon null. */
export function markConflicts(error: unknown): MarkConflict[] | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  const raw = error.fieldErrors.conflicts;
  if (!raw || raw.length === 0) return null;
  const out: MarkConflict[] = [];
  for (const chunk of raw) {
    try {
      const parsed: unknown = JSON.parse(chunk);
      if (Array.isArray(parsed)) out.push(...(parsed as MarkConflict[]));
    } catch {
      /* détail illisible : le 409 reste affiché par ErrorNotice */
    }
  }
  return out;
}

/** 409 à la publication : procès-verbal de délibération non signé (RG-DEL-3). */
export function isMinutesUnsigned(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && /RG-DEL-3|proc[eè]s-verbal/i.test(`${error.problem?.detail ?? ''} ${error.message}`);
}
