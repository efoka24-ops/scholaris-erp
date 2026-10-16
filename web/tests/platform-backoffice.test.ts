import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PlatformClientsView from '@/views/platform/PlatformClientsView.vue';
import PlatformClientView from '@/views/platform/PlatformClientView.vue';
import PlatformRequestsView from '@/views/platform/PlatformRequestsView.vue';
import PlatformRequestView from '@/views/platform/PlatformRequestView.vue';
import PlatformPaymentsView from '@/views/platform/PlatformPaymentsView.vue';
import PlatformAdminsView from '@/views/platform/PlatformAdminsView.vue';
import PlatformAuditView from '@/views/platform/PlatformAuditView.vue';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// Back-office de l'administrateur de plateforme : chaque écran affiche ses données et envoie les bons appels.
beforeEach(() => {
  freshEnvironment();
  clearPending();
});

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Emmanuel', last_name: 'Foka', greeting: 'Emmanuel', context: { establishment_id: null, role: null } };
const page = (items: unknown[], next: string | null = null) => ({ data: items, links: { next: null }, meta: { next_cursor: next } });

function signInPlatform(): void {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = platformMe.id;
}

const client = (over: Record<string, unknown> = {}) => ({
  id: 'T1', name: 'ETS FOKA', status: 'active', state: 'active', state_reason: null, suspended_at: null, archived_at: null,
  contact: { name: 'Emmanuel Foka', email: 'emm@foka.test', phone: null }, city: 'Douala', region: 'Littoral', locale: 'fr',
  created_at: '2026-10-15', applications: [], establishments: [{ id: 'E1', name: 'ETS FOKA', type: 'higher', closed_at: null, created_at: '2026-10-15' }], ...over,
});

const admin = (over: Record<string, unknown> = {}) => ({
  id: 'U1', person_id: 'P1', first_name: 'Paul', last_name: 'Mballa', email: 'paul@foka.test', phone: null, status: 'pending',
  two_factor_enabled: false, last_login_at: null, created_at: '2026-10-15', ...over,
});

const request = (over: Record<string, unknown> = {}) => ({
  id: 'AR9', reference: 'REF00009', status: 'pending', institution_name: 'ETS FOKA', target_type: 'higher', city: 'Douala', region: 'Littoral', country: 'CM',
  contact: { first_name: 'Emmanuel', last_name: 'Foka', email: 'emm@foka.test', phone: null, position: null }, ministerial_authorization: null,
  app_key: 'higher', app_label: 'IPES', message: null, locale: 'fr', info_request: null, complement: null, decision_reason: null, decided_by: null,
  decided_at: null, tenant_id: null, establishment_id: null, archived_at: null, archive_reason: null, created_at: '2026-10-15T09:00:00+01:00',
  updated_at: '2026-10-15T09:00:00+01:00', ...over,
});

