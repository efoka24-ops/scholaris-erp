import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import { guard } from '@/router';
import AppShell from '@/components/AppShell.vue';
import CodeInput from '@/components/CodeInput.vue';
import HomeView from '@/views/HomeView.vue';
import LoginView from '@/views/LoginView.vue';
import TwoFactorView from '@/views/TwoFactorView.vue';
import PublicHomeView from '@/views/platform/PublicHomeView.vue';
import PublicCatalogView from '@/views/platform/PublicCatalogView.vue';
import AccessRequestView from '@/views/platform/AccessRequestView.vue';
import AccessTrackingView from '@/views/platform/AccessTrackingView.vue';
import PlatformDashboardView from '@/views/platform/PlatformDashboardView.vue';
import PlatformRequestsView from '@/views/platform/PlatformRequestsView.vue';
import PlatformRequestView from '@/views/platform/PlatformRequestView.vue';
import PlatformClientsView from '@/views/platform/PlatformClientsView.vue';
import PlatformClientView from '@/views/platform/PlatformClientView.vue';
import PlatformCatalogView from '@/views/platform/PlatformCatalogView.vue';
import PlatformPaymentsView from '@/views/platform/PlatformPaymentsView.vue';
import MyApplicationsView from '@/views/platform/MyApplicationsView.vue';
import { loadPending, savePending, clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import { useContextStore } from '@/stores/context';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(() => {
  freshEnvironment();
  clearPending();
});

const TOKEN = 'a'.repeat(48);
const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Emmanuel', last_name: 'Foka', greeting: 'Emmanuel', context: { establishment_id: null, role: null } };
const directorMe: Me = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] });
const page = (items: unknown[], next: string | null = null) => ({ data: items, links: { next: null }, meta: { next_cursor: next } });
const problem = (status: number, detail: string, slug = 'x', extra: Record<string, unknown> = {}) =>
  jsonResponse({ type: `https://api.scholaris.cm/errors/${slug}`, title: 'Erreur', status, detail, trace_id: 'TR-PLAT', ...extra }, status);
const recent2fa = () => problem(403, 'Validez à nouveau votre double authentification pour cette action.', 'two-factor-recent-required');

const publicApps = [
  { key: 'ipes', label: 'IPES', target: 'higher', status: 'available', version: '1.0.0', monthly_price_xaf: 60000, trial_days: 90 },
  { key: 'secondary', label: 'Secondaire', target: 'secondary', status: 'coming_soon', version: '0.1.0', monthly_price_xaf: 50000, trial_days: 90 },
];

const entitlement = (over: Record<string, unknown> = {}) => ({
  id: 'ENT1', tenant_id: 'T1', app_key: 'ipes', label: 'IPES', target: 'higher', state: 'trial', source: 'access_request',
  started_on: '2026-10-01', trial_ends_on: '2026-12-29', trial_days_left: 42, paid_until: null, due_on: '2026-12-30',
  monthly_price_xaf: 60000, price_overridden: false, past_due_since: null, suspended_at: null, closed_at: null, state_reason: null,
  writable: true, readable: true, ...over,
});

const accessRequest = (over: Record<string, unknown> = {}) => ({
  id: 'AR01', reference: 'REF00001', status: 'pending', institution_name: 'IPES Les Palmiers', target_type: 'higher', city: 'Douala',
  region: 'Littoral', country: 'CM', contact: { first_name: 'Awa', last_name: 'Ngono', email: 'awa@palmiers.cm', phone: '+237699000000', position: 'Directrice' },
  ministerial_authorization: 'N° 123/MINESUP', app_key: 'ipes', app_label: 'IPES', message: 'Bonjour', locale: 'fr', info_request: null,
  complement: null, decision_reason: null, decided_by: null, decided_at: null, tenant_id: null, establishment_id: null,
  created_at: '2026-10-09T09:00:00+01:00', updated_at: '2026-10-09T09:00:00+01:00', ...over,
});

function signInPlatform(): void {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = platformMe.id;
}

async function runGuard(path: string): Promise<unknown> {
  const router = createRouter({ history: createMemoryHistory(), routes });
  return guard(router.resolve(path) as never);
}

