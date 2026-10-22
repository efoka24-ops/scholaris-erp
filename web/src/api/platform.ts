// Appels du module Platform : catalogue public et demandes d'accès (sans compte), espace de l'administrateur de
// plateforme (/platform/**, jamais d'en-tête X-Establishment-Id) et applications du client (/me/applications,
// /applications/**). Contrat : specs/001-socle-plateforme/contracts/openapi.d/platform.yaml.
import { ApiError, http } from './http';

export type AppTarget = 'higher' | 'secondary' | 'primary' | 'nursery';
export type AppStatus = 'available' | 'coming_soon' | 'retired';
export type AccessRequestStatus = 'pending' | 'info_requested' | 'accepted' | 'rejected';
export type EntitlementState = 'trial' | 'active' | 'past_due' | 'suspended' | 'closed';
export type PaymentChannel = 'mtn_momo' | 'orange_money' | 'cash' | 'bank_transfer';
export type PaymentStatus = 'pending' | 'succeeded' | 'failed' | 'cancelled';

export const APP_TARGETS: AppTarget[] = ['higher', 'secondary', 'primary', 'nursery'];
export const APP_STATUSES: AppStatus[] = ['available', 'coming_soon', 'retired'];
export const REQUEST_STATUSES: AccessRequestStatus[] = ['pending', 'info_requested', 'accepted', 'rejected'];
export const PAYMENT_CHANNELS: PaymentChannel[] = ['mtn_momo', 'orange_money'];
export const PAYMENT_STATUSES: PaymentStatus[] = ['pending', 'succeeded', 'failed', 'cancelled'];
export const MAX_MONTHS = 12;
/** Jeton de suivi d'une demande d'accès : 48 caractères hexadécimaux. */
export const TRACKING_TOKEN = /^[a-f0-9]{48}$/;

export interface PublicApplication {
  key: string;
  label: string;
  target: AppTarget;
  /** Niveau (= target) et régime (privé ou public) : une carte par niveau, le régime désigne l'application à demander. */
  family?: AppTarget;
  sector?: 'private' | 'public';
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
  /** Secondaire : obligatoire (leg, lycee_technique, ces, cetic, college_prive, lycee_prive, autre). */
  establishment_subtype?: string;
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
  sector?: 'private' | 'public';
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
  /** Délais effectifs et surcharges du client (essai, grâce, périodicité, niveau de blocage). */
  terms?: EntitlementTerms;
  /** Options de service souscrites et montant mensuel dû (tarif de base + options). */
  options?: EntitlementOption[];
  options_total_xaf?: number;
  monthly_total_xaf?: number;
  /** Promesse de paiement : le blocage automatique est reporté jusqu'à cette date. */
  promise_until?: string | null;
  /** Mode régularisation de paiement (null : pas bloqué pour impayé). */
  regularisation?: { level: RegularisationLevel; origin: 'auto' | 'manual'; since: string | null; days_elapsed: number } | null;
}

export type RegularisationLevel = 'read_only' | 'locked';
export const REGULARISATION_LEVELS: RegularisationLevel[] = ['read_only', 'locked'];
export type RegularisationBucket = 'past_due' | 'regularisation' | 'promise';
export const BILLING_PERIODS = [1, 3, 12] as const;

export interface EntitlementTerms {
  trial_days: number;
  trial_days_overridden: boolean;
  grace_days: number;
  grace_days_overridden: boolean;
  billing_period_months: number;
  billing_period_overridden: boolean;
  regularisation_level: RegularisationLevel;
  regularisation_level_overridden: boolean;
}

export interface EntitlementOption {
  option_key: string;
  label_fr: string;
  label_en: string;
  price_xaf: number;
  default_price_xaf?: number;
  price_overridden?: boolean;
}

/** Option de service du catalogue de la plateforme. */
export interface ServiceOption {
  option_key: string;
  label_fr: string;
  label_en: string;
  monthly_price_xaf: number;
  /** Applications concernées ([] : toutes). */
  app_keys: string[];
  active: boolean;
  subscribers: number;
}

