import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { idbAll } from '@/offline/db';
import {
  counters,
  cycle,
  discardCall,
  flushCalls,
  listQueue,
  loadDraft,
  loadRoster,
  retryCall,
  saveDraft,
  setArrival,
  submitCall,
} from '@/views/public-schools/offlineCalls';
import { MAX_FAILURES, lockVault, refreshVault, sealGet, sealNames, sealPut, setupPin, unlockWithPin, unlocked, wipeVault } from '@/views/public-schools/vault';
import { freshEnvironment, jsonResponse, mockApi } from './helpers';

const FAST = 1000; // itérations PBKDF2 réduites : seul le test en a besoin
const roster = Array.from({ length: 60 }, (_, i) => ({ person_id: `P${String(i).padStart(2, '0')}`, last_name: `Nom${i}`, first_name: `Prénom${i}`, sex: i % 2 ? 'F' : 'M' }));

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
});

describe('coffre de l’appareil (T645)', () => {
  it('chiffre ce qu’il garde : rien de lisible dans IndexedDB, relisible avec le code', async () => {
    await setupPin('123456', FAST);
    await sealPut('roster:E1:C1', roster);
    const stored = JSON.stringify(await idbAll('cache'));
    expect(stored).not.toContain('Nom1');
    expect(stored).not.toContain('Prénom59');
    expect(await sealGet('roster:E1:C1')).toEqual(roster);
  });

  it('refuse le contenu coffre fermé, puis le rend avec le bon code', async () => {
    await setupPin('123456', FAST);
    await sealPut('x', { a: 1 });
    lockVault();
    expect(unlocked.value).toBe(false);
    await expect(sealPut('y', 1)).rejects.toThrow();
    expect(await sealGet('x')).toBeUndefined();
    expect(await unlockWithPin('123456')).toEqual({ ok: true });
    expect(await sealGet('x')).toEqual({ a: 1 });
  });

  it('compte les échecs et efface tout au cinquième (données et appels en attente compris)', async () => {
    await setupPin('123456', FAST);
    await sealPut('roster:E1:C1', roster);
    await sealPut('queue:abc', { client_uuid: 'abc' });
    lockVault();
    for (let i = 1; i < MAX_FAILURES; i++) {
      expect(await unlockWithPin('000000')).toEqual({ ok: false, wiped: false, remaining: MAX_FAILURES - i });
    }
    expect((await refreshVault()).failures).toBe(MAX_FAILURES - 1);
    expect(await unlockWithPin('000000')).toEqual({ ok: false, wiped: true, remaining: 0 });
    expect((await refreshVault()).initialised).toBe(false);
    expect(await sealNames('')).toEqual([]);
    expect((await idbAll<{ key: string }>('cache')).filter((r) => r.key.startsWith('ps:'))).toEqual([]);
  });

  it('remet le compteur à zéro après un bon code', async () => {
    await setupPin('123456', FAST);
    lockVault();
    await unlockWithPin('111111');
    await unlockWithPin('111111');
    expect((await refreshVault()).failures).toBe(2);
    expect(await unlockWithPin('123456')).toEqual({ ok: true });
    expect((await refreshVault()).failures).toBe(0);
  });

  it('rejette un code qui n’a pas 6 chiffres', async () => {
    await expect(setupPin('12ab56', FAST)).rejects.toThrow();
    await setupPin('123456', FAST);
    lockVault();
    expect((await unlockWithPin('abc')).ok).toBe(false);
  });
});

