import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginView from '@/views/LoginView.vue';
import HomeView from '@/views/HomeView.vue';
import AttendanceView from '@/views/AttendanceView.vue';
import FamilyPortalView from '@/views/FamilyPortalView.vue';
import MobilePayPanel from '@/components/MobilePayPanel.vue';
import { guard } from '@/router';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import { listQueue } from '@/offline/queue';
import { todayIso } from '@/lib/format';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';

beforeEach(freshEnvironment);

describe('écran Connexion', () => {
  it('affiche des libellés liés aux champs et refuse un envoi vide sans appeler le serveur', async () => {
    const calls = mockApi({});
    const { wrapper } = await mountView(LoginView, {}, '/connexion');
    expect(wrapper.get('label[for="identifier"]').text()).toContain('Numéro de téléphone ou e-mail');
    expect(wrapper.get('label[for="password"]').text()).toBe('Mot de passe');
    await wrapper.get('form').trigger('submit');
    expect(calls).toHaveLength(0);
    expect(wrapper.text()).toContain('Saisissez votre numéro de téléphone ou votre adresse e-mail.');
    expect(wrapper.get('#identifier').attributes('aria-invalid')).toBe('true');
  });

  it('se connecte en mode web (cookie), charge le profil puis ouvre l\'accueil', async () => {
    const calls = mockApi({
      'POST /auth/login': { token_type: 'Bearer', expires_at: '2026-10-09T20:00:00+01:00', new_device: false, two_factor_required: false, two_factor_setup_required: false, user: { id: 'U1', person_id: 'P1', first_name: 'Joseph', last_name: 'Ateba', locale: 'fr', status: 'active' } },
      'GET /me': { data: teacherMe },
    });
    const { wrapper, router } = await mountView(LoginView, {}, '/connexion');
    await wrapper.get('#identifier').setValue('+237600000001');
    await wrapper.get('#password').setValue('un mot de passe solide');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const login = calls.find((c) => c.path === '/auth/login');
    expect(login?.body).toMatchObject({ identifier: '+237600000001', remember: true, client: 'web' });
    expect(login?.headers['X-Requested-With']).toBe('XMLHttpRequest');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), { timeout: 8000 });
    expect(useAuthStore().me?.first_name).toBe('Joseph');
    expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('');
  });

  it('envoie vers la double authentification quand elle est exigée', async () => {
    mockApi({
      'POST /auth/login': { token_type: 'Bearer', expires_at: 'x', new_device: true, two_factor_required: true, two_factor_setup_required: false, user: { id: 'U1', person_id: 'P1', first_name: 'A', last_name: 'B', locale: 'fr', status: 'active' } },
      'GET /me': { data: { ...teacherMe, two_factor: { enabled: true, required: true, verified: false } } },
    });
    const { wrapper, router } = await mountView(LoginView, {}, '/connexion');
    await wrapper.get('#identifier').setValue('a@b.cm');
    await wrapper.get('#password').setValue('x');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('twofactor'), { timeout: 8000 });
  });

  it('dit combien d\'essais il reste, affiche la référence de trace et ne divulgue pas le mot de passe', async () => {
    mockApi({
      'POST /auth/login': jsonResponse({ type: 'https://api.scholaris.cm/errors/invalid-credentials', title: 'Identifiants incorrects', status: 401, detail: 'Mot de passe incorrect. Il vous reste 2 essais avant un verrouillage de 15 minutes.', attempts_left: 2, trace_id: 'TRACE-123' }, 401),
    });
    const { wrapper } = await mountView(LoginView, {}, '/connexion');
    await wrapper.get('#identifier').setValue('a@b.cm');
    await wrapper.get('#password').setValue('secret-visible?');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const alert = wrapper.get('[role="alert"]');
    expect(alert.text()).toContain('Il vous reste 2 essais');
    expect(alert.text()).toContain('TRACE-123');
    expect(wrapper.html()).not.toContain('secret-visible?');
  });

  it('bloque le bouton pendant un verrouillage de 15 minutes et affiche le décompte', async () => {
    mockApi({
      'POST /auth/login': jsonResponse({ type: 'https://api.scholaris.cm/errors/locked', title: 'Compte verrouillé', status: 423, detail: 'Trop d\'échecs de connexion.', attempts_left: 0, locked_until: '2026-10-09T10:15:00+01:00', trace_id: 't' }, 423, { 'Retry-After': '900' }),
    });
    const { wrapper } = await mountView(LoginView, {}, '/connexion');
    await wrapper.get('#identifier').setValue('a@b.cm');
    await wrapper.get('#password').setValue('x');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('15:00');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });
});