describe('séparation plateforme / client (garde de routes et menus)', () => {
  it('reconnaît l\'administrateur de plateforme par une sonde sans en-tête d\'établissement et ne lui ouvre que ses écrans', async () => {
    signIn(platformMe, [], null);
    const calls = mockApi({ 'GET /platform/dashboard': { data: {} } });
    expect(await runGuard('/')).toEqual({ name: 'platform-dashboard' });
    const probe = calls.find((c) => c.path === '/platform/dashboard');
    expect(probe?.headers['X-Establishment-Id']).toBeUndefined();
    expect(usePlatformStore().isAdmin).toBe(true);
    expect(await runGuard('/notes')).toEqual({ name: 'platform-dashboard' });
    expect(await runGuard('/facturation')).toEqual({ name: 'platform-dashboard' });
    expect(await runGuard('/platform/clients')).toBe(true);
    expect(await runGuard('/securite')).toBe(true);
    expect(calls.filter((c) => c.path === '/platform/dashboard')).toHaveLength(1); // une seule sonde par compte
  });

  it('suit le champ platform_admin de /me lorsqu\'il existe, sans sonde', async () => {
    signIn({ ...platformMe, platform_admin: true } as Me, [], null);
    const calls = mockApi({});
    expect(await runGuard('/')).toEqual({ name: 'platform-dashboard' });
    expect(calls.some((c) => c.path === '/platform/dashboard')).toBe(false);
  });

  it('refuse les écrans /platform à un compte client, sans sonder si la personne a un établissement', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({});
    expect(await runGuard('/platform')).toEqual({ name: 'forbidden' });
    expect(await runGuard('/platform/demandes')).toEqual({ name: 'forbidden' });
    expect(calls.some((c) => c.path === '/platform/dashboard')).toBe(false);
    expect(await runGuard('/applications')).toBe(true);
  });

  it('un super administrateur sans établissement dont la sonde est refusée reste un compte client', async () => {
    signIn({ ...teacherMe, context: { establishment_id: null, role: null } }, [], null);
    useContextStore().tenantAdmin = true;
    mockApi({ 'GET /platform/dashboard': problem(403, 'Réservé à l\'administrateur de plateforme.') });
    expect(await runGuard('/platform')).toEqual({ name: 'forbidden' });
    expect(usePlatformStore().isAdmin).toBe(false);
  });

  it('ne sonde pas avant la double authentification (le serveur l\'exige pour ce rôle)', async () => {
    signIn({ ...platformMe, two_factor: { enabled: true, required: true, verified: false } }, [], null);
    const calls = mockApi({});
    expect(await runGuard('/platform')).toEqual({ name: 'twofactor' });
    expect(calls.some((c) => c.path === '/platform/dashboard')).toBe(false);
  });

  it('le menu de l\'administrateur de plateforme ne montre que les écrans de plateforme', async () => {
    signInPlatform();
    mockApi({ 'GET /platform/dashboard': { data: {} } });
    const { wrapper } = await mountView(AppShell, {}, '/platform');
    const labels = wrapper.findAll('nav#nav a').map((a) => a.text());
    expect(labels).toEqual(['Tableau de bord', 'Demandes d\'accès', 'Clients', 'Catalogue', 'Paiements', 'Administrateurs', 'Journal', 'Sécurité']);
    expect(wrapper.text()).toContain('Espace administrateur de plateforme');
    expect(wrapper.find('#ctx-est').exists()).toBe(false);
  });

  it('le menu d\'un compte client ne montre aucun écran de plateforme mais propose « Mes applications » à la direction', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({});
    const { wrapper } = await mountView(AppShell, {}, '/personnes');
    const labels = wrapper.findAll('nav#nav a').map((a) => a.text());
    expect(labels).toContain('Mes applications');
    expect(labels).not.toContain('Demandes d\'accès');
    expect(labels).not.toContain('Catalogue');
  });
});

