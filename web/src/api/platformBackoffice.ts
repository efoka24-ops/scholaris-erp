// Back-office de la plateforme (C-112) : rôles fins, santé, indicateurs, journal d'activité, suivi des messages et envoi direct.
// Les routes /platform/** ne reçoivent jamais d'en-tête d'établissement.
import { http } from './http';
import type { PlatformAction } from './platform';
import type { PlatformRole } from '@/lib/permissions';

const NO_ESTABLISHMENT = { establishmentId: false as const };
const seg = encodeURIComponent;

export interface PlatformMe {
  user_id: string;
  role: PlatformRole | null;
  permissions: string[];
}

export const fetchPlatformMe = async (): Promise<PlatformMe> => (await http.get<{ data: PlatformMe }>('/platform/me', undefined, NO_ESTABLISHMENT)).data;

export const backofficeActions = {
  setRole: (user: string, role: PlatformRole): PlatformAction => ({ method: 'PATCH', path: `/platform/administrators/${seg(user)}/role`, body: { role } }),
  logoutUser: (tenant: string, user: string): PlatformAction => ({ method: 'POST', path: `/platform/clients/${seg(tenant)}/users/${seg(user)}/logout`, body: {} }),
};

// --- Santé de la plateforme (point 1) ---------------------------------------------------------------------------------------
export type HealthStatus = 'ok' | 'warning' | 'critical' | 'unknown';

export interface HealthProbe {
  key: string;
  status: HealthStatus;
  value: number | null;
  unit: string;
  thresholds: { warning: number; critical: number; direction: 'above' | 'below' } | null;
  detail: string | null;
  trace_ids?: string[];
  mode?: string | null;
  driver?: string;
  last_backup_at?: string;
  free_mb?: number;
}

export interface HealthReport {
  status: HealthStatus;
  as_of: string;
  probes: HealthProbe[];
}

export interface HealthTile {
  available: boolean;
  reason?: string;
  status?: HealthStatus;
  counts?: Record<HealthStatus, number>;
  alerts?: Array<{ key: string; status: HealthStatus }>;
}

export const fetchPlatformHealth = async (): Promise<HealthReport> => (await http.get<{ data: HealthReport }>('/platform/health', undefined, NO_ESTABLISHMENT)).data;

export const healthTone = (s: HealthStatus): 'success' | 'warning' | 'ink' | 'neutral' => (s === 'ok' ? 'success' : s === 'warning' ? 'warning' : s === 'critical' ? 'ink' : 'neutral');

// --- Indicateurs d'usage (point 2) ------------------------------------------------------------------------------------------
export interface ClientActivityRow { tenant_id: string; name: string; actions_7d: number; last_login_at: string | null; active: boolean }
export interface PlatformKpi {
  as_of: string;
  online: { window_minutes: number; total: number; items: Array<{ tenant_id: string; tenant_name: string; user_id: string; first_name: string; last_name: string; role: string | null; since: string; last_seen_at: string; device: string | null }> };
  logins: { today: number; week: number; month: number; series: Array<{ date: string; count: number }> };
  active_users: { d7: number; d30: number };
  security: { failed_logins_24h: number; lockouts_24h: number; locked_accounts: number };
  clients_activity: { inactive_days: number; active: number; inactive: number; top_active: ClientActivityRow[]; least_active: ClientActivityRow[] };
  usage_by_app: Array<{ key: string; label: string; clients: number; establishments: number; learners: number; actions_7d: number }>;
  conversion: { trials: number; converted: number; rate_percent: number | null };
  messages: {
    today: { sms: { sent: number; delivered: number; failed: number }; email: { sent: number; failed: number } };
    month: { sms: { sent: number; delivered: number; failed: number }; email: { sent: number; failed: number } };
    sms_quota_remaining: number | null;
    sms_mode: string | null;
  };
}

export const fetchPlatformKpi = async (): Promise<PlatformKpi> => (await http.get<{ data: PlatformKpi }>('/platform/kpi', undefined, NO_ESTABLISHMENT)).data;

// --- Suivi des actions (point 3) ------------------------------------------------------------------------------------------
export interface ActivityEntry {
  id: string;
  tenant_id: string;
  tenant_name: string;
  seq: number;
  occurred_at: string;
  actor_id: string | null;
  action: string;
  object_type: string;
  object_id: string;
  details: Record<string, unknown>;
  ip: string | null;
}
export interface ActivityFilters { tenantId?: string; actorId?: string; action?: string; from?: string; to?: string }
const activityQuery = (f: ActivityFilters): Record<string, string | undefined> => ({
  'filter[tenant_id]': f.tenantId || undefined,
  'filter[actor_id]': f.actorId || undefined,
  'filter[action]': f.action || undefined,
  'filter[from]': f.from || undefined,
  'filter[to]': f.to || undefined,
});

export const fetchActivity = (filters: ActivityFilters, cursor: string | null = null) =>
  http.page<ActivityEntry>('/platform/activity', { ...activityQuery(filters), cursor, per_page: 50 }, NO_ESTABLISHMENT);
