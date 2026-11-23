import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import type { ContextTile } from '@/api/types';
import { attachmentProblem, type Assessment, type Grid } from '@/api/higher-grades';
import { http } from '@/api/http';
import { higherGradesRoutes } from '@/views/higher/grades/routes';
import AssessmentGridView from '@/views/higher/grades/AssessmentGridView.vue';
import CorrectionRequest from '@/views/higher/grades/CorrectionRequest.vue';
import { flushQueue, listQueue, resolveConflict } from '@/offline/queue';
import { queueHigherMark } from '@/offline/higherMarks';
import { useOfflineStore } from '@/stores/offline';
import { IpesDefaultsCodes } from './higher-grades.fixtures';
import { flushPromises, freshEnvironment, mockApi, signIn, teacherMe, tile } from './helpers';

const PERMS = ['higher.access', 'higher.grades.read', 'assessment.enter', 'higher.grades.submit'];
const base = {
  'GET /me/applications': { data: [{ app_key: 'higher', state: 'active' }] },
  'GET /higher/me': { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: ['enseignant'], all_schools: true, school_ids: [], school_selector: false } },
  'GET /higher/groups': { data: [] },
  'GET /higher/curricula': { data: [] },
};

const assessment: Assessment = {
  id: 'A1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L1', group_id: null, academic_year_id: 'Y1', curriculum_id: 'C1', unit_id: 'U1',
  element_id: 'EC1', element_code: 'INF111', element_title: 'Algorithmique', semester_ordinal: 1, kind: 'cc', exam_session: 'normal', label: 'Devoir 1',
  held_on: null, entry_due_on: null, max_score: 20, max_score_label: '20', weight_percent: 40, score_step: 0.25, anonymous: false, anonymity_lifted: false,
  status: 'draft', status_trail: [], version: 1, created_by: 'U1', created_at: '2026-10-01T08:00:00Z', updated_at: '2026-10-01T08:00:00Z',
};
const grid: Grid = {
  assessment, editable: true, next_states: ['submitted'], absence_codes: IpesDefaultsCodes, entered: 1, total: 2,
  lines: [
    { mark_id: 'M1', person_id: 'P1', matricule: 'MAT-01', last_name: 'Ngono', first_name: 'Rachel', group_id: null, anonymous_code: null, score: 12, absence: null, version: 2 },
    { mark_id: 'M2', person_id: 'P2', matricule: 'MAT-02', last_name: 'Obama', first_name: 'Cédric', group_id: null, anonymous_code: null, score: null, absence: null, version: 0 },
  ],
};

