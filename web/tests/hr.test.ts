import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import { routes } from '@/router/routes';
import { guard } from '@/router';
import ExportButton from '@/components/ExportButton.vue';
import TeacherPortalView from '@/views/teacher/TeacherPortalView.vue';
import StaffListView from '@/views/staff/StaffListView.vue';
import StaffView from '@/views/staff/StaffView.vue';
import VacationsView from '@/views/staff/VacationsView.vue';
import LeaveView from '@/views/staff/LeaveView.vue';
import ReportsView from '@/views/reports/ReportsView.vue';
import RegulatoryView from '@/views/reports/RegulatoryView.vue';
import PeopleView from '@/views/PeopleView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(() => {
  freshEnvironment();
  URL.createObjectURL = vi.fn(() => 'blob:test');
  URL.revokeObjectURL = vi.fn();
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
});

const page = (items: unknown[]) => ({ data: items, links: { next: null }, meta: { next_cursor: null } });
const problem = (status: number, detail: string) => jsonResponse({ type: 'https://api.scholaris.cm/errors/x', title: 'Erreur', status, detail, trace_id: 'TR-HR' }, status);
const csv = () => new Response('a;b\n1;2', { status: 200, headers: { 'Content-Type': 'text/csv' } });

const directorMe = { ...teacherMe, id: 'U-DIR', person_id: 'P-DIR', context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ roles: [{ code: 'directeur', label: 'Directeur' }] });
const asDirector = () => signIn(directorMe, [directorTile], 'directeur');
const asTeacher = () => signIn(teacherMe);

const staffTeacher = { id: 'S1', person_id: 'P-TEACH', full_name: 'Joseph Ateba', kind: 'vacataire', status: 'active', grade: 'Chargé de cours', category: 'B', employee_number: null, department_id: null, hired_on: '2025-09-01', left_on: null };
const staffOther = { id: 'S2', person_id: 'P-2', full_name: 'Marie Eyenga', kind: 'permanent', status: 'active', grade: null, category: null, employee_number: 'M-12', department_id: null, hired_on: null, left_on: null };

