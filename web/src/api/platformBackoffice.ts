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
