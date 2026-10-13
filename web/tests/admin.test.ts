import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import { guard } from '@/router';
import HomeView from '@/views/HomeView.vue';
import AdminHomeView from '@/views/admin/AdminHomeView.vue';
import AdminEstablishmentsView from '@/views/admin/AdminEstablishmentsView.vue';
import AdminEstablishmentView from '@/views/admin/AdminEstablishmentView.vue';
import AdminYearsView from '@/views/admin/AdminYearsView.vue';
import AdminStructuresView from '@/views/admin/AdminStructuresView.vue';
import AdminUsersView from '@/views/admin/AdminUsersView.vue';
import AdminSettingsView from '@/views/admin/AdminSettingsView.vue';
import { formatSettingValue, parseSettingValue, SETTINGS_CATALOG } from '@/views/admin/catalog';
import { useContextStore } from '@/stores/context';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);

const superMe = { ...teacherMe, context: { establishment_id: null, role: null } };
const directorMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ roles: [{ code: 'directeur', label: 'Directeur' }] });
const superTile = tile({ roles: [{ code: 'super_admin', label: 'Super administrateur' }] });
const page = (items: unknown[]) => ({ data: items, links: { next: null }, meta: { next_cursor: null } });
const problem = (status: number, detail: string) => jsonResponse({ type: 'https://api.scholaris.cm/errors/x', title: 'Erreur', status, detail, trace_id: 'TR-ADM' }, status);

describe('accès aux écrans d\'administration', () => {
  async function run(path: string): Promise<unknown> {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path).catch(() => undefined);
    return guard(router.resolve(path) as never);
  }

  it('reconnaît un administrateur du client sans établissement grâce à la liste des établissements (sans en-tête)', async () => {
    signIn(superMe, [], null);
    useContextStore().loaded = false;
    const calls = mockApi({ 'GET /me/contexts': { data: [] }, 'GET /establishments': page([]) });
    await useContextStore().load({ establishment_id: null, role: null });
    const ctx = useContextStore();
    expect(ctx.tenantAdmin).toBe(true);
    expect(calls.find((c) => c.path === '/establishments')?.headers['X-Establishment-Id']).toBeUndefined();
    expect(ctx.can('organisation.manage', 'identity.manage')).toBe(true);
    expect(ctx.can('billing.read')).toBe(false); // seules les permissions d'administration sont ouvertes
  });

  it('ne donne rien à une personne sans rattachement quand la liste est refusée', async () => {
    signIn(teacherMe, [], null);
    useContextStore().loaded = false;
    mockApi({ 'GET /me/contexts': { data: [] }, 'GET /establishments': problem(403, 'Droit manquant.') });
    await useContextStore().load({ establishment_id: null, role: null });
    expect(useContextStore().tenantAdmin).toBe(false);
    expect(useContextStore().can('organisation.manage')).toBe(false);
  });

  it('ouvre l\'administration au super administrateur sans établissement, mais renvoie les écrans d\'établissement vers l\'accueil', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    expect(await run('/administration')).toBe(true);
    expect(await run('/administration/etablissements')).toBe(true);
    expect(await run('/administration/annees')).toEqual({ name: 'home' });
    expect(await run('/administration/parametres')).toEqual({ name: 'home' });
  });

  it('ouvre tout au super administrateur et au directeur d\'un établissement, rien à l\'enseignant ni au comptable', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    for (const p of ['/administration', '/administration/etablissements', '/administration/annees', '/administration/structures', '/administration/utilisateurs', '/administration/parametres']) {
      expect(await run(p)).toBe(true);
    }
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'super_admin' } }, [superTile], 'super_admin');
    expect(await run('/administration/utilisateurs')).toBe(true);
    signIn(teacherMe);
    expect(await run('/administration')).toEqual({ name: 'forbidden' });
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'comptable' } }, [tile({ roles: [{ code: 'comptable', label: 'Comptable' }] })], 'comptable');
    expect(await run('/administration/parametres')).toEqual({ name: 'forbidden' });
  });
});

