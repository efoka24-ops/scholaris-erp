// Aides d'affichage des écrans IPES : tons des étiquettes et libellés traduits des valeurs de l'API.
import type { MessageKey } from '@/i18n';

export type Tone = 'success' | 'warning' | 'ink' | 'neutral' | undefined;

export function toneOf(status: string | null | undefined): Tone {
  switch (status) {
    case 'valid':
    case 'published':
    case 'open':
    case 'admitted':
    case 'enrolled':
    case 'verified':
    case 'paid':
    case 'approved':
    case 'active':
    case 'frozen':
    case 'graduated':
      return 'success';
    case 'expired':
    case 'rejected':
    case 'cancelled':
    case 'overdue':
    case 'financial_hold':
    case 'excluded':
    case 'withdrawn':
    case 'suspended':
    case 'missing':
      return 'warning';
    case 'draft':
    case 'pending':
    case 'upcoming':
    case 'submitted':
      return 'neutral';
    default:
      return undefined;
  }
}

/** Clé de traduction d'une valeur (`prefix` + valeur) ; le composant affiche la valeur brute si la clé manque. */
export const k = (prefix: string, value: string | null | undefined): MessageKey => `${prefix}${value ?? ''}` as MessageKey;

/** Niveau d'alerte d'expiration (90, 30 ou 7 jours) ou null. */
export function expiryThreshold(daysLeft: number | null | undefined): 90 | 30 | 7 | null {
  if (daysLeft === null || daysLeft === undefined || daysLeft < 0) return null;
  if (daysLeft <= 7) return 7;
  if (daysLeft <= 30) return 30;
  if (daysLeft <= 90) return 90;
  return null;
}

export const splitCodes = (text: string): string[] => text.split(/[,;\s]+/).map((s) => s.trim()).filter(Boolean);
