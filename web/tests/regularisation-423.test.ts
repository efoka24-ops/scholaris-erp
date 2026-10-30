import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { HttpClient, REGULARISATION_REQUIRED, SUBSCRIPTION_SUSPENDED, http, regularisationLevel } from '@/api/http';
import type { MyRegularisation, RegularisationItem } from '@/api/platform';
import type { Me, Problem } from '@/api/types';
import { createAppRouter } from '@/router';
import RegularisationBanner from '@/components/RegularisationBanner.vue';
import { clearRegularisation, isLockedForRegularisation, regularisationNotice } from '@/views/platform/regularisationGate';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, signIn, teacherMe, tile } from './helpers';

const directorMe: Me = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] });

const problem = (type: string, detail: string): Problem => ({ type, title: 'Refus', status: 423, detail, trace_id: 'TEST' });
const locked = (): Response => jsonResponse(problem(REGULARISATION_REQUIRED, 'Régularisation de paiement requise.'), 423);
const suspended = (): Response => jsonResponse(problem(SUBSCRIPTION_SUSPENDED, 'Abonnement suspendu : lecture et export seulement.'), 423);

const item = (over: Partial<RegularisationItem> = {}): RegularisationItem => ({
  tenant_id: 'T1', tenant_name: 'ETS FOKA', app_key: 'ipes', label: 'IPES', state: 'suspended', level: 'locked', bucket: 'regularisation', origin: 'auto',
  due_on: '2026-12-30', period_from: '2026-12-31', since: '2027-01-14', days_late: 16, days_elapsed: 2, months: 1, monthly_total_xaf: 60000, base_price_xaf: 60000,
  options: [], amount_due_xaf: 60000, balance_xaf: 60000, credit_xaf: 0, blocks_on: null, days_left: null, promise_until: null, promise_reason: null, receipts: [], ...over,
});
const mine = (over: Partial<MyRegularisation> = {}): MyRegularisation => ({
  mode: 'ok', locked: false, can_pay: true, items: [], contact: { name: 'TRU GROUP', email: 'contact@tru.test', phone: '+237699000001' },
  payment_numbers: [], requests: [], partial_min_xaf: 1000, tolerance_xaf: 100, expiry_days: 7, ...over,
});

/** Routeur de l'application (avec son garde) déjà ouvert sur `path`, dans un onglet où le client était à jour. */
async function openTab(path: string): Promise<ReturnType<typeof createAppRouter>> {
  const router = createAppRouter(createMemoryHistory());
  await router.push(path);
  await router.isReady();
  return router;
}

beforeEach(() => {
  freshEnvironment();
  clearRegularisation();
});

describe('423 du blocage central : reconnaissance', () => {
  it('distingue « bloqué » et « lecture seule » ; ignore les autres 423 et la liste blanche', () => {
    expect(regularisationLevel(423, problem(REGULARISATION_REQUIRED, ''), '/people')).toBe('locked');
    expect(regularisationLevel(423, problem(SUBSCRIPTION_SUSPENDED, ''), '/higher/schools')).toBe('read_only');
    expect(regularisationLevel(423, problem('https://api.scholaris.cm/errors/application-suspended', ''), '/higher/schools')).toBeNull();
    expect(regularisationLevel(409, problem(REGULARISATION_REQUIRED, ''), '/people')).toBeNull();
    for (const path of ['/me', '/me/contexts', '/me/regularisation', '/me/regularisation/declarations', '/auth/logout', '/applications/higher/payments', '/application-payments/X/receipt', '/public/applications', '/health', '/platform/clients']) {
      expect(regularisationLevel(423, problem(REGULARISATION_REQUIRED, ''), path)).toBeNull();
    }
  });

  it('le client HTTP prévient une seule fois par réponse, puis lève toujours l’erreur', async () => {
    const onRegularisation = vi.fn();
    const c = new HttpClient({ baseUrl: '/api/v1', fetchImpl: (async () => locked()) as unknown as typeof fetch, getEstablishmentId: () => 'E1', getLocale: () => 'fr', onRegularisation });
    await expect(c.get('/people')).rejects.toMatchObject({ status: 423 });
    expect(onRegularisation).toHaveBeenCalledTimes(1);
    expect(onRegularisation.mock.calls[0]?.[0]).toBe('locked');
    await expect(c.get('/me/regularisation')).rejects.toMatchObject({ status: 423 });
    expect(onRegularisation).toHaveBeenCalledTimes(1);
  });
});