describe('ExportButton', () => {
  it('télécharge le CSV avec le format et les filtres courants', async () => {
    asDirector();
    const calls = mockApi({ 'GET /people/export': csv });
    const wrapper = mount(ExportButton, { props: { path: '/people/export', query: { 'filter[q]': 'ate' }, name: 'personnes' } });
    await wrapper.get('[data-export="csv"]').trigger('click');
    await flushPromises();
    const call = calls.find((c) => c.path === '/people/export');
    expect(call?.query).toMatchObject({ format: 'csv', 'filter[q]': 'ate' });
    expect(call?.headers['X-Establishment-Id']).toBe('E1');
    expect(URL.createObjectURL).toHaveBeenCalled();
  });

  it('dit clairement que l\'Excel est indisponible (422) et ne propose plus que le CSV', async () => {
    asDirector();
    mockApi({ 'GET /people/export': (c) => (c.query.format === 'xlsx' ? problem(422, 'Paramètre inconnu : format.') : csv()) });
    const wrapper = mount(ExportButton, { props: { path: '/people/export' } });
    await wrapper.get('[data-export="xlsx"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-export="xlsx"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Excel n’est pas disponible');
    expect(wrapper.find('[data-export="csv"]').exists()).toBe(true);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('affiche les autres erreurs (403) sans masquer le bouton Excel', async () => {
    asDirector();
    mockApi({ 'GET /people/export': problem(403, 'Droit manquant.') });
    const wrapper = mount(ExportButton, { props: { path: '/people/export' } });
    await wrapper.get('[data-export="csv"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').text()).toContain('Droit manquant.');
    expect(wrapper.find('[data-export="xlsx"]').exists()).toBe(true);
  });

  it('est présent sur la liste des personnes avec le filtre de recherche', async () => {
    asDirector();
    mockApi({ 'GET /people': page([]), 'GET /enrollments': page([]), 'GET /people/export': csv });
    const { wrapper } = await mountView(PeopleView);
    expect(wrapper.find('[data-export="csv"]').exists()).toBe(true);
  });
});

describe('portail enseignant', () => {
  const portal = {
    person_id: 'P-TEACH', kind: 'vacataire', today: '2026-10-09',
    classes: [{ establishment_id: 'E1', establishment_name: 'Collège Les Palmiers', structure_id: '3eA', subject_label: 'Mathématiques', session_kind: 'td' }],
    timetable_today: [{ subject: 'Mathématiques', start: '08:00:00', end: '09:30:00', establishment_id: 'E1', structure_id: '3eA', room_id: 'S12' }],
    grading_todo: { available: false, reason: 'Aucune source ne fournit les saisies de notes à faire.', items: [] },
    hours_month: { month: '2026-10', declared_minutes: 150, validated_minutes: 60, rejected_minutes: 0 },
  };

  it('affiche classes, cours du jour, heures et « Indisponible » avec la raison pour les notes à saisir', async () => {
    asTeacher();
    const calls = mockApi({ 'GET /portal/teacher': { data: portal } });
    const { wrapper } = await mountView(TeacherPortalView);
    expect(calls.find((c) => c.path === '/portal/teacher')?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.get('[data-test="classes"]').text()).toContain('Mathématiques');
    expect(wrapper.get('[data-test="timetable"]').text()).toContain('08:00 – 09:30');
    expect(wrapper.get('[data-test="hours"]').text()).toContain('2 h 30');
    expect(wrapper.get('[data-test="grading"]').text()).toContain('Indisponible');
    expect(wrapper.get('[data-test="grading"]').text()).toContain('Aucune source ne fournit');
    const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));
    expect(hrefs).toContain('/appel');
    expect(hrefs).toContain('/notes');
  });

  it('liste les saisies à faire quand une source existe et explique un refus (403)', async () => {
    asTeacher();
    mockApi({ 'GET /portal/teacher': { data: { ...portal, grading_todo: { available: true, reason: null, items: [{ id: 'A1', label: 'Devoir 2 — 3eA' }] } } } });
    const { wrapper } = await mountView(TeacherPortalView);
    expect(wrapper.get('[data-test="grading"]').text()).toContain('Devoir 2 — 3eA');

    mockApi({ 'GET /portal/teacher': problem(403, 'Aucune fiche d\'enseignant n\'est rattachée à votre compte.') });
    const again = await mountView(TeacherPortalView);
    expect(again.wrapper.find('[role="alert"]').text()).toContain('Aucune fiche');
  });
});

describe('accès aux écrans personnel et rapports', () => {
  async function run(path: string): Promise<unknown> {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path).catch(() => undefined);
    return guard(router.resolve(path) as never);
  }
  it('ouvre le personnel et les rapports au directeur, les congés et les vacations à l\'enseignant, mais pas la liste du personnel', async () => {
    asDirector();
    for (const p of ['/personnel', '/personnel/S1', '/personnel/vacations', '/personnel/conges', '/rapports', '/rapports/statistiques']) expect(await run(p)).toBe(true);
    asTeacher();
    expect(await run('/portail/enseignant')).toBe(true);
    expect(await run('/personnel/conges')).toBe(true);
    expect(await run('/personnel/vacations')).toBe(true);
    expect(await run('/personnel')).toEqual({ name: 'forbidden' });
    expect(await run('/rapports')).toEqual({ name: 'forbidden' });
  });
});

