import { beforeEach, describe, expect, it } from 'vitest';
import { discardItem, enqueue, flushQueue, listQueue, queueCounts, resolveConflict, retryItem } from '@/offline/queue';
import { queueGradeEdit, validateScore, parseScore } from '@/offline/grades';
import { clearStatus, counters, loadDraft, saveDraft, setStatus, submitCall } from '@/offline/attendance';
import { ulid } from '@/lib/ulid';
import { freshEnvironment, jsonResponse, mockApi } from './helpers';

beforeEach(freshEnvironment);

const session = (uuid: string, extra: Record<string, unknown> = {}) => ({
  client_uuid: uuid,
  slot_id: 'S1',
  date: '2026-10-09',
  validate: true,
  records: [{ person_id: 'A', status: 'P', client_uuid: ulid() }],
  ...extra,
});

async function addCall(est = 'E1', uuid: string = ulid()): Promise<string> {
  await enqueue({ client_uuid: uuid, kind: 'attendance', establishment_id: est, label: '4e B · Maths', payload: session(uuid) });
  return uuid;
}

describe("file d'attente : appels", () => {
  it("envoie vers /attendance-sessions/sync avec l'établissement de l'élément puis vide la file", async () => {
    const uuid = await addCall('E7');
    const calls = mockApi({ 'POST /attendance-sessions/sync': (c) => ({ data: [{ client_uuid: (c.body?.sessions as Array<{ client_uuid: string }>)[0]?.client_uuid, status: 'synced' }] }) });
    const report = await flushQueue();
    expect(report).toMatchObject({ sent: 1, conflicts: 0, errors: 0, offline: false });
    expect(calls).toHaveLength(1);
    expect(calls[0]?.headers['X-Establishment-Id']).toBe('E7');
    expect((calls[0]?.body?.sessions as unknown[]).length).toBe(1);
    expect(await listQueue()).toHaveLength(0);
    expect(uuid).toBeTruthy();
  });

  it('garde tout en attente sans réseau, puis rejoue le même client_uuid (jamais de doublon)', async () => {
    const uuid = await addCall();
    mockApi({ 'POST /attendance-sessions/sync': () => {
      throw new TypeError('Failed to fetch');
    } });
    const offline = await flushQueue();
    expect(offline.offline).toBe(true);
    expect((await queueCounts()).pending).toBe(1);

    const calls = mockApi({ 'POST /attendance-sessions/sync': () => ({ data: [{ client_uuid: uuid, status: 'synced', replayed: true }] }) });
    const online = await flushQueue();
    expect(online.sent).toBe(1);
    expect((calls[0]?.body?.sessions as Array<{ client_uuid: string }>)[0]?.client_uuid).toBe(uuid);
    expect(await listQueue()).toHaveLength(0);
  });

  it('conserve un conflit avec les deux versions et ne le renvoie pas tout seul', async () => {
    const uuid = await addCall();
    const server = { id: 'SES1', status: 'validated', present_count: 38 };
    const client = { status: 'open', present_count: 40 };
    mockApi({ 'POST /attendance-sessions/sync': { data: [{ client_uuid: uuid, status: 'conflict', problem: { status: 409, detail: "L'appel est déjà validé.", server_version: server, client_version: client } }] } });
    const report = await flushQueue();
    expect(report.conflicts).toBe(1);
    const [item] = await listQueue();
    expect(item?.status).toBe('conflict');
    expect(item?.server_version).toEqual(server);
    expect(item?.client_version).toEqual(client);

    const calls = mockApi({ 'POST /attendance-sessions/sync': { data: [] } });
    await flushQueue();
    expect(calls).toHaveLength(0); // un conflit n'est jamais renvoyé automatiquement

    await resolveConflict(uuid, 'keep-server');
    expect(await listQueue()).toHaveLength(0);
  });

  it('marque en erreur un refus global (422) sans le perdre, et permet de le rejouer ou de l\'abandonner', async () => {
    const uuid = await addCall();
    mockApi({ 'POST /attendance-sessions/sync': jsonResponse({ type: 't', title: 'Invalide', status: 422, detail: 'Il manque le statut de 2 élèves.', trace_id: 'x' }, 422) });
    const report = await flushQueue();
    expect(report.errors).toBe(1);
    const [item] = await listQueue();
    expect(item?.status).toBe('error');
    expect(item?.message).toContain('Il manque');

    await retryItem(uuid);
    expect((await listQueue())[0]?.status).toBe('pending');
    await discardItem(uuid);
    expect(await listQueue()).toHaveLength(0);
  });

  it("s'arrête sur une session expirée (401) et garde les éléments", async () => {
    await addCall();
    mockApi({ 'POST /attendance-sessions/sync': jsonResponse({ type: 't', title: 'Non authentifié', status: 401, detail: '', trace_id: 'x' }, 401) });
    const report = await flushQueue();
    expect(report.unauthorized).toBe(true);
    expect((await queueCounts()).pending).toBe(1);
  });

  it('envoie par lots de 50', async () => {
    for (let i = 0; i < 60; i++) await addCall();
    const calls = mockApi({ 'POST /attendance-sessions/sync': (c) => ({ data: (c.body?.sessions as Array<{ client_uuid: string }>).map((s) => ({ client_uuid: s.client_uuid, status: 'synced' })) }) });
    const report = await flushQueue();
    expect(calls).toHaveLength(2);
    expect(report.sent).toBe(60);
  });

  it('regroupe par établissement : un envoi par X-Establishment-Id', async () => {
    await addCall('E1');
    await addCall('E2');
    const calls = mockApi({ 'POST /attendance-sessions/sync': (c) => ({ data: (c.body?.sessions as Array<{ client_uuid: string }>).map((s) => ({ client_uuid: s.client_uuid, status: 'synced' })) }) });
    await flushQueue();
    expect(calls.map((c) => c.headers['X-Establishment-Id']).sort()).toEqual(['E1', 'E2']);
  });
});

