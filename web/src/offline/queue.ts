// File d'attente hors ligne : appels (POST /attendance-sessions/sync) et notes (POST /grades/sync).
// Principes : tout est d'abord écrit sur l'appareil ; l'envoi est idempotent (client_uuid) ; un conflit n'est jamais
// résolu automatiquement : les deux versions sont conservées jusqu'au choix de la personne.
import { ApiError, http as defaultHttp, type HttpClient } from '@/api/http';
import type { Problem, SyncResult } from '@/api/types';
import { idbAll, idbDelete, idbGet, idbPut } from './db';
import { ulid } from '@/lib/ulid';

export type QueueKind = 'attendance' | 'grade';
export type QueueStatus = 'pending' | 'conflict' | 'error';

export interface QueueItem {
  client_uuid: string;
  kind: QueueKind;
  establishment_id: string;
  /** Corps tel qu'envoyé à l'API (une séance d'appel, ou une note). */
  payload: Record<string, unknown>;
  /** Libellé affiché dans la liste d'attente (« 4e B · Mathématiques · 09/10/2026 »). */
  label: string;
  status: QueueStatus;
  created_at: string;
  attempts: number;
  message?: string;
  server_version?: Record<string, unknown>;
  client_version?: Record<string, unknown>;
}

export interface FlushReport {
  sent: number;
  conflicts: number;
  errors: number;
  /** Faux réseau ou serveur injoignable : il reste des éléments à envoyer plus tard. */
  offline: boolean;
  /** Session expirée : se reconnecter avant de réessayer. */
  unauthorized: boolean;
}

const BATCH_SIZE = 50;
let flushing: Promise<FlushReport> | null = null;
const listeners = new Set<() => void>();