describe('garde de routes', () => {
  async function run(path: string): Promise<unknown> {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path).catch(() => undefined);
    return guard(router.resolve(path) as never);
  }

  it('renvoie un visiteur anonyme vers la connexion en retenant la destination', async () => {
    mockApi({ 'GET /me': jsonResponse({ type: 't', title: 'Non authentifié', status: 401, detail: '', trace_id: 'x' }, 401) });
    expect(await run('/appel')).toEqual({ name: 'login', query: { redirect: '/appel' } });
  });

  it('refuse une page dont la permission manque (un parent ne voit pas la caisse)', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } }, [tile({ roles: [{ code: 'parent', label: 'Parent' }] })], 'parent');
    expect(await run('/facturation')).toEqual({ name: 'forbidden' });
    expect(await run('/portail/famille')).toBe(true);
  });

  it('autorise la caisse au caissier et le portail famille ne lui est pas ouvert', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'caissier' } }, [tile({ roles: [{ code: 'caissier', label: 'Caissier' }] })], 'caissier');
    expect(await run('/facturation')).toBe(true);
    expect(await run('/portail/famille')).toEqual({ name: 'forbidden' });
  });

  it('impose la double authentification avant tout accès', async () => {
    signIn({ ...teacherMe, two_factor: { enabled: true, required: true, verified: false } });
    expect(await run('/')).toEqual({ name: 'twofactor' });
    signIn({ ...teacherMe, two_factor: { enabled: false, required: true, verified: false } });
    expect(await run('/')).toEqual({ name: 'twofactor-setup' });
  });
});

describe('écran Accueil « à traiter »', () => {
  const tiles = [
    { kind: 'group', id: 'group', name: 'Tout le groupe', roles: [{ code: 'direction_groupe', label: 'Direction de groupe' }] },
    tile({ id: 'E1', name: 'Collège Les Palmiers', roles: [{ code: 'directeur', label: "Directeur d'établissement" }, { code: 'enseignant', label: 'Enseignant' }] }),
    tile({ id: 'E2', name: 'IPES', type: 'higher', accent: '#00AAFF', roles: [{ code: 'enseignant', label: 'Enseignant' }] }),
  ];

  it('salue par le prénom, liste les tuiles et les tâches à traiter', async () => {
    signIn(teacherMe, tiles as never);
    mockApi({
      'GET /me/todo': { data: [{ kind: 'Approbation', title: 'Annulation de paiement R-IPES-26-04821', due_at: '2026-10-10T12:00:00+01:00', link: '/app/facturation' }] },
    });
    const { wrapper } = await mountView(HomeView);
    expect(wrapper.get('h1').text()).toBe('Bonjour Joseph.');
    expect(wrapper.text()).toContain('Vue consolidée');
    expect(wrapper.text()).toContain('Collège Les Palmiers');
    expect(wrapper.text()).toContain('Supérieur');
    expect(wrapper.text()).toContain('Annulation de paiement R-IPES-26-04821');
    expect(wrapper.findAll('article').length).toBe(3);
  });

  it('dit quand rien n\'attend la personne', async () => {
    signIn(teacherMe, tiles as never);
    mockApi({ 'GET /me/todo': { data: [] } });
    const { wrapper } = await mountView(HomeView);
    expect(wrapper.text()).toContain("Rien ne vous attend pour l'instant.");
  });

  it('change de contexte sans reconnexion puis ouvre l\'application du rôle', async () => {
    signIn(teacherMe, tiles as never);
    const calls = mockApi({
      'GET /me/todo': { data: [] },
      'POST /me/context': (c) => ({ data: { context: tiles.find((t) => t.id === c.body?.establishment_id), role: 'enseignant' } }),
    });
    const { wrapper, router } = await mountView(HomeView);
    await wrapper.get('button[aria-label="Ouvrir IPES"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/me/context')?.body).toEqual({ establishment_id: 'E2' });
    expect(useContextStore().activeId).toBe('E2');
    expect(useContextStore().accent).toBe('#00AAFF');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('attendance'), { timeout: 8000 });
  });
});