describe('back-office de la plateforme', () => {
  it('demandes : une demande en attente (ETS FOKA) est affichée, et le filtre « Archivées » interroge filter[archived]=1', async () => {
    signInPlatform();
    const calls = mockApi({ 'GET /platform/access-requests': (c) => page(c.query['filter[archived]'] === '1' ? [] : [request()]) });
    const { wrapper } = await mountView(PlatformRequestsView, {}, '/platform/demandes');
    expect(calls[0]?.query['filter[status]']).toBe('pending');
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.text()).toContain('ETS FOKA');
    const archived = wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'archived')!;
    await archived.setValue(true);
    await flushPromises();
    expect(calls.at(-1)?.query['filter[archived]']).toBe('1');
    expect(calls.at(-1)?.query['filter[status]']).toBeUndefined();
  });

  it('demande de test : archivage avec motif obligatoire', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/access-requests/AR9': { data: request() },
      'POST /platform/access-requests/AR9/archive': { data: request({ archived_at: '2026-10-16T08:00:00+01:00', archive_reason: 'Test' }) },
    });
    const { wrapper } = await mountView(PlatformRequestView, { id: 'AR9' }, '/platform/demandes/AR9');
    await wrapper.findAll('button').find((b) => b.text() === 'Archiver la demande')!.trigger('click');
    await wrapper.get('#ra-reason').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(wrapper.text()).toContain('Indiquez le motif');
    await wrapper.get('#ra-reason').setValue('Demande de test');
    await wrapper.get('#ra-reason').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/archive'))?.body).toEqual({ reason: 'Demande de test' });
    expect(wrapper.text()).toContain('Supprimer définitivement');
  });

  it('clients : filtre par état et création directe d\'un client', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/clients': (c) => ({ data: c.query['filter[state]'] === 'archived' ? [] : [client()] }),
      'POST /platform/clients': { data: client({ id: 'T2', name: 'Institut Nouveau' }) },
    });
    const { wrapper } = await mountView(PlatformClientsView, {}, '/platform/clients');
    expect(wrapper.text()).toContain('ETS FOKA');
    expect(wrapper.text()).toContain('Douala');
    const archived = wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'archived')!;
    await archived.setValue(true);
    await flushPromises();
    expect(calls.at(-1)?.query['filter[state]']).toBe('archived');

    await wrapper.findAll('button').find((b) => b.text() === 'Créer un client')!.trigger('click');
    await wrapper.get('#cli-create').trigger('submit');
    expect(wrapper.text()).toContain('au moins 3 caractères');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.get('#cc-name').setValue('Institut Nouveau');
    await wrapper.get('#cc-contact').setValue('Awa Ngo');
    await wrapper.get('#cc-email').setValue('awa@nouveau.test');
    await wrapper.get('#cc-type').setValue('higher');
    await wrapper.get('#cli-create').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ name: 'Institut Nouveau', contact_name: 'Awa Ngo', contact_email: 'awa@nouveau.test', establishment_type: 'higher' });
  });

  it('fiche client : suspension motivée, établissement ajouté, administrateur invité puis bloqué', async () => {
    signInPlatform();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const calls = mockApi({
      'GET /platform/clients/T1': { data: client() },
      'GET /platform/applications': { data: [] },
      'GET /platform/clients/T1/administrators': { data: [admin()] },
      'POST /platform/clients/T1/suspend': { data: client({ state: 'suspended' }) },
      'POST /platform/clients/T1/establishments': { data: { id: 'E2', name: 'Annexe', type: 'primary', closed_at: null, created_at: '2026-10-16' } },
      'POST /platform/clients/T1/administrators': { data: admin({ id: 'U2', invitation: 'sent' }) },
      'POST /platform/clients/T1/administrators/U1/block': { data: admin({ status: 'locked' }) },
    });
    const { wrapper } = await mountView(PlatformClientView, { tenant: 'T1' }, '/platform/clients/T1');
    expect(wrapper.text()).toContain('emm@foka.test');
    expect(wrapper.text()).toContain('Paul Mballa');

    await wrapper.findAll('button').find((b) => b.text() === 'Suspendre le client')!.trigger('click');
    await wrapper.get('#cp-reason').setValue('Impayés');
    await wrapper.get('#cp-reason').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.path === '/platform/clients/T1/suspend')?.body).toEqual({ reason: 'Impayés' });

    await wrapper.findAll('button').find((b) => b.text() === 'Ajouter un établissement')!.trigger('click');
    await wrapper.get('#est-name').setValue('Annexe');
    await wrapper.get('#est-type').setValue('primary');
    await wrapper.get('#est-name').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.path === '/platform/clients/T1/establishments' && c.method === 'POST')?.body).toEqual({ name: 'Annexe', type: 'primary' });

    await wrapper.findAll('button').find((b) => b.text() === 'Inviter un administrateur')!.trigger('click');
    await wrapper.get('#adm-first').setValue('Jean');
    await wrapper.get('#adm-last').setValue('Kamga');
    await wrapper.get('#adm-email').setValue('jean@foka.test');
    await wrapper.get('#adm-invite').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/platform/clients/T1/administrators' && c.method === 'POST')?.body).toEqual({ first_name: 'Jean', last_name: 'Kamga', email: 'jean@foka.test' });
    expect(wrapper.text()).toContain('Invitation envoyée');

    await wrapper.findAll('button').find((b) => b.text() === 'Bloquer')!.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/platform/clients/T1/administrators/U1/block')).toBe(true);
  });

  it('paiements : enregistrement manuel en espèces et annulation motivée', async () => {
    signInPlatform();
    vi.spyOn(window, 'prompt').mockReturnValue('Chèque refusé');
    const payment = { id: 'PAY1', tenant_id: 'T1', app_key: 'higher', reference: 'MAN-PAY1', amount_xaf: 60000, months: 1, channel: 'cash', status: 'succeeded', receipt_number: 'SCH-2026-000001', covered_from: '2026-12-30', covered_until: '2027-01-29', paid_at: '2026-10-16T08:00:00+01:00', created_at: '2026-10-16T08:00:00+01:00' };
    const ent = { id: 'ENT1', tenant_id: 'T1', app_key: 'higher', label: 'IPES', state: 'trial' };
    const calls = mockApi({
      'GET /platform/payments': page([payment]),
      'GET /platform/clients': { data: [client({ applications: [ent] })] },
      'POST /platform/payments': { data: payment },
      'POST /platform/payments/PAY1/cancel': { data: { ...payment, status: 'cancelled' } },
    });
    const { wrapper } = await mountView(PlatformPaymentsView, {}, '/platform/paiements');
    expect(wrapper.text()).toContain('Espèces');
    await wrapper.findAll('button').find((b) => b.text() === 'Enregistrer un paiement')!.trigger('click');
    await wrapper.get('#mp-client').setValue('T1');
    await wrapper.get('#mp-app').setValue('higher');
    await wrapper.get('#mp-amount').setValue('60000');
    await wrapper.get('#mp-client').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/platform/payments')?.body).toEqual({ tenant_id: 'T1', app_key: 'higher', months: 1, channel: 'cash', amount_xaf: 60000 });
    expect(wrapper.text()).toContain('SCH-2026-000001');

    await wrapper.findAll('button').find((b) => b.text() === 'Annuler')!.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/platform/payments/PAY1/cancel')?.body).toEqual({ reason: 'Chèque refusé' });
  });

  it('administrateurs de plateforme et journal : listes affichées', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/administrators': { data: [admin({ status: 'active', two_factor_enabled: true })] },
      'GET /platform/audit': page([{ id: '12', seq: 12, occurred_at: '2026-10-16T08:00:00+01:00', actor_id: 'UP', action: 'platform.client_created', object_type: 'tenant', object_id: 'T1', details: { name: 'ETS FOKA' }, ip: null }], 'c2'),
    });
    const admins = await mountView(PlatformAdminsView, {}, '/platform/administrateurs');
    expect(admins.wrapper.text()).toContain('Paul Mballa');
    expect(admins.wrapper.text()).toContain('Actif');

    const audit = await mountView(PlatformAuditView, {}, '/platform/journal');
    expect(audit.wrapper.text()).toContain('platform.client_created');
    expect(audit.wrapper.text()).toContain('name : ETS FOKA');
    await audit.wrapper.get('#au-action').setValue('platform.client');
    await audit.wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.at(-1)?.query['filter[action]']).toBe('platform.client');
  });
});