describe('personnel', () => {
  it('liste les fiches avec filtres et export, et crée une fiche après recherche de la personne', async () => {
    asDirector();
    const calls = mockApi({
      'GET /staff': page([staffTeacher, staffOther]),
      'GET /people': page([{ id: 'P-9', first_name: 'Paul', last_name: 'Nana' }]),
      'POST /staff': { data: { ...staffOther, id: 'S9', person_id: 'P-9' } },
    });
    const { wrapper } = await mountView(StaffListView);
    expect(wrapper.text()).toContain('Joseph Ateba');
    expect(wrapper.text()).toContain('Vacataire');
    expect(wrapper.find('[data-export="csv"]').exists()).toBe(true);

    await wrapper.get('form[data-test="create-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Recherchez et choisissez la personne');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await wrapper.get('input[type="search"]').setValue('Nana');
    await wrapper.findAll('button').find((b) => b.text() === 'Rechercher')?.trigger('click');
    await flushPromises();
    await wrapper.get('form[data-test="create-form"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/staff')?.body).toMatchObject({ person_id: 'P-9', kind: 'permanent' });
  });

  it('affiche la fiche, ajoute une qualification et fixe un droit de congé', async () => {
    asDirector();
    const calls = mockApi({
      'GET /staff/S1': { data: staffTeacher },
      'GET /staff/S1/qualifications': { data: [] },
      'GET /staff/S1/assignments': { data: [{ id: 'A1', staff_id: 'S1', structure_id: 'st1', subject_label: 'Maths', session_kind: 'td', planned_minutes: 600, validated_minutes: 120, status: 'active' }] },
      'GET /leave-balances': { data: [] },
      'GET /structures': page([{ id: 'st1', name: '3e A' }]),
      'POST /staff/S1/qualifications': { data: { id: 'Q1', staff_id: 'S1', title: 'Master de mathématiques', institution: null, obtained_on: null } },
      'PUT /leave-balances': { data: [{ staff_id: 'S1', leave_type: 'annual', leave_year: 2026, entitled_days: 20, used_days: 0, pending_days: 0, remaining_days: 20 }] },
    });
    const { wrapper } = await mountView(StaffView, { id: 'S1' });
    expect(wrapper.text()).toContain('Joseph Ateba');
    expect(wrapper.get('[data-test="assignments"]').text()).toContain('3e A');
    expect(wrapper.get('[data-test="assignments"]').text()).toContain('2 h');

    await wrapper.get('[data-test="qualifications"] form').trigger('submit');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await wrapper.get('[data-test="qualifications"] input[type="text"]').setValue('Master de mathématiques');
    await wrapper.get('[data-test="qualifications"] form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-test="qualifications"]').text()).toContain('Master de mathématiques');

    await wrapper.get('[data-test="balances"] input[type="number"]').setValue(20);
    await wrapper.get('[data-test="balances"] form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PUT')?.body).toMatchObject({ staff_id: 'S1', leave_type: 'annual', entitled_days: 20 });
    expect(wrapper.get('[data-test="balances"]').text()).toContain('20');
  });

  it('masque les actions de gestion à une personne sans staff.manage', async () => {
    asTeacher();
    mockApi({
      'GET /staff/S1': { data: staffTeacher }, 'GET /staff/S1/qualifications': { data: [] }, 'GET /staff/S1/assignments': { data: [] }, 'GET /leave-balances': { data: [] },
    });
    const { wrapper } = await mountView(StaffView, { id: 'S1' });
    expect(wrapper.find('[data-action="suspend"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="qualifications"] form').exists()).toBe(false);
  });
});