describe('écran Appel mobile', () => {
  const today = todayIso();
  const roster = ['P1', 'P2', 'P3'];
  const api = (): ReturnType<typeof mockApi> =>
    mockApi({
      'GET /timetable': { data: { view: { type: 'teacher', id: 'P-TEACH' }, week_start: today, week_end: today, days: [{ date: today, weekday: 1, entries: [{ slot_id: 'SLOT1', structure_ids: ['ST1'], subject_label: 'Mathématiques', teacher_person_id: 'P-TEACH', original_teacher_person_id: null, room_id: 'R1', start_time: '08:00:00', end_time: '09:55:00', status: 'planned', change_id: null }] }] } },
      'GET /structures': { data: [{ id: 'ST1', name: '4e B' }], meta: { next_cursor: null } },
      'GET /enrollments': { data: roster.map((p) => ({ id: `EN-${p}`, person_id: p, establishment_id: 'E1', structure_id: 'ST1', status: 'active' })), meta: { next_cursor: null } },
      'GET /people': { data: roster.map((p, i) => ({ id: p, first_name: ['Yann', 'Nadia', 'Paul'][i], last_name: ['Foka', 'Bello', 'Chia'][i] })), meta: { next_cursor: null } },
      'POST /attendance-sessions/sync': (c) => ({ data: (c.body?.sessions as Array<{ client_uuid: string }>).map((s) => ({ client_uuid: s.client_uuid, status: 'synced' })) }),
    });

  it("suit le parcours de la maquette : liste, P/A/R, compteurs, « Valider l'appel (n/N) »", async () => {
    signIn();
    const calls = api();
    const { wrapper } = await mountView(AttendanceView);
    expect(wrapper.text()).toContain('Mathématiques');
    expect(wrapper.text()).toContain('4e B');
    expect(wrapper.get('[data-test="pending-counter"]').text()).toBe("0 appel en attente d'envoi");

    await wrapper.get('section ul button').trigger('click');
    await flushPromises();
    expect(wrapper.get('#call-title').text()).toBe('Appel · 4e B');
    expect(wrapper.get('header').text()).toContain('08:00 – 09:55');
    expect(wrapper.get('header').text()).toContain('Mathématiques · 3 élèves');
    const validate = wrapper.findAll('button').find((b) => b.text().startsWith("Valider l'appel"));
    expect(validate?.text()).toBe("Valider l'appel (0/3)");
    expect(validate?.attributes('disabled')).toBeDefined();

    // noms triés : BELLO, CHIA, FOKA
    const rows = wrapper.findAll('ul.roster li');
    expect(rows.map((r) => r.find('.name').text())).toEqual(['BELLO Nadia', 'CHIA Paul', 'FOKA Yann']);
    await rows[0]?.findAll('button.pick')[1]?.trigger('click'); // BELLO absente
    await rows[1]?.findAll('button.pick')[2]?.trigger('click'); // CHIA en retard
    expect(wrapper.text()).toContain("Heure d'arrivée");
    expect(wrapper.get('.totals').text()).toBe('0 présent1 absent1 retard');
    expect(wrapper.findAll('button').find((b) => b.text().startsWith("Valider"))?.text()).toBe("Valider l'appel (2/3)");

    await rows[2]?.findAll('button.pick')[0]?.trigger('click'); // FOKA présent
    const finalButton = wrapper.findAll('button').find((b) => b.text().startsWith('Valider'));
    expect(finalButton?.text()).toBe("Valider l'appel (3/3)");
    expect(finalButton?.attributes('disabled')).toBeUndefined();
    await finalButton?.trigger('click');
    await flushPromises();

    const sync = calls.find((c) => c.path === '/attendance-sessions/sync');
    expect(sync).toBeTruthy();
    expect(sync?.headers['X-Establishment-Id']).toBe('E1');
    const sent = (sync?.body?.sessions as Array<Record<string, unknown>>)[0] as { client_uuid: string; slot_id: string; date: string; validate: boolean; records: Array<{ person_id: string; status: string; arrived_at?: string; client_uuid: string }> };
    expect(sent).toMatchObject({ slot_id: 'SLOT1', date: today, validate: true });
    expect(sent.client_uuid).toMatch(/^[0-9A-Z]{26}$/);
    expect(sent.records).toHaveLength(3);
    expect(sent.records.find((r) => r.person_id === 'P2')?.status).toBe('A');
    expect(sent.records.find((r) => r.person_id === 'P3')).toMatchObject({ status: 'R' });
    expect(sent.records.find((r) => r.person_id === 'P3')?.arrived_at).toMatch(/^\d\d:\d\d$/);
    expect(wrapper.text()).toContain("L'appel est enregistré et envoyé");
    expect(await listQueue()).toHaveLength(0);
  });

  it("hors ligne : l'appel reste sur l'appareil, le compteur monte, et part au retour du réseau", async () => {
    signIn();
    api();
    const { wrapper } = await mountView(AttendanceView);
    await wrapper.get('section ul button').trigger('click');
    await flushPromises();
    // le réseau tombe après le chargement de l'effectif
    const offline = useOfflineStore();
    offline.online = false;
    await wrapper.findAll('button').find((b) => b.text() === 'Tous présents')?.trigger('click');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text().startsWith('Valider'))?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Il sera synchronisé au retour du réseau');
    expect(await listQueue('attendance')).toHaveLength(1);
    expect(offline.pendingAttendance).toBe(1);
    expect(wrapper.get('[data-test="pending-counter"]').text()).toBe("1 appel en attente d'envoi");

    // retour du réseau
    offline.online = true;
    const calls = mockApi({
      'POST /attendance-sessions/sync': (c) => ({ data: (c.body?.sessions as Array<{ client_uuid: string }>).map((s) => ({ client_uuid: s.client_uuid, status: 'synced' })) }),
    });
    await offline.sync();
    expect(calls).toHaveLength(1);
    expect(offline.pendingAttendance).toBe(0);
  });

  it('reprend le brouillon après rechargement (les appuis sont déjà sur l\'appareil)', async () => {
    signIn();
    api();
    let ctx = await mountView(AttendanceView);
    await ctx.wrapper.get('section ul button').trigger('click');
    await flushPromises();
    await ctx.wrapper.findAll('button').find((b) => b.text() === 'Tous présents')?.trigger('click');
    await flushPromises();
    ctx.wrapper.unmount();
    ctx = await mountView(AttendanceView);
    await ctx.wrapper.get('section ul button').trigger('click');
    await flushPromises();
    expect(ctx.wrapper.get('.totals').text()).toBe('3 présents0 absent0 retard');
  });
});