describe('accueil du super administrateur sans établissement', () => {
  it('propose « Créer le premier établissement » au lieu du message d\'absence', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    mockApi({ 'GET /me/todo': { data: [] } });
    const { wrapper } = await mountView(HomeView);
    expect(wrapper.text()).toContain('Créer le premier établissement');
    expect(wrapper.text()).not.toContain('Aucun établissement ne vous est rattaché');
    const link = wrapper.findAll('a').find((a) => a.text().includes('Créer le premier établissement'));
    expect(link?.attributes('href')).toContain('/administration/etablissements');
    expect(link?.attributes('href')).toContain('nouveau=1');
  });

  it('garde le message d\'absence pour une personne qui n\'administre pas', async () => {
    signIn(teacherMe, [], null);
    mockApi({ 'GET /me/todo': { data: [] } });
    const { wrapper } = await mountView(HomeView);
    expect(wrapper.text()).toContain('Aucun établissement ne vous est rattaché');
  });

  it('le tableau d\'accueil de l\'administration invite à créer le premier établissement', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    const { wrapper } = await mountView(AdminHomeView, {}, '/administration');
    expect(wrapper.get('h1').text()).toBe('Administration');
    expect(wrapper.text()).toContain('Commencez par créer votre premier établissement');
    expect(wrapper.text()).toContain('Disponible une fois un établissement choisi.');
  });
});

describe('écran Établissements', () => {
  it('crée le premier établissement sans en-tête X-Establishment-Id, puis l\'active', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    let created = false;
    const est = { id: 'E9', name: 'Lycée Test', type: 'higher', branding: null, modules_enabled: [] };
    const calls = mockApi({
      'GET /establishments': () => page(created ? [est] : []),
      'POST /establishments': () => {
        created = true;
        return jsonResponse({ data: est }, 201);
      },
      'GET /me/contexts': () => ({ data: created ? [tile({ id: 'E9', name: 'Lycée Test', type: 'higher', roles: [{ code: 'super_admin', label: 'Super administrateur' }] })] : [] }),
      'POST /me/context': { data: { context: tile({ id: 'E9', name: 'Lycée Test', type: 'higher' }), role: 'super_admin' } },
    });
    const { wrapper } = await mountView(AdminEstablishmentsView, {}, '/administration/etablissements?nouveau=1');
    expect(wrapper.text()).toContain('Aucun établissement');
    await wrapper.get('#est-name').setValue('Lycée Test');
    await wrapper.get('#est-type').setValue('higher');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/establishments');
    expect(post?.body).toEqual({ name: 'Lycée Test', type: 'higher' });
    expect(post?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.text()).toContain('« Lycée Test » est créé');
    expect(wrapper.text()).toContain('Supérieur');
    expect(useContextStore().establishmentId).toBe('E9');
  });

  it('demande le nom avant d\'envoyer et ne montre pas la création au directeur', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    const calls = mockApi({ 'GET /establishments': page([]) });
    const { wrapper } = await mountView(AdminEstablishmentsView, {}, '/administration/etablissements?nouveau=1');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.text()).toContain('Saisissez le nom de l’établissement.');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    wrapper.unmount();

    signIn(directorMe, [directorTile], 'directeur');
    useContextStore().tenantAdmin = false;
    mockApi({ 'GET /establishments': page([{ id: 'E1', name: 'Collège Les Palmiers', type: 'secondary', modules_enabled: ['people'] }]) });
    const second = await mountView(AdminEstablishmentsView, {}, '/administration/etablissements');
    expect(second.wrapper.text()).toContain('Collège Les Palmiers');
    expect(second.wrapper.text()).not.toContain('Nouvel établissement');
  });

  it('affiche l\'erreur du serveur avec ce qu\'il faut faire et la trace', async () => {
    signIn(superMe, [], null);
    useContextStore().tenantAdmin = true;
    mockApi({ 'GET /establishments': page([]), 'POST /establishments': problem(422, 'Le nom est trop long.') });
    const { wrapper } = await mountView(AdminEstablishmentsView, {}, '/administration/etablissements?nouveau=1');
    await wrapper.get('#est-name').setValue('X');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Le nom est trop long.');
    expect(wrapper.get('[role="alert"]').text()).toContain('TR-ADM');
  });
});