describe('vacations', () => {
  const hour = (over: Record<string, unknown> = {}) => ({ id: 'H1', staff_id: 'S1', held_on: '2026-10-05', minutes: 90, session_kind: 'td', content: 'Fractions', status: 'declared', source: 'manual', declared_by: 'U-OTHER', teacher_signed_at: '2026-10-05T10:00:00+01:00', rejection_reason: null, statement_id: null, ...over });

  it('valide une heure, rejette une autre avec motif obligatoire et exporte avec les filtres', async () => {
    asDirector();
    const calls = mockApi({
      'GET /staff': page([staffTeacher]),
      'GET /vacation-hours': page([hour(), hour({ id: 'H2' })]),
      'POST /vacation-hours/H1/validate': { data: hour({ status: 'validated' }) },
      'POST /vacation-hours/H2/reject': { data: hour({ id: 'H2', status: 'rejected', rejection_reason: 'Séance non tenue' }) },
    });
    const { wrapper } = await mountView(VacationsView);
    const rows = () => wrapper.findAll('[data-test="hours"] tbody tr');
    expect(rows()).toHaveLength(2);
    expect(calls.find((c) => c.path === '/vacation-hours')?.query['filter[period]']).toMatch(/^\d{4}-\d{2}$/);
    expect(wrapper.find('[data-export="csv"]').exists()).toBe(true);

    await rows()[0]?.get('[data-action="validate"]').trigger('click');
    await flushPromises();
    expect(rows()[0]?.text()).toContain('Validée');

    await rows()[1]?.get('[data-action="reject"]').trigger('click');
    await wrapper.get('[data-test="hours"] tbody form').trigger('submit');
    expect(wrapper.text()).toContain('Le motif est obligatoire.');
    expect(calls.some((c) => c.path === '/vacation-hours/H2/reject')).toBe(false);
    await wrapper.get('[data-test="hours"] tbody form input').setValue('Séance non tenue');
    await wrapper.get('[data-test="hours"] tbody form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/vacation-hours/H2/reject')?.body).toEqual({ reason: 'Séance non tenue' });
    expect(wrapper.text()).toContain('Séance non tenue');
  });

  it('ne propose pas à un directeur de valider sa propre déclaration', async () => {
    asDirector();
    mockApi({ 'GET /staff': page([staffTeacher]), 'GET /vacation-hours': page([hour({ declared_by: 'U-DIR' })]) });
    const { wrapper } = await mountView(VacationsView);
    expect(wrapper.find('[data-action="validate"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="reject"]').exists()).toBe(true);
  });

  it('laisse l\'enseignant déclarer et émarger ses heures, sans validation ni onglet des taux', async () => {
    asTeacher();
    const calls = mockApi({
      'GET /staff': page([staffTeacher]),
      'GET /vacation-hours': page([hour({ teacher_signed_at: null, declared_by: 'U1' })]),
      'POST /vacation-hours': { data: hour({ id: 'H9' }) },
      'POST /vacation-hours/H1/sign': { data: hour({ declared_by: 'U1' }) },
    });
    const { wrapper } = await mountView(VacationsView);
    expect(wrapper.find('[data-tab="rates"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="validate"]').exists()).toBe(false);

    await wrapper.get('[data-action="sign"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/vacation-hours/H1/sign')).toBe(true);

    await wrapper.get('[data-test="declare-form"] input[type="number"]').setValue(0);
    await wrapper.get('[data-test="declare-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Entre 1 et 720 minutes.');
    await wrapper.get('[data-test="declare-form"] input[type="number"]').setValue(45);
    await wrapper.get('[data-test="declare-form"] input[type="checkbox"]').setValue(true);
    await wrapper.get('[data-test="declare-form"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/vacation-hours')?.body).toMatchObject({ staff_id: 'S1', minutes: 45, signed: true });
  });

  it('gère les contrats : création validée côté client, soumission, approbation', async () => {
    asDirector();
    const contract = { id: 'C1', staff_id: 'S1', reference: 'VAC-2026-00001', starts_on: '2026-09-01', ends_on: '2027-06-30', hourly_rate: 5000, max_minutes: 6000, status: 'draft' };
    const calls = mockApi({
      'GET /staff': page([staffTeacher]), 'GET /vacation-hours': page([]),
      'GET /vacation-contracts': page([contract]),
      'POST /vacation-contracts/C1/submit': { data: { ...contract, status: 'pending_approval' } },
      'POST /vacation-contracts/C1/approve': { data: { ...contract, status: 'active' } },
      'POST /vacation-contracts': { data: { ...contract, id: 'C2' } },
    });
    const { wrapper } = await mountView(VacationsView);
    await wrapper.get('[data-tab="contracts"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="contracts"]').text()).toContain('VAC-2026-00001');

    await wrapper.get('[data-test="contract-form"]').trigger('submit');
    expect(wrapper.get('[data-test="contract-form"]').text()).toContain('Choisissez un membre');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await wrapper.get('[data-action="submit"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-action="approve"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="contracts"] tbody').text()).toContain('Actif');
    expect(wrapper.find('[data-action="terminate"]').exists()).toBe(true);
  });

  it('génère, soumet, approuve puis paie un relevé', async () => {
    asDirector();
    const st = { id: 'R1', staff_id: 'S1', period: '2026-10', minutes_total: 150, hetd_milli: 2500, hourly_rate: 5000, gross_amount: 12500, status: 'draft', paid_on: null, payment_reference: null };
    const calls = mockApi({
      'GET /staff': page([staffTeacher]), 'GET /vacation-hours': page([]),
      'GET /vacation-statements': page([st]),
      'POST /vacation-statements/R1/submit': { data: { ...st, status: 'pending_approval' } },
      'POST /vacation-statements/R1/approve': { data: { ...st, status: 'validated' } },
      'POST /vacation-statements/R1/pay': (c: { body?: Record<string, unknown> }) => ({ data: { ...st, status: 'paid', paid_on: c.body?.paid_on, payment_reference: c.body?.payment_reference } }),
      'GET /vacation-statements/R1': { data: { ...st, lines: [{ id: 'H1', staff_id: 'S1', held_on: '2026-10-05', minutes: 90, session_kind: 'td', content: 'Fractions', status: 'validated' }] } },
    });
    const { wrapper } = await mountView(VacationsView);
    await wrapper.get('[data-tab="statements"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="statements"]').text()).toContain('2,5');
    expect(wrapper.get('[data-test="statements"]').text()).toContain('12 500');

    await wrapper.get('[data-action="detail"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="statements"]').text()).toContain('Fractions');

    await wrapper.get('[data-action="submit"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-action="approve"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-action="pay"]').trigger('click');
    await wrapper.get('[data-test="statements"] tbody form').trigger('submit');
    expect(wrapper.text()).toContain('La référence du paiement est obligatoire.');
    await wrapper.get('[data-test="statements"] tbody form input[type="text"]').setValue('VIR-001');
    await wrapper.get('[data-test="statements"] tbody form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/vacation-statements/R1/pay')?.body).toMatchObject({ payment_reference: 'VIR-001' });
    expect(wrapper.get('[data-test="statements"] tbody').text()).toContain('Payé');
  });

  it('montre l\'erreur du serveur quand l\'approbation est refusée (403, demandeur)', async () => {
    asDirector();
    const contract = { id: 'C1', staff_id: 'S1', reference: 'VAC-1', starts_on: '2026-09-01', ends_on: '2027-06-30', hourly_rate: 5000, max_minutes: 6000, status: 'pending_approval' };
    mockApi({ 'GET /staff': page([staffTeacher]), 'GET /vacation-hours': page([]), 'GET /vacation-contracts': page([contract]), 'POST /vacation-contracts/C1/approve': problem(403, 'Le demandeur ne peut pas approuver.') });
    const { wrapper } = await mountView(VacationsView);
    await wrapper.get('[data-tab="contracts"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-action="approve"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Le demandeur ne peut pas approuver.');
  });
});

