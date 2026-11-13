// Porte hors ligne de la maternelle publique (T735, C-111) : paquet du matin (GET /public-nursery/pickup/package) gardé dans le coffre
// chiffré de l'appareil, gestes de la porte (arrivée, remise, refus, maintien) écrits d'abord sur l'appareil puis rejoués au retour du
// réseau, un par un, avec un identifiant client stable (client_uuid) : un rejeu ne crée jamais un second événement côté serveur.
// Hors ligne, une remise ne se fait qu'à une personne autorisée du paquet, sans restriction de garde ; un paquet trop ancien
// (`max_age_days`) ou d'un autre jour met la porte en mode « doute » : aucune remise sans appel à la direction. Un code à usage unique
// ne se vérifie jamais hors ligne. Un conflit (remise refusée par le serveur, autorisation périmée) n'est jamais résolu en silence :
// il reste affiché jusqu'à ce que la personne le retire.
import { ApiError } from '@/api/http';
import { gate, pickupAuth, type MorningPackage, type MorningPackageChild } from '@/api/publicNursery';
import { todayIso } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { sealDelete, sealGet, sealNames, sealPut } from '../public-schools/vault';

export type GateActionType = 'arrival' | 'handover' | 'refusal' | 'hold';
export type GateQueueStatus = 'pending' | 'conflict' | 'error';

export interface GateAction {
  type: GateActionType;
  learner_id: string;
  learner_name: string;
  school_day: string;
  pickup_id?: string;
  pickup_name?: string;
  declared_name?: string;
  remark?: string;
}
export interface GateQueued extends GateAction {
  client_uuid: string;
  establishment_id: string;
  occurred_at: string;
  status: GateQueueStatus;
  /** Raison rendue par le serveur (restricted, unauthorized, stale_authorization…) ou message d'erreur. */
  reason?: string;
  message?: string;
  created_at: string;
}
export interface GateFlushReport {
  sent: number;
  conflicts: number;
  errors: number;
  offline: boolean;
  unauthorized: boolean;
}
export interface PackageState {
  ageDays: number;
  doubt: boolean;
  reason: 'fresh' | 'too_old' | 'other_day';
}

const PACKAGE = 'pn:package:';
const QUEUE = 'pn:gate:';
const isNetwork = (e: unknown): boolean => e instanceof ApiError && (e.network || e.status >= 500 || e.status === 429);

/** Horodatage ISO 8601 à la seconde avec décalage (format attendu par le serveur). */
export function atomNow(now: Date = new Date()): string {
  const pad = (n: number): string => String(Math.abs(n)).padStart(2, '0');
  const offset = -now.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  return `${todayIso(now)}T${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}${sign}${pad(Math.trunc(offset / 60))}:${pad(offset % 60)}`;
}

// ------------------------------------------------------------------------------------------------------- paquet du matin

/** Télécharge le paquet du jour et le garde chiffré ; sans réseau, rend le dernier paquet gardé. */
export async function loadPackage(establishmentId: string, date: string): Promise<{ pkg: MorningPackage; fromCache: boolean }> {
  const name = `${PACKAGE}${establishmentId}`;
  try {
    const pkg = await pickupAuth.morningPackage(date);
    await sealPut(name, pkg);
    return { pkg, fromCache: false };
  } catch (e) {
    const cached = isNetwork(e) ? await sealGet<MorningPackage>(name) : undefined;
    if (cached) return { pkg: cached, fromCache: true };
    throw e;
  }
}

export async function cachedPackage(establishmentId: string): Promise<MorningPackage | undefined> {
  return sealGet<MorningPackage>(`${PACKAGE}${establishmentId}`);
}

/** Âge du paquet ; « doute » s'il dépasse l'âge maximal paramétré ou s'il n'est pas celui du jour. */
export function packageState(pkg: MorningPackage, today: string, now: Date = new Date()): PackageState {
  const generated = Date.parse(pkg.generated_at);
  const ageDays = Number.isNaN(generated) ? Number.POSITIVE_INFINITY : Math.floor((now.getTime() - generated) / 86_400_000);
  if (pkg.max_age_days !== null && ageDays > pkg.max_age_days) return { ageDays, doubt: true, reason: 'too_old' };
  if (pkg.date !== today) return { ageDays, doubt: true, reason: 'other_day' };
  return { ageDays, doubt: false, reason: 'fresh' };
}

/** Personnes à qui l'enfant peut être remis hors ligne : autorisées au paquet et non visées par une restriction de garde. */
export function handoverCandidates(child: MorningPackageChild): MorningPackageChild['authorized'] {
  const restricted = new Set(child.restrictions.map((r) => r.restricted_pickup_id).filter((id): id is string => !!id));
  return child.authorized.filter((p) => !restricted.has(p.id));
}

// -------------------------------------------------------------------------------------------------------------- file

