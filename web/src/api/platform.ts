// Appels du module Platform : catalogue public et demandes d'accès (sans compte), espace de l'administrateur de
// plateforme (/platform/**, jamais d'en-tête X-Establishment-Id) et applications du client (/me/applications,
// /applications/**). Contrat : specs/001-socle-plateforme/contracts/openapi.d/platform.yaml.
import { ApiError, http } from './http';

export type AppTarget = 'higher' | 'secondary' | 'primary' | 'nursery';
export type AppStatus = 'available' | 'coming_soon' | 'retired';
export type AccessRequestStatus = 'pending' | 'info_requested' | 'accepted' | 'rejected';
export type EntitlementState = 'trial' | 'active' | 'past_due' | 'suspended' | 'closed';
export type PaymentChannel = 'mtn_momo' | 'orange_money';
export type PaymentStatus = 'pending' | 'succeeded' | 'failed';

export const APP_TARGETS: AppTarget[] = ['higher', 'secondary', 'primary', 'nursery'];
export const APP_STATUSES: AppStatus[] = ['available', 'coming_soon', 'retired'];
export const REQUEST_STATUSES: AccessRequestStatus[] = ['pending', 'info_requested', 'accepted', 'rejected'];
export const PAYMENT_CHANNELS: PaymentChannel[] = ['mtn_momo', 'orange_money'];
export const PAYMENT_STATUSES: PaymentStatus[] = ['pending', 'succeeded', 'failed'];
export const MAX_MONTHS = 12;
/** Jeton de suivi d'une demande d'accès : 48 caractères hexadécimaux. */
export const TRACKING_TOKEN = /^[a-f0-9]{48}$/;

export interface PublicApplication {
  key: string;
  label: string;
  target: AppTarget;
  status: AppStatus;
  version: string;
  monthly_price_xaf: number;
  trial_days: number;
}

export interface CatalogApplication extends PublicApplication {
  required_modules: string[];
  grace_days: number;
}

export interface AccessRequestInput {
  institution_name: string;
  target_type: AppTarget;
  city: string;
  region: string;
  contact_first_name: string;
  contact_last_name: string;
  contact_email: string;
  contact_phone?: string;
  contact_position?: string;
  ministerial_authorization?: string;
  app_key: string;
  message?: string;
  locale?: 'fr' | 'en';
  /** Champ piège : jamais rempli par une personne. */
  website?: string;
}

export interface AccessRequestReceipt {
  tracking_token: string;
  status: AccessRequestStatus;
  message?: string;
}

export interface AccessTracking {
  reference: string;
  institution_name: string;
  app_key: string;
  app_label: string;
  status: AccessRequestStatus;
  info_request: string | null;
  decision_reason: string | null;
  submitted_at: string;
  updated_at: string;
}

export interface Entitlement {
  id: string;
  tenant_id: string;
  app_key: string;
  label: string;
  target: AppTarget | null;
  state: EntitlementState;
  source: string;
  started_on: string;
  trial_ends_on: string;
  trial_days_left: number;
  paid_until: string | null;
  due_on: string | null;
  monthly_price_xaf: number;
  price_overridden: boolean;
  past_due_since: string | null;
  suspended_at: string | null;
  closed_at: string | null;
  state_reason: string | null;
  writable: boolean;
  readable: boolean;
  tenant_name?: string | null;
  establishments?: Array<{ id: string; name: string; type: string; accent?: string | null }>;
}

export interface AccessRequest {
  id: string;
  reference: string;
  status: AccessRequestStatus;
  institution_name: string;
  target_type: AppTarget;
  city: string;
  region: string;
  country: string;
  contact: { first_name: string; last_name: string; email: string; phone: string | null; position: string | null };
  ministerial_authorization: string | null;
  app_key: string;
  app_label: string;
  message: string | null;
  locale: string;
  info_request: string | null;
  complement: string | null;
  decision_reason: string | null;
  decided_by: string | null;
  decided_at: string | null;
  tenant_id: string | null;
  establishment_id: string | null;
  created_at: string;
  updated_at: string;
  /** Présents dans la réponse de l'acceptation. */
  entitlement?: Entitlement;
  administrator?: { person_id: string; login: string; status: string } | null;
}

/** Indicateur du tableau de bord : un indicateur sans source est rendu available:false avec la raison, jamais estimé. */
export interface Metric {
  available: boolean;
  reason?: string;
  total?: number;
  value?: number;
  [extra: string]: unknown;
}

