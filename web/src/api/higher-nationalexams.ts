// Examens nationaux BTS/HND (EXN) et tutelle (TUT) de l'application Supérieur : /api/v1/higher/national-* et /higher/tutelage/*.
// Contrats : openapi.d/higher-nationalexams.yaml et higher-tutelage.yaml. Export de la liste (API-17) : 2FA récente (403
// « second-factor-required »), filigrane nominatif, alerte au directeur général. Dossier de diplôme : 409 si PV final non signé
// ou quitus absent (RG-TUT-2). Ajout d'un étudiant hors année de sortie : 422 RG-EXN-1.
import { ApiError, http } from './http';
import { H, type Q } from './higher';

export type ExamType = 'bts' | 'hnd';
export type SessionStatus = 'open' | 'exported' | 'closed';
export type CandidateStatus = 'eligible' | 'complete' | 'exported' | 'convened' | 'resulted' | 'withdrawn';
export type ExamResult = 'passed' | 'failed' | 'absent';
export type TransmissionKind = 'lists' | 'marks' | 'transcripts' | 'minutes';
export type DiplomaFileStatus = 'transmitted' | 'in_progress' | 'available' | 'withdrawn';

export const EXAM_TYPES: ExamType[] = ['bts', 'hnd'];
export const CANDIDATE_STATUSES: CandidateStatus[] = ['eligible', 'complete', 'exported', 'convened', 'resulted', 'withdrawn'];
export const TRANSMISSION_KINDS: TransmissionKind[] = ['lists', 'marks', 'transcripts', 'minutes'];
/** Suite permise du dossier de diplôme (TransmissionRules::FILE_FLOW). */
export const DIPLOMA_NEXT: Record<DiplomaFileStatus, DiplomaFileStatus[]> = {
  transmitted: ['in_progress', 'available'], in_progress: ['available'], available: ['withdrawn'], withdrawn: [],
};

const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;

export interface NationalSession {
  id: string; school_id: string; department_id: string; program_id: string; level_id: string; academic_year_id: string; exam_type: ExamType;
  specialty_label: string; calendar_ref: string | null; registration_deadline: string | null; exam_starts_on: string | null;
  attendance_from: string; attendance_to: string; fee_xaf: number; required_pieces: string[]; status: SessionStatus; export_count: number;
  last_exported_at: string | null; last_exported_by: string | null; export_fingerprint: string | null; created_at: string; updated_at: string;
}

export interface SessionInput {
  program_id: string; academic_year_id: string; exam_type: ExamType; attendance_from: string; attendance_to: string;
  specialty_label?: string; calendar_ref?: string; registration_deadline?: string; exam_starts_on?: string; fee_xaf?: number; required_pieces?: string[];
}

export interface Candidate {
  id: string; session_id: string; enrolment_id: string; person_id: string; matricule: string | null; school_id: string; program_id: string;
  level_id: string; group_id: string | null; status: CandidateStatus; presence_percent: number; attendance_ok: boolean; fee_receipt: string | null;
  pieces_received: string[]; candidate_number: string | null; exam_centre: string | null; convened_at: string | null; result_code: ExamResult | null;
  result_mark: number | null; result_imported_at: string | null; withdrawn_reason: string | null; created_at: string; updated_at: string;
}

export interface CandidateChanges { pieces_received?: string[]; fee_receipt?: string | null; exam_centre?: string | null; candidate_number?: string | null }

export interface ExportedFile { filename: string; format: string; content_type: string; rows: number; columns: string[]; content_base64: string }
export interface ExportResult { session_id: string; watermark: string; fingerprint: string; file: ExportedFile }
export interface ImportResult { imported: number; rejected: Array<{ line: number; key: string; reason: string }> }
export interface SessionStatistics {
  session_id: string; exam_type: ExamType; specialty_label: string; program_id: string; academic_year_id: string;
  counts: Record<string, number>; success_rate: number | null;
}
export interface ListEvent { id: string; session_id: string; candidate_id: string | null; event_kind: string; after_export: boolean; detail: Record<string, unknown>; actor_id: string; alerted_count: number; created_at: string }
export interface Eligibility { person_id: string; rg_exn_1: boolean; enrolment_id: string | null; reasons: string[]; attendance: { presence_percent: number; threshold_percent: number; attendance_ok: boolean } | null }

export interface Convention {
  tutelage_id: string; program_id: string; program_name: string; academic_year_id: string; university_name: string; convention_reference: string | null;
  convention_ends_on: string | null; approved_programme_ref: string | null; days_left: number | null; expiring: boolean; expired: boolean;
}
export interface Transmission {
  id: string; school_id: string; program_id: string; level_id: string | null; academic_year_id: string; period_id: string | null; tutelage_id: string;
  university_name: string; transmission_kind: TransmissionKind; status: 'sent' | 'acknowledged' | 'rejected'; items_count: number; fingerprint: string;
  file_name: string | null; note: string | null; sent_by: string; sent_at: string; answered_at: string | null; answered_by: string | null;
}
export interface TransmissionInput { kind: TransmissionKind; program_id: string; academic_year_id: string; level_id?: string; period_id?: string; format?: 'csv' | 'xlsx'; note?: string }
export interface DiplomaFile {
  id: string; school_id: string; program_id: string; level_id: string; academic_year_id: string; person_id: string; enrolment_id: string; tutelage_id: string;
  university_name: string; decision_code: string; status: DiplomaFileStatus; status_note: string | null; transmitted_at: string; status_changed_at: string | null; created_by: string;
}