describe('fiche d\'un établissement', () => {
  const api = () =>
    mockApi({
      'GET /establishments/E1': { data: { id: 'E1', name: 'Collège Les Palmiers', type: 'secondary', branding: {}, modules_enabled: ['people'] } },
      'GET /establishments/E1/branding': { data: { accent: '#F26B1D', headers: ['République du Cameroun'], authorization_numbers: ['N°123'] } },
      'GET /establishments/E1/modules': { data: { modules_enabled: ['people'], available: ['people', 'billing', 'reporting'] } },
      'PATCH /establishments/E1': (c) => ({ data: { id: 'E1', name: String(c.body?.name), type: 'secondary' } }),
      'PUT /establishments/E1/branding': (c) => ({ data: c.body }),
      'PUT /establishments/E1/modules': (c) => ({ data: { modules_enabled: c.body?.modules, available: ['people', 'billing', 'reporting'] } }),
      'GET /me/contexts': { data: [directorTile] },
    });

  it('renomme, enregistre couleur et en-têtes, active des modules', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api();
    const { wrapper } = await mountView(AdminEstablishmentView, { id: 'E1' }, '/administration/etablissements/E1');
    expect(wrapper.get('h1').text()).toBe('Collège Les Palmiers');
    expect((wrapper.get('#b-headers').element as HTMLTextAreaElement).value).toBe('République du Cameroun');

    await wrapper.get('#e-name').setValue('Collège des Palmiers');
    await wrapper.get('form[aria-labelledby="rename-title"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ name: 'Collège des Palmiers' });

    await wrapper.get('#b-accent').setValue('#00AAFF');
    await wrapper.get('#b-headers').setValue('Paix - Travail - Patrie\nMinistère des Enseignements secondaires');
    await wrapper.get('form[aria-labelledby="brand-title"]').trigger('submit');
    await flushPromises();
    const brand = calls.find((c) => c.method === 'PUT' && c.path.endsWith('/branding'));
    expect(brand?.body?.accent).toBe('#00AAFF');
    expect(brand?.body?.headers).toEqual(['Paix - Travail - Patrie', 'Ministère des Enseignements secondaires']);
    expect(brand?.headers['X-Establishment-Id']).toBeUndefined();

    const boxes = wrapper.findAll('fieldset.mods input[type="checkbox"]');
    expect(boxes).toHaveLength(3);
    await boxes[1]?.setValue(true);
    await wrapper.get('form[aria-labelledby="mod-title"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PUT' && c.path.endsWith('/modules'))?.body).toEqual({ modules: ['people', 'billing'] });
  });

  it('refuse une couleur trop sombre avant l\'envoi (contraste 4,5:1)', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api();
    const { wrapper } = await mountView(AdminEstablishmentView, { id: 'E1' }, '/administration/etablissements/E1');
    await wrapper.get('#b-accent').setValue('#101010');
    expect(wrapper.get('#b-accent-hint').text()).toContain('contraste d’au moins 4,5:1');
    expect(wrapper.get('form[aria-labelledby="brand-title"] button[type="submit"]').attributes('disabled')).toBeDefined();
    await wrapper.get('form[aria-labelledby="brand-title"]').trigger('submit');
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
  });
});

