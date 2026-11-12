// États d'une inscription et d'une pièce du dossier (module Personnes et inscriptions) : libellés traduits, jamais la clé technique.
import type { MessageKey } from '@/i18n';

export const ENROLLMENT_STATUSES = ['draft', 'active', 'conditional', 'suspended', 'withdrawn', 'transferred', 'excluded', 'graduated'] as const;
export const DOCUMENT_STATUSES = ['received', 'expiring_soon', 'expired', 'missing'] as const;

type Translate = (key: MessageKey) => string;

/** Libellé d'un état d'inscription ; un état inconnu (version plus récente du serveur) reste affiché tel quel. */
export function enrollmentStatusLabel(status: string, t: Translate): string {
  return (ENROLLMENT_STATUSES as readonly string[]).includes(status) ? t(`people.enrollmentStatus.${status}` as MessageKey) : status;
}

export function documentStatusLabel(status: string, t: Translate): string {
  return (DOCUMENT_STATUSES as readonly string[]).includes(status) ? t(`people.documentStatus.${status}` as MessageKey) : status;
}