async function mountGrid() {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherGradesRoutes] });
  await router.push('/superieur/notes/evaluations/A1');
  await router.isReady();
  const wrapper = mount(AssessmentGridView, { props: { id: 'A1' }, global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

const edit = { assessment_id: 'A1', mark_id: 'M2', person_id: 'P2', score: 14.5, absence: null, base_version: 0 };

describe('IPES · notes hors ligne (file « higher-marks »)', () => {
  beforeEach(() => {
    freshEnvironment();
    signIn(teacherMe, [{ ...tile({ type: 'higher' }), permissions: PERMS } as ContextTile]);
  });

  it('rejoue vers /higher/grades/sync au format attendu par le serveur', async () => {
    const item = await queueHigherMark('E1', edit, 'INF111 · Devoir 1 · Obama');
    const calls = mockApi({ 'POST /higher/grades/sync': (c) => ({ data: (c.body?.entries as Array<{ client_uuid: string }>).map((e) => ({ client_uuid: e.client_uuid, status: 'applied', version: 1 })) }) });
    const report = await flushQueue();
    expect(report).toMatchObject({ sent: 1, conflicts: 0, errors: 0 });
    expect(calls[0]?.headers['X-Establishment-Id']).toBe('E1');
    expect(calls[0]?.body).toEqual({ entries: [{ client_uuid: item.client_uuid, assessment_id: 'A1', mark_id: 'M2', person_id: 'P2', base_version: 0, score: 14.5 }] });
    expect(await listQueue()).toHaveLength(0);
  });

  it('une case modifiée deux fois garde sa version de base ; un conflit est conservé puis renvoyé sur la version du serveur', async () => {
    await queueHigherMark('E1', edit, 'x');
    await queueHigherMark('E1', { ...edit, score: 15, base_version: 7 }, 'x');
    const [only] = await listQueue('higher-marks');
    expect([only?.payload.score, only?.payload.base_version]).toEqual([15, 0]);

    mockApi({ 'POST /higher/grades/sync': (c) => ({ data: [{ client_uuid: (c.body?.entries as Array<{ client_uuid: string }>)[0]?.client_uuid, status: 'conflict', server: { version: 3, score: 9, absence: null }, client: { score: 15, absence: null } }] }) });
    expect((await flushQueue()).conflicts).toBe(1);
    const [conflict] = await listQueue('higher-marks');
    expect(conflict?.status).toBe('conflict');
    expect(conflict?.server_version).toEqual({ version: 3, score: 9, absence: null });

    await resolveConflict(conflict!.client_uuid, 'resend');
    const [again] = await listQueue('higher-marks');
    expect([again?.status, again?.payload.base_version, again?.payload.score]).toEqual(['pending', 3, 15]);
  });

  it('hors ligne : la grille enregistre sur l\'appareil (en attente), puis montre le conflit sans le résoudre', async () => {
    const calls = mockApi({ ...base, 'GET /higher/grade-assessments/A1/marks': { data: grid } });
    const wrapper = await mountGrid();
    useOfflineStore().online = false;
    await flushPromises();
    expect(wrapper.find('[data-test="grid-offline"]').exists()).toBe(true);

    await wrapper.findAll('input.hg-cell')[1]!.setValue('14,5');
    await wrapper.find('[data-test="grid-save"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'PUT' || c.path === '/higher/grades/sync')).toBe(false);
    expect(wrapper.find('[data-test="state-M2"]').text()).toBe('En attente');
    expect((wrapper.findAll('input.hg-cell')[1]!.element as HTMLInputElement).value).toBe('14,5');

    mockApi({
      ...base,
      'GET /higher/grade-assessments/A1/marks': { data: grid },
      'POST /higher/grades/sync': (c) => ({ data: [{ client_uuid: (c.body?.entries as Array<{ client_uuid: string }>)[0]?.client_uuid, status: 'conflict', server: { version: 1, score: 11, absence: null }, client: { score: 14.5, absence: null } }] }),
    });
    useOfflineStore().online = true;
    await useOfflineStore().sync();
    await flushPromises();
    const box = wrapper.find('[data-test="grid-offline-conflict"]');
    expect(box.exists()).toBe(true);
    expect(box.text()).toContain('11');
    expect(box.text()).toContain('14,5');
    expect(wrapper.find('[data-test="state-M2"]').text()).toBe('Conflit');

    await wrapper.find('[data-test="offline-keep-server"]').trigger('click');
    await flushPromises();
    expect(await listQueue('higher-marks')).toHaveLength(0);
    expect(wrapper.find('[data-test="grid-offline-conflict"]').exists()).toBe(false);
  });

  it('envoyé : l\'état passe à « Envoyé » après le rejeu', async () => {
    mockApi({
      ...base,
      'GET /higher/grade-assessments/A1/marks': { data: grid },
      'POST /higher/grades/sync': (c) => ({ data: (c.body?.entries as Array<{ client_uuid: string }>).map((e) => ({ client_uuid: e.client_uuid, status: 'applied', version: 1 })) }),
    });
    const wrapper = await mountGrid();
    useOfflineStore().online = false;
    await wrapper.findAll('input.hg-cell')[1]!.setValue('14,5');
    await wrapper.find('[data-test="grid-save"]').trigger('click');
    await flushPromises();
    useOfflineStore().online = true;
    await useOfflineStore().sync();
    await flushPromises();
    expect(wrapper.find('[data-test="state-M2"]').text()).toBe('Envoyé');
  });

  it('sans réseau, la grille est relue depuis l\'appareil', async () => {
    mockApi({ ...base, 'GET /higher/grade-assessments/A1/marks': { data: grid } });
    (await mountGrid()).unmount();
    mockApi({ ...base, 'GET /higher/grade-assessments/A1/marks': () => { throw new TypeError('Failed to fetch'); } });
    const wrapper = await mountGrid();
    expect(wrapper.findAll('[data-test="grid-line"]')).toHaveLength(2);
    expect(wrapper.find('[data-test="grid-offline"]').text()).toContain('dernière lecture');
  });
});

describe('IPES · pièce jointe d\'une demande de correction', () => {
  beforeEach(() => {
    freshEnvironment();
    signIn(teacherMe, [{ ...tile({ type: 'higher' }), permissions: PERMS } as ContextTile]);
  });

  it('contrôle type et taille avant envoi', () => {
    expect(attachmentProblem(null)).toBe('missing');
    expect(attachmentProblem({ size: 10, type: 'text/plain' })).toBe('type');
    expect(attachmentProblem({ size: 6 * 1024 * 1024, type: 'application/pdf' })).toBe('size');
    expect(attachmentProblem({ size: 10, type: 'image/png' })).toBeNull();
  });

  it('envoie le fichier en multipart (champ attachment) ; refuse un type non accepté', async () => {
    const sent: FormData[] = [];
    http.configure({
      baseUrl: '/api/v1', getEstablishmentId: () => 'E1', getLocale: () => 'fr', onUnauthorized: undefined,
      fetchImpl: (async (_input: RequestInfo | URL, init?: RequestInit) => {
        if (init?.body instanceof FormData) sent.push(init.body);
        return new Response(JSON.stringify({ data: { id: 'C1' } }), { status: 201, headers: { 'Content-Type': 'application/json' } });
      }) as typeof fetch,
    });
    const wrapper = mount(CorrectionRequest, { props: { markId: 'M1', title: 'Ngono', max: 20, step: 0.25, codes: IpesDefaultsCodes } });
    await wrapper.find('#cr-score').setValue('13');
    await wrapper.find('#cr-reason').setValue('Erreur de report');
    const input = wrapper.find('[data-test="correction-file"]');
    const pick = async (file: File) => {
      Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
      await input.trigger('change');
    };

    await pick(new File(['MZ'], 'outil.exe', { type: 'application/x-msdownload' }));
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(sent).toHaveLength(0);
    expect(wrapper.find('[data-test="correction-file-error"]').text()).toContain('PDF');

    await pick(new File(['%PDF-1.4'], 'copie.pdf', { type: 'application/pdf' }));
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(sent).toHaveLength(1);
    expect(sent[0]?.get('score')).toBe('13');
    expect(sent[0]?.get('reason')).toBe('Erreur de report');
    expect(sent[0]?.get('attachment')).toBeInstanceOf(Blob);
    expect(wrapper.emitted('sent')).toBeTruthy();
  });
});