describe('écran Années académiques, périodes et clôture', () => {
  const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-15', status: 'open', closed_at: null };
  const checks = [
    { key: 'billing.unpaid', label: 'Impayés', status: 'ok', blocking_count: 0, detail: null },
    { key: 'assessment.grades_unlocked', label: 'Notes non verrouillées', status: 'not_evaluable', blocking_count: 0, detail: 'Aucun module ne fournit encore ce contrôle.' },
  ];
  const closure = (requestedBy: string) => ({ id: 'C1', academic_year_id: 'Y1', next_academic_year_id: null, status: 'pending_approval', requested_by: requestedBy, requested_at: '2027-07-01T09:00:00+01:00', decided_by: null, closed_at: null, steps: {} });

  it('crée une année puis une période adaptée au type (trimestres ou séquences au secondaire)', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let made = false;
    const calls = mockApi({
      'GET /academic-years': () => page(made ? [year] : []),
      'POST /academic-years': (c) => {
        made = true;
        return jsonResponse({ data: { ...year, ...c.body } }, 201);
      },
      'GET /periods': page([]),
      'POST /periods': (c) => jsonResponse({ data: { id: 'P1', ...c.body } }, 201),
      'GET /academic-years/Y1/closure-check': { data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 1, checks, closure: null } },
    });
    const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
    expect(wrapper.text()).toContain('Aucune année scolaire');
    await wrapper.get('#y-label').setValue('2026-2027');
    await wrapper.get('#y-start').setValue('2026-09-01');
    await wrapper.get('#y-end').setValue('2027-07-15');
    await wrapper.get('form[aria-labelledby="y-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/academic-years')?.body).toEqual({ label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-15' });
    expect(wrapper.text()).toContain('Périodes de 2026-2027');
    const kinds = wrapper.findAll('#p-kind option').map((o) => o.text());
    expect(kinds).toEqual(['Trimestre', 'Séquence']);

    await wrapper.get('#p-start').setValue('2026-09-01');
    await wrapper.get('#p-end').setValue('2026-12-15');
    await wrapper.get('form[aria-labelledby="p-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/periods')?.body).toEqual({ academic_year_id: 'Y1', kind: 'trimester', ordinal: 1, starts_on: '2026-09-01', ends_on: '2026-12-15' });
    expect(wrapper.text()).toContain('Trimestre');
  });

  it('refuse une fin de période avant le début sans appeler le serveur', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /academic-years': page([year]),
      'GET /periods': page([]),
      'GET /academic-years/Y1/closure-check': { data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 0, checks, closure: null } },
    });
    const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
    await wrapper.get('tbody button').trigger('click');
    await flushPromises();
    await wrapper.get('#p-start').setValue('2026-12-15');
    await wrapper.get('#p-end').setValue('2026-09-01');
    await wrapper.get('form[aria-labelledby="p-new"]').trigger('submit');
    expect(wrapper.text()).toContain('La date de fin ne peut pas précéder la date de début.');
    expect(calls.some((c) => c.path === '/periods' && c.method === 'POST')).toBe(false);
  });

  it('dépose une demande de clôture, puis l\'état « en attente » interdit à son auteur de l\'approuver', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let current: unknown = null;
    const calls = mockApi({
      'GET /academic-years': page([year]),
      'GET /periods': page([]),
      'GET /academic-years/Y1/closure-check': () => ({ data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 1, checks, closure: current } }),
      'POST /academic-years/Y1/close': () => {
        current = closure('U1'); // la personne connectée (teacherMe.id)
        return jsonResponse({ data: current }, 202);
      },
    });
    const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
    await wrapper.get('tbody button').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="closure-status"]').text()).toBe('Aucune demande');
    expect(wrapper.text()).toContain('Non évaluable');
    const request = wrapper.findAll('button').find((b) => b.text() === 'Demander la clôture');
    await request?.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path === '/academic-years/Y1/close')).toBe(true);
    expect(wrapper.get('[data-test="closure-status"]').text()).toBe('En attente d’approbation');
    expect(wrapper.text()).toContain('Demande déposée par vous');
    expect(wrapper.text()).toContain('Vous ne pouvez pas approuver votre propre demande');
    const approve = wrapper.findAll('button').find((b) => b.text() === 'Approuver la clôture');
    expect(approve?.attributes('disabled')).toBeDefined();
  });

  it('permet à une autre personne d\'approuver, et affiche l\'année clôturée', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let current: unknown = closure('U2');
    let yearStatus = 'open';
    const calls = mockApi({
      'GET /academic-years': () => page([{ ...year, status: yearStatus, closed_at: yearStatus === 'closed' ? '2027-07-02T10:00:00+01:00' : null }]),
      'GET /periods': page([]),
      'GET /academic-years/Y1/closure-check': () => ({ data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 0, checks, closure: current } }),
      'POST /academic-years/Y1/close/approve': () => {
        yearStatus = 'closed';
        current = { ...closure('U2'), status: 'closed', closed_at: '2027-07-02T10:00:00+01:00' };
        return { data: current };
      },
    });
    const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
    await wrapper.get('tbody button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('double authentification');
    const approve = wrapper.findAll('button').find((b) => b.text() === 'Approuver la clôture');
    expect(approve?.attributes('disabled')).toBeUndefined();
    await approve?.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'POST' && c.path.endsWith('/close/approve'))).toBe(true);
    expect(wrapper.get('[data-test="closure-status"]').text()).toBe('Clôturée');
    expect(wrapper.text()).toContain('Année clôturée le');
  });

  it('dit quoi faire quand l\'approbation est refusée (double authentification)', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /academic-years': page([year]),
      'GET /periods': page([]),
      'GET /academic-years/Y1/closure-check': { data: { academic_year_id: 'Y1', ready: true, blocking_count: 0, not_evaluable_count: 0, checks, closure: closure('U2') } },
      'POST /academic-years/Y1/close/approve': problem(403, 'Une double authentification récente est exigée pour approuver la clôture d\'une année.'),
    });
    const { wrapper } = await mountView(AdminYearsView, {}, '/administration/annees');
    await wrapper.get('tbody button').trigger('click');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Approuver la clôture')?.trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('double authentification récente');
  });
});