export interface PlatformDashboard {
  as_of?: string;
  clients?: Metric;
  access_requests_pending?: Metric;
  applications?: Metric & { open_total?: number; items?: Array<{ key: string; label: string; status: AppStatus; target: AppTarget; open: number; states: Record<string, number> }> };
  trials_ending?: Metric & { horizon_days?: number; items?: Entitlement[] };
  unpaid?: Metric & { items?: Entitlement[] };
  mrr_xaf?: Metric & { paying_subscriptions?: number };
  collected_last_30_days_xaf?: Metric;
  health?: Metric;
}

export interface Tenant {
  id: string;
  name: string;
  status: string;
  created_at: string;
  applications: Entitlement[];
  establishments?: Array<{ id: string; name: string; type: string }>;
}

export interface SubscriptionPayment {
  id: string;
  tenant_id: string;
  app_key: string;
  reference: string;
  amount_xaf: number;
  months: number;
  channel: PaymentChannel;
  status: PaymentStatus;
  receipt_number: string | null;
  covered_from: string | null;
  covered_until: string | null;
  paid_at: string | null;
  created_at: string;
}

export interface PaymentReceipt {
  receipt_number: string;
  issuer: string;
  tenant_id: string;
  app_key: string;
  app_label: string;
  amount_xaf: number;
  months: number;
  covered_from: string | null;
  covered_until: string | null;
  channel: PaymentChannel;
  reference: string;
  paid_at: string | null;
}

export interface MyApplications {
  opened: Entitlement[];
  catalog: Array<Pick<PublicApplication, 'key' | 'label' | 'target' | 'status' | 'monthly_price_xaf' | 'trial_days'>>;
}

export interface PageOf<T> {
  items: T[];
  nextCursor: string | null;
}

/** Les routes /platform/** ne reçoivent jamais d'établissement : l'administrateur de plateforme n'en a pas. */
const NO_ESTABLISHMENT = { establishmentId: false as const };

// --- Public (sans compte) -------------------------------------------------------------------------------------------------
export const fetchPublicApplications = async (): Promise<PublicApplication[]> => (await http.get<{ data: PublicApplication[] }>('/public/applications')).data ?? [];

export const submitAccessRequest = async (input: AccessRequestInput): Promise<AccessRequestReceipt> =>
  (await http.post<{ data: AccessRequestReceipt }>('/public/access-requests', input)).data;

export const trackAccessRequest = async (token: string): Promise<AccessTracking> =>
  (await http.get<{ data: AccessTracking }>(`/public/access-requests/${encodeURIComponent(token)}`)).data;

export const sendComplement = async (token: string, message: string): Promise<AccessTracking> =>
  (await http.post<{ data: AccessTracking }>(`/public/access-requests/${encodeURIComponent(token)}/complement`, { message })).data;

// --- Administrateur de plateforme : lectures ------------------------------------------------------------------------------
export const fetchPlatformDashboard = async (): Promise<PlatformDashboard> => (await http.get<{ data: PlatformDashboard }>('/platform/dashboard', undefined, NO_ESTABLISHMENT)).data;