describe('pages publiques', () => {
  it('connexion : lien « Demander l\'accès » et découverte des applications', async () => {
    mockApi({});
    const { wrapper } = await mountView(LoginView, {}, '/connexion');
    const link = wrapper.findAll('a').find((a) => a.text() === 'Demander l\'accès');
    expect(link?.attributes('href')).toBe('/demande-acces');
    expect(wrapper.findAll('a').some((a) => a.attributes('href') === '/decouvrir')).toBe(true);
  });

  it('accueil public : appels à l\'action vers la demande d\'accès et le catalogue', async () => {
    mockApi({});
    const { wrapper } = await mountView(PublicHomeView, {}, '/decouvrir');
    expect(wrapper.get('h1').text()).toContain('une application par type d\'établissement');
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));
    expect(hrefs).toContain('/demande-acces');
    expect(hrefs).toContain('/applications-disponibles');
  });

  it('catalogue : IPES disponible avec demande d\'accès, autres applications « Bientôt »', async () => {
    const calls = mockApi({ 'GET /public/applications': { data: publicApps } });
    const { wrapper } = await mountView(PublicCatalogView, {}, '/applications-disponibles');
    expect(wrapper.text()).toContain('IPES');
    expect(wrapper.text()).toContain('Essai gratuit de 90 jours');
    expect(wrapper.text()).toContain('60 000 FCFA');
    expect(wrapper.text()).toContain('Bientôt disponible');
    const cta = wrapper.findAll('a').filter((a) => a.attributes('aria-label')?.startsWith('Demander l\'accès à'));
    expect(cta).toHaveLength(1);
    expect(cta[0]?.attributes('href')).toBe('/demande-acces?application=ipes');
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined();
  });

  async function fillRequest(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']): Promise<void> {
    await wrapper.get('#ar-institution_name').setValue('IPES Les Palmiers');
    await wrapper.get('#ar-target_type').setValue('higher');
    await flushPromises(2);
    await wrapper.get('#ar-city').setValue('Douala');
    await wrapper.get('#ar-region').setValue('Littoral');
    await wrapper.get('#ar-contact_first_name').setValue('Awa');
    await wrapper.get('#ar-contact_last_name').setValue('Ngono');
    await wrapper.get('#ar-contact_email').setValue('Awa@Palmiers.cm');
    await wrapper.get('#ar-contact_phone').setValue('+237 6 99 00 00 00');
  }

  it('demande d\'accès : validation locale qui dit quoi faire, aucun envoi tant que le formulaire est incomplet', async () => {
    const calls = mockApi({ 'GET /public/applications': { data: publicApps } });
    const { wrapper } = await mountView(AccessRequestView, {}, '/demande-acces');
    await wrapper.get('form').trigger('submit');
    await flushPromises(2);
    expect(wrapper.text()).toContain('Indiquez le nom complet de l\'établissement (3 à 160 caractères).');
    expect(wrapper.text()).toContain('Indiquez une adresse e-mail valide');
    expect(wrapper.get('#ar-institution_name').attributes('aria-invalid')).toBe('true');
    expect(wrapper.get('#ar-institution_name').attributes('aria-describedby')).toBe('ar-institution_name-err');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('demande d\'accès : champ piège caché et non envoyé, jeton de suivi affiché et copiable', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const calls = mockApi({
      'GET /public/applications': { data: publicApps },
      'POST /public/access-requests': () => jsonResponse({ data: { tracking_token: TOKEN, status: 'pending', message: 'Demande enregistrée. Si les informations sont valides, vous recevrez un message de confirmation.' } }, 202),
    });
    const { wrapper } = await mountView(AccessRequestView, {}, '/demande-acces');
    const trap = wrapper.get('#ar-website');
    expect(trap.element.closest('[aria-hidden="true"]')).not.toBeNull();
    expect(trap.attributes('tabindex')).toBe('-1');
    await fillRequest(wrapper);
    expect((wrapper.get('#ar-app_key').element as HTMLSelectElement).value).toBe('ipes'); // seule application disponible pour la cible
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.path).toBe('/public/access-requests');
    expect(post?.body).toEqual({
      institution_name: 'IPES Les Palmiers', target_type: 'higher', city: 'Douala', region: 'Littoral', contact_first_name: 'Awa',
      contact_last_name: 'Ngono', contact_email: 'awa@palmiers.cm', contact_phone: '+237699000000', app_key: 'ipes', locale: 'fr',
    });
    expect(post?.body).not.toHaveProperty('website');
    expect((wrapper.get('#ar-token').element as HTMLInputElement).value).toBe(TOKEN);
    expect(wrapper.text()).toContain('il ne sera plus affiché');
    await wrapper.findAll('button').find((b) => b.text() === 'Copier le numéro')!.trigger('click');
    await flushPromises(2);
    expect(writeText).toHaveBeenCalledWith(TOKEN);
    expect(wrapper.text()).toContain('Numéro copié.');
    expect(wrapper.findAll('a').some((a) => a.attributes('href') === `/demande-acces/${TOKEN}`)).toBe(true);
  });

  it('demande d\'accès : erreurs du serveur placées sous le champ et limite de débit expliquée', async () => {
    let attempt = 0;
    mockApi({
      'GET /public/applications': { data: publicApps },
      'POST /public/access-requests': () => {
        attempt++;
        return attempt === 1
          ? problem(422, 'Données invalides', 'validation', { errors: { app_key: ['Cette application ne convient pas à ce type d\'établissement.'] } })
          : jsonResponse({ type: 'about:blank', title: 'Trop de requêtes', status: 429, detail: '' }, 429, { 'Retry-After': '120' });
      },
    });
    const { wrapper } = await mountView(AccessRequestView, {}, '/demande-acces');
    await fillRequest(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('#ar-app_key-err').text()).toBe('Cette application ne convient pas à ce type d\'établissement.');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toMatch(/120/);
  });

  it('suivi : complément demandé, réponse envoyée, et numéro invalide signalé sans appel', async () => {
    let status = 'info_requested';
    const calls = mockApi({
      [`GET /public/access-requests/${TOKEN}`]: () => ({ data: { reference: 'REF00001', institution_name: 'IPES Les Palmiers', app_key: 'ipes', app_label: 'IPES', status, info_request: 'Joignez l\'arrêté d\'ouverture.', decision_reason: null, submitted_at: '2026-10-09T09:00:00Z', updated_at: '2026-10-09T10:00:00Z' } }),
      [`POST /public/access-requests/${TOKEN}/complement`]: () => {
        status = 'pending';
        return { data: { reference: 'REF00001', institution_name: 'IPES Les Palmiers', app_key: 'ipes', app_label: 'IPES', status, info_request: null, decision_reason: null, submitted_at: '2026-10-09T09:00:00Z', updated_at: '2026-10-09T11:00:00Z' } };
      },
    });
    const { wrapper } = await mountView(AccessTrackingView, { token: TOKEN }, `/demande-acces/${TOKEN}`);
    expect(wrapper.text()).toContain('Complément demandé');
    expect(wrapper.text()).toContain('Joignez l\'arrêté d\'ouverture.');
    await wrapper.get('#tr-complement').setValue('');
    await wrapper.get('.info form').trigger('submit');
    expect(wrapper.text()).toContain('Rédigez votre réponse avant de l\'envoyer.');
    await wrapper.get('#tr-complement').setValue('Arrêté n° 45 joint.');
    await wrapper.get('.info form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ message: 'Arrêté n° 45 joint.' });
    expect(wrapper.text()).toContain('Complément envoyé');
    expect(wrapper.text()).toContain('En attente');

    const bad = await mountView(AccessTrackingView, { token: 'pas-un-jeton' }, '/demande-acces/pas-un-jeton');
    expect(bad.wrapper.text()).toContain('Le numéro de suivi compte 48 caractères');
  });

  it('suivi : jeton inconnu et demande refusée avec motif', async () => {
    const other = 'b'.repeat(48);
    mockApi({
      [`GET /public/access-requests/${TOKEN}`]: problem(404, 'Introuvable'),
      [`GET /public/access-requests/${other}`]: { data: { reference: 'R2', institution_name: 'X', app_key: 'ipes', app_label: 'IPES', status: 'rejected', info_request: null, decision_reason: 'Autorisation ministérielle absente.', submitted_at: '2026-10-01T09:00:00Z', updated_at: '2026-10-02T09:00:00Z' } },
    });
    const missing = await mountView(AccessTrackingView, { token: TOKEN }, `/demande-acces/${TOKEN}`);
    expect(missing.wrapper.text()).toContain('Aucune demande ne correspond à ce numéro');
    const rejected = await mountView(AccessTrackingView, { token: other }, `/demande-acces/${other}`);
    expect(rejected.wrapper.text()).toContain('Autorisation ministérielle absente.');
  });
});