describe('écran Structures', () => {
  it('au secondaire : niveaux, classes et groupes, pas de filière ; crée une classe', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /structures': page([{ id: 'S1', org_unit_id: null, kind: 'level', name: 'Seconde', capacity: null }]),
      'GET /org-units': page([]),
      'POST /structures': (c) => jsonResponse({ data: { id: 'S2', org_unit_id: null, capacity: null, ...c.body } }, 201),
    });
    const { wrapper } = await mountView(AdminStructuresView, {}, '/administration/structures');
    expect(wrapper.findAll('#s-kind option').map((o) => o.text())).toEqual(['Niveaux', 'Classes', 'Groupes']);
    expect(wrapper.text()).toContain('Seconde');
    await wrapper.get('#s-kind').setValue('class');
    await wrapper.get('#s-name').setValue('2nde A');
    await wrapper.get('#s-cap').setValue('45');
    await wrapper.get('form[aria-labelledby="s-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ kind: 'class', name: '2nde A', org_unit_id: null, capacity: 45 });
    expect(wrapper.text()).toContain('2nde A');
  });

  it('au supérieur : filières et départements', async () => {
    signIn(directorMe, [tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] })], 'directeur');
    mockApi({
      'GET /structures': page([]),
      'GET /org-units': page([{ id: 'D1', parent_id: null, type: 'department', name: 'Droit' }]),
    });
    const { wrapper } = await mountView(AdminStructuresView, {}, '/administration/structures');
    expect(wrapper.findAll('#s-kind option').map((o) => o.text())).toContain('Filières');
    expect(wrapper.text()).toContain('Départements et facultés');
    expect(wrapper.text()).toContain('Droit');
  });

  it('refuse un effectif invalide sans appeler le serveur', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /structures': page([]), 'GET /org-units': page([]) });
    const { wrapper } = await mountView(AdminStructuresView, {}, '/administration/structures');
    await wrapper.get('#s-name').setValue('2nde B');
    await wrapper.get('#s-cap').setValue('0');
    await wrapper.get('form[aria-labelledby="s-new"]').trigger('submit');
    expect(wrapper.text()).toContain('nombre entier entre 1 et 10 000');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });
});