describe('onglet déjà ouvert qui reçoit un 423', () => {
  it('« bloqué » : redirection immédiate vers l’écran de régularisation et cache du garde relu', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let situation = mine();
    const calls = mockApi({ 'GET /me/regularisation': () => ({ data: situation }), 'GET /people': locked() });
    const router = await openTab('/');
    expect(router.currentRoute.value.name).not.toBe('regularisation');
    expect(await isLockedForRegularisation(directorMe.id)).toBe(false);

    situation = mine({ mode: 'locked', locked: true, items: [item()] });
    await expect(http.get('/people')).rejects.toMatchObject({ status: 423 });
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('regularisation'), { timeout: 15000, interval: 50 });
    // Le cache d'une minute est oublié : la situation est relue tout de suite, sans attendre son expiration.
    expect(await isLockedForRegularisation(directorMe.id)).toBe(true);
    expect(calls.filter((c) => c.path === '/me/regularisation').length).toBeGreaterThanOrEqual(2);
  });

  it('sans boucle : plusieurs refus simultanés, puis un refus sur l’écran de régularisation lui-même', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine() }, 'GET /people': locked(), 'GET /higher/schools': locked() });
    const router = await openTab('/');
    const replace = vi.spyOn(router, 'replace');

    await Promise.allSettled([http.get('/people'), http.get('/higher/schools'), http.get('/people')]);
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('regularisation'), { timeout: 15000, interval: 50 });
    expect(replace).toHaveBeenCalledTimes(1);

    await Promise.allSettled([http.get('/people')]);
    await flushPromises();
    expect(replace).toHaveBeenCalledTimes(1);
  });

  it('pas de redirection depuis un écran public ou de connexion', async () => {
    mockApi({ 'GET /me': jsonResponse({ type: 'about:blank', title: 'Non connecté', status: 401, detail: '' }, 401), 'GET /people': locked() });
    const router = await openTab('/connexion');
    const before = router.currentRoute.value.fullPath;
    await Promise.allSettled([http.get('/people')]);
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe(before);
  });

  it('« lecture seule » : pas de redirection, message clair dans le bandeau qui se relit aussitôt', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let situation = mine();
    const calls = mockApi({ 'GET /me/regularisation': () => ({ data: situation }), 'POST /people': suspended() });
    const router = await openTab('/');
    const wrapper = mount(RegularisationBanner, { global: { plugins: [router] } });
    await flushPromises();
    expect(wrapper.find('[role="region"]').exists()).toBe(false);
    const reads = calls.filter((c) => c.path === '/me/regularisation').length;

    situation = mine({ mode: 'read_only', items: [item({ level: 'read_only' })] });
    await expect(http.post('/people', { last_name: 'Essomba' })).rejects.toMatchObject({ status: 423 });
    await flushPromises();

    expect(router.currentRoute.value.name).not.toBe('regularisation');
    expect(regularisationNotice.value).toBe('Abonnement suspendu : lecture et export seulement.');
    expect(wrapper.find('[role="alert"]').text()).toBe('Abonnement suspendu : lecture et export seulement.');
    expect(wrapper.text()).toContain('Abonnement suspendu : lecture et export seulement.');
    expect(calls.filter((c) => c.path === '/me/regularisation').length).toBeGreaterThan(reads);

    // Le message ne survit pas au changement d'écran ; le bandeau de lecture seule reste.
    await router.push('/securite');
    await flushPromises();
    expect(regularisationNotice.value).toBeNull();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    wrapper.unmount();
  });
});
