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

// --- Code d'accès à usage unique (dépannage et partage de l'accès) -----------------------------------------------------
const problem403 = () =>
  new Response(JSON.stringify({ type: 'https://api.scholaris.cm/errors/two-factor-recent-required', title: 'Erreur', status: 403, detail: 'Validez à nouveau votre double authentification pour cette action.', trace_id: 'TR' }), {
    status: 403,
    headers: { 'Content-Type': 'application/problem+json' },
  });

const accessCode = (over: Record<string, unknown> = {}) => ({
  code: '482915', purpose: 'activation', expires_at: '2026-10-19T10:05:00+01:00', expires_in: 300, login: 'paul@foka.test',
  delivery: { channel: 'none', status: 'not_sent', masked_to: null },
  share: {
    establishment_name: 'ETS FOKA', login: 'paul@foka.test', login_url: 'https://app.scholaris.cm/connexion', activation_url: 'https://app.scholaris.cm/activation', locale: 'fr',
    channels: ['email', 'sms'], message: { subject: 'SCHOLARIS : votre accès à ETS FOKA', text: 'Bonjour Paul, code 482915 sur https://app.scholaris.cm/activation', sms: 'SCHOLARIS ETS FOKA : code 482915' },
  },
  ...over,
});

const clientUser = { id: 'U7', person_id: 'P7', first_name: 'Awa', last_name: 'Ngo', email: 'awa@foka.test', phone: '+237690123456', status: 'active', roles: ['teacher'], two_factor_enabled: false, last_login_at: null };

describe('back-office : code d\'accès', () => {
  it('fiche client : 2FA récente exigée (jamais mise de côté), puis code affiché, copié, envoi SMS en échec signalé, renvoi par e-mail', async () => {
    signInPlatform();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    let attempts = 0;
    const calls = mockApi({
      'GET /platform/clients/T1': { data: client() },
      'GET /platform/applications': { data: [] },
      'GET /platform/clients/T1/administrators': { data: [] },
      'GET /platform/clients/T1/users': { data: [clientUser] },
      'POST /platform/clients/T1/users/U7/access-code': () => (++attempts === 1 ? problem403() : { data: accessCode({ purpose: 'reset', delivery: { channel: 'sms', status: 'failed', masked_to: '+2376••••3456' } }) }),
      'POST /platform/clients/T1/users/U7/access-code/send': { data: { delivery: { channel: 'email', status: 'sent', masked_to: 'a•••@foka.test' } } },
    });
    const { wrapper } = await mountView(PlatformClientView, { tenant: 'T1' }, '/platform/clients/T1');
    expect(wrapper.text()).toContain('Awa Ngo');
    expect(wrapper.text()).toContain('teacher');

    await wrapper.get('[data-test="user-access-code"]').trigger('click');
    await wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'sms')!.setValue(true);
    await wrapper.findAll('form').at(-1)!.trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('double authentification');
    expect(sessionStorage.getItem('scholaris.platform.pending-action')).toBeNull();

    await wrapper.findAll('form').at(-1)!.trigger('submit');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/platform/clients/T1/users/U7/access-code').at(-1)?.body).toEqual({ send: 'sms' });
    expect(wrapper.get('[data-test="access-code"]').text()).toBe('482915');
    expect(wrapper.text()).toContain('Code de réinitialisation du mot de passe');
    expect(wrapper.get('[data-test="delivery"]').text()).toContain('a échoué');
    expect(wrapper.text()).toContain('https://app.scholaris.cm/connexion');

    await wrapper.get('[data-test="copy-code"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('482915');
    expect(wrapper.text()).toContain('Copié dans le presse-papiers.');
    await wrapper.get('[data-test="copy-message"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenLastCalledWith('Bonjour Paul, code 482915 sur https://app.scholaris.cm/activation');

    await wrapper.get('[data-test="send-email"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/access-code/send'))?.body).toEqual({ channel: 'email', code: '482915' });
    expect(wrapper.get('[data-test="delivery"]').text()).toContain('a•••@foka.test');
  });

  it('copie : repli par sélection quand l\'API Clipboard est absente', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    const exec = vi.fn().mockReturnValue(true);
    Object.defineProperty(document, 'execCommand', { value: exec, configurable: true });
    const { copyText } = await import('@/views/platform/clipboard');
    expect(await copyText('482915')).toBe(true);
    expect(exec).toHaveBeenCalledWith('copy');
  });

  it('acceptation d\'une demande : envoi automatique coché, récapitulatif et code affichés', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/access-requests/AR9': { data: request({ contact: { first_name: 'Emmanuel', last_name: 'Foka', email: 'emm@foka.test', phone: '+237690000001', position: null } }) },
      'GET /platform/clients': { data: [] },
      'POST /platform/access-requests/AR9/accept': {
        data: request({ status: 'accepted', tenant_id: 'T1', administrator: { person_id: 'P1', user_id: 'U1', login: 'emm@foka.test', status: 'pending_activation' }, access: accessCode({ delivery: { channel: 'email', status: 'sent', masked_to: 'e•••@foka.test' } }) }),
      },
    });
    const { wrapper } = await mountView(PlatformRequestView, { id: 'AR9' }, '/platform/demandes/AR9');
    await wrapper.findAll('button').find((b) => b.text() === 'Accepter')!.trigger('click');
    await flushPromises();
    expect((wrapper.get('[data-test="auto-send"]').element as HTMLInputElement).checked).toBe(true);
    await wrapper.get('#rq-tenant').element.closest('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/accept'))?.body).toEqual({ share_send: 'email' });
    expect(wrapper.get('[data-test="access-code"]').text()).toBe('482915');
    expect(wrapper.text()).toContain('ETS FOKA');
    expect(wrapper.get('[data-test="delivery"]').text()).toContain('e•••@foka.test');
    expect(wrapper.find('[data-test="send-sms"]').exists()).toBe(true);
  });
});