describe('administrateur de plateforme', () => {
  it('tableau de bord : indicateurs, MRR, « Indisponible » avec la raison, aucun en-tête d\'établissement', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/dashboard': { data: {
        as_of: '2026-10-10T08:00:00+01:00',
        clients: { available: true, total: 3 },
        access_requests_pending: { available: true, total: 2 },
        applications: { available: true, open_total: 3, items: [{ key: 'ipes', label: 'IPES', status: 'available', target: 'higher', open: 3, states: { trial: 2, active: 1 } }] },
        trials_ending: { available: true, horizon_days: 14, total: 1, items: [{ ...entitlement({ trial_days_left: 5 }), tenant_name: 'IPES Les Palmiers' }] },
        unpaid: { available: true, total: 0, items: [] },
        mrr_xaf: { available: true, value: 60000, paying_subscriptions: 1 },
        collected_last_30_days_xaf: { available: true, value: 120000 },
        health: { available: false, reason: 'Aucune sonde de santé n\'alimente encore le tableau de bord de la plateforme.' },
      } },
    });
    const { wrapper } = await mountView(PlatformDashboardView, {}, '/platform');
    expect(wrapper.get('h1').text()).toBe('Tableau de bord de la plateforme');
    expect(wrapper.text()).toContain('Revenu mensuel récurrent');
    expect(wrapper.text()).toContain('60 000 FCFA');
    expect(wrapper.text()).toContain('1 abonnement(s) payant(s)');
    expect(wrapper.text()).toContain('Indisponible');
    expect(wrapper.text()).toContain('Aucune sonde de santé');
    expect(wrapper.text()).toContain('IPES Les Palmiers');
    expect(wrapper.text()).toContain('Aucun abonnement en retard ni suspendu.');
    expect(calls.every((c) => c.headers['X-Establishment-Id'] === undefined)).toBe(true);
    expect(wrapper.findAll('a').some((a) => a.text().startsWith('Traiter les demandes'))).toBe(true);
  });

  it('demandes d\'accès : filtre « En attente » par défaut, puis « Acceptée »', async () => {
    signInPlatform();
    const calls = mockApi({ 'GET /platform/access-requests': (c) => page(c.query['filter[status]'] === 'accepted' ? [accessRequest({ status: 'accepted' })] : [accessRequest()]) });
    const { wrapper } = await mountView(PlatformRequestsView, {}, '/platform/demandes');
    expect(calls[0]?.query['filter[status]']).toBe('pending');
    expect(wrapper.text()).toContain('IPES Les Palmiers');
    const accepted = wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'accepted')!;
    await accepted.setValue(true);
    await flushPromises();
    expect(calls.at(-1)?.query['filter[status]']).toBe('accepted');
    expect(wrapper.text()).toContain('Acceptée');
  });

  it('détail : refus avec motif obligatoire, puis 2FA récente exigée, ressaisie du code et reprise de l\'action', async () => {
    signInPlatform();
    let recent = false;
    const calls = mockApi({
      'GET /platform/access-requests/AR01': () => ({ data: accessRequest(recent ? { status: 'rejected', decision_reason: 'Hors périmètre.' } : {}) }),
      'POST /platform/access-requests/AR01/reject': () => (recent ? { data: accessRequest({ status: 'rejected', decision_reason: 'Hors périmètre.' }) } : recent2fa()),
    });
    const { wrapper, router } = await mountView(PlatformRequestView, { id: 'AR01' }, '/platform/demandes/AR01');
    expect(wrapper.text()).toContain('awa@palmiers.cm');
    expect(wrapper.text()).toContain('N° 123/MINESUP');
    await wrapper.findAll('button').find((b) => b.text() === 'Refuser')!.trigger('click');
    await wrapper.findAll('form').at(-1)!.trigger('submit');
    expect(wrapper.text()).toContain('Indiquez un motif');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.get('#rq-reason').setValue('Hors périmètre.');
    await wrapper.findAll('form').at(-1)!.trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Double authentification à confirmer');
    await wrapper.findAll('button').find((b) => b.text() === 'Ressaisir mon code')!.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.name).toBe('twofactor');
    expect(router.currentRoute.value.query.redirect).toBe('/platform/demandes/AR01');
    expect(loadPending()?.body).toEqual({ reason: 'Hors périmètre.' });

    // Retour sur la page après la ressaisie du code : la reprise est proposée, jamais exécutée sans un clic.
    recent = true;
    const back = await mountView(PlatformRequestView, { id: 'AR01' }, '/platform/demandes/AR01');
    expect(back.wrapper.text()).toContain('Action interrompue');
    expect(calls.filter((c) => c.path === '/platform/access-requests/AR01/reject')).toHaveLength(1);
    await back.wrapper.findAll('button').find((b) => b.text() === 'Reprendre l\'action')!.trigger('click');
    await flushPromises();
    const replays = calls.filter((c) => c.path === '/platform/access-requests/AR01/reject');
    expect(replays).toHaveLength(2);
    expect(replays[1]?.body).toEqual({ reason: 'Hors périmètre.' });
    expect(replays[1]?.headers['X-Establishment-Id']).toBeUndefined();
    expect(back.wrapper.text()).toContain('Action exécutée');
    expect(loadPending()).toBeNull();
  });

  it('détail : acceptation avec rattachement facultatif à un client existant', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/access-requests/AR01': { data: accessRequest() },
      'GET /platform/clients': { data: [{ id: 'T9', name: 'Groupe Palmiers', status: 'active', created_at: '2026-01-01', applications: [] }] },
      'POST /platform/access-requests/AR01/accept': { data: accessRequest({ status: 'accepted', tenant_id: 'T9', entitlement: entitlement({ trial_ends_on: '2027-01-07' }), administrator: { person_id: 'P1', login: 'awa@palmiers.cm', status: 'pending_activation' } }) },
    });
    const { wrapper } = await mountView(PlatformRequestView, { id: 'AR01' }, '/platform/demandes/AR01');
    await wrapper.findAll('button').find((b) => b.text() === 'Accepter')!.trigger('click');
    await flushPromises();
    await wrapper.get('#rq-tenant').setValue('T9');
    await wrapper.findAll('form').at(-1)!.trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/accept'))?.body).toEqual({ tenant_id: 'T9' });
    expect(wrapper.text()).toContain('Demande acceptée.');
    expect(wrapper.text()).toContain('awa@palmiers.cm (activation en attente)');
    expect(wrapper.text()).toContain('Cette demande est traitée');
  });

  it('clients : liste filtrable et fiche avec suspension (motif), tarif spécifique et ouverture d\'application', async () => {
    signInPlatform();
    const client = { id: 'T1', name: 'IPES Les Palmiers', status: 'active', created_at: '2026-10-01', applications: [entitlement()], establishments: [{ id: 'E1', name: 'IPES Les Palmiers', type: 'higher' }] };
    const calls = mockApi({
      'GET /platform/clients': { data: [client, { ...client, id: 'T2', name: 'Lycée Bilingue', applications: [] }] },
      'GET /platform/clients/T1': { data: client },
      'GET /platform/applications': { data: [{ ...publicApps[0], required_modules: ['people'], grace_days: 15 }, { key: 'primary', label: 'Primaire', target: 'primary', status: 'available', version: '1.0', monthly_price_xaf: 30000, trial_days: 90, required_modules: [], grace_days: 15 }] },
      'POST /platform/clients/T1/applications/ipes/suspend': { data: entitlement({ state: 'suspended' }) },
      'PATCH /platform/clients/T1/applications/ipes': { data: entitlement() },
      'POST /platform/clients/T1/applications': { data: entitlement({ app_key: 'primary' }) },
    });
    const list = await mountView(PlatformClientsView, {}, '/platform/clients');
    expect(list.wrapper.text()).toContain('Lycée Bilingue');
    await list.wrapper.get('#cli-search').setValue('palmiers');
    expect(list.wrapper.text()).not.toContain('Lycée Bilingue');

    const { wrapper } = await mountView(PlatformClientView, { tenant: 'T1' }, '/platform/clients/T1');
    expect(wrapper.text()).toContain('Essai gratuit');
    await wrapper.findAll('button').find((b) => b.text() === 'Suspendre')!.trigger('click');
    await wrapper.get('form.panel').trigger('submit');
    expect(wrapper.text()).toContain('Indiquez le motif');
    await wrapper.get('textarea').setValue('Impayé depuis 30 jours.');
    await wrapper.get('form.panel').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/suspend'))?.body).toEqual({ reason: 'Impayé depuis 30 jours.' });

    await wrapper.findAll('button').find((b) => b.text() === 'Tarif spécifique')!.trigger('click');
    await wrapper.get('input[inputmode="numeric"]').setValue('');
    await wrapper.get('form.panel').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ monthly_price_xaf: null });

    await wrapper.get('#cl-open-app').setValue('primary');
    await wrapper.findAll('form').at(-1)!.trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/platform/clients/T1/applications')?.body).toEqual({ app_key: 'primary' });
  });

  it('catalogue : n\'envoie que les champs modifiés et valide les nombres', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/applications': { data: [{ ...publicApps[0], required_modules: ['people', 'billing'], grace_days: 15 }] },
      'PATCH /platform/applications/ipes': { data: { ...publicApps[0], monthly_price_xaf: 65000, required_modules: [], grace_days: 15 } },
    });
    const { wrapper } = await mountView(PlatformCatalogView, {}, '/platform/catalogue');
    expect(wrapper.text()).toContain('Modules requis : people, billing');
    await wrapper.get('button[aria-label="Modifier IPES"]').trigger('click');
    await wrapper.get('#cat-trial').setValue('400');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.text()).toContain('entre 0 et 365');
    await wrapper.get('#cat-trial').setValue('90');
    await wrapper.get('#cat-price').setValue('65 000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ monthly_price_xaf: 65000 });
    expect(wrapper.text()).toContain('Catalogue mis à jour.');
  });

  it('paiements d\'abonnement : liste et filtre par état', async () => {
    signInPlatform();
    const calls = mockApi({
      'GET /platform/payments': page([{ id: 'PAY1', tenant_id: 'T1', app_key: 'ipes', reference: 'SUB-001', amount_xaf: 120000, months: 2, channel: 'mtn_momo', status: 'succeeded', receipt_number: 'R-0001', covered_from: '2026-10-01', covered_until: '2026-11-30', paid_at: '2026-10-02T10:00:00Z', created_at: '2026-10-02T09:00:00Z' }]),
      'GET /platform/clients': { data: [{ id: 'T1', name: 'IPES Les Palmiers', status: 'active', created_at: '2026-10-01', applications: [] }] },
    });
    const { wrapper } = await mountView(PlatformPaymentsView, {}, '/platform/paiements');
    expect(wrapper.text()).toContain('SUB-001');
    expect(wrapper.text()).toContain('120 000 FCFA');
    expect(wrapper.text()).toContain('MTN Mobile Money');
    expect(wrapper.text()).toContain('IPES Les Palmiers');
    await wrapper.get('#pp-status').setValue('failed');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/platform/payments').at(-1)?.query['filter[status]']).toBe('failed');
  });
});

