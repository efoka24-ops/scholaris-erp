import { http } from '@/api/http';

export interface PublicNurseryObservation {
  learner_id: string;
  structure_id: string;
  observer_id: string;
  frame_id: string;
  domain_id: string;
  item_id: string;
  level_code: 'acquired' | 'in_progress' | 'not_yet';
  remark?: string | null;
  observed_on?: string;
  client_uuid?: string;
}

export interface PublicNurseryLifeBookMediaDraft {
  content: string;
  mime_type: 'image/jpeg' | 'image/png';
  tagging_done: boolean;
  no_child_visible: boolean;
  child_ids: string[];
  masked_from_id?: string | null;
}

export interface PublicNurseryLifeBookDraft {
  structure_id: string;
  title: string;
  body?: string | null;
  activity_on: string;
  media?: PublicNurseryLifeBookMediaDraft[];
}

export interface PublicNurseryTransitionDecision {
  id: string;
  learner_id: string;
  school_year: string;
  from_level: 'ps' | 'ms' | 'gs';
  proposal: 'promote' | 'to_primary';
  decision: 'promote' | 'to_primary' | 'retain' | null;
  educator_opinion: string | null;
  status: string;
  parent_request_ref?: string | null;
  inspector_opinion?: string | null;
}

export interface PublicNurseryLiaisonSheet {
  learner_id: string;
  school_year: string;
  path_summary: string | null;
  strengths: string | null;
  needs: string | null;
  status: string;
  html: string;
}

export function createObservation(body: PublicNurseryObservation): Promise<{ data: Record<string, unknown> }> {
  return http.post('/public-nursery/sync/observations', body);
}

export function listLifeBooks(structureId?: string): Promise<{ data: Array<Record<string, unknown>> }> {
  return http.get('/public-nursery/development/lifebook', structureId ? { structure_id: structureId } : {});
}

export function createLifeBookDraft(body: PublicNurseryLifeBookDraft): Promise<{ data: Record<string, unknown> }> {
  return http.post('/public-nursery/development/lifebook', body);
}

export function publishLifeBook(id: string): Promise<{ data: Record<string, unknown> }> {
  return http.post(`/public-nursery/development/lifebook/${id}/publish`);
}

export function addLifeBookMedia(id: string, body: PublicNurseryLifeBookMediaDraft): Promise<{ data: Record<string, unknown> }> {
  return http.post(`/public-nursery/development/lifebook/${id}/media`, body);
}

export function listTransitionDecisions(schoolYear: string): Promise<{ data: PublicNurseryTransitionDecision[] }> {
  return http.get('/public-nursery/transition/decisions', { school_year: schoolYear });
}

export function proposeTransitionDecisions(body: { academic_year_id: string; next_year_id: string }): Promise<{ data: { status: string; summary: string; counts: Record<string, number> } }> {
  return http.post('/public-nursery/transition/decisions/propose', body);
}

export function decideTransition(id: string, body: Record<string, string | null>): Promise<{ data: PublicNurseryTransitionDecision }> {
  return http.post(`/public-nursery/transition/decisions/${id}/decide`, body);
}

export function loadLiaisonSheet(learnerId: string, schoolYear: string): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.get('/public-nursery/transition/liaison', { learner_id: learnerId, school_year: schoolYear });
}

export function createLiaisonSheet(body: { learner_id: string; school_year: string }): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.post('/public-nursery/transition/liaison', body);
}

export function signLiaisonSheet(body: { learner_id: string; school_year: string }): Promise<{ data: PublicNurseryLiaisonSheet }> {
  return http.post('/public-nursery/transition/liaison/sign', body);
}
// --- N-002 : contribution exigible, effectifs, porte (arrivées, maintien, registre, contrôle O7), carnet DOC-18 par HTTP ---
type Page<T> = { data: T[]; meta: { next_cursor: string | null; per_page: number } };