export const fetchPlatformCatalog = async (): Promise<CatalogApplication[]> => (await http.get<{ data: CatalogApplication[] }>('/platform/applications', undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchClients = async (): Promise<Tenant[]> => (await http.get<{ data: Tenant[] }>('/platform/clients', undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchClient = async (tenant: string): Promise<Tenant> =>
  (await http.get<{ data: Tenant }>(`/platform/clients/${encodeURIComponent(tenant)}`, undefined, NO_ESTABLISHMENT)).data;

export const fetchAccessRequests = (status: AccessRequestStatus | null, cursor: string | null = null): Promise<PageOf<AccessRequest>> =>
  http.page<AccessRequest>('/platform/access-requests', { 'filter[status]': status, cursor, per_page: 50 }, NO_ESTABLISHMENT);

export const fetchAccessRequest = async (id: string): Promise<AccessRequest> =>
  (await http.get<{ data: AccessRequest }>(`/platform/access-requests/${encodeURIComponent(id)}`, undefined, NO_ESTABLISHMENT)).data;

export const fetchPlatformPayments = (filters: { status?: PaymentStatus | null; tenantId?: string | null }, cursor: string | null = null): Promise<PageOf<SubscriptionPayment>> =>
  http.page<SubscriptionPayment>('/platform/payments', { 'filter[status]': filters.status, 'filter[tenant_id]': filters.tenantId, cursor, per_page: 50 }, NO_ESTABLISHMENT);

// --- Administrateur de plateforme : actions (rejouables après une nouvelle double authentification) ------------------------
export interface PlatformAction {
  method: 'POST' | 'PATCH';
  path: string;
  body: Record<string, unknown>;
}

const seg = (v: string): string => encodeURIComponent(v);

export const actions = {
  acceptRequest: (id: string, tenantId: string | null): PlatformAction => ({ method: 'POST', path: `/platform/access-requests/${seg(id)}/accept`, body: tenantId ? { tenant_id: tenantId } : {} }),
  rejectRequest: (id: string, reason: string): PlatformAction => ({ method: 'POST', path: `/platform/access-requests/${seg(id)}/reject`, body: { reason } }),
  requestInfo: (id: string, message: string): PlatformAction => ({ method: 'POST', path: `/platform/access-requests/${seg(id)}/request-info`, body: { message } }),
  openForClient: (tenant: string, appKey: string): PlatformAction => ({ method: 'POST', path: `/platform/clients/${seg(tenant)}/applications`, body: { app_key: appKey } }),
  suspend: (tenant: string, key: string, reason: string): PlatformAction => ({ method: 'POST', path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/suspend`, body: { reason } }),
  close: (tenant: string, key: string, reason: string): PlatformAction => ({ method: 'POST', path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/close`, body: { reason } }),
  reactivate: (tenant: string, key: string, reason: string | null, until: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/reactivate`,
    body: { ...(reason ? { reason } : {}), ...(until ? { until } : {}) },
  }),
  setPrice: (tenant: string, key: string, price: number | null): PlatformAction => ({ method: 'PATCH', path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}`, body: { monthly_price_xaf: price } }),
  updateCatalog: (key: string, changes: Record<string, unknown>): PlatformAction => ({ method: 'PATCH', path: `/platform/applications/${seg(key)}`, body: changes }),
};

export async function runPlatformAction<T = unknown>(action: PlatformAction): Promise<T> {
  const res = await http.request<{ data: T }>(action.method, action.path, { body: action.body, ...NO_ESTABLISHMENT });
  return res?.data as T;
}

/** 403 « two-factor-recent-required » : l'action sensible exige une double authentification de moins de 15 minutes. */
export function isRecentTwoFactorRequired(error: unknown): boolean {
  return error instanceof ApiError && error.status === 403 && error.slug === 'two-factor-recent-required';
}

// --- Client : applications de l'utilisateur -------------------------------------------------------------------------------
export async function fetchMyApplications(): Promise<MyApplications> {
  const res = await http.get<{ data: Entitlement[]; meta?: { catalog?: MyApplications['catalog'] } }>('/me/applications');
  return { opened: res.data ?? [], catalog: res.meta?.catalog ?? [] };
}

export const openApplication = async (key: string): Promise<Entitlement> => (await http.post<{ data: Entitlement }>(`/applications/${seg(key)}/open`)).data;

export const payApplication = async (key: string, body: { months: number; channel: PaymentChannel; payer_phone: string }, idempotencyKey: string): Promise<SubscriptionPayment> =>
  (await http.post<{ data: SubscriptionPayment }>(`/applications/${seg(key)}/payments`, body, { idempotencyKey })).data;

export async function fetchApplicationPayments(key: string): Promise<{ payments: SubscriptionPayment[]; entitlement: Entitlement | null }> {
  const res = await http.get<{ data: SubscriptionPayment[]; meta?: { entitlement?: Entitlement } }>(`/applications/${seg(key)}/payments`);
  return { payments: res.data ?? [], entitlement: res.meta?.entitlement ?? null };
}

export const fetchReceipt = async (paymentId: string): Promise<PaymentReceipt> => (await http.get<{ data: PaymentReceipt }>(`/application-payments/${seg(paymentId)}/receipt`)).data;

/** Téléphone payeur ou de contact : 8 à 15 chiffres, « + » initial facultatif (espaces, points, tirets et parenthèses ignorés). */
export function normalizePhone(raw: string): string | null {
  const clean = raw.replace(/[\s.\-()]/g, '');
  return /^\+?[0-9]{8,15}$/.test(clean) ? clean : null;
}