describe('applications du client', () => {
  const mine = { data: [{ ...entitlement(), establishments: [{ id: 'E1', name: 'IPES Les Palmiers', type: 'higher' }] }], meta: { catalog: [{ key: 'primary', label: 'Primaire', target: 'primary', status: 'available', monthly_price_xaf: 30000, trial_days: 90 }, { key: 'nursery', label: 'Maternelle', target: 'nursery', status: 'coming_soon', monthly_price_xaf: 20000, trial_days: 90 }] } };

  it('tuile « Mes applications » dans le lanceur pour la direction, absente pour un enseignant', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/todo': { data: [] }, 'GET /me/applications': mine });
    const { wrapper } = await mountView(HomeView);
    expect(wrapper.text()).toContain('Mes applications');
    expect(wrapper.text()).toContain('42 jours d\'essai restants');
    expect(wrapper.findAll('a').some((a) => a.attributes('href') === '/applications')).toBe(true);

    signIn(teacherMe);
    const teacher = await mountView(HomeView);
    expect(teacher.wrapper.text()).not.toContain('Gérer mes applications');
  });

  it('état de l\'essai, ouverture en libre-service après confirmation, « Bientôt » pour les autres', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /me/applications': mine, 'POST /applications/primary/open': () => jsonResponse({ data: entitlement({ app_key: 'primary' }) }, 201) });
    const { wrapper } = await mountView(MyApplicationsView, {}, '/applications');
    expect(wrapper.text()).toContain('42 jours d\'essai restants');
    expect(wrapper.text()).toContain('Établissements : IPES Les Palmiers');
    expect(wrapper.text()).toContain('Bientôt disponible');
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined(); // /me/** sans établissement
    await wrapper.get('button[aria-label="Ouvrir Primaire"]').trigger('click');
    expect(wrapper.text()).toContain('démarre un essai gratuit de 90 jours');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.findAll('button').find((b) => b.text() === 'Confirmer l\'ouverture')!.trigger('click');
    await flushPromises();
    const open = calls.find((c) => c.path === '/applications/primary/open');
    expect(open?.headers['X-Establishment-Id']).toBe('E1');
    expect(wrapper.text()).toContain('Primaire est ouverte');
  });

  it('paiement mobile money : validation, clé d\'idempotence conservée entre deux tentatives, historique et reçu', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let attempt = 0;
    const payment = { id: 'PAY1', tenant_id: 'T1', app_key: 'ipes', reference: 'SUB-001', amount_xaf: 180000, months: 3, channel: 'orange_money', status: 'succeeded', receipt_number: 'R-0001', covered_from: '2026-12-30', covered_until: '2027-03-29', paid_at: '2026-10-10T10:00:00Z', created_at: '2026-10-10T09:00:00Z' };
    const calls = mockApi({
      'GET /me/applications': mine,
      'POST /applications/ipes/payments': () => (++attempt === 1 ? jsonResponse({ type: 'about:blank', title: 'Erreur', status: 503, detail: 'Passerelle indisponible.' }, 503) : jsonResponse({ data: { ...payment, status: 'pending' } }, 202)),
      'GET /applications/ipes/payments': { data: [payment], meta: { entitlement: entitlement() } },
      'GET /application-payments/PAY1/receipt': { data: { receipt_number: 'R-0001', issuer: 'SCHOLARIS', tenant_id: 'T1', app_key: 'ipes', app_label: 'IPES', amount_xaf: 180000, months: 3, covered_from: '2026-12-30', covered_until: '2027-03-29', channel: 'orange_money', reference: 'SUB-001', paid_at: '2026-10-10T10:00:00Z' } },
    });
    const { wrapper } = await mountView(MyApplicationsView, {}, '/applications');
    await wrapper.get('button[aria-label="Payer l\'abonnement de IPES"]').trigger('click');
    const form = () => wrapper.get('form.panel');
    await form().trigger('submit');
    expect(wrapper.text()).toContain('Choisissez MTN Mobile Money ou Orange Money.');
    expect(wrapper.text()).toContain('Indiquez le numéro mobile money');
    await wrapper.get('select[id^="pay-months"]').setValue('3');
    await wrapper.get('input[value="orange_money"]').setValue(true);
    await wrapper.get('input[type="tel"]').setValue('6 99 00 00 00');
    expect(wrapper.text()).toContain('Montant à payer : 180 000 FCFA');
    await form().trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Passerelle indisponible.');
    await form().trigger('submit');
    await flushPromises();
    const posts = calls.filter((c) => c.method === 'POST' && c.path === '/applications/ipes/payments');
    expect(posts).toHaveLength(2);
    expect(posts[0]?.body).toEqual({ months: 3, channel: 'orange_money', payer_phone: '699000000' });
    expect(posts[0]?.headers['Idempotency-Key']).toBeTruthy();
    expect(posts[1]?.headers['Idempotency-Key']).toBe(posts[0]?.headers['Idempotency-Key']);
    expect(posts[0]?.headers['X-Establishment-Id']).toBe('E1');
    expect(wrapper.text()).toContain('Paiement demandé (référence SUB-001)');

    await wrapper.get('button[aria-label="Historique et reçus de IPES"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('SUB-001');
    await wrapper.get('button[aria-label="Voir le reçu du paiement SUB-001"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Reçu n° R-0001');
    expect(wrapper.text()).toContain('Orange Money');
  });

  it('sans établissement actif : consultation seulement, avec la marche à suivre', async () => {
    signIn({ ...teacherMe, context: { establishment_id: null, role: null } }, [], null);
    useContextStore().tenantAdmin = true;
    mockApi({ 'GET /me/applications': mine });
    const { wrapper } = await mountView(MyApplicationsView, {}, '/applications');
    expect(wrapper.text()).toContain('choisissez d\'abord un établissement');
    expect(wrapper.find('button[aria-label="Payer l\'abonnement de IPES"]').exists()).toBe(false);
  });
});

describe('double authentification récente', () => {
  it('l\'écran existant renvoie vers la page d\'origine (chemin interne seulement)', async () => {
    signInPlatform();
    mockApi({ 'POST /auth/2fa/verify': { verified: true, trusted: false }, 'GET /me': { data: platformMe }, 'GET /me/contexts': { data: [] }, 'GET /platform/dashboard': { data: {} }, 'GET /platform/clients/T1': { data: { id: 'T1', name: 'X', status: 'active', created_at: '2026-01-01', applications: [] } } });
    const { wrapper, router } = await mountView(TwoFactorView, {}, '/double-authentification?redirect=/platform/clients/T1');
    await wrapper.findComponent(CodeInput).setValue('123456');
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/platform/clients/T1'), { timeout: 8000 });
  });

  it('une action mise de côté expire et n\'est rejouée que sur une route /platform', () => {
    savePending({ method: 'POST', path: '/platform/x', body: {}, label: 'x', returnTo: '/platform', savedAt: Date.now() - 11 * 60 * 1000 });
    expect(loadPending()).toBeNull();
    savePending({ method: 'POST', path: '/payments', body: {}, label: 'x', returnTo: '/platform', savedAt: Date.now() });
    expect(loadPending()).toBeNull();
  });
});