/** Ligne de la liste « À régulariser » (plateforme) et de l'écran de régularisation (client). */
export interface RegularisationItem {
  tenant_id: string;
  tenant_name?: string;
  app_key: string;
  label: string;
  state: EntitlementState;
  level: RegularisationLevel | null;
  bucket: RegularisationBucket;
  origin: 'auto' | 'manual' | null;
  due_on: string;
  period_from: string;
  since: string;
  days_late: number;
  days_elapsed: number;
  months: number;
  monthly_total_xaf: number;
  base_price_xaf: number;
  options: EntitlementOption[];
  amount_due_xaf: number;
  promise_until: string | null;
  promise_reason: string | null;
  receipts?: Array<{ id: string; receipt_number: string; amount_xaf: number; paid_at: string | null }>;
}

export interface RegularisationOverview {
  items: RegularisationItem[];
  total: number;
  amountDueXaf: number;
  counts: Record<RegularisationBucket, number>;
}

export interface MyRegularisation {
  mode: 'ok' | 'past_due' | RegularisationLevel;
  locked: boolean;
  can_pay: boolean;
  items: RegularisationItem[];
  contact: { name: string; email: string | null; phone: string | null };
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
  archived_at?: string | null;
  archive_reason?: string | null;
  created_at: string;
  updated_at: string;
  /** Présents dans la réponse de l'acceptation. */
  entitlement?: Entitlement;
  administrator?: { person_id: string; user_id?: string; login: string; status: string } | null;
  /** Code d'activation de l'administrateur invité et récapitulatif à partager (rendu une seule fois). */
  access?: AccessCode | null;
}

// --- Code d'accès à usage unique (dépannage et partage de l'accès d'un compte) --------------------------------------------
export type AccessSend = 'none' | 'email' | 'sms';
export type AccessChannel = 'email' | 'sms';

export interface AccessDelivery {
  channel: AccessSend;
  /** not_sent (afficher seulement), sent, failed (fournisseur en échec), unavailable (pas de contact pour ce canal). */
  status: 'not_sent' | 'sent' | 'failed' | 'unavailable';
  masked_to: string | null;
}

export interface AccessShare {
  establishment_name: string;
  login: string;
  login_url: string;
  activation_url: string;
  locale: 'fr' | 'en';
  channels: AccessChannel[];
  message: { subject: string; text: string; sms: string };
}

export interface AccessCode {
  code: string;
  purpose: 'activation' | 'reset';
  expires_at: string;
  expires_in: number;
  login: string;
  delivery: AccessDelivery;
  share: AccessShare;
}