describe('congés', () => {
  const leave = (over: Record<string, unknown> = {}) => ({ id: 'L1', staff_id: 'S2', leave_type: 'annual', starts_on: '2030-11-02', ends_on: '2030-11-04', days: 3, reason: null, status: 'pending', requested_by: 'U-2', decided_by: null, ...over });

  it('approuve la demande d\'un autre mais pas la sienne', async () => {
    asDirector();
    const calls = mockApi({
      'GET /staff': page([{ ...staffOther, person_id: 'P-DIR', id: 'S0', full_name: 'Directeur' }, staffOther]),
      'GET /leave-requests': page([leave(), leave({ id: 'L2', staff_id: 'S0' })]),
      'GET /leave-calendar': { data: [leave({ status: 'approved' })] },
      'GET /leave-balances': { data: [{ staff_id: 'S0', leave_type: 'annual', leave_year: 2026, entitled_days: 20, used_days: 3, pending_days: 0, remaining_days: 17 }] },
      'POST /leave-requests/L1/approve': { data: leave({ status: 'approved' }) },
    });
    const { wrapper } = await mountView(LeaveView);
    const rows = wrapper.findAll('[data-test="requests"] tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.find('[data-action="approve"]').exists()).toBe(true);
    expect(rows[1]?.find('[data-action="approve"]').exists()).toBe(false);
    expect(rows[1]?.find('[data-action="cancel"]').exists()).toBe(true);
    await rows[0]?.get('[data-action="approve"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/leave-requests/L1/approve')).toBe(true);
    expect(wrapper.get('[data-test="balances"]').text()).toContain('17');
    expect(wrapper.get('[data-test="calendar"]').text()).toContain('Marie Eyenga');
  });

  it('valide les dates puis envoie la demande de l\'enseignant ; il peut annuler la sienne', async () => {
    asTeacher();
    const calls = mockApi({
      'GET /staff': page([staffTeacher]),
      'GET /leave-requests': page([leave({ staff_id: 'S1' })]),
      'GET /leave-calendar': { data: [] },
      'GET /leave-balances': { data: [] },
      'POST /leave-requests': { data: leave({ id: 'L3', staff_id: 'S1' }) },
      'POST /leave-requests/L1/cancel': { data: leave({ staff_id: 'S1', status: 'cancelled' }) },
    });
    const { wrapper } = await mountView(LeaveView);
    expect(wrapper.find('[data-action="approve"]').exists()).toBe(false);
    await wrapper.get('[data-test="leave-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Renseignez les deux dates');
    const dates = wrapper.findAll('[data-test="leave-form"] input[type="date"]');
    await dates[0]?.setValue('2030-12-02');
    await dates[1]?.setValue('2030-12-01');
    await wrapper.get('[data-test="leave-form"]').trigger('submit');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await dates[1]?.setValue('2030-12-05');
    await wrapper.get('[data-test="leave-form"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/leave-requests')?.body).toMatchObject({ leave_type: 'annual', starts_on: '2030-12-02', ends_on: '2030-12-05' });

    await wrapper.get('[data-action="cancel"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="requests"] tbody').text()).toContain('Annulé');
  });

  it('affiche le conflit (409) renvoyé par le serveur', async () => {
    asTeacher();
    mockApi({
      'GET /staff': page([staffTeacher]), 'GET /leave-requests': page([]), 'GET /leave-calendar': { data: [] }, 'GET /leave-balances': { data: [] },
      'POST /leave-requests': problem(409, 'Solde de congé insuffisant.'),
    });
    const { wrapper } = await mountView(LeaveView);
    const dates = wrapper.findAll('[data-test="leave-form"] input[type="date"]');
    await dates[0]?.setValue('2030-12-02');
    await dates[1]?.setValue('2030-12-05');
    await wrapper.get('[data-test="leave-form"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Solde de congé insuffisant.');
  });
});