export async function listGateQueue(): Promise<GateQueued[]> {
  const items: GateQueued[] = [];
  for (const name of await sealNames(QUEUE)) {
    const item = await sealGet<GateQueued>(name);
    if (item) items.push(item);
  }
  return items.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

const listeners = new Set<() => void>();
export function onGateQueueChange(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function notify(): void {
  listeners.forEach((fn) => fn());
}
async function store(item: GateQueued): Promise<void> {
  await sealPut(`${QUEUE}${item.client_uuid}`, item);
  notify();
}

export class GateRefusal extends Error {
  constructor(readonly code: 'doubt' | 'not_authorized' | 'remark_required' | 'name_required') {
    super(code);
    this.name = 'GateRefusal';
  }
}

/**
 * Enregistre un geste sur l'appareil (avant tout envoi). Hors ligne, la remise n'est acceptée que pour une personne du paquet, sans
 * restriction, et jamais en mode « doute ».
 */
export async function recordGate(establishmentId: string, action: GateAction, context: { pkg: MorningPackage | null; doubt: boolean }): Promise<GateQueued> {
  if (action.type === 'handover') {
    if (context.doubt || !context.pkg) throw new GateRefusal('doubt');
    const child = context.pkg.children.find((c) => c.id === action.learner_id);
    const person = child ? handoverCandidates(child).find((p) => p.id === action.pickup_id) : undefined;
    if (!person) throw new GateRefusal('not_authorized');
    action = { ...action, pickup_name: person.name };
  }
  if (action.type === 'hold' && !(action.remark ?? '').trim()) throw new GateRefusal('remark_required');
  if (action.type === 'refusal' && !(action.declared_name ?? '').trim()) throw new GateRefusal('name_required');
  const now = new Date();
  const item: GateQueued = {
    ...action,
    remark: action.remark?.trim(),
    declared_name: action.declared_name?.trim(),
    client_uuid: ulid(),
    establishment_id: establishmentId,
    occurred_at: atomNow(now),
    status: 'pending',
    created_at: now.toISOString(),
  };
  await store(item);
  return item;
}

let flushing: Promise<GateFlushReport> | null = null;

/** Rejoue les gestes en attente, dans l'ordre, un seul envoi à la fois. */
export function flushGate(): Promise<GateFlushReport> {
  if (flushing) return flushing;
  flushing = doFlush().finally(() => {
    flushing = null;
    notify();
  });
  return flushing;
}

async function send(item: GateQueued): Promise<{ reason?: string }> {
  const common = { learner_id: item.learner_id, school_day: item.school_day, client_uuid: item.client_uuid, occurred_at: item.occurred_at };
  switch (item.type) {
    case 'arrival':
      await gate.arrival(common);
      return {};
    case 'hold':
      await gate.hold({ ...common, remark: item.remark ?? '' });
      return {};
    case 'handover': {
      const decision = await gate.handover({ learner_id: item.learner_id, school_day: item.school_day, client_uuid: item.client_uuid, origin: 'offline', pickup_id: item.pickup_id, declared_name: item.pickup_name });
      return { reason: decision.reason };
    }
    case 'refusal':
      await gate.handover({ learner_id: item.learner_id, school_day: item.school_day, client_uuid: item.client_uuid, origin: 'offline', declared_name: item.declared_name || '—' });
      return {};
  }
}

async function doFlush(): Promise<GateFlushReport> {
  const report: GateFlushReport = { sent: 0, conflicts: 0, errors: 0, offline: false, unauthorized: false };
  for (const item of (await listGateQueue()).filter((i) => i.status === 'pending')) {
    try {
      const { reason } = await send(item);
      if (reason === 'stale_authorization') {
        // L'enfant est parti avec une autorisation retirée depuis le paquet : enregistré par le serveur, alerte envoyée, et signalé ici.
        await store({ ...item, status: 'conflict', reason });
        report.conflicts++;
      } else {
        await sealDelete(`${QUEUE}${item.client_uuid}`);
        report.sent++;
      }
    } catch (e) {
      if (!(e instanceof ApiError)) throw e;
      if (isNetwork(e)) {
        report.offline = true;
        return report;
      }
      if (e.status === 401) {
        report.unauthorized = true;
        return report;
      }
      const refused = e.status === 422 ? (e.problem as { data?: { status?: string; reason?: string } } | null)?.data : undefined;
      if (refused?.status === 'refused' && item.type === 'refusal') {
        await sealDelete(`${QUEUE}${item.client_uuid}`); // refus attendu : enregistré et alerté par le serveur
        report.sent++;
        continue;
      }
      if (refused?.status === 'refused' || e.status === 409) {
        await store({ ...item, status: 'conflict', reason: refused?.reason, message: e.problem?.detail || undefined });
        report.conflicts++;
        continue;
      }
      await store({ ...item, status: 'error', message: [e.problem?.detail, ...Object.values(e.fieldErrors).flat()].filter(Boolean).join(' ') || e.message });
      report.errors++;
    }
  }
  return report;
}

export async function retryGate(uuid: string): Promise<void> {
  const item = await sealGet<GateQueued>(`${QUEUE}${uuid}`);
  if (item) await store({ ...item, status: 'pending', reason: undefined, message: undefined });
}

export async function discardGate(uuid: string): Promise<void> {
  await sealDelete(`${QUEUE}${uuid}`);
  notify();
}