describe('écran Utilisateurs et rôles', () => {
  const assignment = { id: 'A1', person_id: 'P1', role: 'enseignant', scope_type: 'establishment', scope_id: 'E1', establishment_id: 'E1', starts_at: '2026-09-01T00:00:00+01:00', ends_at: null, delegated_by: null };
  const api = (extra: Record<string, unknown> = {}) =>
    mockApi({
      'GET /role-assignments': page([assignment]),
      'GET /people/P1': { data: { id: 'P1', first_name: 'Joseph', last_name: 'Ateba' } },
      'GET /people': page([{ id: 'P2', first_name: 'Nadia', last_name: 'Bello' }]),
      'GET /org-units': page([]),
      'GET /structures': page([{ id: 'S1', org_unit_id: null, kind: 'class', name: '2nde A', capacity: null }]),
      ...extra,
    } as never);

  it('liste les rôles avec le nom de la personne et la portée, et retire un rôle', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api({ 'DELETE /role-assignments/A1': new Response(null, { status: 204 }) });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    const row = wrapper.get('tbody tr');
    expect(row.text()).toContain('ATEBA Joseph');
    expect(row.text()).toContain('Enseignant');
    expect(row.text()).toContain('Établissement · Collège Les Palmiers');
    expect(row.text()).toContain('Sans limite');
    await row.get('button').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/role-assignments/A1')).toBe(true);
    expect(wrapper.text()).toContain('est retiré à ATEBA Joseph');
  });

  it('attribue un rôle d\'établissement à une personne trouvée par recherche', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api({ 'POST /role-assignments': (c: { body: unknown }) => jsonResponse({ data: { id: 'A2', ...(c.body as object) } }, 201) });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    expect(wrapper.findAll('#a-role option').some((o) => o.text() === 'Super administrateur')).toBe(false);
    expect(wrapper.findAll('#a-role option').some((o) => o.text() === 'Direction de groupe')).toBe(false);
    await wrapper.get('#a-q').setValue('Bello');
    await wrapper.get('#a-q').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await wrapper.get('.found button').trigger('click');
    await wrapper.get('#a-role').setValue('comptable');
    await wrapper.get('form[aria-labelledby="a-new"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/role-assignments');
    expect(post?.body).toEqual({ person_id: 'P2', role: 'comptable', scope_type: 'establishment', scope_id: 'E1', establishment_id: 'E1' });
    expect(wrapper.text()).toContain('est attribué à BELLO Nadia');
  });

  it('un rôle de niveau groupe se donne avec la portée « groupe » seulement, et il est réservé au super administrateur', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'super_admin' } }, [superTile], 'super_admin');
    const calls = api({ 'POST /role-assignments': (c: { body: unknown }) => jsonResponse({ data: { id: 'A3', ...(c.body as object) } }, 201) });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    await wrapper.get('#a-q').setValue('Bello');
    await wrapper.get('#a-q').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await wrapper.get('.found button').trigger('click');
    await wrapper.get('#a-role').setValue('direction_groupe');
    expect(wrapper.find('#a-target').exists()).toBe(false);
    expect((wrapper.get('#a-scope').element as HTMLSelectElement).disabled).toBe(true);
    await wrapper.get('form[aria-labelledby="a-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/role-assignments')?.body).toEqual({ person_id: 'P2', role: 'direction_groupe', scope_type: 'tenant' });
  });

  it('exige une date de fin pour une délégation, puis délègue', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api({ 'POST /role-assignments/delegations': (c: { body: unknown }) => jsonResponse({ data: { id: 'A4', ...(c.body as object) } }, 201) });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    await wrapper.get('#a-q').setValue('Bello');
    await wrapper.get('#a-q').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await wrapper.get('.found button').trigger('click');
    await wrapper.get('#a-role').setValue('directeur');
    await wrapper.get('.check input[type="checkbox"]').setValue(true);
    await wrapper.get('form[aria-labelledby="a-new"]').trigger('submit');
    expect(wrapper.text()).toContain('Une délégation doit avoir une date de fin.');
    expect(calls.some((c) => c.path === '/role-assignments/delegations')).toBe(false);
    await wrapper.get('#a-to').setValue('2026-11-30');
    await wrapper.get('form[aria-labelledby="a-new"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.path === '/role-assignments/delegations');
    expect(post?.body?.role).toBe('directeur');
    expect(String(post?.body?.ends_at)).toMatch(/^2026-11-30T|^2026-11-29T/);
    expect(wrapper.text()).toContain('est délégué à BELLO Nadia');
  });

  it('crée une personne avec son compte, la sélectionne, et exige un contact', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = api({ 'POST /users': { data: { person_id: 'P9', user_id: 'U9', status: 'pending' } } });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    await wrapper.get('#u-ln').setValue('Foka');
    await wrapper.get('#u-fn').setValue('Emmanuel');
    await wrapper.get('form[aria-labelledby="u-new"]').trigger('submit');
    expect(wrapper.get('#u-contact').classes()).toContain('error');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.get('#u-em').setValue('emm@example.cm');
    await wrapper.get('form[aria-labelledby="u-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/users')?.body).toEqual({ first_name: 'Emmanuel', last_name: 'Foka', email: 'emm@example.cm', locale: 'fr' });
    expect(wrapper.text()).toContain('Emmanuel Foka est créée');
    expect(wrapper.get('.picked').text()).toContain('FOKA Emmanuel');
  });

  it('montre le refus du serveur avec sa trace', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    api({ 'GET /role-assignments': problem(403, 'Accès refusé aux affectations.') });
    const { wrapper } = await mountView(AdminUsersView, {}, '/administration/utilisateurs');
    expect(wrapper.get('[role="alert"]').text()).toContain('TR-ADM');
  });
});

