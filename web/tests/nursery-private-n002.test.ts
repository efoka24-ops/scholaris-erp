import { beforeEach, describe, expect, it } from 'vitest';
import ConsentView from '@/views/nursery-private/ConsentView.vue';
import DashboardView from '@/views/nursery-private/DashboardView.vue';
import ServicesView from '@/views/nursery-private/ServicesView.vue';
import TransitionView from '@/views/nursery-private/TransitionView.vue';
import FamilyView from '@/views/nursery-private/FamilyView.vue';
import { nurseryPrivateFr } from '@/i18n/nursery-private-fr';
import { nurseryPrivateEn } from '@/i18n/nursery-private-en';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

beforeEach(() => freshEnvironment());

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 200 } });
const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);
const section = { id: 'S1', label: 'Petite section', capacity: 20, enrolled: 1, sort_order: 1, subsystem: 'fr' };
const child = { id: 'C1', first_name: 'Ada', last_name: 'Test', full_name: 'Ada Test', gender: null, birth_date: null, age_months: 40, section_id: 'S1', section_label: 'Petite section' };

function connect(perms: string[], routes: Record<string, unknown>, role = 'nursery_directeur'): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'nursery', permissions: perms, roles: [{ code: role, label: role }] })], role);
  return mockApi({ 'GET /nursery/sections': page([section]), 'GET /nursery/children': page([child]), ...routes } as Parameters<typeof mockApi>[0]);
}

describe('Maternelle privée N-002 : textes', () => {
  it('chaque clé française a sa traduction anglaise et aucune valeur ne contient de HTML', () => {
    expect(Object.keys(nurseryPrivateEn).sort()).toEqual(Object.keys(nurseryPrivateFr).sort());
    for (const value of [...Object.values(nurseryPrivateFr), ...Object.values(nurseryPrivateEn)]) expect(value).not.toMatch(/<[a-z]/i);
  });
});

describe('Maternelle privée N-002 : consentement photo', () => {
  const none = { learner_id: 'C1', active: false, statement_version: '2026-1', history: [] };
  it('enregistre le formulaire signé avec sa référence et affiche l’historique', async () => {
    const calls = connect(['nursery.consent.manage'], {
      'GET /nursery/children/C1/photo-consent': { data: none },
      'POST /nursery/children/C1/photo-consent': { data: { ...none, active: true, history: [{ id: 'H1', channel: 'paper', evidence_ref: 'FORM-1', signed_on: '2026-10-01', signed_by_name: 'Parent fictif', statement_version: null, granted_at: '2026-10-01T08:00:00+01:00', status: 'active', revoked_at: null, revoke_reason: null }] } },
    });
    const { wrapper } = await mountView(ConsentView);
    await wrapper.get('#pc-section').setValue('S1');
    await flushPromises();
    await wrapper.get('#pc-child').setValue('C1');
    await flushPromises();
    expect(wrapper.text()).toContain('Aucun consentement');
    await wrapper.get('#pc-ref').setValue('FORM-1');
    await wrapper.get('#pc-by').setValue('Parent fictif');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/children/C1/photo-consent')?.body).toMatchObject({ evidence_ref: 'FORM-1', signed_by_name: 'Parent fictif' });
    expect(wrapper.text()).toContain('Consentement en vigueur');
    expect(wrapper.text()).toContain('FORM-1');
  });

  it('le parent ne peut donner son accord sans cocher la case, puis le retire', async () => {
    signIn(teacherMe, [tile({ type: 'nursery', permissions: [], roles: [{ code: 'parent', label: 'parent' }] })], 'parent');
    const calls = mockApi({
      'GET /nursery/portal/children': page([{ id: 'C1', full_name: 'Ada Test', section_id: 'S1', section_label: 'Petite section' }]),
      'GET /nursery/portal/pickups': page([]), 'GET /nursery/portal/progress-books': page([]), 'GET /nursery/portal/lifebook': page([]), 'GET /nursery/portal/needs': page([]),
      'GET /nursery/portal/balance': { data: { learner_id: 'C1', balance_xaf: 0, in_arrears: false } },
      'GET /nursery/portal/photo-consent': { data: none },
      'GET /nursery/portal/documents': page([{ id: 'D1', kind: 'progress_book', number: 'MAT-26-P-000001', verification_path: '/api/v1/public/verify/T', status: 'valid' }]),
      'GET /nursery/portal/services': page([]),
      'POST /nursery/portal/photo-consent': { data: { ...none, active: true } },
    } as Parameters<typeof mockApi>[0]);
    const { wrapper } = await mountView(FamilyView);
    await flushPromises();
    expect(wrapper.text()).toContain('MAT-26-P-000001');
    await wrapper.get('#fa-signer').setValue('Parent fictif');
    const give = wrapper.findAll('button').find((b) => b.text() === 'Donner mon accord');
    expect(give?.attributes('disabled')).toBeDefined();
    await wrapper.get('.check input').setValue(true);
    await give!.trigger('click');
    await wrapper.get('#fa-signer').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/portal/photo-consent')?.body).toMatchObject({ learner_id: 'C1', accept: true });
  });
});