describe('appel hors ligne de l’école publique', () => {
  beforeEach(async () => {
    await setupPin('123456', FAST);
  });

  it('tous présents par défaut : un appui absent, un second retard, un troisième présent', async () => {
    let draft = await loadDraft('E1', 'C1', '2026-10-21', 'morning');
    expect(counters(draft, 60)).toEqual({ present: 60, absent: 0, late: 0 });
    draft = cycle(draft, 'P03', '08:10');
    expect(counters(draft, 60)).toEqual({ present: 59, absent: 1, late: 0 });
    draft = cycle(draft, 'P03', '08:10');
    expect(draft.exceptions.P03).toEqual({ status: 'R', arrived_at: '08:10' });
    expect(counters(draft, 60)).toEqual({ present: 60, absent: 0, late: 1 });
    draft = setArrival(draft, 'P03', '08:25');
    expect(draft.exceptions.P03?.arrived_at).toBe('08:25');
    draft = cycle(draft, 'P03');
    expect(draft.exceptions).toEqual({});
  });

  it('le brouillon survit au rechargement (même client_uuid)', async () => {
    const draft = cycle(await loadDraft('E1', 'C1', '2026-10-21', 'morning'), 'P01');
    await saveDraft('E1', draft);
    const again = await loadDraft('E1', 'C1', '2026-10-21', 'morning');
    expect(again.client_uuid).toBe(draft.client_uuid);
    expect(again.exceptions.P01?.status).toBe('A');
    const afternoon = await loadDraft('E1', 'C1', '2026-10-21', 'afternoon');
    expect(afternoon.client_uuid).not.toBe(draft.client_uuid);
  });

  it('met l’appel en file sans réseau puis le rejoue : PUT idempotent, exceptions seulement, établissement en en-tête', async () => {
    let draft = await loadDraft('E1', 'C1', '2026-10-21', 'morning');
    draft = cycle(cycle(draft, 'P07'), 'P07', '08:05'); // retard
    draft = cycle(draft, 'P12'); // absent
    await submitCall('E1', draft, 'CM2 A');

    mockApi({
      'PUT /public/classes/C1/attendance/2026-10-21': () => {
        throw new TypeError('Failed to fetch');
      },
    });
    expect(await flushCalls()).toMatchObject({ sent: 0, offline: true });
    const queued = await listQueue();
    expect(queued).toHaveLength(1);
    expect(queued[0]).toMatchObject({ status: 'pending', absent: 1, late: 1, class_label: 'CM2 A' });

    const calls = mockApi({ 'PUT /public/classes/C1/attendance/2026-10-21': { data: { status: 'ok' } } });
    expect(await flushCalls()).toMatchObject({ sent: 1, offline: false });
    expect(await listQueue()).toHaveLength(0);
    expect(calls).toHaveLength(1);
    expect(calls[0]?.headers['X-Establishment-Id']).toBe('E1');
    expect(calls[0]?.body).toEqual({
      day_part: 'morning',
      client_uuid: draft.client_uuid,
      records: [
        { person_id: 'P07', status: 'R', arrived_at: '08:05' },
        { person_id: 'P12', status: 'A' },
      ],
    });
  });

  it('un appel sans exception (tous présents) part avec une liste vide', async () => {
    await submitCall('E1', await loadDraft('E1', 'C1', '2026-10-21', 'afternoon'), 'CM2 A');
    const calls = mockApi({ 'PUT /public/classes/C1/attendance/2026-10-21': { data: {} } });
    await flushCalls();
    expect((calls[0]?.body as { records: unknown[] }).records).toEqual([]);
  });

  it('un conflit n’est jamais écrasé : l’appel reste, marqué en conflit, jusqu’à décision', async () => {
    await submitCall('E1', cycle(await loadDraft('E1', 'C1', '2026-10-21', 'morning'), 'P01'), 'CM2 A');
    mockApi({ 'PUT /public/classes/C1/attendance/2026-10-21': jsonResponse({ type: 'about:blank', title: 'Conflit', status: 409, detail: 'Appel déjà enregistré.' }, 409) });
    expect(await flushCalls()).toMatchObject({ sent: 0, conflicts: 1 });
    const [item] = await listQueue();
    expect(item?.status).toBe('conflict');
    expect(item?.message).toContain('déjà enregistré');
    // Un second envoi ne touche plus un appel en conflit.
    const calls = mockApi({});
    await flushCalls();
    expect(calls).toHaveLength(0);
    await discardCall(item!.client_uuid);
    expect(await listQueue()).toHaveLength(0);
  });

  it('un refus (lecture seule 423, droits 403) est gardé en erreur, réessayable', async () => {
    await submitCall('E1', cycle(await loadDraft('E1', 'C1', '2026-10-21', 'morning'), 'P01'), 'CM2 A');
    mockApi({ 'PUT /public/classes/C1/attendance/2026-10-21': jsonResponse({ type: 'about:blank', title: 'Lecture seule', status: 423, detail: 'Application en lecture seule.' }, 423) });
    expect(await flushCalls()).toMatchObject({ errors: 1 });
    const [item] = await listQueue();
    expect(item?.status).toBe('error');
    await retryCall(item!.client_uuid);
    expect((await listQueue())[0]?.status).toBe('pending');
  });

  it('arrête l’envoi sur une session expirée (401) sans rien perdre', async () => {
    await submitCall('E1', cycle(await loadDraft('E1', 'C1', '2026-10-21', 'morning'), 'P01'), 'CM2 A');
    mockApi({ 'PUT /public/classes/C1/attendance/2026-10-21': jsonResponse({ type: 'about:blank', title: 'Non', status: 401, detail: 'Connectez-vous.' }, 401) });
    expect(await flushCalls()).toMatchObject({ unauthorized: true, sent: 0 });
    expect(await listQueue()).toHaveLength(1);
  });

  it('liste d’appel : lue sur le serveur, gardée chiffrée, relue sur l’appareil sans réseau', async () => {
    mockApi({ 'GET /public/classes/C1/pupils': { data: [...roster].reverse() } });
    const online = await loadRoster('E1', 'C1');
    expect(online.fromCache).toBe(false);
    expect(online.roster).toHaveLength(60);
    expect(online.roster[0]?.last_name).toBe('Nom0');
    mockApi({
      'GET /public/classes/C1/pupils': () => {
        throw new TypeError('Failed to fetch');
      },
    });
    const offline = await loadRoster('E1', 'C1');
    expect(offline.fromCache).toBe(true);
    expect(offline.roster).toHaveLength(60);
  });

  it('une erreur de droits n’est pas masquée par le cache', async () => {
    mockApi({ 'GET /public/classes/C1/pupils': { data: roster } });
    await loadRoster('E1', 'C1');
    mockApi({ 'GET /public/classes/C1/pupils': jsonResponse({ type: 'about:blank', title: 'Interdit', status: 403, detail: 'Non.' }, 403) });
    await expect(loadRoster('E1', 'C1')).rejects.toMatchObject({ status: 403 });
  });
});