/** Compte d'un client, en lecture seule (aucune donnée métier). */
export interface ClientUser {
  id: string;
  person_id: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  status: AdministratorStatus;
  roles: string[];
  two_factor_enabled: boolean;
  last_login_at: string | null;
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

export type ClientState = 'active' | 'suspended' | 'archived';
export const CLIENT_STATES: ClientState[] = ['active', 'suspended', 'archived'];

export interface ClientEstablishment {
  id: string;
  name: string;
  type: AppTarget;
  closed_at: string | null;
  created_at: string;
}

export interface Tenant {
  id: string;
  name: string;
  status: string;
  /** État de la fiche client (absent d'une ancienne réponse : « active »). */
  state?: ClientState;
  state_reason?: string | null;
  suspended_at?: string | null;
  archived_at?: string | null;
  contact?: { name: string | null; email: string | null; phone: string | null };
  city?: string | null;
  region?: string | null;
  locale?: string;
  created_at: string;
  applications: Entitlement[];
  establishments?: ClientEstablishment[];
}

export interface ClientInput {
  name: string;
  contact_name: string;
  contact_email?: string;
  contact_phone?: string;
  city?: string;
  region?: string;
  locale?: 'fr' | 'en';
  establishment_type?: AppTarget;
}

export type AdministratorStatus = 'pending' | 'active' | 'locked';

export interface Administrator {
  id: string;
  person_id: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  status: AdministratorStatus;
  two_factor_enabled: boolean;
  last_login_at: string | null;
  created_at: string;
  /** Résultat de l'envoi de l'invitation (sent, blocked…), présent après une invitation. */
  invitation?: string;
}

export interface AdministratorInput {
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string;
  locale?: 'fr' | 'en';
}

export interface AuditEntry {
  id: string;
  seq: number;
  occurred_at: string;
  actor_id: string | null;
  action: string;
  object_type: string;
  object_id: string;
  details: Record<string, unknown>;
  ip: string | null;
}

export type ManualChannel = 'cash' | 'bank_transfer';
export const MANUAL_CHANNELS: ManualChannel[] = ['cash', 'bank_transfer'];

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
  note?: string | null;
  cancelled_at?: string | null;
  cancel_reason?: string | null;
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
  catalog: Array<Pick<PublicApplication, 'key' | 'label' | 'target' | 'family' | 'sector' | 'status' | 'monthly_price_xaf' | 'trial_days'>>;
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

/** Réglage de plateforme : valeur, défaut et bornes (GET /platform/settings). */
export interface PlatformSetting { value: number; default: number; min: number; max: number; updated_at: string | null }
export const fetchPlatformSettings = async (): Promise<Record<string, PlatformSetting>> =>
  (await http.get<{ data: Record<string, PlatformSetting> }>('/platform/settings', undefined, NO_ESTABLISHMENT)).data ?? {};

/** Liste « À régulariser » : impayés, applications en régularisation, promesses en cours. */
export async function fetchRegularisation(bucket: RegularisationBucket | null = null): Promise<RegularisationOverview> {
  const res = await http.get<{ data: RegularisationItem[]; meta: { total: number; amount_due_xaf: number; counts: Record<RegularisationBucket, number> } }>(
    '/platform/regularisation',
    bucket ? { 'filter[bucket]': bucket } : undefined,
    NO_ESTABLISHMENT,
  );
  return { items: res.data ?? [], total: res.meta?.total ?? 0, amountDueXaf: res.meta?.amount_due_xaf ?? 0, counts: res.meta?.counts ?? { past_due: 0, regularisation: 0, promise: 0 } };
}

/** Export CSV de la liste « À régulariser » (téléchargement authentifié). */
export const fetchRegularisationCsv = (bucket: RegularisationBucket | null = null): Promise<Blob> =>
  http.get<Blob>('/platform/regularisation/export', bucket ? { 'filter[bucket]': bucket } : undefined, { expect: 'blob', ...NO_ESTABLISHMENT });

export const fetchServiceOptions = async (): Promise<ServiceOption[]> => (await http.get<{ data: ServiceOption[] }>('/platform/service-options', undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchPlatformCatalog = async (): Promise<CatalogApplication[]> => (await http.get<{ data: CatalogApplication[] }>('/platform/applications', undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchClients = async (state: ClientState | null = null): Promise<Tenant[]> =>
  (await http.get<{ data: Tenant[] }>('/platform/clients', state ? { 'filter[state]': state } : undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchClientEstablishments = async (tenant: string): Promise<ClientEstablishment[]> =>
  (await http.get<{ data: ClientEstablishment[] }>(`/platform/clients/${encodeURIComponent(tenant)}/establishments`, undefined, NO_ESTABLISHMENT)).data ?? [];

/** Administrateurs d'un client (tenant) ou de la plateforme (tenant null). */
export const fetchAdministrators = async (tenant: string | null): Promise<Administrator[]> =>
  (await http.get<{ data: Administrator[] }>(tenant ? `/platform/clients/${encodeURIComponent(tenant)}/administrators` : '/platform/administrators', undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchClientUsers = async (tenant: string): Promise<ClientUser[]> =>
  (await http.get<{ data: ClientUser[] }>(`/platform/clients/${encodeURIComponent(tenant)}/users`, undefined, NO_ESTABLISHMENT)).data ?? [];

export const fetchPlatformAudit = (filters: { action?: string | null; from?: string | null; to?: string | null }, cursor: string | null = null): Promise<PageOf<AuditEntry>> =>
  http.page<AuditEntry>('/platform/audit', { 'filter[action]': filters.action || null, 'filter[from]': filters.from || null, 'filter[to]': filters.to || null, cursor, per_page: 50 }, NO_ESTABLISHMENT);

export const fetchPlatformReceipt = async (paymentId: string): Promise<PaymentReceipt & { tenant_name: string }> =>
  (await http.get<{ data: PaymentReceipt & { tenant_name: string } }>(`/platform/payments/${encodeURIComponent(paymentId)}/receipt`, undefined, NO_ESTABLISHMENT)).data;

export const fetchClient = async (tenant: string): Promise<Tenant> =>
  (await http.get<{ data: Tenant }>(`/platform/clients/${encodeURIComponent(tenant)}`, undefined, NO_ESTABLISHMENT)).data;

export const fetchAccessRequests = (status: AccessRequestStatus | null, cursor: string | null = null, archived = false): Promise<PageOf<AccessRequest>> =>
  http.page<AccessRequest>('/platform/access-requests', { 'filter[status]': status, 'filter[archived]': archived ? '1' : null, cursor, per_page: 50 }, NO_ESTABLISHMENT);

export const fetchAccessRequest = async (id: string): Promise<AccessRequest> =>
  (await http.get<{ data: AccessRequest }>(`/platform/access-requests/${encodeURIComponent(id)}`, undefined, NO_ESTABLISHMENT)).data;

export const fetchPlatformPayments = (filters: { status?: PaymentStatus | null; tenantId?: string | null }, cursor: string | null = null): Promise<PageOf<SubscriptionPayment>> =>
  http.page<SubscriptionPayment>('/platform/payments', { 'filter[status]': filters.status, 'filter[tenant_id]': filters.tenantId, cursor, per_page: 50 }, NO_ESTABLISHMENT);

// --- Administrateur de plateforme : actions (rejouables après une nouvelle double authentification) ------------------------
export interface PlatformAction {
  method: 'POST' | 'PATCH' | 'DELETE';
  path: string;
  body: Record<string, unknown>;
}

const seg = (v: string): string => encodeURIComponent(v);

export const actions = {
  updateSettings: (values: Record<string, number>): PlatformAction => ({ method: 'PATCH', path: '/platform/settings', body: values }),
  acceptRequest: (id: string, tenantId: string | null, shareSend: AccessSend = 'none'): PlatformAction => ({
    method: 'POST',
    path: `/platform/access-requests/${seg(id)}/accept`,
    body: { ...(tenantId ? { tenant_id: tenantId } : {}), ...(shareSend !== 'none' ? { share_send: shareSend } : {}) },
  }),
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
  // Back-office : clients, établissements, administrateurs, demandes, paiements manuels.
  createClient: (input: ClientInput): PlatformAction => ({ method: 'POST', path: '/platform/clients', body: { ...input } }),
  updateClient: (tenant: string, changes: Record<string, unknown>): PlatformAction => ({ method: 'PATCH', path: `/platform/clients/${seg(tenant)}`, body: changes }),
  clientState: (tenant: string, kind: 'suspend' | 'reactivate' | 'archive', reason: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/${kind}`,
    body: reason ? { reason } : {},
  }),
  createEstablishment: (tenant: string, name: string, type: AppTarget): PlatformAction => ({ method: 'POST', path: `/platform/clients/${seg(tenant)}/establishments`, body: { name, type } }),
  renameEstablishment: (tenant: string, id: string, name: string): PlatformAction => ({ method: 'PATCH', path: `/platform/clients/${seg(tenant)}/establishments/${seg(id)}`, body: { name } }),
  establishmentState: (tenant: string, id: string, close: boolean, reason: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/establishments/${seg(id)}/${close ? 'close' : 'reopen'}`,
    body: reason ? { reason } : {},
  }),
  inviteAdministrator: (tenant: string | null, input: AdministratorInput): PlatformAction => ({
    method: 'POST',
    path: tenant ? `/platform/clients/${seg(tenant)}/administrators` : '/platform/administrators',
    body: { ...input },
  }),
  administrator: (tenant: string | null, user: string, kind: 'resend-invitation' | 'block' | 'unblock' | 'reset-access'): PlatformAction => ({
    method: 'POST',
    path: `${tenant ? `/platform/clients/${seg(tenant)}` : '/platform'}/administrators/${seg(user)}/${kind}`,
    body: {},
  }),
  issueAccessCode: (tenant: string | null, user: string, send: AccessSend): PlatformAction => ({
    method: 'POST',
    path: `${tenant ? `/platform/clients/${seg(tenant)}/users` : '/platform/administrators'}/${seg(user)}/access-code`,
    body: { send },
  }),
  sendAccessCode: (tenant: string | null, user: string, channel: AccessChannel, code: string): PlatformAction => ({
    method: 'POST',
    path: `${tenant ? `/platform/clients/${seg(tenant)}/users` : '/platform/administrators'}/${seg(user)}/access-code/send`,
    body: { channel, code },
  }),
  archiveRequest: (id: string, reason: string): PlatformAction => ({ method: 'POST', path: `/platform/access-requests/${seg(id)}/archive`, body: { reason } }),
  deleteRequest: (id: string): PlatformAction => ({ method: 'DELETE', path: `/platform/access-requests/${seg(id)}`, body: {} }),
  recordPayment: (input: { tenant_id: string; app_key: string; months: number; channel: ManualChannel; amount_xaf?: number; note?: string }): PlatformAction => ({
    method: 'POST',
    path: '/platform/payments',
    body: { ...input },
  }),
  setTerms: (tenant: string, key: string, changes: Record<string, number | string | null>, reason: string): PlatformAction => ({
    method: 'PATCH',
    path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/terms`,
    body: { ...changes, reason },
  }),
  subscribeOption: (tenant: string, key: string, optionKey: string, price: number | null, reason: string): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/options`,
    body: { option_key: optionKey, price_xaf: price, reason },
  }),
  unsubscribeOption: (tenant: string, key: string, optionKey: string, reason: string): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/applications/${seg(key)}/options/${seg(optionKey)}/remove`,
    body: { reason },
  }),
  lockClient: (tenant: string, reason: string, level: RegularisationLevel | null, appKey: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/regularisation/lock`,
    body: { reason, ...(level ? { level } : {}), ...(appKey ? { app_key: appKey } : {}) },
  }),
  unlockClient: (tenant: string, reason: string, until: string | null, appKey: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/regularisation/unlock`,
    body: { reason, ...(until ? { until } : {}), ...(appKey ? { app_key: appKey } : {}) },
  }),
  grantDelay: (tenant: string, until: string, reason: string, appKey: string | null): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/regularisation/delay`,
    body: { until, reason, ...(appKey ? { app_key: appKey } : {}) },
  }),
  clientLevel: (tenant: string, level: RegularisationLevel | null, reason: string): PlatformAction => ({
    method: 'POST',
    path: `/platform/clients/${seg(tenant)}/regularisation/level`,
    body: { level, reason },
  }),
  createServiceOption: (input: { option_key: string; label_fr: string; label_en: string; monthly_price_xaf: number; app_keys: string[] }): PlatformAction => ({
    method: 'POST',
    path: '/platform/service-options',
    body: { ...input },
  }),
  updateServiceOption: (key: string, changes: Record<string, unknown>): PlatformAction => ({ method: 'PATCH', path: `/platform/service-options/${seg(key)}`, body: changes }),
  deleteServiceOption: (key: string): PlatformAction => ({ method: 'DELETE', path: `/platform/service-options/${seg(key)}`, body: {} }),
  cancelPayment: (id: string, reason: string): PlatformAction => ({ method: 'POST', path: `/platform/payments/${seg(id)}/cancel`, body: { reason } }),
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

/** Situation de paiement du client de l'utilisateur (écran de régularisation). */
export const fetchMyRegularisation = async (): Promise<MyRegularisation> => (await http.get<{ data: MyRegularisation }>('/me/regularisation')).data;

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

/** Canaux d'envoi d'un code d'accès : e-mail si présent, SMS pour un mobile camerounais (+237 6…) seulement. */
export function accessChannels(email: string | null | undefined, phone: string | null | undefined): AccessChannel[] {
  const out: AccessChannel[] = [];
  if (email) out.push('email');
  if (phone && /^\+2376\d{8}$/.test(phone)) out.push('sms');
  return out;
}
