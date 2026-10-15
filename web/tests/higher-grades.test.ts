import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import type { ContextTile } from '@/api/types';
import type { Assessment, Grid } from '@/api/higher-grades';
import { higherGradesRoutes } from '@/views/higher/grades/routes';
import AssessmentsView from '@/views/higher/grades/AssessmentsView.vue';
import AssessmentGridView from '@/views/higher/grades/AssessmentGridView.vue';
import CorrectionsView from '@/views/higher/grades/CorrectionsView.vue';
import MyResultsView from '@/views/higher/grades/MyResultsView.vue';
import { parseCell } from '@/views/higher/grades/marks';
import { IpesDefaultsCodes } from './higher-grades.fixtures';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile, type RecordedCall } from './helpers';

const PERMS = ['higher.access', 'higher.grades.read', 'assessment.enter', 'higher.grades.submit', 'assessment.validate', 'higher.grades.publish', 'assessment.correction.approve'];

function higherTile(permissions: string[] = PERMS, role = 'enseignant'): ContextTile {
  return { ...tile({ type: 'higher', roles: [{ code: role, label: role }] }), permissions } as ContextTile;
}

const base = {
  'GET /me/applications': { data: [{ app_key: 'higher', state: 'active' }] },
  'GET /higher/me': { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: ['enseignant'], all_schools: true, school_ids: [], school_selector: false } },
  'GET /higher/groups': { data: [] },
  'GET /higher/curricula': { data: [] },
};

function assessment(over: Partial<Assessment> = {}): Assessment {
  return {
    id: 'A1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L1', group_id: null, academic_year_id: 'Y1', curriculum_id: 'C1', unit_id: 'U1',
    element_id: 'EC1', element_code: 'INF111', element_title: 'Algorithmique', semester_ordinal: 1, kind: 'cc', exam_session: 'normal', label: 'Devoir 1',
    held_on: null, entry_due_on: '2026-10-20', max_score: 20, max_score_label: '20', weight_percent: 40, score_step: 0.25, anonymous: false, anonymity_lifted: false,
    status: 'draft', status_trail: [], version: 1, created_by: 'U1', created_at: '2026-10-01T08:00:00Z', updated_at: '2026-10-01T08:00:00Z', ...over,
  };
}

function grid(over: Partial<Assessment> = {}, editable = true): Grid {
  return {
    assessment: assessment(over), editable, next_states: editable ? ['submitted'] : over.status === 'validated' ? ['locked'] : over.status === 'locked' ? ['published'] : [],
    absence_codes: IpesDefaultsCodes,
    lines: [
      { mark_id: 'M1', person_id: 'P1', matricule: 'MAT-01', last_name: 'Ngono', first_name: 'Rachel', group_id: null, anonymous_code: null, score: 12, absence: null, version: 2 },
      { mark_id: 'M2', person_id: 'P2', matricule: 'MAT-02', last_name: 'Obama', first_name: 'Cédric', group_id: null, anonymous_code: null, score: null, absence: null, version: 0 },
    ],
    entered: 1, total: 2,
  };
}