export interface PortalRecord {
  matricule: string | null; last_name: string | null; first_names: string | null; gender: string | null; program_code: string; program_name: string;
  level_code: string | null; academic_year: string; enrolment_status: string; attempt_number: number; university_name: string;
}
export interface Consultation { id: string; user_id: string; program_id: string; academic_year_id: string; resource_kind: string; rows_count: number; created_at: string }
const S = `${H}/national-sessions`;
const C = `${H}/national-candidates`;
const T = `${H}/tutelage`;

export const nationalExams = {
  sessions: (q: Q = {}): Promise<NationalSession[]> => unwrap(http.get(S, q)),
  session: (id: string): Promise<NationalSession> => http.get(`${S}/${seg(id)}`),
  create: (body: SessionInput): Promise<NationalSession> => http.post(S, body),
  close: (id: string): Promise<NationalSession> => http.patch(`${S}/${seg(id)}`, { status: 'closed' }),
  candidates: (id: string, q: Q = {}): Promise<Candidate[]> => unwrap(http.get(`${S}/${seg(id)}/candidates`, q)),
  add: (id: string, personId: string): Promise<Candidate> => http.post(`${S}/${seg(id)}/candidates`, { person_id: personId }),
  generate: (id: string): Promise<{ added: number; already_listed: number }> => http.post(`${S}/${seg(id)}/candidates/generate`),
  exportList: (id: string, format: 'csv' | 'xlsx'): Promise<ExportResult> => http.post(`${S}/${seg(id)}/candidates/export`, { format }),
  convene: (id: string, examCentre: string, numberPrefix: string): Promise<{ convened: number }> =>
    http.post(`${S}/${seg(id)}/convocations`, { exam_centre: examCentre, number_prefix: numberPrefix || undefined }),
  importResults: (id: string, csv: string): Promise<ImportResult> => http.post(`${S}/${seg(id)}/results/import`, { csv }),
  statistics: (id: string): Promise<SessionStatistics> => http.get(`${S}/${seg(id)}/statistics`),
  events: (id: string): Promise<ListEvent[]> => unwrap(http.get(`${S}/${seg(id)}/events`)),
  eligibility: (id: string, personId: string): Promise<Eligibility> => http.get(`${S}/${seg(id)}/eligibility`, { person_id: personId }),
  successRates: (q: Q = {}): Promise<SessionStatistics[]> => unwrap(http.get(`${H}/national-exams/success-rates`, q)),
  updateCandidate: (id: string, body: CandidateChanges): Promise<Candidate> => http.patch(`${C}/${seg(id)}`, body),
  withdraw: (id: string, reason: string): Promise<Candidate> => http.post(`${C}/${seg(id)}/withdraw`, { reason }),
  issueDocument: (id: string, code: 'DOC-12' | 'DOC-17'): Promise<Record<string, unknown>> => http.post(`${C}/${seg(id)}/documents`, { code }),
};

export const tutelage = {
  convention: (programId: string, academicYearId: string): Promise<Convention> => http.get(`${T}/convention`, { program_id: programId, academic_year_id: academicYearId }),
  transmissions: (q: Q = {}): Promise<Transmission[]> => unwrap(http.get(`${T}/transmissions`, q)),
  transmit: (body: TransmissionInput): Promise<{ transmission: Transmission; file?: ExportedFile }> => http.post(`${T}/transmissions`, body),
  answer: (id: string, status: 'acknowledged' | 'rejected', note?: string): Promise<Transmission> => http.post(`${T}/transmissions/${seg(id)}/answer`, { status, note }),
  diplomaFiles: (q: Q = {}): Promise<DiplomaFile[]> => unwrap(http.get(`${T}/diploma-files`, q)),
  transmitDiplomaFile: (personId: string, programId: string, academicYearId: string): Promise<DiplomaFile> =>
    http.post(`${T}/diploma-files`, { person_id: personId, program_id: programId, academic_year_id: academicYearId }),
  moveDiplomaFile: (id: string, status: DiplomaFileStatus, note?: string): Promise<DiplomaFile> => http.patch(`${T}/diploma-files/${seg(id)}`, { status, note }),
  portalEnrolments: (programId: string, academicYearId: string): Promise<{ convention: Convention; data: PortalRecord[] }> =>
    http.get(`${T}/portal/enrolments`, { program_id: programId, academic_year_id: academicYearId }),
  consultations: (q: Q = {}): Promise<Consultation[]> => unwrap(http.get(`${T}/consultations`, q)),
};

/** 422 RG-EXN-1 : étudiant non inscrit en année de sortie de la spécialité (TST-09). */
export function isNotEligible(error: unknown): boolean {
  return error instanceof ApiError && error.status === 422 && /RG-EXN-1/.test(`${error.problem?.detail ?? ''} ${error.message}`);
}

/** 409 RG-TUT-2 : raisons du refus du dossier de diplôme (errors.person_id), sinon null. */
export function diplomaRefusals(error: unknown): string[] | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  return error.fieldErrors.person_id ?? [error.problem?.detail ?? error.message];
}

/** Propose le fichier exporté au téléchargement (navigateur seulement). */
export function saveExportedFile(file: ExportedFile): void {
  if (typeof URL.createObjectURL !== 'function') return;
  const bytes = Uint8Array.from(atob(file.content_base64), (c) => c.charCodeAt(0));
  const url = URL.createObjectURL(new Blob([bytes], { type: file.content_type }));
  const link = document.createElement('a');
  link.href = url;
  link.download = file.filename;
  link.click();
  URL.revokeObjectURL(url);
}