export interface ContributionQuota {
  code: string;
  label: string;
  percent: number;
}
export interface ContributionSplit {
  lines: (ContributionQuota & { amount_xaf: number })[];
  unallocated_xaf: number;
}
export interface ContributionReceipt {
  id: string;
  learner_id: string;
  learner_name: string | null;
  academic_year_id: string;
  amount_xaf: number;
  payer_name: string;
  paid_on: string;
  receipt_number: string;
  document_id: string;
  verification_path: string;
  split: ContributionSplit | null;
  status: 'valid' | 'cancelled';
  cancel_reason: string | null;
  cancelled_at: string | null;
  created_at: string;
}
export interface ContributionSummary {
  rules: { amount_xaf: number | null; installments: number | null; quotas: ContributionQuota[]; source: string | null };
  enrolled: number;
  paid: number;
  unpaid: number;
  expected_xaf: number | null;
  collected_xaf: number;
  receipts_valid: number;
  receipts_cancelled: number;
  split: ContributionSplit;
  quotas_configured: boolean;
}
export interface CapacityReport {
  max_children: number | null;
  over_capacity: number;
  classes: { section_id: string; name: string; enrolled: number; max_children: number | null; over_capacity: boolean; excess: number }[];
}
export interface GateRegisterRow {
  learner_id: string;
  name: string;
  section_id: string | null;
  status: 'not_arrived' | 'still_at_school' | 'kept' | 'left';
  arrived_at: string | null;
  left_at: string | null;
  left_to: string | null;
  kept: boolean;
  refusals: number;
  stale: boolean;
  alerts: string[];
}
export interface GateRegister {
  date: string;
  totals: Record<string, number>;
  children: GateRegisterRow[];
}
export interface PublicProgressBook {
  id: string;
  learner_id: string;
  structure_id: string;
  period_id: string;
  school_year: string;
  status: 'draft' | 'submitted' | 'validated' | 'published';
  synthesis: string;
  snapshot: { domain: string; label: string; level: string; comment: string }[];
  edition: number;
  edition_label: string | null;
}

const data = async <T>(promise: Promise<{ data: T }>): Promise<T> => (await promise).data;

export const contributions = {
  summary: () => data(http.get<{ data: ContributionSummary }>('/public-nursery/contributions/summary')),
  list: (query: Record<string, string> = {}) => http.get<Page<ContributionReceipt>>('/public-nursery/contributions', { per_page: 200, ...query }).then((r) => r.data),
  unpaid: () => http.get<Page<{ learner_id: string; name: string }>>('/public-nursery/contributions/unpaid', { per_page: 200 }).then((r) => r.data),
  collect: (body: { learner_id: string; payer_name: string; paid_on?: string; locale?: 'fr' | 'en' }) => data(http.post<{ data: ContributionReceipt }>('/public-nursery/contributions', body)),
  cancel: (id: string, reason: string) => data(http.post<{ data: ContributionReceipt }>(`/public-nursery/contributions/${id}/cancel`, { reason })),
  capacity: () => data(http.get<{ data: CapacityReport }>('/public-nursery/sections/capacity')),
};

export const gate = {
  arrival: (body: { learner_id: string; school_day?: string }) => data(http.post<{ data: Record<string, unknown> }>('/public-nursery/pickup/arrivals', body)),
  hold: (body: { learner_id: string; remark: string; school_day?: string }) => data(http.post<{ data: Record<string, unknown> }>('/public-nursery/pickup/holds', body)),
  register: (date: string) => data(http.get<{ data: GateRegister }>('/public-nursery/register', { date })),
  audit: (date: string) => data(http.get<{ data: { date: string; ok: boolean; anomalies: { event_id: string; learner_id: string; reason: string; occurred_at: string }[] } }>('/public-nursery/register/audit', { date })),
};

export const progressBooks = {
  get: (id: string) => data(http.get<{ data: PublicProgressBook }>(`/public-nursery/development/progress-books/${id}`)),
  saveDraft: (body: { learner_id: string; structure_id: string; period_id: string; school_year: string; synthesis: string; items: { domain: string; label: string; level: string; comment: string }[] }) =>
    data(http.post<{ data: PublicProgressBook }>('/public-nursery/development/progress-books', body)),
  validate: (id: string) => data(http.post<{ data: PublicProgressBook }>(`/public-nursery/development/progress-books/${id}/validate`)),
  publish: (id: string) => data(http.post<{ data: PublicProgressBook }>(`/public-nursery/development/progress-books/${id}/publish`)),
  reissue: (id: string, label: 'duplicate' | 'rectified') => data(http.post<{ data: PublicProgressBook }>(`/public-nursery/development/progress-books/${id}/reissue`, { edition_label: label })),
  pdf: (id: string, locale: 'fr' | 'en' = 'fr') => http.get<Blob>(`/public-nursery/development/progress-books/${id}/pdf`, { locale }, { expect: 'blob' }),
};