export const fetchActivityCsv = (filters: ActivityFilters): Promise<Blob> =>
  http.get<Blob>('/platform/activity/export', activityQuery(filters), { expect: 'blob', ...NO_ESTABLISHMENT });

export interface ClientDelivery {
  id: string;
  tenant_id: string;
  channel: string;
  recipient: string;
  template_key: string | null;
  status: string;
  reason: string | null;
  provider_reference: string | null;
  attempts: number;
  created_at: string;
  sent_at: string | null;
  delivered_at: string | null;
}

export interface PlatformUserCard {
  id: string;
  person_id: string;
  tenant_id: string;
  first_name: string;
  last_name: string;
  phone: string;
  email: string;
  status: string;
  roles: string[];
  two_factor_enabled: boolean;
  last_login_at: string | null;
  created_at: string;
  sessions: Array<{ id: string; device: string | null; created_at: string; last_used_at: string | null; expires_at: string }>;
  logins: Array<{ occurred_at: string; ip: string | null; method: string | null }>;
  actions: Array<{ occurred_at: string; action: string; object_type: string; object_id: string }>;
  messages: ClientDelivery[];
}

export const fetchPlatformUser = async (tenant: string, user: string): Promise<PlatformUserCard> =>
  (await http.get<{ data: PlatformUserCard }>(`/platform/clients/${seg(tenant)}/users/${seg(user)}`, undefined, NO_ESTABLISHMENT)).data;

/** Masquage d'affichage des coordonnées dans les listes : « 6•• •• •• 12 », « p•••@domaine ». */
export function maskAddress(value: string | null | undefined): string {
  if (!value) return '—';
  if (value.includes('@')) {
    const [local, domain] = value.split('@', 2);
    return `${local.slice(0, 1)}•••@${domain}`;
  }
  let digits = value.replace(/\D/g, '');
  if (digits.startsWith('237') && digits.length === 12) digits = digits.slice(3);
  return digits.length < 4 ? '—' : `${digits.slice(0, 1)}•• •• •• ${digits.slice(-2)}`;
}

// --- Suivi des messages et envoi direct (point 4) ---------------------------------------------------------------------------
export type DirectTarget = 'person' | 'client_admins' | 'app_clients';
export type DirectChannel = 'sms' | 'email' | 'app';
export interface DirectMessageInput {
  target_type: DirectTarget;
  tenant_id?: string;
  user_id?: string;
  app_key?: string;
  channel: DirectChannel;
  subject: string;
  body: string;
}
export interface DirectPreview {
  recipients: number;
  reachable: number;
  sample: Array<{ tenant_id: string; first_name: string; address: string }>;
  sms_segments: number;
  estimated_cost_xaf: number;
  max_recipients: number;
  bulk: boolean;
  email_html: string | null;
}
export interface DirectRow {
  id: string;
  batch_id: string;
  tenant_id: string;
  tenant_name: string;
  recipient_user_id: string;
  recipient_masked: string;
  channel: DirectChannel;
  subject: string;
  status: string;
  reason: string | null;
  provider_reference: string | null;
  attempts: number;
  created_at: string;
}
export interface InternalStats { tenant_id: string; tenant_name: string; conversations: number; messages: number; hidden: number }

export const fetchClientDeliveries = (filters: { channel?: string; tenantId?: string; status?: string }, cursor: string | null = null) =>
  http.page<ClientDelivery & { tenant_name: string }>('/platform/messages/deliveries', {
    'filter[channel]': filters.channel || undefined, 'filter[tenant_id]': filters.tenantId || undefined, 'filter[status]': filters.status || undefined, cursor, per_page: 50,
  }, NO_ESTABLISHMENT);
export const fetchDirectMessages = (filters: { channel?: string }, cursor: string | null = null) =>
  http.page<DirectRow>('/platform/messages/direct', { 'filter[channel]': filters.channel || undefined, cursor, per_page: 50 }, NO_ESTABLISHMENT);
export const fetchInternalStats = async (): Promise<InternalStats[]> => (await http.get<{ data: InternalStats[] }>('/platform/messages/internal', undefined, NO_ESTABLISHMENT)).data ?? [];
export const previewDirectMessage = async (input: DirectMessageInput): Promise<DirectPreview> =>
  (await http.post<{ data: DirectPreview }>('/platform/messages/direct/preview', { ...input }, NO_ESTABLISHMENT)).data;

export const messageActions = {
  send: (input: DirectMessageInput): PlatformAction => ({ method: 'POST', path: '/platform/messages/direct', body: { ...input } }),
  retryDelivery: (tenant: string, id: string): PlatformAction => ({ method: 'POST', path: `/platform/messages/deliveries/${seg(tenant)}/${seg(id)}/retry`, body: {} }),
  retryDirect: (id: string): PlatformAction => ({ method: 'POST', path: `/platform/messages/direct/${seg(id)}/retry`, body: {} }),
};
