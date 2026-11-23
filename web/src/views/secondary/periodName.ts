// Libellé lisible d'une période du socle (C-118) : « Trimestre 1 », « Séquence 2 », « Semestre 1 » ; sinon son libellé, en dernier
// recours son identifiant. Partagé par les écrans Notes et Conseil de classe du secondaire.
import type { MessageKey } from '@/i18n';

export interface NamedPeriod {
  id: string;
  kind?: string;
  ordinal?: number | null;
  label?: string | null;
  name?: string | null;
}

const KINDS = ['trimester', 'sequence', 'semester'];

export function periodName(p: NamedPeriod, t: (key: MessageKey, params?: Record<string, string | number>) => string): string {
  if (p.kind !== undefined && KINDS.includes(p.kind) && typeof p.ordinal === 'number') return `${t(`admin.period.${p.kind}` as MessageKey)} ${p.ordinal}`;
  const own = (p.label ?? p.name ?? '').trim();
  return own !== '' ? own : p.id;
}