describe('écran Paramètres', () => {
  const effective = [
    { key: 'assessment.scale', value: 20, source: 'tenant', overridden: [] },
    { key: 'billing.reminder_offsets', value: [-7, 1, 15], source: 'establishment', overridden: [] },
    { key: 'custom.flag', value: true, source: 'platform', overridden: [] },
  ];

  it('affiche la valeur et son origine, enregistre au niveau de l\'établissement', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /settings/effective': { data: effective },
      'PUT /settings': (c) => ({ data: { scope: 'establishment', key: c.body?.key, value: c.body?.value }, effective: { key: c.body?.key, value: c.body?.value, source: 'establishment', overridden: [] } }),
    });
    const { wrapper } = await mountView(AdminSettingsView, {}, '/administration/parametres');
    const scale = wrapper.get('[id="set-assessment.scale"]');
    expect((scale.element as HTMLInputElement).value).toBe('20');
    expect(wrapper.get('[id="hint-assessment.scale"]').text()).toContain('défini pour le client');
    expect((wrapper.get('[id="set-billing.reminder_offsets"]').element as HTMLInputElement).value).toBe('-7, 1, 15');
    expect(wrapper.text()).toContain('custom.flag');

    await scale.setValue('25');
    await scale.element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ scope: 'establishment', key: 'assessment.scale', value: 25 });
    expect(wrapper.get('[id="hint-assessment.scale"]').text()).toContain('défini pour cet établissement');
    expect(wrapper.text()).toContain('Enregistré.');
  });

  it('enregistre une liste de relances et une valeur booléenne', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /settings/effective': { data: [] },
      'PUT /settings': (c) => ({ data: { scope: 'establishment', key: c.body?.key, value: c.body?.value }, effective: null }),
    });
    const { wrapper } = await mountView(AdminSettingsView, {}, '/administration/parametres');
    await wrapper.get('[id="set-billing.reminder_offsets"]').setValue('-3, 2, 10');
    await wrapper.get('[id="set-billing.reminder_offsets"]').element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    await wrapper.get('[id="set-attendance.notify_parents"]').setValue('false');
    await wrapper.get('[id="set-attendance.notify_parents"]').element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    const puts = calls.filter((c) => c.method === 'PUT').map((c) => c.body);
    expect(puts).toEqual([
      { scope: 'establishment', key: 'billing.reminder_offsets', value: [-3, 2, 10] },
      { scope: 'establishment', key: 'attendance.notify_parents', value: false },
    ]);
  });

  it('refuse une valeur hors limites avec le message précis, sans appeler le serveur', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /settings/effective': { data: effective } });
    const { wrapper } = await mountView(AdminSettingsView, {}, '/administration/parametres');
    const scale = wrapper.get('[id="set-assessment.scale"]');
    await scale.setValue('5000');
    await scale.element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(wrapper.get('form [role="alert"]').text()).toBe('La valeur doit être comprise entre 1 et 1000.');
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
  });

  it('montre le refus du serveur sur la ligne concernée', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /settings/effective': { data: effective }, 'PUT /settings': problem(403, 'Vous n\'avez pas le droit de modifier les paramètres.') });
    const { wrapper } = await mountView(AdminSettingsView, {}, '/administration/parametres');
    const scale = wrapper.get('[id="set-assessment.scale"]');
    await scale.setValue('18');
    await scale.element.closest('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(wrapper.text()).toContain('pas le droit de modifier les paramètres');
    expect(wrapper.text()).toContain('TR-ADM');
  });
});