export function onQueueChange(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function notify(): void {
  listeners.forEach((fn) => fn());
}

export async function enqueue(input: Omit<QueueItem, 'status' | 'created_at' | 'attempts'>): Promise<QueueItem> {
  const item: QueueItem = { ...input, status: 'pending', created_at: new Date().toISOString(), attempts: 0 };
  await idbPut('queue', item);
  notify();
  return item;
}

export async function listQueue(kind?: QueueKind): Promise<QueueItem[]> {
  const all = await idbAll<QueueItem>('queue');
  return all.filter((i) => !kind || i.kind === kind).sort((a, b) => a.created_at.localeCompare(b.created_at));
}

export async function queueCounts(): Promise<{ pending: number; conflict: number; error: number; attendance: number; grade: number }> {
  const all = await listQueue();
  return {
    pending: all.filter((i) => i.status === 'pending').length,
    conflict: all.filter((i) => i.status === 'conflict').length,
    error: all.filter((i) => i.status === 'error').length,
    attendance: all.filter((i) => i.kind === 'attendance' && i.status === 'pending').length,
    grade: all.filter((i) => i.kind === 'grade' && i.status === 'pending').length,
  };
}

/** Envoie tout ce qui attend. Un seul envoi à la fois ; un appel concurrent attend le résultat de l'envoi en cours. */
export function flushQueue(client: HttpClient = defaultHttp): Promise<FlushReport> {
  if (flushing) return flushing;
  flushing = doFlush(client).finally(() => {
    flushing = null;
    notify();
  });
  return flushing;
}

async function doFlush(client: HttpClient): Promise<FlushReport> {
  const report: FlushReport = { sent: 0, conflicts: 0, errors: 0, offline: false, unauthorized: false };
  const pending = (await listQueue()).filter((i) => i.status === 'pending');
  const groups = new Map<string, QueueItem[]>();
  for (const item of pending) {
    const key = `${item.kind}|${item.establishment_id}`;
    groups.set(key, [...(groups.get(key) ?? []), item]);
  }

  for (const items of groups.values()) {
    for (let i = 0; i < items.length; i += BATCH_SIZE) {
      const chunk = items.slice(i, i + BATCH_SIZE);
      const stop = await sendChunk(client, chunk, report);
      if (stop) return report;
    }
  }
  return report;
}

/** @returns vrai s'il faut arrêter l'envoi (réseau coupé, session expirée, serveur en difficulté). */
async function sendChunk(client: HttpClient, chunk: QueueItem[], report: FlushReport): Promise<boolean> {
  const first = chunk[0];
  if (!first) return false;
  const attendance = first.kind === 'attendance';
  const path = attendance ? '/attendance-sessions/sync' : '/grades/sync';
  const body = attendance ? { sessions: chunk.map((c) => c.payload) } : { entries: chunk.map((c) => c.payload) };

  let results: SyncResult[];
  try {
    const res = await client.post<{ data: SyncResult[] | { results: SyncResult[] } }>(path, body, { establishmentId: first.establishment_id });
    results = Array.isArray(res.data) ? res.data : (res.data?.results ?? []);
  } catch (e) {
    if (!(e instanceof ApiError)) throw e;
    if (e.network || e.status >= 500 || e.status === 429) {
      report.offline = true;
      return true;
    }
    if (e.status === 401) {
      report.unauthorized = true;
      return true;
    }
    if (e.status === 409 && Array.isArray(e.problem?.results)) {
      results = e.problem.results as SyncResult[];
    } else {
      // Refus global (droit manquant, données invalides) : rien ne sera accepté sans intervention.
      for (const item of chunk) await markError(item, e.problem, e.message);
      report.errors += chunk.length;
      return false;
    }
  }

  const byUuid = new Map(results.map((r) => [r.client_uuid, r]));
  for (const item of chunk) {
    const result = byUuid.get(item.client_uuid);
    if (!result) continue; // sans réponse : reste en attente, il sera renvoyé (l'envoi est idempotent)
    if (result.status === 'conflict') {
      const server = result.problem?.server_version ?? (result.server as unknown as Record<string, unknown> | undefined);
      const client_ = result.problem?.client_version ?? result.client;
      await idbPut('queue', {
        ...item,
        status: 'conflict',
        message: result.problem?.detail ?? result.message,
        server_version: server,
        client_version: client_ ?? item.payload,
        attempts: item.attempts + 1,
      } satisfies QueueItem);
      report.conflicts++;
    } else if (result.status === 'error' || result.status === 'rejected') {
      await markError(item, result.problem, result.message);
      report.errors++;
    } else {
      await idbDelete('queue', item.client_uuid);
      report.sent++;
    }
  }
  return false;
}

async function markError(item: QueueItem, problem: Partial<Problem> | null | undefined, fallback?: string): Promise<void> {
  const fields = problem?.errors ? Object.values(problem.errors).flat().join(' ') : '';
  const message = [problem?.detail, fields].filter(Boolean).join(' ') || fallback || '';
  await idbPut('queue', { ...item, status: 'error', message, attempts: item.attempts + 1 } satisfies QueueItem);
}

export type ConflictChoice = 'keep-server' | 'resend';

/**
 * Résout un conflit sur décision de la personne.
 * - keep-server : abandonne la version de l'appareil.
 * - resend (notes seulement) : renvoie la version de l'appareil sur la base de la version actuelle du serveur.
 */
export async function resolveConflict(uuid: string, choice: ConflictChoice): Promise<void> {
  const item = await idbGet<QueueItem>('queue', uuid);
  if (!item) return;
  if (choice === 'resend' && item.kind === 'grade') {
    const serverVersion = Number((item.server_version as { version?: unknown } | undefined)?.version);
    if (!Number.isFinite(serverVersion)) throw new Error('Version du serveur inconnue');
    const fresh = ulid(); // nouvelle clé : l'ancienne est déjà connue du serveur comme « en conflit »
    await idbDelete('queue', uuid);
    await idbPut('queue', {
      ...item,
      client_uuid: fresh,
      payload: { ...item.payload, client_uuid: fresh, base_version: serverVersion },
      status: 'pending',
      message: undefined,
      server_version: undefined,
      client_version: undefined,
      attempts: 0,
    } satisfies QueueItem);
  } else {
    await idbDelete('queue', uuid);
  }
  notify();
}

export async function retryItem(uuid: string): Promise<void> {
  const item = await idbGet<QueueItem>('queue', uuid);
  if (item) await idbPut('queue', { ...item, status: 'pending', message: undefined });
  notify();
}

export async function discardItem(uuid: string): Promise<void> {
  await idbDelete('queue', uuid);
  notify();
}
