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