describe('rapports', () => {
  const schedule = (over: Record<string, unknown> = {}) => ({ id: 'RS1', name: 'Direction hebdomadaire', report_type: 'direction', frequency: 'weekly', run_day: 1, recipient_ids: ['P-DIR'], status: 'active', next_run_on: '2030-10-14', last_run_at: null, last_run_status: null, ...over });

  it('crée un rapport planifié avec validation, l\'exécute et affiche l\'historique', async () => {
    asDirector();
    const calls = mockApi({
      'GET /report-schedules': page([schedule()]),
      'POST /report-schedules': { data: schedule({ id: 'RS2', name: 'Mensuel' }) },
      'POST /report-schedules/RS1/run': { data: { status: 'done', sent_count: 1, failed_count: 0 } },
      'GET /report-schedules/RS1/runs': { data: [{ id: 'RN1', run_key: '2030-10-09', trigger_kind: 'manual', status: 'done', sent_count: 1, failed_count: 0, summary: 'Étudiants inscrits : 12', created_at: '2030-10-09T10:00:00+01:00' }] },
    });
    const { wrapper } = await mountView(ReportsView);
    expect(wrapper.text()).toContain('Direction hebdomadaire');

    await wrapper.get('form[data-test="schedule-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Un nom de 1 à 120 caractères');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await wrapper.get('form[data-test="schedule-form"] input[type="text"]').setValue('Mensuel');
    const selects = wrapper.findAll('form[data-test="schedule-form"] select');
    await selects[1]?.setValue('monthly');
    await wrapper.get('form[data-test="schedule-form"] input[type="number"]').setValue(30);
    await wrapper.get('form[data-test="schedule-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Indiquez un jour entre 1 et 28.');
    await wrapper.get('form[data-test="schedule-form"] input[type="number"]').setValue(5);
    await wrapper.get('form[data-test="schedule-form"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/report-schedules')?.body).toEqual({ name: 'Mensuel', frequency: 'monthly', recipient_ids: ['P-DIR'], run_day: 5, report_type: 'direction' });

    await wrapper.get('[data-action="run"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('1 envoyé(s), 0 échec(s)');
    await wrapper.get('[data-action="runs"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="runs"]').text()).toContain('Étudiants inscrits : 12');
  });

  it('met en pause, modifie et supprime après confirmation', async () => {
    asDirector();
    const calls = mockApi({
      'GET /report-schedules': page([schedule()]),
      'PATCH /report-schedules/RS1': (c: { body?: Record<string, unknown> }) => ({ data: schedule(c.body ?? {}) }),
      'DELETE /report-schedules/RS1': { data: { deleted: true } },
    });
    const { wrapper } = await mountView(ReportsView);
    await wrapper.get('[data-action="pause"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ status: 'paused' });
    expect(wrapper.text()).toContain('En pause');

    await wrapper.get('[data-action="edit"]').trigger('click');
    await wrapper.get('form[data-test="schedule-form"] input[type="text"]').setValue('Renommé');
    await wrapper.get('form[data-test="schedule-form"]').trigger('submit');
    await flushPromises();
    expect(calls.filter((c) => c.method === 'PATCH')[1]?.body).toMatchObject({ name: 'Renommé' });
    expect(calls.filter((c) => c.method === 'PATCH')[1]?.body).not.toHaveProperty('report_type');

    await wrapper.get('[data-action="delete"]').trigger('click');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    await wrapper.get('[data-action="confirm-delete"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/report-schedules/RS1')).toBe(true);
    expect(wrapper.text()).toContain('Aucun rapport planifié.');
  });

  it('affiche le refus (403) sans droit reporting.manage', async () => {
    asDirector();
    mockApi({ 'GET /report-schedules': problem(403, 'Vous n\'avez pas le droit de gérer les rapports planifiés.') });
    const { wrapper } = await mountView(ReportsView);
    expect(wrapper.get('[role="alert"]').text()).toContain('pas le droit de gérer');
  });
});