describe('écran Portail famille', () => {
  const portal = {
    guarantor_id: 'G1',
    family_balance_xaf: 35000,
    next_due: { available: false, reason: "Aucun contrat n'expose les échéances." },
    children: [
      {
        person: { id: 'C1', name: 'Yann Foka' },
        access: 'full',
        enrollments: [{ id: 'EN1', establishment_id: 'E1', establishment_name: 'Collège Les Palmiers', structure_id: 'ST1' }],
        balance_xaf: 35000,
        next_due: { available: false, reason: 'x' },
        attendance: { available: true, sessions: 120, absences: 4, unjustified_absences: 2, lates: 1 },
        results: { blocked: false, latest: { id: 'R1', establishment_id: 'E1', period_id: 'T1', period_label: 'Trimestre 1', average: 14.5, rank: 7, class_size: 42, decision: null }, items: [] },
        documents: { blocked: false, items: [{ id: 'D1', number: 'CLP-26-B-004812', type: 'bulletin', verification_token: 'tok' }] },
        life_book: { available: false, reason: 'Aucun contrat n\'expose le cahier de vie.' },
      },
      {
        person: { id: 'C2', name: 'Nadia Foka' },
        access: 'restricted',
        restricted_reason: "Cet apprenant majeur n'a pas autorisé l'accès de ses parents (consentement parent_access).",
        enrollments: [{ id: 'EN2', establishment_id: 'E2', establishment_name: 'IPES', structure_id: 'ST9' }],
        balance_xaf: 0,
        next_due: { available: false, reason: 'x' },
      },
    ],
  };
  const parentMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } };
  const parentTile = tile({ roles: [{ code: 'parent', label: 'Parent' }] });

  it('affiche le solde famille, l\'échéance indisponible, une carte par enfant et l\'accès restreint', async () => {
    signIn(parentMe, [parentTile], 'parent');
    mockApi({ 'GET /portal/family': { data: portal } });
    const { wrapper } = await mountView(FamilyPortalView);
    expect(wrapper.text()).toContain('35 000 FCFA');
    expect(wrapper.text()).toContain('Échéance : indisponible pour le moment');
    const cards = wrapper.findAll('article.child');
    expect(cards).toHaveLength(2);
    expect(cards[0]?.text()).toContain('Yann Foka');
    expect(cards[0]?.text()).toContain('14,50');
    expect(cards[0]?.text()).toContain('7');
    expect(cards[0]?.text()).toContain('2 non justifiées');
    expect(cards[0]?.text()).toContain('Cahier de vie : Indisponible');
    expect(cards[1]?.text()).toContain("n'a pas autorisé l'accès de ses parents");
    expect(cards[1]?.text()).not.toContain('Moyenne');
    expect(wrapper.find('[role="status"].notice').exists()).toBe(false);
  });

  it('montre le dernier état connu, signalé, quand le réseau manque', async () => {
    signIn(parentMe, [parentTile], 'parent');
    mockApi({ 'GET /portal/family': { data: portal } });
    await mountView(FamilyPortalView);
    mockApi({ 'GET /portal/family': () => {
      throw new TypeError('Failed to fetch');
    } });
    const { wrapper } = await mountView(FamilyPortalView);
    expect(wrapper.text()).toContain('Hors ligne : ces informations datent de votre dernière consultation.');
    expect(wrapper.text()).toContain('Yann Foka');
  });

  it('affiche le message de l\'API et la trace quand le compte n\'est rattaché à aucune personne (403)', async () => {
    signIn(parentMe, [parentTile], 'parent');
    mockApi({ 'GET /portal/family': jsonResponse({ type: 't', title: 'Accès refusé', status: 403, detail: "Votre compte n'est rattaché à aucune personne : contactez l'établissement.", trace_id: 'T-403' }, 403) });
    const { wrapper } = await mountView(FamilyPortalView);
    expect(wrapper.get('[role="alert"]').text()).toContain("rattaché à aucune personne");
    expect(wrapper.get('[role="alert"]').text()).toContain('T-403');
  });

  it('« Tout payer » : une demande mobile money par établissement, clé d\'idempotence, attente puis confirmation', async () => {
    signIn(parentMe, [parentTile], 'parent');
    let polls = 0;
    const calls = mockApi({
      'GET /payment-references': { data: [{ id: 'PR1', guarantor_id: 'G1', reference: 'FAM-0412' }] },
      'POST /payments': jsonResponse({ data: { id: 'PAY1', channel: 'orange_money', amount_xaf: 35000, status: 'pending', receipt_number: null } }, 202),
      'GET /payments/PAY1': () => ({ data: { id: 'PAY1', channel: 'orange_money', amount_xaf: 35000, status: ++polls >= 2 ? 'confirmed' : 'pending', receipt_number: polls >= 2 ? 'R-CLP-26-00012' : null } }),
    });
    const { wrapper } = await mountView(MobilePayPanel, {
      dues: [{ establishment_id: 'E1', establishment_name: 'Collège Les Palmiers', amount_xaf: 35000 }],
      guarantorId: 'G1',
      pollMs: 1,
    });
    await wrapper.get('#pay-phone').setValue('abc');
    await wrapper.get('button.btn--primary').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Saisissez un numéro valide');
    expect(calls.find((c) => c.path === '/payments')).toBeUndefined();

    await wrapper.get('#pay-phone').setValue('+237 6 99 00 11 22');
    await wrapper.findAll('input[type="radio"]')[1]?.setValue();
    await wrapper.get('button.btn--primary').trigger('click');
    await new Promise((r) => setTimeout(r, 60));
    await flushPromises();
    const pay = calls.find((c) => c.path === '/payments' && c.method === 'POST');
    expect(pay?.body).toEqual({ channel: 'mtn_momo', amount_xaf: 35000, family_reference: 'FAM-0412', payer_phone: '+237 6 99 00 11 22' });
    expect(pay?.headers['X-Establishment-Id']).toBe('E1');
    expect(pay?.headers['Idempotency-Key']).toMatch(/^[0-9A-Z]{26}$/);
    expect(wrapper.text()).toContain('Payé');
    expect(wrapper.text()).toContain('R-CLP-26-00012');
    expect(wrapper.emitted('paid')).toBeTruthy();
  });
});
