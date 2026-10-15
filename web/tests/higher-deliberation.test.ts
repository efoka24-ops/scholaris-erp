import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { higherDeliberationRoutes } from '@/views/higher/deliberation/routes';
import PreparationView from '@/views/higher/deliberation/PreparationView.vue';
import DocumentsView from '@/views/higher/deliberation/DocumentsView.vue';
import JuryView from '@/views/higher/deliberation/JuryView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile } from './helpers';

const PERMS = ['higher.access', 'higher.deliberation.read', 'higher.deliberation.prepare', 'assessment.deliberation.sign', 'assessment.publish'];
const higherMe = { data: { application: { key: 'higher', state: 'active', writable: true }, establishment_id: 'E1', roles: [], all_schools: true, school_ids: [], school_selector: false } };
const jury = (over: Record<string, unknown> = {}) => ({
  data: {
    id: 'J1', school_id: 'SC1', program_id: 'PR1', level_id: 'L1', structure_id: 'G1', academic_year_id: 'Y1', period_id: 'S1', period_label: 'S1',
    exam_session: 'normal', regime: 'lmd', president_person_id: 'P-PRES', quorum_min: 2, agenda: null, status: 'simulated', deliberation_ref: null,
    minutes_fingerprint: null, minutes_version: 0, signed_at: null, published_at: null, reopen_count: 0, created_at: '2026-10-01', updated_at: '2026-10-01',
    members: [{ person_id: 'P-PRES', name: 'Pr. Mbarga', member_role: 'president', present: true, signed: false }], present_count: 1, quorum_reached: false,
    jury_points: [], signatures: [], ...over,
  },
});
const marie = {
  person_id: 'P-MARIE', name: 'Marie N.', matricule: 'IPES-25-0001', average: 11.55, gpa: 2.4, credits_earned: 30, credits_total: 30, mention: 'Passable',
  proposal: 'Admis', borderline: [], rank: 1,
};
const paul = { person_id: 'P-PAUL', name: 'Paul E.', matricule: 'IPES-25-0002', average: 9.6, gpa: 1.8, credits_earned: 24, credits_total: 30, mention: null, proposal: 'Rattrapage', borderline: [{ kind: 'semester', unit: null, value: 9.6, threshold: 10, gap: 0.4 }], rank: 2 };
const simulation = { data: { jury_id: 'J1', rules: { strategy: 'lmd', version: 2, options: {} }, students: [marie, paul], summary: { students: 2, by_proposal: { Admis: 1, Rattrapage: 1 }, borderline: 1 }, unlocked: false, margin: 0.5 } };
const problem = (status: number, slug: string, detail: string) => jsonResponse({ type: `https://api.scholaris.cm/errors/${slug}`, title: 'Refus', status, detail, trace_id: 'TR-DEL' }, status);

async function mountAt(component: Component, path: string, props: Record<string, unknown>) {
  const router = createRouter({ history: createMemoryHistory(), routes: [...routes, ...higherDeliberationRoutes] });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

beforeEach(() => {
  freshEnvironment();
  signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'higher_scolarite' } }, [tile({ type: 'higher', permissions: PERMS })], 'higher_scolarite');
});

describe('Délibérations IPES', () => {
  it('affiche la simulation à la française (Marie N. : 11,55, MGP 2,40, 30 crédits) et surligne les cas limites', async () => {
    const calls = mockApi({ 'GET /higher/me': higherMe, 'GET /higher/deliberation/juries/J1': jury(), 'POST /higher/deliberation/juries/J1/simulate': simulation });
    const { wrapper } = await mountAt(PreparationView, '/superieur/deliberation/jurys/J1/preparation', { id: 'J1' });
    await wrapper.get('#op-pass').setValue('10,5');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const row = wrapper.get('[data-test="sim-P-MARIE"]');
    expect(row.get('[data-test="average"]').text()).toContain('11,55');
    expect(row.get('[data-test="gpa"]').text()).toBe('2,40');
    expect(row.get('[data-test="credits"]').text()).toBe('30 / 30');
    expect(row.classes()).not.toContain('hdl-borderline');
    expect(wrapper.get('[data-test="sim-P-PAUL"]').classes()).toContain('hdl-borderline');
    expect(wrapper.get('[data-test="sim-P-PAUL"]').text()).toContain('0,40');
    const post = calls.find((c) => c.method === 'POST' && c.path.endsWith('/simulate'));
    expect(post?.body).toEqual({ overrides: { unit_pass_mark: 10.5 }, margin: 0.5 });
  });

  it('explique le refus 409 RG-DEL-3 de la liste des résultats avant signature du PV', async () => {
    mockApi({
      'GET /higher/me': higherMe,
      'GET /higher/deliberation/juries/J1': jury(),
      'POST /higher/deliberation/juries/J1/simulate': simulation,
      'POST /higher/deliberation/juries/J1/documents': problem(409, 'conflict', 'La liste des résultats s\'affiche après la signature du procès-verbal par le président (RG-DEL-3, RG-DOC-3).'),
    });
    const { wrapper } = await mountAt(DocumentsView, '/superieur/deliberation/jurys/J1/documents', { id: 'J1' });
    await wrapper.get('[data-test="issue-DOC-16"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="rg-del-3"]').text()).toContain('RG-DEL-3');
    expect(wrapper.find('.notice--error').exists()).toBe(false);
  });

  it('renvoie vers la double authentification quand la signature du PV l\'exige, puis revient', async () => {
    mockApi({
      'GET /higher/me': higherMe,
      'GET /higher/deliberation/juries/J1': jury({ status: 'closed', deliberation_ref: 'D1', minutes_fingerprint: 'abcdef0123456789abcdef', minutes_version: 1 }),
      'POST /deliberations/D1/sign': problem(403, 'forbidden', 'Opération sensible : confirmez votre double authentification puis recommencez.'),
      'POST /results/publish': problem(403, 'second-factor-required', 'Double authentification récente exigée.'),
    });
    const { wrapper, router } = await mountAt(JuryView, '/superieur/deliberation/jurys/J1', { id: 'J1' });
    await wrapper.get('[data-test="sign"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="tfa"]').exists()).toBe(true);
    await wrapper.get('[data-test="publish"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="tfa"]').exists()).toBe(true);
    await wrapper.get('[data-test="tfa"] button').trigger('click');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('twofactor'), { timeout: 5000 });
    expect(router.currentRoute.value.name).toBe('twofactor');
    expect(router.currentRoute.value.query.redirect).toBe('/superieur/deliberation/jurys/J1');
  });
});