describe('statistiques réglementaires', () => {
  it('affiche les effectifs et « Indisponible » avec la raison pour le niveau', async () => {
    asDirector();
    mockApi({
      'GET /regulatory-statistics': { data: {
        generated_at: '2026-10-09T09:00:00+01:00', total_enrolled: 30,
        by_academic_year: { available: true, items: [{ academic_year_id: '2026-2027', enrolled: 30 }] },
        by_structure: { available: true, items: [{ structure_id: '3eA', enrolled: 18 }, { structure_id: null, enrolled: 12 }] },
        by_sex: { available: true, items: [{ sex: 'female', enrolled: 14 }, { sex: 'unknown', enrolled: 16 }] },
        by_level: { available: false, reason: 'Aucun contrat n\'expose le niveau d\'une classe.' },
      } },
    });
    const { wrapper } = await mountView(RegulatoryView);
    expect(wrapper.get('[data-test="by_academic_year"]').text()).toContain('2026-2027');
    expect(wrapper.get('[data-test="by_structure"]').text()).toContain('3eA');
    expect(wrapper.get('[data-test="by_sex"]').text()).toContain('Filles');
    expect(wrapper.get('[data-test="by_level"]').text()).toContain('Indisponible');
    expect(wrapper.get('[data-test="by_level"]').text()).toContain('Aucun contrat n\'expose le niveau');
    expect(wrapper.get('article.kpi').text()).toContain('30');
  });
});