describe('catalogue des paramètres', () => {
  const def = (key: string) => SETTINGS_CATALOG.find((d) => d.key === key)!;

  it('analyse les valeurs selon leur nature', () => {
    expect(parseSettingValue(def('assessment.pass_mark'), '9,5')).toEqual({ ok: true, value: 9.5 });
    expect(parseSettingValue(def('assessment.pass_mark'), '')).toEqual({ ok: false, reason: 'empty' });
    expect(parseSettingValue(def('assessment.quorum_percent'), '101')).toEqual({ ok: false, reason: 'range' });
    expect(parseSettingValue(def('billing.reminder_offsets'), '-7; 1\n15')).toEqual({ ok: true, value: [-7, 1, 15] });
    expect(parseSettingValue(def('billing.reminder_offsets'), '1.5')).toEqual({ ok: false, reason: 'list' });
    expect(parseSettingValue(def('attendance.sanction_scale'), 'blame, retenue')).toEqual({ ok: true, value: ['blame', 'retenue'] });
    expect(parseSettingValue(def('billing.sibling_discount'), '{"mode":"percent","value":10}')).toEqual({ ok: true, value: { mode: 'percent', value: 10 } });
    expect(parseSettingValue(def('billing.sibling_discount'), '{oups')).toEqual({ ok: false, reason: 'json' });
    expect(parseSettingValue(def('attendance.notify_parents'), 'false')).toEqual({ ok: true, value: false });
  });

  it('met en forme les valeurs du serveur', () => {
    expect(formatSettingValue(def('billing.reminder_offsets'), [-7, 1])).toBe('-7, 1');
    expect(formatSettingValue(def('attendance.notify_parents'), true)).toBe('true');
    expect(formatSettingValue(def('assessment.scale'), undefined)).toBe('');
  });
});
