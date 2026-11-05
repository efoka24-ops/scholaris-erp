import { beforeEach, describe, expect, it } from 'vitest';
import PickupAuthView from '@/views/public-nursery/PickupAuthView.vue';
import { publicNurseryRoutes } from '@/views/public-nursery/routes';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// C-106 : écran « sécurité de la sortie » de la maternelle publique (DOC-21 carte, code à usage unique montré une seule fois, DOC-19
// registre). Données fictives.

beforeEach(() => freshEnvironment());

const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);

function connect(routes: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'nursery', permissions: ['public_nursery.pickup.handover'], roles: [{ code: 'pub_school_director', label: 'Directeur' }] })], 'pub_school_director');
  return mockApi(routes as Parameters<typeof mockApi>[0]);
}

const register = { data: { date: '2026-11-05', totals: {}, children: [{ learner_id: 'L1', name: 'Lina Fictive', section_id: 'S1', status: 'still_at_school', arrived_at: null, left_at: null, left_to: null, kept: false, refusals: 0, stale: false, alerts: [] }] } };
const person = { id: 'A1', learner_id: 'L1', pickup_name: 'Tante Fictive', relationship: 'tante', grant_type: 'permanent', valid_on: null, status: 'active' };

describe('maternelle publique : sécurité de la sortie', () => {
  it('la route est réservée aux maternelles et à la porte', () => {
    const route = publicNurseryRoutes.find((r) => r.name === 'pn-pickup-auth');
    expect(route?.meta?.establishmentTypes).toEqual(['nursery']);
    expect(route?.meta?.perms).toContain('public_nursery.pickup.handover');
  });

  it('émet la carte d’une personne autorisée puis le code du jour, rendu une seule fois', async () => {
    const calls = connect({
      'GET /public-nursery/register': register,
      'GET /public-nursery/pickup/authorized-persons': { data: [person] },
      'POST /public-nursery/pickup/authorized-persons/A1/card': { data: { id: 'X1', kind: 'pickup_card', source_id: 'A1', learner_id: 'L1', document_id: 'DOC1', number: 'EMP-26-K-000001', verification_path: '/api/v1/public/verify/T', locale: 'fr', status: 'valid', issued_at: 'x' } },
      'GET /documents/DOC1/preview': new Response(new Blob(['%PDF-1.4']), { status: 200, headers: { 'Content-Type': 'application/pdf' } }),
      'POST /public-nursery/pickup/codes': { data: { id: 'C1', code: '482915', learner_id: 'L1', pickup_id: 'A1', valid_on: '2026-11-05' } },
    });
    (globalThis as { URL: typeof URL }).URL.createObjectURL = () => 'blob:test';
    window.open = (() => null) as typeof window.open;
    const { wrapper } = await mountView(PickupAuthView);
    await flushPromises();

    await wrapper.get('#pa-learner').setValue('L1');
    await flushPromises();
    expect(wrapper.text()).toContain('Tante Fictive');
    const card = wrapper.findAll('button').find((b) => b.text().includes('Carte (DOC-21)'))!;
    await card.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/pickup/authorized-persons/A1/card')?.body).toEqual({ locale: 'fr' });
    expect(wrapper.text()).toContain('EMP-26-K-000001');

    const code = wrapper.findAll('button').find((b) => b.text().includes('Code du jour'))!;
    await code.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/pickup/codes')?.body).toMatchObject({ learner_id: 'L1', pickup_id: 'A1' });
    expect(wrapper.text()).toContain('482915');
    expect(wrapper.text()).toContain('Il ne sera plus affiché');

    // Changer d’enfant efface le code affiché : il n’est jamais relisible.
    await wrapper.get('#pa-learner').setValue('');
    await flushPromises();
    expect(wrapper.text()).not.toContain('482915');
  });

  it('imprime le registre du jour (DOC-19)', async () => {
    const calls = connect({
      'GET /public-nursery/register': register,
      'POST /public-nursery/register/document': { data: { id: 'X2', kind: 'register', source_id: '2026-11-05', learner_id: null, document_id: 'DOC2', number: 'EMP-26-R-000001', verification_path: '/x', locale: 'fr', status: 'valid', issued_at: 'x' } },
      'GET /documents/DOC2/preview': new Response(new Blob(['%PDF-1.4']), { status: 200, headers: { 'Content-Type': 'application/pdf' } }),
    });
    (globalThis as { URL: typeof URL }).URL.createObjectURL = () => 'blob:test';
    window.open = (() => null) as typeof window.open;
    const { wrapper } = await mountView(PickupAuthView);
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text().includes('DOC-19'))!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/register/document')?.body).toMatchObject({ locale: 'fr' });
    expect(wrapper.text()).toContain('EMP-26-R-000001');
  });
});
