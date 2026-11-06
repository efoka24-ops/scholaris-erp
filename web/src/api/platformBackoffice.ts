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