async function mountAt(component: Component, path: string, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherGradesRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

const problem = (status: number, slug: string, detail: string, errors?: Record<string, string[]>) =>
  jsonResponse({ type: `https://api.scholaris.cm/errors/${slug}`, title: slug, status, detail, trace_id: 'T', ...(errors ? { errors } : {}) }, status);

describe('IPES · notes', () => {
  beforeEach(() => {
    freshEnvironment();
    signIn(teacherMe, [higherTile()]);
  });

  it('contrôle de saisie : note au pas, code d\'absence, barème', () => {
    expect(parseCell('13,5', 20, 0.25, IpesDefaultsCodes)).toEqual({ ok: true, value: { score: 13.5, absence: null } });
    expect(parseCell('abj', 20, 0.25, IpesDefaultsCodes)).toEqual({ ok: true, value: { score: null, absence: 'justified' } });
    expect(parseCell('21', 20, 0.25, IpesDefaultsCodes).ok).toBe(false);
    expect(parseCell('12,3', 20, 0.25, IpesDefaultsCodes).ok).toBe(false);
  });

  it('liste les évaluations et filtre par état', async () => {
    const calls = mockApi({ ...base, 'GET /higher/grade-assessments': { data: [assessment()], meta: { count: 1 } } });
    const { wrapper } = await mountAt(AssessmentsView, '/superieur/notes');
    expect(wrapper.findAll('[data-test="assessment-row"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('INF111');
    expect(wrapper.find('[data-test="create-assessment"]').exists()).toBe(true);
    await wrapper.find('#af-st').setValue('submitted');
    await flushPromises();
    const last = calls.filter((c) => c.path === '/higher/grade-assessments').pop();
    expect(last?.query['filter[status]']).toBe('submitted');
  });

  it('enregistre un lot avec la version lue', async () => {
    const calls = mockApi({ ...base, 'GET /higher/grade-assessments/A1/marks': { data: grid() }, 'PUT /higher/grade-assessments/A1/marks': { data: { written: 1, unchanged: 0, discipline_referrals: 0 } } });
    const { wrapper } = await mountAt(AssessmentGridView, '/superieur/notes/evaluations/A1', { id: 'A1' });
    const inputs = wrapper.findAll('input.hg-cell');
    await inputs[1]!.setValue('14,5');
    await wrapper.find('[data-test="grid-save"]').trigger('click');
    await flushPromises();
    const put = calls.find((c: RecordedCall) => c.method === 'PUT');
    expect(put?.body).toEqual({ marks: [{ person_id: 'P2', score: 14.5, expected_version: 0 }] });
    expect(wrapper.find('[data-test="grid-saved"]').exists()).toBe(true);
  });

  it('409 : conflit affiché, saisie conservée, rien n\'est écrasé', async () => {
    const conflicts = JSON.stringify([{ line: 1, mark_id: 'M1', expected_version: 2, current_version: 3, current: { score: 9, absence: null } }]);
    mockApi({
      ...base,
      'GET /higher/grade-assessments/A1/marks': { data: grid() },
      'PUT /higher/grade-assessments/A1/marks': () => problem(409, 'version-conflict', 'Des notes ont changé', { conflicts: [conflicts] }),
    });
    const { wrapper } = await mountAt(AssessmentGridView, '/superieur/notes/evaluations/A1', { id: 'A1' });
    await wrapper.findAll('input.hg-cell')[0]!.setValue('15');
    await wrapper.find('[data-test="grid-save"]').trigger('click');
    await flushPromises();
    const box = wrapper.find('[data-test="grid-conflict"]');
    expect(box.exists()).toBe(true);
    expect(box.text()).toContain('9');
    expect((wrapper.findAll('input.hg-cell')[0]!.element as HTMLInputElement).value).toBe('15');
  });

  it('verrouillage refusé faute de 2FA récente : renvoi vers la double authentification puis retour', async () => {
    mockApi({
      ...base,
      'GET /higher/grade-assessments/A1/marks': { data: grid({ status: 'validated' }, false) },
      'POST /higher/grade-assessments/A1/lock': () => problem(403, 'second-factor-required', 'Double authentification récente requise.'),
    });
    const { wrapper, router } = await mountAt(AssessmentGridView, '/superieur/notes/evaluations/A1', { id: 'A1' });
    expect(wrapper.find('[data-test="grid-closed"]').exists()).toBe(true);
    await wrapper.find('[data-test="life-lock"]').trigger('click');
    await wrapper.find('[data-test="life-go"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="needs-2fa"]').exists()).toBe(true);
    await wrapper.find('[data-test="needs-2fa"] button').trigger('click');
    await flushPromises(30);
    expect(router.currentRoute.value.name).toBe('twofactor');
    expect(String(router.currentRoute.value.query.redirect)).toContain('resume=lock');
  });

  it('publication refusée sans PV signé (409 RG-DEL-3)', async () => {
    mockApi({
      ...base,
      'GET /higher/grade-assessments/A1/marks': { data: grid({ status: 'locked' }, false) },
      'POST /higher/grade-assessments/A1/publish': () => problem(409, 'conflict', 'Publication refusée : le procès-verbal doit d\'abord être signé par le président du jury (RG-DEL-3).'),
    });
    const { wrapper } = await mountAt(AssessmentGridView, '/superieur/notes/evaluations/A1', { id: 'A1' });
    await wrapper.find('[data-test="life-publish"]').trigger('click');
    await wrapper.find('[data-test="life-go"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="pv-required"]').text()).toContain('RG-DEL-3');
  });

  it('corrections : pas de décision sur sa propre demande', async () => {
    const corr = (id: string, by: string) => ({
      id, mark_id: 'M1', assessment_id: 'A1', element_code: 'INF111', assessment_label: 'Examen', person_id: 'P1', old: { score: 8, absence: null }, new: { score: 11, absence: null },
      reason: 'Erreur de report', attachment_ref: 'GED-1', query_id: null, requested_by: by, status: 'pending', approval_step: 0,
      next_step: { label: 'Chef de département', role: 'higher_chef_dept', require_recent_2fa: false }, steps: [], approval_trail: [], decided_at: null, created_at: '2026-10-10T08:00:00Z',
    });
    const calls = mockApi({ ...base, 'GET /higher/grade-corrections': { data: [corr('C1', 'U1'), corr('C2', 'U9')] }, 'POST /higher/grade-corrections/C2/approve': { data: { ...corr('C2', 'U9'), status: 'approved' } } });
    const { wrapper } = await mountAt(CorrectionsView, '/superieur/notes/corrections');
    expect(wrapper.findAll('[data-test="approve"]')).toHaveLength(1);
    await wrapper.find('[data-test="approve"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/higher/grade-corrections/C2/approve')).toBe(true);
  });

  it('« Mes notes » : affichage et dépôt d\'une requête', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'apprenant' } }, [higherTile(['higher.access', 'self.read', 'higher.query.create'], 'apprenant')], 'apprenant');
    const calls = mockApi({
      ...base,
      'GET /higher/students/me/results': { data: { person_id: 'P1', semesters: [], elements: [{ element_id: 'EC1', element_code: 'INF111', element_title: 'Algorithmique', academic_year_id: 'Y1', semester_ordinal: 1, ec_mark: 12.4,
        assessments: [{ assessment_id: 'A1', mark_id: 'M1', kind: 'exam', label: 'Examen', exam_session: 'normal', max_score: 20, weight_percent: 60, score: 11, absence: null, published_at: '2026-10-10', query_until: '2026-10-17', query_open: true }] }] } },
      'GET /higher/students/me/grade-queries': { data: [] },
      'POST /higher/students/me/grade-queries': () => jsonResponse({ data: { id: 'Q1' } }, 201),
    });
    const { wrapper } = await mountAt(MyResultsView, '/superieur/mes-notes');
    expect(wrapper.find('[data-test="my-element"]').text()).toContain('12,4');
    await wrapper.find('[data-test="ask-M1"]').trigger('click');
    await wrapper.find('#mq-text').setValue('Exercice 3 non compté');
    await wrapper.find('[data-test="query-form"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ mark_id: 'M1', message: 'Exercice 3 non compté' });
  });
});
