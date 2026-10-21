import type { AccessRequestStatus, AppStatus, EntitlementState, PaymentStatus } from '@/api/platform';
import type { MessageKey } from '@/i18n';

// Libellés et tons des étiquettes de statut des écrans de la plateforme (texte toujours explicite, jamais la couleur seule).
type Tone = 'success' | 'warning' | 'ink' | 'neutral' | undefined;

export const stateKey = (s: EntitlementState | string): MessageKey => `plat.state.${s}` as MessageKey;
export const appStatusKey = (s: AppStatus | string): MessageKey => `plat.appStatus.${s}` as MessageKey;
export const requestStatusKey = (s: AccessRequestStatus | string): MessageKey => `plat.reqStatus.${s}` as MessageKey;
export const paymentStatusKey = (s: PaymentStatus | string): MessageKey => `plat.payStatus.${s}` as MessageKey;
export const channelKey = (c: string): MessageKey => `plat.channel.${c}` as MessageKey;
export const targetKey = (t: string | null | undefined): MessageKey => `type.${t ?? 'higher'}` as MessageKey;
export const sectorKey = (s: string): MessageKey => `plat.sector.${s === 'public' ? 'public' : 'private'}` as MessageKey;
export const sourceKey = (s: string): MessageKey => `plat.source.${s}` as MessageKey;

export function stateTone(s: string): Tone {
  if (s === 'active') return 'success';
  if (s === 'past_due' || s === 'suspended') return 'warning';
  if (s === 'closed') return 'neutral';
  return undefined;
}

export function requestTone(s: string): Tone {
  if (s === 'accepted') return 'success';
  if (s === 'rejected') return 'neutral';
  if (s === 'info_requested') return 'warning';
  return 'ink';
}

export function paymentTone(s: string): Tone {
  if (s === 'succeeded') return 'success';
  if (s === 'failed') return 'warning';
  return undefined;
}

export function appStatusTone(s: string): Tone {
  if (s === 'available') return 'success';
  if (s === 'retired') return 'neutral';
  return undefined;
}
