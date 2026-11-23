// Candidature en ligne (IPES) : page publique « Candidater » (sans compte) et espace du candidat connecté.
// Contrats : specs/001-socle-plateforme/contracts/openapi.d/higher-apply.yaml (public) et higher-admissions.yaml (connecté).
// Un lien inconnu, désactivé ou mal formé répond toujours 404 : l'écran ne distingue jamais ces cas.
import { http } from './http';
import type { ApplicationStatus, Diploma, Mention, PaymentChannel } from './higher';

const seg = (v: string): string => encodeURIComponent(v);
const unwrap = async <T>(p: Promise<{ data: T }>): Promise<T> => (await p).data;
const H = '/higher/admissions';

export interface RequiredDocument { code: string; label: string; blocking: boolean }
export interface OpenCampaign {
  id: string; title: string; school: string; program: string; level: string; opens_on: string; closes_on: string; seats: number;
  application_fee_xaf: number; selection_mode: 'file' | 'contest' | 'mixed'; required_documents: RequiredDocument[];
}
export interface PublicInstitute { institute: { name: string }; campaigns: OpenCampaign[] }
export interface RegistrationChallenge { channel: 'sms' | 'email'; masked_to: string; expires_in: number; resend_in: number }
export interface RegistrationInput { first_name: string; last_name: string; identifier: string; locale: 'fr' | 'en'; website?: string }

export type FollowDocumentStatus = 'missing' | 'uploaded' | 'verified' | 'rejected';
export interface FollowDocument {
  id: string; requirement_code: string; label: string; blocking: boolean; status: FollowDocumentStatus; rejection_reason: string | null;
  has_file: boolean; mime: string | null; size: number | null;
}
export interface FollowUp {
  id: string; reference: string; channel: string; status: ApplicationStatus;
  campaign: { id: string; title: string; closes_on: string; selection_mode: 'file' | 'contest' | 'mixed' };
  application_kind: 'new' | 'transfer'; origin_institution: string | null; diploma: Diploma; diploma_series: string | null; diploma_year: number | null; diploma_mention: Mention | null;
  submitted_at: string | null; completed_at: string | null;
  fee: { status: 'unpaid' | 'pending' | 'paid' | 'none'; amount_xaf: number; receipt_number: string | null };
  documents: FollowDocument[]; missing_documents: number;
  result: { decision: string; reason: string | null; conditional_until: string | null; waitlist_rank: number | null; decided_at: string | null } | null;
  convocation: { contest_date: string; center_name: string; room_name: string; seat_number: string; document_id: string | null } | null;
  created_at: string;
}
export interface LinkState { exists: boolean; enabled: boolean; slug: string | null; path: string | null }
export interface ApplicationDraftInput {
  campaign_id: string; application_kind: 'new' | 'transfer'; origin_institution?: string; diploma: Diploma; diploma_series?: string; diploma_year?: number; diploma_mention?: Mention;
}
export interface CreatedApplication { id: string; reference: string }

/** Taille maximale d'une pièce (octets) et types acceptés : le serveur relit le contenu réel, ceci n'évite que des allers-retours. */
export const MAX_FILE_BYTES = 5 * 1024 * 1024;
export const ACCEPTED_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

export const publicApply = {
  institute: (slug: string): Promise<PublicInstitute> => unwrap(http.get(`/public/admissions/${seg(slug)}`, undefined, { establishmentId: false })),
  register: (slug: string, b: RegistrationInput): Promise<RegistrationChallenge> => unwrap(http.post(`/public/admissions/${seg(slug)}/register`, b, { establishmentId: false })),
};

export const candidate = {
  openCampaigns: (): Promise<OpenCampaign[]> => unwrap(http.get(`${H}/open-campaigns`)),
  mine: (): Promise<FollowUp[]> => http.all<FollowUp>(`${H}/my-applications`),
  followUp: (id: string): Promise<FollowUp> => unwrap(http.get(`${H}/applications/${seg(id)}/follow-up`)),
  create: (b: ApplicationDraftInput): Promise<CreatedApplication> => unwrap(http.post(`${H}/applications`, b)),
  upload: (id: string, documentId: string, file: File): Promise<unknown> => {
    const form = new FormData();
    form.append('file', file);
    return http.post(`${H}/applications/${seg(id)}/documents/${seg(documentId)}/file`, undefined, { form });
  },
  download: (id: string, documentId: string): Promise<Blob> => http.get<Blob>(`${H}/applications/${seg(id)}/documents/${seg(documentId)}/file`, undefined, { expect: 'blob' }),
  submit: (id: string): Promise<unknown> => unwrap(http.post(`${H}/applications/${seg(id)}/submit`)),
  payFee: (id: string, b: { channel?: PaymentChannel; payer_phone?: string }, key: string): Promise<unknown> =>
    unwrap(http.post(`${H}/applications/${seg(id)}/fee-payment`, b, { idempotencyKey: key })),
  withdraw: (id: string, reason: string): Promise<unknown> => unwrap(http.post(`${H}/applications/${seg(id)}/withdraw`, { reason })),
};

export const applyLink = {
  get: (): Promise<LinkState> => unwrap(http.get(`${H}/public-link`)),
  put: (b: { enabled?: boolean; rotate?: boolean }): Promise<LinkState> => unwrap(http.put(`${H}/public-link`, b)),
};

/** Contrôle côté navigateur avant envoi (type déclaré et taille) ; null = acceptable. */
export function fileProblem(file: { type: string; size: number }): 'type' | 'size' | null {
  if (file.size > MAX_FILE_BYTES) return 'size';
  if (!ACCEPTED_TYPES.includes(file.type)) return 'type';
  return null;
}