describe("file d'attente : notes", () => {
  it("garde la version de base d'origine quand une même cellule est modifiée deux fois hors ligne", async () => {
    await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P1', value: 12, absence: null, base_version: 3 }, 'P1 · Devoir');
    await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P1', value: 14, absence: null, base_version: 3 }, 'P1 · Devoir');
    const items = await listQueue('grade');
    expect(items).toHaveLength(1);
    expect(items[0]?.payload).toMatchObject({ value: 14, base_version: 3 });
  });

  it('enregistre les notes acceptées et conserve celles en conflit (409 avec les deux versions)', async () => {
    const ok = await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P1', value: 12, absence: null, base_version: 1 }, 'ok');
    const ko = await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P2', value: 8, absence: null, base_version: 1 }, 'conflit');
    const serverGrade = { id: 'G2', assessment_id: 'AS1', person_id: 'P2', score: 15, absence: null, version: 4 };
    const calls = mockApi({
      'POST /grades/sync': jsonResponse(
        {
          type: 'https://api.scholaris.cm/errors/conflict', title: 'Conflit', status: 409, detail: '1 note(s) ont été modifiées entre-temps.',
          results: [
            { client_uuid: ok.client_uuid, status: 'updated' },
            { client_uuid: ko.client_uuid, status: 'conflict', server: serverGrade, client: { value: 8 } },
          ],
        },
        409,
      ),
    });
    const report = await flushQueue();
    expect(calls[0]?.path).toBe('/grades/sync');
    expect(Object.keys(calls[0]?.body ?? {})).toEqual(['entries']);
    expect(report).toMatchObject({ sent: 1, conflicts: 1 });
    const left = await listQueue();
    expect(left).toHaveLength(1);
    expect(left[0]).toMatchObject({ status: 'conflict', server_version: serverGrade, client_version: { value: 8 } });
  });

  it('renvoie « ma version » sur la base de la version du serveur, avec une nouvelle clé', async () => {
    const ko = await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P2', value: 8, absence: null, base_version: 1 }, 'c');
    mockApi({ 'POST /grades/sync': jsonResponse({ type: 't', title: 'Conflit', status: 409, detail: '', results: [{ client_uuid: ko.client_uuid, status: 'conflict', server: { id: 'G', assessment_id: 'AS1', person_id: 'P2', score: 15, absence: null, version: 4 } }] }, 409) });
    await flushQueue();
    await resolveConflict(ko.client_uuid, 'resend');
    const [item] = await listQueue('grade');
    expect(item?.status).toBe('pending');
    expect(item?.client_uuid).not.toBe(ko.client_uuid);
    expect(item?.payload).toMatchObject({ base_version: 4, value: 8, client_uuid: item?.client_uuid });
  });

  it('traite un rejet individuel comme une erreur à corriger', async () => {
    const bad = await queueGradeEdit('E1', { assessment_id: 'AS1', person_id: 'P3', value: 25, absence: null, base_version: 0 }, 'x');
    mockApi({ 'POST /grades/sync': { data: { results: [{ client_uuid: bad.client_uuid, status: 'rejected', message: '25 dépasse le maximum de 20.' }] } } });
    const report = await flushQueue();
    expect(report.errors).toBe(1);
    expect((await listQueue())[0]).toMatchObject({ status: 'error', message: '25 dépasse le maximum de 20.' });
  });

  it('contrôle la saisie comme le serveur', () => {
    expect(validateScore(22, 20)).toBe('over');
    expect(validateScore(-1, 20)).toBe('negative');
    expect(validateScore(null, 20)).toBe('ok');
    expect(validateScore(20, 20)).toBe('ok');
    expect(parseScore('14,5')).toBe(14.5);
    expect(parseScore('')).toBeNull();
    expect(Number.isNaN(parseScore('abc'))).toBe(true);
  });
});

