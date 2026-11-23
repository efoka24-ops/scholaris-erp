// Sortie sécurisée hors ligne : paquet du matin gardé sur l'appareil et gestes (remise, refus, maintien) mis en file puis rejoués dès que
// le réseau revient, par les routes en ligne avec `origin: offline` et `client_uuid` : le serveur est idempotent (jamais de doublon).
// Au-delà de l'âge limite du paquet (lu de /settings par le serveur), l'écran passe en mode « doute » : aucune remise sans appel à la direction.
import { cacheDelete, cacheGet, cachePut } from '@/offline/db';
import { nursery, type PickupPackage } from '@/api/nurseryPrivate';
import { newUuid } from './useNur';

export type OutboxKind = 'handover' | 'refusal' | 'hold';

export interface OutboxItem {
  client_uuid: string;
  kind: OutboxKind;
  establishment_id: string;
  label: string;
  payload: Record<string, unknown>;
  occurred_at: string;
}

const PACKAGE_KEY = (est: string, section: string): string => `nursery:package:${est}:${section}`;
const OUTBOX_KEY = (est: string): string => `nursery:outbox:${est}`;

export async function savePackage(est: string, pkg: PickupPackage): Promise<void> {
  await cachePut(PACKAGE_KEY(est, pkg.section_id), pkg);
}

export async function cachedPackage(est: string, section: string): Promise<PickupPackage | null> {
  return (await cacheGet<PickupPackage>(PACKAGE_KEY(est, section))) ?? null;
}

/** Âge du paquet en jours entiers. */
export function packageAgeDays(pkg: PickupPackage, now: Date = new Date()): number {
  return Math.floor((now.getTime() - new Date(pkg.generated_at).getTime()) / 86_400_000);
}

/** Mode « doute » : paquet périmé selon la limite du serveur, ou limite non communiquée. */
export function isDoubtful(pkg: PickupPackage | null, now: Date = new Date()): boolean {
  if (pkg === null || pkg.max_age_days === null) return true;
  return packageAgeDays(pkg, now) > pkg.max_age_days;
}

export async function listOutbox(est: string): Promise<OutboxItem[]> {
  return (await cacheGet<OutboxItem[]>(OUTBOX_KEY(est))) ?? [];
}

export async function enqueueGesture(est: string, kind: OutboxKind, label: string, payload: Record<string, unknown>): Promise<OutboxItem> {
  const item: OutboxItem = { client_uuid: newUuid(), kind, establishment_id: est, label, payload, occurred_at: new Date().toISOString() };
  await cachePut(OUTBOX_KEY(est), [...(await listOutbox(est)), item]);
  return item;
}

/** Rejoue la file : un geste accepté (ou déjà vu) sort de la file ; une erreur réseau le garde ; un refus métier est rendu à l'appelant. */
export async function flushOutbox(est: string): Promise<{ sent: number; failed: { item: OutboxItem; error: unknown }[] }> {
  const remaining: OutboxItem[] = [];
  const failed: { item: OutboxItem; error: unknown }[] = [];
  let sent = 0;
  for (const item of await listOutbox(est)) {
    const body = { ...item.payload, origin: 'offline', client_uuid: item.client_uuid, occurred_at: item.occurred_at };
    try {
      if (item.kind === 'handover') await nursery.handover(body);
      else if (item.kind === 'refusal') await nursery.refusal(body);
      else await nursery.hold(body);
      ++sent;
    } catch (error) {
      const status = (error as { status?: number }).status;
      if (status === undefined || status >= 500) remaining.push(item);
      else if (status === 422 && item.kind === 'handover') ++sent; // refus enregistré par le serveur (alerte envoyée) : le geste est traité
      else {
        failed.push({ item, error });
        remaining.push(item);
      }
    }
  }
  if (remaining.length === 0) await cacheDelete(OUTBOX_KEY(est));
  else await cachePut(OUTBOX_KEY(est), remaining);

  return { sent, failed };
}
