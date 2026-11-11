// Aides des écrans du primaire privé introduites par FIX-PRI : libellés du démarrage, résumé de la publication des bulletins,
// inscriptions « en cours » du guichet et facture de l'étape 5 de l'inscription. Fonctions pures ou à client injecté (testables).
import { http as defaultHttp, type HttpClient } from '@/api/http';
import type { Enrollment } from '@/api/types';
import type { MessageKey } from '@/i18n';

type Translate = (key: MessageKey, params?: Record<string, string | number>) => string;

/** Libellé d'un réglage du démarrage (jamais la clé technique). Clé inconnue : la clé elle-même, faute de mieux. */
export function setupKeyLabel(t: Translate, key: string): string {
  const k = `pri.setup.key.${key}`;
  const label = t(k as MessageKey);
  return label === k ? key : label;
}

/** Libellé d'une valeur de réglage (`day_part` → « par demi-journée »). Valeur absente : null (l'écran dit « non défini »). */
export function setupValueLabel(t: Translate, key: string, value: unknown): string | null {
  if (value === null || value === undefined || value === '') return null;
  const k = `pri.setup.value.${key}.${String(value)}`;
  const label = t(k as MessageKey);
  return label === k ? String(value) : label;
}

export interface PublishOutcome {
  published: number;
  notified: number;
  families?: number;
  not_notified?: Record<string, number>;
}

const KNOWN_REASONS = ['consent_missing', 'no_contact', 'opted_out', 'sms_cap_reached'];

/** Résumé de la publication : bulletins visibles, familles prévenues par SMS, et pourquoi les autres ne l'ont pas été. */
export function publishSummary(t: Translate, done: PublishOutcome): string {
  const families = done.families ?? done.notified;
  const parts = [t('pri.cards.publishedResult', { published: done.published, notified: done.notified, families })];
  if (families === 0) parts.push(t('pri.cards.noGuarantor'));
  const reasons = Object.entries(done.not_notified ?? {}).filter(([, n]) => n > 0);
  if (reasons.length > 0) {
    const details = reasons
      .map(([reason, n]) => (KNOWN_REASONS.includes(reason) ? t(`pri.cards.reason.${reason}` as MessageKey, { n }) : t('pri.cards.reason.other', { n, reason })))
      .join(' ; ');
    parts.push(t('pri.cards.notNotified', { details }));
  }
  return parts.join(' ');
}

/**
 * Statuts d'une inscription « en cours » au guichet : validée (active) ou validée sous réserve des pièces (conditional). Une
 * inscription sous réserve est une inscription en cours : le guichet la montre pour compléter le dossier, facturer et délivrer.
 */
export const DESK_STATUSES = ['active', 'conditional'] as const;

/** Inscriptions en cours de l'année (un appel par statut : le filtre du socle n'accepte qu'une valeur). */
export async function deskEnrollments(yearId: string | null, client: HttpClient = defaultHttp): Promise<Enrollment[]> {
  const lists = await Promise.all(
    DESK_STATUSES.map((status) => client.all<Enrollment>('/enrollments', { 'filter[status]': status, ...(yearId ? { 'filter[academic_year_id]': yearId } : {}) })),
  );
  return lists.flat();
}

/**
 * Facture de l'étape 5 de l'inscription : le service financier (billing.manage) passe par la facturation du socle ; le secrétariat
 * d'une école primaire (primary.enrol) par la facture d'inscription du primaire (inscription + première tranche, mêmes grilles).
 * Sans l'un ni l'autre : null (l'écran dit que les frais sont gérés par le service financier).
 */
export function enrolmentInvoiceRoute(can: (...perms: string[]) => boolean, establishmentType: string | null | undefined, enrollmentId: string): { path: string; body?: Record<string, unknown> } | null {
  if (can('billing.manage')) return { path: '/invoices', body: { enrollment_id: enrollmentId } };
  if (establishmentType === 'primary' && can('primary.enrol')) return { path: `/primary/enrolments/${enrollmentId}/invoice` };
  return null;
}

export interface CatalogueFee {
  code: string;
  name: string;
  amount_xaf: number;
}

/**
 * Frais du catalogue de facturation utilisables par un forfait de service (FIX-PRI 6) : actifs ET pourvus d'un code (la facture d'un
 * forfait retrouve son frais par ce code). Catalogue illisible (droit billing.read absent) : null, l'écran garde la saisie libre.
 */
export async function serviceFees(client: HttpClient = defaultHttp): Promise<CatalogueFee[] | null> {
  try {
    const items = await client.all<{ code?: string | null; name: string; amount_xaf: number; active?: boolean | number }>('/fee-items');
    return items
      .filter((i) => typeof i.code === 'string' && i.code !== '' && i.active !== false && i.active !== 0)
      .map((i) => ({ code: String(i.code), name: i.name, amount_xaf: i.amount_xaf }));
  } catch {
    return null;
  }
}

/** Capacité saisie (champ numérique : Vue rend un nombre, ou une chaîne vide) → entier ou null. */
export function capacityValue(raw: unknown): number | null {
  const text = String(raw ?? '').trim();
  return text === '' ? null : Number(text);
}
