import { beforeEach, describe, expect, it } from 'vitest';
import ContributionView from '@/views/public-nursery/ContributionView.vue';
import GateRegisterView from '@/views/public-nursery/GateRegisterView.vue';
import ProgressBookView from '@/views/public-nursery/ProgressBookView.vue';
import { publicNurseryFr } from '@/i18n/public-nursery-fr';
import { publicNurseryEn } from '@/i18n/public-nursery-en';
import { publicNurseryRoutes } from '@/views/public-nursery/routes';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

beforeEach(() => freshEnvironment());

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 200 } });
const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);
const summary = (over: Record<string, unknown> = {}) => ({
  rules: { amount_xaf: 7500, installments: 1, quotas: [], source: 'Circulaire MINEDUB (visa MINFI du 7 mars 2022)' },
  enrolled: 2, paid: 0, unpaid: 2, expected_xaf: 15000, collected_xaf: 0, receipts_valid: 0, receipts_cancelled: 0,
  split: { lines: [], unallocated_xaf: 0 }, quotas_configured: false, ...over,
});

function connect(perms: string[], routes: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'nursery', permissions: perms, roles: [{ code: 'pub_school_director', label: 'Directeur' }] })], 'pub_school_director');
  return mockApi(routes as Parameters<typeof mockApi>[0]);
}

describe('Maternelle publique N-002 : textes et routes', () => {
  it('FR et EN ont les mêmes clés ; les routes sont réservées aux maternelles', () => {
    expect(Object.keys(publicNurseryEn).sort()).toEqual(Object.keys(publicNurseryFr).sort());
    for (const route of publicNurseryRoutes) expect(route.meta?.establishmentTypes).toEqual(['nursery']);
  });
});

describe('Maternelle publique N-002 : contribution exigible', () => {
  it('affiche 7 500 FCFA en une tranche, des quotas « à confirmer », et encaisse contre reçu', async () => {
    const calls = connect(['public_schools.school.write'], {
      'GET /public-nursery/contributions/summary': { data: summary() },
      'GET /public-nursery/contributions': page([]),
      'GET /public-nursery/contributions/unpaid': page([{ learner_id: 'L1', name: 'Lina Fictive' }]),
      'POST /public-nursery/contributions': { data: { id: 'R1', learner_id: 'L1', learner_name: 'Lina Fictive', academic_year_id: 'Y', amount_xaf: 7500, payer_name: 'Parent fictif', paid_on: '2026-09-07', receipt_number: 'EMP-26-Q-000001', document_id: 'D1', verification_path: '/api/v1/public/verify/T', split: null, status: 'valid', cancel_reason: null, cancelled_at: null, created_at: 'x' } },
    });
    const { wrapper } = await mountView(ContributionView);
    await flushPromises();
    expect(wrapper.text()).toMatch(/7\s?500/);
    expect(wrapper.text()).toContain('1 tranche');
    expect(wrapper.text()).toContain('à confirmer');
    await wrapper.get('#ct-child').setValue('L1');
    await wrapper.get('#ct-payer').setValue('Parent fictif');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/contributions')?.body).toEqual({ learner_id: 'L1', payer_name: 'Parent fictif' });
    expect(wrapper.text()).toContain('EMP-26-Q-000001');
  });

  it('une annulation exige un motif ; le refus du serveur est affiché', async () => {
    const stub = { id: 'R1', learner_id: 'L1', learner_name: 'Lina Fictive', academic_year_id: 'Y', amount_xaf: 7500, payer_name: 'P', paid_on: '2026-09-07', receipt_number: 'N1', document_id: 'D1', verification_path: '/x', split: null, status: 'valid', cancel_reason: null, cancelled_at: null, created_at: 'x' };
    connect(['public_schools.school.write'], {
      'GET /public-nursery/contributions/summary': { data: summary() },
      'GET /public-nursery/contributions': page([stub]),
      'GET /public-nursery/contributions/unpaid': page([]),
      'POST /public-nursery/contributions/R1/cancel': jsonResponse({ type: 'x', title: 'Conflit', status: 409, detail: 'Reçu déjà annulé.', trace_id: 'T' }, 409),
    });
    const { wrapper } = await mountView(ContributionView);
    await flushPromises();
    const cancel = wrapper.findAll('button').find((b) => b.text() === 'Annuler le reçu')!;
    expect(cancel.attributes('disabled')).toBeDefined();
    await wrapper.get('input[placeholder="Motif d’annulation"]').setValue('Erreur');
    await cancel.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Reçu déjà annulé.');
  });
});

describe('Maternelle publique N-002 : registre, effectifs, carnet', () => {
  it('alerte sur une classe au-delà du plafond et exige un motif pour garder un enfant', async () => {
    const calls = connect(['public_nursery.pickup.handover'], {
      'GET /public-nursery/register': { data: { date: '2026-10-08', totals: { children: 1, arrived: 1, left: 0, still_at_school: 1 }, children: [{ learner_id: 'L1', name: 'Lina Fictive', section_id: 'S', status: 'still_at_school', arrived_at: 'x', left_at: null, left_to: null, kept: false, refusals: 0, stale: false, alerts: ['parent'] }] } },
      'GET /public-nursery/sections/capacity': { data: { max_children: 30, over_capacity: 1, classes: [{ section_id: 'S', name: 'Petite section', enrolled: 33, max_children: 30, over_capacity: true, excess: 3 }] } },
      'POST /public-nursery/pickup/holds': { data: {} },
    });
    const { wrapper } = await mountView(GateRegisterView);
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Petite section : 33 enfants pour 30 au plus.');
    const hold = wrapper.findAll('button').find((b) => b.text() === 'Garder à l’école')!;
    expect(hold.attributes('disabled')).toBeDefined();
    await wrapper.get('input[placeholder="Motif du maintien"]').setValue('Parent prévenu');
    await hold.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/pickup/holds')?.body).toMatchObject({ learner_id: 'L1', remark: 'Parent prévenu' });
  });

  it('le carnet est enregistré sur le serveur puis validé', async () => {
    const book = { id: 'B1', learner_id: 'L1', structure_id: 'S', period_id: 'T1', school_year: '2026-2027', status: 'draft', synthesis: 'Belle curiosité', snapshot: [{ domain: 'Langage', label: 'S’exprime', level: 'in_progress', comment: '' }], edition: 1, edition_label: null };
    const calls = connect(['public_nursery.dev.observe'], {
      'POST /public-nursery/development/progress-books': { data: book },
      'POST /public-nursery/development/progress-books/B1/validate': { data: { ...book, status: 'validated' } },
    });
    const { wrapper } = await mountView(ProgressBookView);
    await wrapper.get('#pb-learner').setValue('L1');
    await wrapper.get('#pb-section').setValue('S');
    await wrapper.get('#pb-year').setValue('2026-2027');
    await wrapper.get('#pb-domain').setValue('Langage');
    await wrapper.get('#pb-point').setValue('S’exprime');
    await wrapper.findAll('button').find((b) => b.text() === '+')!.trigger('click');
    await wrapper.get('#pb-summary').setValue('Belle curiosité');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/development/progress-books')?.body).toMatchObject({ learner_id: 'L1', structure_id: 'S', period_id: 'T1', items: [{ domain: 'Langage', label: 'S’exprime', level: 'in_progress' }] });
    await wrapper.findAll('button').find((b) => b.text() === 'Valider le brouillon')!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('validé');
  });
});