describe('appel : brouillon et validation', () => {
  it('compte P / A / R et exige un statut par élève avant de valider', async () => {
    const roster = [
      { person_id: 'A', name: 'ATEBA Yann' },
      { person_id: 'B', name: 'BELLO Nadia' },
      { person_id: 'C', name: 'CHIA Paul' },
    ];
    let draft = await loadDraft('E1', 'S1', '2026-10-09');
    draft = setStatus(draft, 'A', 'P');
    draft = setStatus(draft, 'B', 'A');
    expect(counters(draft)).toEqual({ present: 1, absent: 1, late: 0, recorded: 2 });
    await expect(submitCall('E1', draft, roster, 'x')).rejects.toThrow(/manque/);

    draft = setStatus(draft, 'C', 'R', '08:12');
    expect(draft.lines.C).toMatchObject({ status: 'R', arrived_at: '08:12' });
    draft = clearStatus(draft, 'A');
    expect(counters(draft).recorded).toBe(2);
    draft = setStatus(draft, 'A', 'P');

    await saveDraft('E1', draft);
    const reloaded = await loadDraft('E1', 'S1', '2026-10-09');
    expect(reloaded.client_uuid).toBe(draft.client_uuid); // le brouillon survit au rechargement, avec la même clé

    await submitCall('E1', reloaded, roster, '4e B');
    const [queued] = await listQueue('attendance');
    expect(queued?.client_uuid).toBe(draft.client_uuid);
    expect(queued?.payload).toMatchObject({ slot_id: 'S1', date: '2026-10-09', validate: true });
    expect((queued?.payload.records as unknown[]).length).toBe(3);
    const fresh = await loadDraft('E1', 'S1', '2026-10-09');
    expect(Object.keys(fresh.lines)).toHaveLength(0); // brouillon effacé après mise en file
  });
});
