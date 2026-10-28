// API de l'application Maternelle privée (préfixe /api/v1/nursery). Contrats : specs/001-socle-plateforme/contracts/openapi.d/nursery-*.yaml.
// L'en-tête X-Establishment-Id est posé par le client HTTP (établissement actif). Aucune règle de sortie, de seuil ni de note n'est codée ici.
import { http, type QueryValue } from '@/api/http';

const ROOT = '/nursery';

const get = async <T>(path: string, query?: Record<string, QueryValue>): Promise<T> => (await http.get<{ data: T }>(`${ROOT}${path}`, query)).data;
const post = async <T>(path: string, body?: unknown): Promise<T> => (await http.post<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const put = async <T>(path: string, body?: unknown): Promise<T> => (await http.put<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const patch = async <T>(path: string, body?: unknown): Promise<T> => (await http.patch<{ data: T }>(`${ROOT}${path}`, body ?? {})).data;
const all = <T>(path: string, query?: Record<string, QueryValue>): Promise<T[]> => http.all<T>(`${ROOT}${path}`, query ?? {});

export interface Section {
  id: string;
  label: string;
  capacity: number | null;
  enrolled: number;
  sort_order: number;
  subsystem: 'fr' | 'en' | 'both';
}
export interface Child {
  id: string;
  first_name: string;
  last_name: string;
  full_name: string;
  gender: string | null;
  birth_date: string | null;
  age_months: number | null;
  section_id: string;
  section_label: string;
  enrollment_id?: string;
  guardians?: { id: string; full_name: string; phone: string | null }[];
  allergies?: string | null;
}
export interface EnrolInput {
  first_name: string;
  last_name: string;
  birth_date: string;
  gender?: 'male' | 'female';
  section_id: string;
  allergies?: string;
  photo_consent?: boolean;
  guardians: { kind: string; identity: { first_name: string; last_name: string; phone?: string } }[];
}
export interface EnrolResult {
  child_id: string;
  enrollment_id: string;
  section_id: string;
  warnings: { code: string; detail: string }[];
}
export interface Authorized {
  id: string;
  learner_id: string;
  name: string;
  relationship: string;
  phone: string | null;
  has_photo: boolean;
  grant_type: 'permanent' | 'day';
  valid_on: string | null;
  status: string;
}
export interface GateEvent {
  id: string;
  learner_id: string;
  event_type: string;
  pickup_id: string | null;
  stale_authorization: boolean;
  occurred_at: string;
}
export interface HandoverAlerts {
  sent: number;
  queued: number;
  blocked: number;
  failed: number;
}
export interface HandoverResult {
  status: 'handed_over' | 'refused';
  reason: string;
  stale_authorization: boolean;
  duplicate: boolean;
  event: GateEvent;
  alerts: HandoverAlerts | null;
}
export interface RollRow {
  child_id: string;
  full_name: string;
  status: 'expected' | 'present' | 'absent';
  arrived_at: string | null;
}
export interface RegisterChild {
  id: string;
  name: string;
  status: 'expected' | 'absent' | 'still_at_school' | 'left';
  arrived_at: string | null;
  left_at: string | null;
  left_to: string | null;
  kept: boolean;
  stale: boolean;
  refusals: number;
  alerts: string[];
}
export interface Register {
  date: string;
  totals: Record<string, number>;
  sections: { id: string; label: string; children: RegisterChild[] }[];
}
export interface PickupPackage {
  section_id: string;
  section_label: string;
  date: string;
  generated_at: string;
  max_age_days: number | null;
  read_days: number | null;
  direction: { name: string; phone: string }[];
  children: { id: string; name: string; absent: boolean; authorized: Authorized[]; restrictions: { restricted_pickup_id: string | null; restricted_person_id: string | null; restricted_name: string }[] }[];
}
export interface Restriction {
  id: string;
  learner_id: string;
  restricted_name: string;
  nature: string;
  decision_reference: string;
  valid_from: string;
  valid_to: string | null;
  status: string;
}
export interface FrameItem {
  id: string;
  code: string;
  label_fr: string;
  label_en: string;
  active: boolean;
}
export interface FrameDomain {
  id: string;
  code: string;
  label_fr: string;
  label_en: string;
  items: FrameItem[];
}
export interface Frame {
  id: string;
  scope: 'platform' | 'tenant';
  origin: string;
  version: number;
  label_fr: string;
  label_en: string;
  status: string;
  to_validate: boolean;
  domains?: FrameDomain[];
}
export interface Observation {
  id: string;
  learner_id: string;
  item_id: string;
  level_code: string;
  remark: string | null;
  observed_on: string;
}
export interface Gap {
  learner_id: string;
  full_name: string;
  domain_id: string;
  domain_label_fr: string;
  domain_label_en: string;
  observations: number;
  required: number;
}
export interface ProgressBook {
  id: string;
  learner_id: string;
  section_id: string;
  period_id: string;
  status: 'draft' | 'submitted' | 'validated' | 'published';
  synthesis: string;
  domains: Record<string, string>;
  observations: { item_id: string; domain_id: string; level_code: string; observed_on: string; remark: string | null }[];
  published_at: string | null;
}
export interface LifeEntry {
  id: string;
  section_id: string;
  title: string;
  body: string | null;
  activity_on: string;
  status: string;
  media: { id: string; mime_type: string; status: string; no_child_visible: boolean; children: string[] }[];
}
export interface ParentLifeEntry {
  id: string;
  title: string;
  body: string | null;
  activity_on: string;
  media: { id: string; mime_type: string }[];
  withheld_media: number;
}
export interface Need {
  id: string;
  learner_id: string;
  need_area: string;
  summary: string;
  status: 'open' | 'oriented' | 'closed';
  orientation_note: string | null;
  reported_at: string;
}
export interface Invoice {
  id: string;
  status: string;
  gross_xaf: number;
  discount_xaf: number;
  net_xaf: number;
  balance_xaf: number;
  due_at_enrolment_xaf: number;
}
export interface Balance {
  learner_id: string;
  balance_xaf: number;
  in_arrears: boolean;
}
export interface OutgoingMessage {
  id: string;
  subject: string;
  body: string;
  recipients: number;
  skipped: number;
  created_at: string;
}
export interface FamilyChild {
  id: string;
  full_name: string;
  section_id: string;
  section_label: string;
}

export interface IssuedDocument {
  id: string;
  kind: 'progress_book' | 'pickup_card' | 'exit_register' | 'liaison_sheet';
  source_id: string;
  learner_id: string | null;
  document_id: string;
  number: string;
  verification_path: string;
  locale: 'fr' | 'en';
  status: 'valid' | 'revoked';
  issued_at: string;
  revoked_at: string | null;
  revoke_reason: string | null;
  already_issued?: boolean;
}
export interface PhotoConsent {
  learner_id: string;
  active: boolean;
  statement_version: string | null;
  history: { id: string; channel: 'paper' | 'portal'; evidence_ref: string | null; signed_on: string; signed_by_name: string; statement_version: string | null; granted_at: string; status: 'active' | 'revoked'; revoked_at: string | null; revoke_reason: string | null }[];
}
export interface TransitionDecision {
  id: string;
  learner_id: string;
  child_name: string | null;
  academic_year_id: string;
  from_section_id: string;
  target_section_id: string | null;
  proposal: 'promote' | 'to_primary';
  decision: 'promote' | 'to_primary' | 'retain' | null;
  educator_opinion: string | null;
  decision_reason: string | null;
  parent_request_ref: string | null;
  status: 'proposed' | 'opined' | 'decided' | 'applied';
}
export interface LiaisonSheet {
  id: string;
  learner_id: string;
  academic_year_id: string;
  section_id: string;
  path_summary: string | null;
  strengths: string | null;
  needs: string | null;
  destination: string | null;
  status: 'draft' | 'signed';
  document_id: string | null;
  signed_at: string | null;
}
export interface ServicePlan {
  id: string;
  kind: 'daycare' | 'canteen' | 'transport';
  label: string;
  fee_item_id: string;
  billing_cycle: 'month' | 'term' | 'year';
  active: boolean;
}
export interface ServiceSubscription {
  id: string;
  learner_id: string;
  plan_id: string;
  plan_label: string | null;
  kind: string | null;
  billing_cycle: string | null;
  starts_on: string;
  ends_on: string | null;
  status: 'active' | 'ended';
}
export interface ServiceInvoice {
  id: string;
  subscription_id: string;
  cycle: string;
  invoice_id: string;
  amount_xaf: number;
  already_invoiced?: boolean;
}
export interface ServiceRoster {
  plan: ServicePlan;
  date: string;
  present: number;
  children: { learner_id: string; name: string | null; section_id: string; present: boolean | null }[];
}
export interface Dashboard {
  date: string;
  totals: { enrolled: number; absent: number; arrived: number; in_arrears: number; balance_xaf: number; observation_gaps: number; over_capacity_sections: number };
  sections: { id: string; label: string; enrolled: number; capacity: number | null; absent: number; arrived: number; in_arrears: number; observation_gaps: number; over_capacity: boolean }[];
  late_pickups: { today: { parent: number; director: number }; month: { parent: number; director: number } };
  services_present_today: number;
}

export const nursery = {
  settings: () => get<Record<string, unknown>>('/settings'),
  sections: () => all<Section>('/sections'),
  createSection: (body: { label: string; capacity?: number; subsystem?: string }) => post<Section>('/sections', body),
  updateSection: (id: string, body: Partial<{ label: string; capacity: number | null; sort_order: number; subsystem: string }>) => patch<Section>(`/sections/${id}`, body),
  children: (sectionId?: string) => all<Child>('/children', sectionId ? { section_id: sectionId } : {}),
  child: (id: string) => get<Child>(`/children/${id}`),
  enrol: (body: EnrolInput) => post<EnrolResult>('/enrolments', body),
  invoiceEnrolment: (enrollmentId: string) => post<Invoice>(`/enrolments/${enrollmentId}/invoice`),
  balance: (childId: string) => get<Balance>(`/children/${childId}/balance`),
  roll: (sectionId: string, date?: string) => all<RollRow>(`/sections/${sectionId}/roll`, date ? { date } : {}),
  call: (sectionId: string, body: { day_part: string; client_uuid: string; validate?: boolean; exceptions?: { child_id: string; status: 'A' | 'R' }[] }) =>
    post<{ present: number; late: number; absent: number; status: string }>(`/sections/${sectionId}/call`, body),
  arrival: (childId: string) => post<{ recorded: boolean }>(`/children/${childId}/arrival`),
  authorized: (learnerId: string, date?: string) => all<Authorized>('/pickup/authorized-persons', { learner_id: learnerId, ...(date ? { date } : {}) }),
  addAuthorized: (body: { learner_id: string; name: string; relationship: string; phone?: string; grant_type: string; valid_on?: string }) => post<Authorized>('/pickup/authorized-persons', body),
  revokeAuthorized: (id: string) => post<Authorized>(`/pickup/authorized-persons/${id}/revoke`),
  pendingRequests: () => all<{ id: string; learner_id: string; name: string; relationship: string; grant_type: string }>('/pickup/requests'),
  decideRequest: (id: string, approve: boolean) => post<unknown>(`/pickup/requests/${id}/${approve ? 'approve' : 'reject'}`),
  restrictions: (learnerId?: string) => all<Restriction>('/pickup/restrictions', learnerId ? { learner_id: learnerId } : {}),
  addRestriction: (body: { learner_id: string; restricted_name: string; nature: string; decision_reference: string; valid_from: string; valid_to?: string }) => post<Restriction>('/pickup/restrictions', body),
  liftRestriction: (id: string, reason: string) => post<Restriction>(`/pickup/restrictions/${id}/lift`, { reason }),
  handover: (body: Record<string, unknown>) => post<HandoverResult>('/pickup/handovers', body),
  refusal: (body: Record<string, unknown>) => post<HandoverResult>('/pickup/refusals', body),
  hold: (body: Record<string, unknown>) => post<GateEvent>('/pickup/holds', body),
  issueCode: (body: { learner_id: string; pickup_id: string; valid_on?: string }) => post<{ code: string; valid_on: string }>('/pickup/codes', body),
  pickupPackage: (sectionId: string, date?: string) => get<PickupPackage>(`/sections/${sectionId}/pickup-package`, date ? { date } : {}),
  register: (date?: string) => get<Register>('/register', date ? { date } : {}),
  frames: () => all<Frame>('/frames'),
  frame: (id: string) => get<Frame>(`/frames/${id}`),
  copyFrame: (id: string) => post<Frame>(`/frames/${id}/copy`),
  activateFrame: (id: string) => post<Frame>(`/frames/${id}/activate`),
  observe: (body: { learner_id: string; item_id: string; level_code: string; remark?: string; client_uuid?: string }) => post<Observation>('/observations', body),
  coverage: (sectionId: string, month?: string) => all<Gap>('/observations/coverage', { section_id: sectionId, ...(month ? { month } : {}) }),
  books: (query: Record<string, QueryValue> = {}) => all<ProgressBook>('/progress-books', query),
  createBook: (learnerId: string) => post<ProgressBook>('/progress-books', { learner_id: learnerId }),
  writeBook: (id: string, body: { synthesis?: string; domains?: Record<string, string> }) => put<ProgressBook>(`/progress-books/${id}`, body),
  bookAction: (id: string, action: 'submit' | 'validate' | 'return' | 'publish') => post<ProgressBook>(`/progress-books/${id}/${action}`),
  lifebook: (sectionId?: string) => all<LifeEntry>('/lifebook/entries', sectionId ? { section_id: sectionId } : {}),
  createEntry: (body: { section_id: string; title: string; body?: string }) => post<LifeEntry>('/lifebook/entries', body),
  addMedia: (entryId: string, body: { content_base64: string; children?: string[]; no_child_visible?: boolean }) => post<LifeEntry>(`/lifebook/entries/${entryId}/media`, body),
  tagMedia: (mediaId: string, body: { children?: string[]; no_child_visible?: boolean }) => post<LifeEntry>(`/lifebook/media/${mediaId}/tag`, body),
  entryAction: (id: string, action: 'publish' | 'withdraw') => post<LifeEntry>(`/lifebook/entries/${id}/${action}`),
  needs: (query: Record<string, QueryValue> = {}) => all<Need>('/needs', query),
  reportNeed: (body: { learner_id: string; need_area: string; summary: string }) => post<Need>('/needs', body),
  orientNeed: (id: string, note: string) => post<Need>(`/needs/${id}/orient`, { orientation_note: note }),
  closeNeed: (id: string) => post<Need>(`/needs/${id}/close`),
  // Documents (module Documents du socle : numéro, QR, contenu figé)
  documents: (query: Record<string, QueryValue> = {}) => all<IssuedDocument>('/documents', query),
  bookDocument: (bookId: string, locale: 'fr' | 'en' = 'fr') => post<IssuedDocument>(`/progress-books/${bookId}/document`, { locale }),
  pickupCard: (pickupId: string, locale: 'fr' | 'en' = 'fr') => post<IssuedDocument>(`/pickup/authorized-persons/${pickupId}/card`, { locale }),
  registerDocument: (date: string, locale: 'fr' | 'en' = 'fr') => post<IssuedDocument>('/register/document', { date, locale }),
  // Consentement photo
  photoConsent: (childId: string) => get<PhotoConsent>(`/children/${childId}/photo-consent`),
  recordPhotoConsent: (childId: string, body: { evidence_ref: string; signed_on: string; signed_by_name: string }) => post<PhotoConsent>(`/children/${childId}/photo-consent`, body),
  revokePhotoConsent: (childId: string, reason: string) => post<PhotoConsent>(`/children/${childId}/photo-consent/revoke`, { reason }),
  // Passage au primaire
  decisions: (query: Record<string, QueryValue> = {}) => all<TransitionDecision>('/transition/decisions', query),
  propose: (sectionId: string) => post<TransitionDecision[]>('/transition/proposals', { section_id: sectionId }),
  opinion: (id: string, opinion: string) => post<TransitionDecision>(`/transition/decisions/${id}/opinion`, { opinion }),
  decide: (id: string, body: { decision: string; reason?: string | null; parent_request_ref?: string | null; target_section_id?: string | null }) => post<TransitionDecision>(`/transition/decisions/${id}/decide`, body),
  closure: (academicYearId: string) => get<{ academic_year_id: string; blocking_count: number; ready: boolean; detail: string }>('/transition/closure', { academic_year_id: academicYearId }),
  liaisons: (query: Record<string, QueryValue> = {}) => all<LiaisonSheet>('/transition/liaison-sheets', query),
  createLiaison: (learnerId: string) => post<LiaisonSheet>('/transition/liaison-sheets', { learner_id: learnerId }),
  updateLiaison: (id: string, body: Partial<Pick<LiaisonSheet, 'path_summary' | 'strengths' | 'needs' | 'destination'>>) => patch<LiaisonSheet>(`/transition/liaison-sheets/${id}`, body),
  signLiaison: (id: string, locale: 'fr' | 'en' = 'fr') => post<LiaisonSheet & { document: IssuedDocument }>(`/transition/liaison-sheets/${id}/sign`, { locale }),
  // Services garderie, cantine, transport
  servicePlans: () => all<ServicePlan>('/services/plans'),
  createServicePlan: (body: { kind: string; label: string; fee_item_id: string; billing_cycle: string }) => post<ServicePlan>('/services/plans', body),
  updateServicePlan: (id: string, body: Partial<{ label: string; fee_item_id: string; active: boolean }>) => patch<ServicePlan>(`/services/plans/${id}`, body),
  subscriptions: (query: Record<string, QueryValue> = {}) => all<ServiceSubscription>('/services/subscriptions', query),
  subscribe: (body: { learner_id: string; plan_id: string; starts_on?: string }) => post<ServiceSubscription>('/services/subscriptions', body),
  endSubscription: (id: string, endsOn: string) => post<ServiceSubscription>(`/services/subscriptions/${id}/end`, { ends_on: endsOn }),
  invoiceSubscription: (id: string, on: string) => post<ServiceInvoice>(`/services/subscriptions/${id}/invoice`, { on }),
  invoiceAll: (body: { plan_id?: string; on: string }) => post<{ issued: number; already: number; failed: { subscription_id: string; reason: string }[] }>('/services/invoices', body),
  serviceRoster: (planId: string, date: string) => get<ServiceRoster>('/services/attendance', { plan_id: planId, date }),
  recordServiceAttendance: (body: { plan_id: string; date: string; marks: { learner_id: string; present: boolean }[] }) => post<ServiceRoster>('/services/attendance', body),
  dashboard: (date?: string) => get<Dashboard>('/dashboard', date ? { date } : {}),
  messages: () => all<OutgoingMessage>('/messages'),
  sendMessage: (body: { subject: string; body: string; section_ids?: string[] }) => post<{ recipients: number; delivered: number; blocked: number; skipped_by_restriction: number }>('/messages', body),
  // Portail de la famille
  family: {
    children: () => all<FamilyChild>('/portal/children'),
    pickups: (learnerId: string) => all<Authorized>('/portal/pickups', { learner_id: learnerId }),
    addPickup: (body: { learner_id: string; name: string; relationship: string; phone?: string; grant_type: string; valid_on?: string }) => post<{ status: string }>('/portal/pickups', body),
    revokePickup: (id: string) => post<Authorized>(`/portal/pickups/${id}/revoke`),
    issueCode: (body: { learner_id: string; pickup_id: string }) => post<{ code: string; valid_on: string }>('/portal/pickup-codes', body),
    declareAllergy: (childId: string, allergies: string | null) => put<{ allergies: string | null }>(`/portal/children/${childId}/allergies`, { allergies }),
    books: (learnerId: string) => all<ProgressBook>('/portal/progress-books', { learner_id: learnerId }),
    lifebook: (learnerId: string) => all<ParentLifeEntry>('/portal/lifebook', { learner_id: learnerId }),
    needs: (learnerId: string) => all<Need>('/portal/needs', { learner_id: learnerId }),
    balance: (learnerId: string) => get<Balance>('/portal/balance', { learner_id: learnerId }),
    documents: (learnerId: string) => all<IssuedDocument>('/portal/documents', { learner_id: learnerId }),
    services: (learnerId: string) => all<ServiceSubscription>('/portal/services', { learner_id: learnerId }),
    photoConsent: (learnerId: string) => get<PhotoConsent>('/portal/photo-consent', { learner_id: learnerId }),
    givePhotoConsent: (body: { learner_id: string; signed_by_name: string; accept: true }) => post<PhotoConsent>('/portal/photo-consent', body),
    withdrawPhotoConsent: (learnerId: string, reason?: string) => post<PhotoConsent>('/portal/photo-consent/revoke', { learner_id: learnerId, ...(reason ? { reason } : {}) }),
  },
};

/** Octets d'une photo (route authentifiée : un `<img>` ne peut pas porter l'en-tête Authorization, on passe par un Blob). */
export const nurseryMedia = (path: string): Promise<Blob> => http.get<Blob>(`${ROOT}${path}`, undefined, { expect: 'blob' });