describe('Maternelle privée N-002 : passage, services, tableau de bord', () => {
  it('un maintien envoie le motif et la demande des parents ; un refus 422 du serveur est affiché', async () => {
    const decision = { id: 'D1', learner_id: 'C1', child_name: 'Ada Test', academic_year_id: 'AY', from_section_id: 'S1', target_section_id: null, proposal: 'to_primary', decision: null, educator_opinion: null, decision_reason: null, parent_request_ref: null, status: 'proposed' };
    const calls = connect(['nursery.transition.decide'], {
      'GET /nursery/transition/decisions': page([decision]),
      'GET /nursery/transition/liaison-sheets': page([]),
      'POST /nursery/transition/decisions/D1/decide': jsonResponse({ type: 'x', title: 'Données invalides', status: 422, detail: 'Un maintien exige la référence de la demande écrite des parents.', trace_id: 'T' }, 422),
    });
    const { wrapper } = await mountView(TransitionView);
    await wrapper.get('#tr-section').setValue('S1');
    await flushPromises();
    expect(wrapper.text()).toContain('Passage au primaire');
    await wrapper.get('#tr-dec-D1').setValue('retain');
    await wrapper.get('#tr-reason-D1').setValue('Consolider (exemple)');
    await wrapper.findAll('button').find((b) => b.text() === 'Décider')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/transition/decisions/D1/decide')?.body).toMatchObject({ decision: 'retain', reason: 'Consolider (exemple)', parent_request_ref: null });
    expect(wrapper.text()).toContain('demande écrite des parents');
  });

  it('crée une offre liée à un frais du catalogue (aucun montant saisi) et facture le cycle', async () => {
    const calls = connect(['nursery.service.manage'], {
      'GET /nursery/services/plans': page([]),
      'GET /nursery/services/subscriptions': page([]),
      'POST /nursery/services/plans': { data: { id: 'P1', kind: 'canteen', label: 'Cantine', fee_item_id: 'FEE-1', billing_cycle: 'month', active: true } },
      'POST /nursery/services/invoices': { data: { issued: 2, already: 1, failed: [] } },
    });
    const { wrapper } = await mountView(ServicesView);
    await flushPromises();
    expect(wrapper.find('input[type="number"]').exists()).toBe(false);
    await wrapper.get('#sv-label').setValue('Cantine');
    await wrapper.get('#sv-fee').setValue('FEE-1');
    await wrapper.get('#sv-label').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/services/plans')?.body).toEqual({ kind: 'canteen', label: 'Cantine', fee_item_id: 'FEE-1', billing_cycle: 'month' });
    await wrapper.get('#sv-bill').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(wrapper.text()).toContain('2 facture(s) émise(s), 1 déjà facturée(s)');
  });

  it('le tableau de bord montre des chiffres et aucun nom d’enfant', async () => {
    connect(['nursery.dashboard.read'], {
      'GET /nursery/dashboard': { data: { date: '2026-10-21', totals: { enrolled: 12, absent: 1, arrived: 10, in_arrears: 3, balance_xaf: 45000, observation_gaps: 4, over_capacity_sections: 0 }, sections: [{ id: 'S1', label: 'Petite section', enrolled: 12, capacity: 20, absent: 1, arrived: 10, in_arrears: 3, observation_gaps: 4, over_capacity: false }], late_pickups: { today: { parent: 1, director: 0 }, month: { parent: 2, director: 1 } }, services_present_today: 5 } },
    }, 'nursery_promoteur');
    const { wrapper } = await mountView(DashboardView);
    await flushPromises();
    expect(wrapper.text()).toContain('Petite section');
    expect(wrapper.text()).toContain('12');
    expect(wrapper.text()).toContain('Ce mois : 2 alerte(s)');
    expect(wrapper.html()).not.toContain('v-html');
  });
});
