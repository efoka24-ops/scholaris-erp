import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardView from '@/views/DashboardView.vue';
import GradesView from '@/views/GradesView.vue';
import TimetableView from '@/views/TimetableView.vue';
import PeopleView from '@/views/PeopleView.vue';
import PersonView from '@/views/PersonView.vue';
import BillingView from '@/views/BillingView.vue';
import MessagesView from '@/views/MessagesView.vue';
import DocumentsView from '@/views/DocumentsView.vue';
import PrivacyView from '@/views/PrivacyView.vue';
import LearnerPortalView from '@/views/LearnerPortalView.vue';
import SyncView from '@/views/SyncView.vue';
import ActivationView from '@/views/ActivationView.vue';
import TwoFactorView from '@/views/TwoFactorView.vue';
import TwoFactorSetupView from '@/views/TwoFactorSetupView.vue';
import SecurityView from '@/views/SecurityView.vue';
import AppShell from '@/components/AppShell.vue';
import CodeInput from '@/components/CodeInput.vue';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import { enqueue, listQueue } from '@/offline/queue';
import { setLocale } from '@/i18n';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';
import { ulid } from '@/lib/ulid';

beforeEach(freshEnvironment);

const directorMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ roles: [{ code: 'directeur', label: 'Directeur' }] });

describe('écran Tableau de bord', () => {
  it('affiche les indicateurs et « Indisponible » avec la raison pour ceux sans source', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /dashboards/direction': { data: { kind: 'direction', scope: { type: 'establishment', establishment_id: 'E1' }, generated_at: '2026-10-09T09:00:00+01:00', indicators: [
        { key: 'learners', label: 'Étudiants inscrits', available: true, value: 1240, unit: 'count' },
        { key: 'recovery', label: 'Frais recouvrés', available: true, value: 72, unit: 'percent' },
        { key: 'attendance', label: 'Assiduité de la semaine', available: false, value: null, unit: 'percent', reason: 'Aucune source ne fournit encore cet indicateur.' },
      ], meta: { degraded_providers: [] } } },
      'GET /authorizations/expiring': { data: [{ id: 'A1', label: 'Licence Droit', valid_to: '2026-10-30' }] },
    });
    const { wrapper } = await mountView(DashboardView);
    const kpis = wrapper.findAll('article.kpi');
    expect(kpis).toHaveLength(3);
    expect(kpis[0]?.text()).toContain('1');
    expect(kpis[1]?.text()).toContain('72 %');
    expect(kpis[2]?.text()).toContain('Indisponible');
    expect(kpis[2]?.text()).toContain('Aucune source ne fournit encore cet indicateur.');
    expect(wrapper.text()).toContain('Licence Droit');
    expect(calls.find((c) => c.path === '/dashboards/direction')?.headers['X-Establishment-Id']).toBe('E1');
  });

  it('vue groupe : consolidé + établissements, sans en-tête d\'établissement', async () => {
    const group = { kind: 'group', id: 'group', name: 'Tout le groupe', roles: [{ code: 'direction_groupe', label: 'Direction de groupe' }] };
    signIn({ ...teacherMe, context: { establishment_id: null, role: 'direction_groupe' } }, [group as never], 'direction_groupe');
    const calls = mockApi({
      'GET /dashboards/groupe': { data: { kind: 'groupe', scope: { type: 'group' }, generated_at: '2026-10-09T09:00:00+01:00', consolidated: [{ key: 'learners', label: 'Apprenants', available: true, value: 3000, unit: 'count', detail: { establishments_counted: 2, establishments_total: 2 } }], establishments: [{ establishment_id: 'E1', name: 'Palmiers', indicators: [{ key: 'learners', label: 'Apprenants', available: true, value: 1200, unit: 'count' }] }] } },
    });
    const { wrapper } = await mountView(DashboardView);
    expect(wrapper.text()).toContain('Vue consolidée');
    expect(wrapper.text()).toContain('Palmiers');
    expect(calls.find((c) => c.path === '/dashboards/groupe')?.headers['X-Establishment-Id']).toBeUndefined();
  });

  it('montre l\'erreur 403 avec sa trace', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /dashboards/direction': jsonResponse({ type: 't', title: 'Accès refusé', status: 403, detail: 'Droit manquant.', trace_id: 'TR-9' }, 403) });
    const { wrapper } = await mountView(DashboardView);
    expect(wrapper.get('[role="alert"]').text()).toContain('TR-9');
  });
});

describe('écran Évaluations (grille de saisie, conseil de classe)', () => {
  const col = { id: 'AS1', subject_id: 'M1', title: 'Devoir', kind: 'homework', weight: 2, max_score: 20, status: 'open', grading_mode: 'numeric', anonymous: false };
  const cell = (v: number | null, version = 1) => ({ state: v === null ? 'missing' : 'value', value: v, absence: null, qualitative: null, version, grade_id: v === null ? null : 'G', message: null });
  const grid = { columns: [col], rows: [
    { number: 1, person_id: 'P1', matricule: 'M001', name: 'BELLO Nadia', cells: { AS1: cell(12) }, average: 12, appraisal: 'Assez bien' },
    { number: 2, person_id: 'P2', matricule: 'M002', name: 'FOKA Yann', cells: { AS1: cell(null, 0) }, average: null, appraisal: null },
  ], counters: { errors: 0, missing: 1, absent_justified: 0 }, rules_version: 2 };
  const api = () =>
    mockApi({
      'GET /structures': { data: [{ id: 'ST1', name: '4e B' }], meta: { next_cursor: null } },
      'GET /periods': { data: [{ id: 'PE1', name: 'Séquence 2' }], meta: { next_cursor: null } },
      'GET /grade-sheets': { data: grid },
      'POST /grades/sync': (c) => ({ data: { results: (c.body?.entries as Array<{ client_uuid: string }>).map((e) => ({ client_uuid: e.client_uuid, status: 'updated' })) } }),
    });

  async function open() {
    signIn();
    const calls = api();
    const ctx = await mountView(GradesView);
    await ctx.wrapper.get('#g-structure').setValue('ST1');
    await ctx.wrapper.get('#g-period').setValue('PE1');
    await flushPromises();
    return { ...ctx, calls };
  }

  it('affiche la grille, les compteurs et refuse 22 sur 20 avec le message de la maquette', async () => {
    const { wrapper, calls } = await open();
    expect(wrapper.text()).toContain('BELLO Nadia');
    expect(wrapper.get('.stats').text()).toContain('1 note manquante');
    const input = wrapper.get('input[aria-label="FOKA Yann · Devoir"]');
    await input.setValue('22');
    await flushPromises();
    expect(wrapper.get('.errs').text()).toBe('Ligne 2 · Devoir : 22 dépasse le maximum de 20. Corrigez avant de valider.');
    expect(wrapper.get('.stats').text()).toContain('1 erreur');
    expect(calls.find((c) => c.path === '/grades/sync')).toBeUndefined();
  });

  it('enregistre automatiquement une note valide : écrite sur l\'appareil puis envoyée avec client_uuid et base_version', async () => {
    const { wrapper, calls } = await open();
    const input = wrapper.get('input[aria-label="FOKA Yann · Devoir"]');
    await input.setValue('14,5');
    await flushPromises(10);
    const sync = calls.find((c) => c.path === '/grades/sync');
    const entry = (sync?.body?.entries as Array<Record<string, unknown>>)[0];
    expect(entry).toMatchObject({ assessment_id: 'AS1', person_id: 'P2', base_version: 0, value: 14.5, absence: null });
    expect(entry?.client_uuid).toMatch(/^[0-9A-Z]{26}$/);
    expect(wrapper.text()).toContain('Enregistré automatiquement');
    expect(await listQueue('grade')).toHaveLength(0);
  });

  it('hors ligne : la note reste « À envoyer » sur l\'appareil', async () => {
    const { wrapper } = await open();
    useOfflineStore().online = false;
    const input = wrapper.get('input[aria-label="FOKA Yann · Devoir"]');
    await input.setValue('9');
    await flushPromises();
    expect(wrapper.text()).toContain('À envoyer');
    expect(await listQueue('grade')).toHaveLength(1);
    expect(wrapper.get('.stats').text()).toContain('1 note en attente d\'envoi');
  });

  it('affiche les deux versions d\'un conflit et ne les résout pas sans décision', async () => {
    signIn();
    await enqueue({ client_uuid: ulid(), kind: 'grade', establishment_id: 'E1', label: 'FOKA Yann · Devoir', payload: { assessment_id: 'AS1', person_id: 'P2', value: 9, absence: null, base_version: 0 } });
    const [item] = await listQueue('grade');
    const { idbPut } = await import('@/offline/db');
    await idbPut('queue', { ...item, status: 'conflict', server_version: { score: 15, version: 3, absence: null } });
    api();
    const ctx = await mountView(GradesView);
    await ctx.wrapper.get('#g-structure').setValue('ST1');
    await ctx.wrapper.get('#g-period').setValue('PE1');
    await flushPromises();
    const alert = ctx.wrapper.get('.notice--warning[role="alert"]');
    expect(alert.text()).toContain('Votre version');
    expect(alert.text()).toContain('9');
    expect(alert.text()).toContain('Version du serveur');
    expect(alert.text()).toContain('15');
    expect(alert.text()).toContain('Garder la version du serveur');
    expect(await listQueue('grade')).toHaveLength(1);
  });

  it('conseil de classe : motif obligatoire quand la décision diffère de la proposition', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /structures': { data: [{ id: 'ST1', name: '4e B' }], meta: { next_cursor: null } },
      'GET /periods': { data: [{ id: 'PE1', name: 'Trimestre 1' }], meta: { next_cursor: null } },
      'GET /grade-sheets': { data: { ...grid, rows: [] } },
      'GET /class-councils': { data: [{ id: 'CC1' }], meta: { next_cursor: null } },
      'GET /class-councils/CC1': { data: { id: 'CC1', structure_id: 'ST1', period_id: 'PE1', period_label: 'Trimestre 1', status: 'open', summary: null, minutes_status: null, lines: [{ id: 'L1', person_id: 'P1', name: 'BELLO Nadia', matricule: 'M1', average: 15.2, rank_in_class: 1, unjustified_absences: 0, conduct: null, proposal: 'Félicitations', decision: 'Félicitations', reason: null, appraisal: null }] } },
      'PATCH /class-councils/CC1/lines/L1': { data: { id: 'CC1', structure_id: 'ST1', period_id: 'PE1', period_label: 'Trimestre 1', status: 'open', summary: null, minutes_status: null, lines: [{ id: 'L1', person_id: 'P1', name: 'BELLO Nadia', matricule: 'M1', average: 15.2, rank_in_class: 1, unjustified_absences: 0, conduct: null, proposal: 'Félicitations', decision: 'Encouragements', reason: 'retards répétés', appraisal: null }] } },
    });
    const { wrapper } = await mountView(GradesView);
    await wrapper.get('#g-structure').setValue('ST1');
    await wrapper.get('#g-period').setValue('PE1');
    await wrapper.get('#tab-council').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('BELLO Nadia');
    const dec = wrapper.get('#dec-L1');
    await dec.setValue('Encouragements');
    await flushPromises();
    expect(wrapper.text()).toContain('Indiquez le motif');
    expect(calls.find((c) => c.method === 'PATCH')).toBeUndefined();
  });
});

describe('écran Emploi du temps', () => {
  it('liste les cours de la semaine et signale une modification ponctuelle', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /structures': { data: [{ id: 'ST1', name: '4e B' }], meta: { next_cursor: null } },
      'GET /timetable': { data: { view: { type: 'structure', id: 'ST1' }, week_start: '2026-10-05', week_end: '2026-10-11', days: [
        { date: '2026-10-05', weekday: 1, entries: [{ slot_id: 'S1', structure_ids: ['ST1'], subject_label: 'Mathématiques', teacher_person_id: 'T', original_teacher_person_id: null, room_id: 'R', start_time: '08:00:00', end_time: '09:55:00', status: 'cancelled', change_id: 'C1' }] },
        { date: '2026-10-06', weekday: 2, entries: [] },
      ] } },
    });
    const { wrapper } = await mountView(TimetableView);
    await wrapper.get('#tt-struct').setValue('ST1');
    await flushPromises();
    expect(wrapper.text()).toContain('08:00 – 09:55');
    expect(wrapper.text()).toContain('Mathématiques');
    expect(wrapper.text()).toContain('cancelled');
    expect(wrapper.text()).toContain('Aucun cours.');
    expect(calls.find((c) => c.path === '/timetable')?.query.structure_id).toBe('ST1');
  });
});

describe('écran Personnes et inscriptions', () => {
  const person = { id: 'P1', first_name: 'Nadia', last_name: 'Bello', birth_date: '2010-05-04', phone: '+237600000001', email: null };
  it('recherche et liste des personnes', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /people': { data: [person], meta: { next_cursor: null } } });
    const { wrapper } = await mountView(PeopleView);
    expect(wrapper.text()).toContain('BELLO Nadia');
    await wrapper.get('#p-q').setValue('bello');
    await wrapper.get('form[role="search"]').trigger('submit');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/people').at(-1)?.query['filter[q]']).toBe('bello');
  });

  it('signale un doublon (409), propose de reprendre la fiche, et confirme « personne différente » de façon explicite', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    let n = 0;
    const calls = mockApi({
      'GET /people': { data: [], meta: { next_cursor: null } },
      'POST /people': (c) => (c.body?.confirm_different ? jsonResponse({ data: { ...person, id: 'P9' } }, 201) : (n++, jsonResponse({ type: 't', title: 'Doublon', status: 409, detail: 'Une personne existe peut-être déjà.', duplicates: [{ id: 'P1', first_name: 'Nadia', last_name: 'Bello', criteria: 3 }], trace_id: 'x' }, 409))),
      'GET /people/P9': { data: { ...person, id: 'P9' } },
    });
    const { wrapper } = await mountView(PeopleView);
    await wrapper.findAll('button').find((b) => b.text() === 'Nouvelle personne')?.trigger('click');
    await wrapper.get('#p-ln').setValue('Bello');
    await wrapper.get('#p-fn').setValue('Nadia');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    expect(n).toBe(1);
    expect(wrapper.text()).toContain('Une personne existe peut-être déjà dans le groupe.');
    expect(wrapper.text()).toContain('3 critères sur 3');
    expect(wrapper.text()).toContain("C'est la même personne : reprendre sa fiche");
    await wrapper.findAll('button').find((b) => b.text().startsWith('Personne différente'))?.trigger('click');
    await flushPromises();
    expect(calls.filter((c) => c.method === 'POST').at(-1)?.body?.confirm_different).toBe(true);
  });

  it('fiche personne : identité, pièces avec complétude, consentements', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /people/P1': { data: person },
      'GET /people/P1/enrollments': { data: [{ id: 'EN1', person_id: 'P1', establishment_id: 'E1', status: 'active', internal_number: 'CLP-2026-0042' }] },
      'GET /people/P1/documents': { data: [{ id: 'D1', kind: 'acte de naissance', status: 'received', expires_on: null }, { id: 'D2', kind: 'photo', status: 'expiring_soon', expires_on: '2026-11-30' }], meta: { completeness: { required: 7, received: 5, missing: 2 } } },
      'GET /consents': { data: [{ id: 'C1', person_id: 'P1', purpose: 'communication', revoked_at: null }] },
    });
    const { wrapper } = await mountView(PersonView, { id: 'P1' });
    expect(wrapper.get('h1').text()).toBe('BELLO Nadia');
    expect(wrapper.text()).toContain('CLP-2026-0042');
    expect(wrapper.text()).toContain('5 / 7');
    expect(wrapper.text()).toContain('communication');
    expect(wrapper.get('progress').attributes('value')).toBe('5');
  });
});

describe('écran Frais et paiements', () => {
  const cashierMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'caissier' } };
  const cashierTile = tile({ roles: [{ code: 'caissier', label: 'Caissier' }] });

  it('journal du jour : reçu numéroté, statuts Rapproché / En caisse / Sans référence / Annulation', async () => {
    signIn(cashierMe, [cashierTile], 'caissier');
    mockApi({
      'GET /payments': { data: [
        { id: 'PAY1', channel: 'cash', amount_xaf: 31600, status: 'confirmed', journal_status: 'En caisse', journal_amount_xaf: 31600, receipt_number: 'R-IPES-26-04821', received_at: '2026-10-09T09:00:00+01:00' },
        { id: 'PAY2', channel: 'orange_money', amount_xaf: 50000, status: 'confirmed', journal_status: 'Sans référence', journal_amount_xaf: 50000, receipt_number: 'R-IPES-26-04822', received_at: '2026-10-09T09:10:00+01:00' },
        { id: 'PAY3', channel: 'cash', amount_xaf: 10000, status: 'confirmed', journal_status: 'Annulation · 2nd agent requis', journal_amount_xaf: -10000, receipt_number: 'R-IPES-26-04823', received_at: '2026-10-09T09:20:00+01:00' },
      ], meta: { next_cursor: null } },
    });
    const { wrapper } = await mountView(BillingView);
    expect(wrapper.text()).toContain('R-IPES-26-04821');
    expect(wrapper.text()).toContain('En caisse');
    expect(wrapper.text()).toContain('Sans référence');
    expect(wrapper.text()).toContain('Annulation · 2nd agent requis');
    expect(wrapper.text()).toContain('-10 000 FCFA');
  });

  it('encaissement en caisse : Idempotency-Key conservée si l\'envoi échoue, nouvelle clé après succès', async () => {
    signIn(cashierMe, [cashierTile], 'caissier');
    const keys: string[] = [];
    let attempt = 0;
    mockApi({
      'GET /payments': { data: [], meta: { next_cursor: null } },
      'GET /cash-sessions': { data: [{ id: 'CS1', register_no: 2, status: 'open', opened_at: '2026-10-09T07:42:00+01:00', float_xaf: 50000, cash_collected_xaf: 0 }], meta: { next_cursor: null } },
      'POST /cash/receipts': (c) => {
        keys.push(c.headers['Idempotency-Key'] ?? '');
        return ++attempt === 1 ? jsonResponse({ type: 't', title: 'Service indisponible', status: 503, detail: 'Réessayez.', trace_id: 'x' }, 503) : { data: { id: 'PAY9', receipt_number: 'R-CLP-26-00001', channel: 'cash', amount_xaf: 35000, status: 'confirmed' } };
      },
    });
    const { wrapper } = await mountView(BillingView);
    await wrapper.findAll('[role="tab"]')[1]?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Caisse n°2 ouverte');
    await wrapper.get('#b-ref').setValue('FAM-0412');
    await wrapper.get('#b-amt').setValue('35000');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Réessayez.');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    expect(wrapper.text()).toContain('Reçu R-CLP-26-00001');
  });
});

describe('écran Messages et annonces', () => {
  it('liste les annonces et permet d\'en publier une', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /announcements': { data: [{ id: 'A1', title: 'Réunion parents', body: 'Samedi à 9 h.', published_at: '2026-10-08T10:00:00+01:00', expires_at: null }], meta: { next_cursor: null } },
      'GET /messages': { data: [], meta: { next_cursor: null } },
      'POST /announcements': { data: { id: 'A2' } },
    });
    const { wrapper } = await mountView(MessagesView);
    expect(wrapper.text()).toContain('Réunion parents');
    await wrapper.get('#a-title').setValue('Rentrée');
    await wrapper.get('#a-body').setValue('Lundi 8 h.');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/announcements')?.body).toEqual({ title: 'Rentrée', body: 'Lundi 8 h.' });
  });
});

describe('écran Documents', () => {
  it('registre des documents émis avec statut', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /documents': { data: [{ id: 'D1', type_code: 'bulletin', number: 'CLP-26-B-004812', status: 'valid', holder_name: 'BELLO Nadia', issued_at: '2026-10-02T08:00:00+01:00', issued_via: 'batch' }], meta: { next_cursor: null } },
    });
    const { wrapper } = await mountView(DocumentsView);
    expect(wrapper.text()).toContain('CLP-26-B-004812');
    expect(wrapper.text()).toContain('BELLO Nadia');
  });

  it('un parent demande un document pour sa propre fiche', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } }, [tile({ roles: [{ code: 'parent', label: 'Parent' }] })], 'parent');
    const calls = mockApi({
      'GET /document-types': { data: [{ id: 'T1', code: 'school_certificate', label: 'Certificat de scolarité' }], meta: { next_cursor: null } },
      'POST /document-requests': { data: { id: 'DR1' } },
    });
    const { wrapper } = await mountView(DocumentsView);
    await wrapper.get('#d-type').setValue('school_certificate');
    await wrapper.findAll('button').find((b) => b.text() === 'Envoyer la demande')?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/document-requests')?.body).toMatchObject({ type: 'school_certificate', subject_person_id: 'P-TEACH' });
    expect(wrapper.text()).toContain('Votre demande est enregistrée.');
  });
});

describe('écran Confidentialité', () => {
  it('permet de donner et de retirer un consentement', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } }, [tile({ roles: [{ code: 'parent', label: 'Parent' }] })], 'parent');
    let consents = [{ id: 'C1', person_id: 'P-TEACH', purpose: 'communication', revoked_at: null }];
    const calls = mockApi({
      'GET /consents': () => ({ data: consents, meta: { next_cursor: null } }),
      'POST /consents/C1/revoke': () => {
        consents = [];
        return { data: {} };
      },
    });
    const { wrapper } = await mountView(PrivacyView);
    expect(wrapper.text()).toContain('Communication par SMS et WhatsApp');
    const btn = wrapper.findAll('button').find((b) => b.text() === 'Retirer mon accord');
    await btn?.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.path === '/consents/C1/revoke')).toBe(true);
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Autoriser')).toHaveLength(3);
  });

  it('équipe : paramètres de conformité et demandes en retard', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /consents': { data: [], meta: { next_cursor: null } },
      'GET /data-requests': { data: [{ id: 'R1', kind: 'access', status: 'received', overdue: true }], meta: { next_cursor: null } },
      'GET /privacy/settings': { data: { deadline_days: 30, requests_retention_months: 36 } },
    });
    const { wrapper } = await mountView(PrivacyView);
    expect(wrapper.text()).toContain('30 jours');
    expect(wrapper.text()).toContain('En retard');
  });
});

describe('écran Portail apprenant', () => {
  it('affiche sa fiche et l\'emploi du temps du jour', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'apprenant' } }, [tile({ roles: [{ code: 'apprenant', label: 'Apprenant' }] })], 'apprenant');
    mockApi({
      'GET /portal/learner': { data: {
        person: { id: 'P1', name: 'Nadia Bello' }, access: 'full', enrollments: [{ id: 'EN1', establishment_id: 'E1', establishment_name: 'Collège Les Palmiers', structure_id: 'ST1' }], balance_xaf: 0, next_due: { available: false, reason: 'x' },
        attendance: { available: true, absences: 1, unjustified_absences: 0 }, results: { blocked: false, latest: null, items: [] }, documents: { blocked: false, items: [] },
        today: '2026-10-09', timetable_today: [{ subject: 'Mathématiques', start: '08:00:00', end: '09:55:00', establishment_id: 'E1' }],
      } },
    });
    const { wrapper } = await mountView(LearnerPortalView);
    expect(wrapper.text()).toContain('Nadia Bello');
    expect(wrapper.text()).toContain('08:00 – 09:55');
    expect(wrapper.text()).toContain('À jour');
  });
});

describe('écran File d\'envoi', () => {
  it('liste les éléments en attente, les erreurs et les conflits avec les deux versions', async () => {
    signIn();
    const conflictId = ulid();
    await enqueue({ client_uuid: conflictId, kind: 'attendance', establishment_id: 'E1', label: '4e B · Mathématiques', payload: { slot_id: 'S1', records: [] } });
    const { idbPut } = await import('@/offline/db');
    const [item] = await listQueue();
    await idbPut('queue', { ...item, status: 'conflict', message: "L'appel est déjà validé.", server_version: { present_count: 38, absent_count: 3, late_count: 1, status: 'validated' }, client_version: { present_count: 40, absent_count: 2, late_count: 0, status: 'open' } });
    await enqueue({ client_uuid: ulid(), kind: 'grade', establishment_id: 'E1', label: 'FOKA Yann · Devoir', payload: { assessment_id: 'A', person_id: 'P' } });
    const { wrapper } = await mountView(SyncView);
    await useOfflineStore().refresh();
    await flushPromises();
    const text = wrapper.text();
    expect(text).toContain('4e B · Mathématiques');
    expect(text).toContain('Conflit');
    expect(text).toContain('Votre version');
    expect(text).toContain('40 présents');
    expect(text).toContain('Version du serveur');
    expect(text).toContain('38 présents');
    expect(text).toContain('Garder la version du serveur');
    expect(text).toContain('1 envoi en attente');
    const keep = wrapper.findAll('button').find((b) => b.text() === 'Garder la version du serveur');
    await keep?.trigger('click');
    await flushPromises();
    expect(await listQueue('attendance')).toHaveLength(0);
  });
});

describe('écrans d\'authentification', () => {
  it('activation du compte : identifiant, code (6 chiffres, décompte), nouveau mot de passe', async () => {
    const calls = mockApi({
      'POST /auth/activation/request': { channel: 'sms', masked_to: '+237 6•• •• 34 56', expires_in: 300, resend_in: 60 },
      'POST /auth/activation/verify': (c) => (c.body?.code === '123456' ? { ticket: 'TICKET', expires_in: 600 } : jsonResponse({ type: 't', title: 'Code incorrect', status: 422, detail: 'Code incorrect.', attempts_left: 2, trace_id: 'x' }, 422)),
      'POST /auth/activation/password': { status: 'active' },
    });
    const { wrapper } = await mountView(ActivationView, { mode: 'activation' }, '/activation');
    await wrapper.get('#act-id').setValue('+237600000001');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('+237 6•• •• 34 56');
    expect(wrapper.text()).toContain('5:00');
    expect(wrapper.text()).toContain('Renvoyer le SMS dans 60 s');
    await wrapper.findComponent(CodeInput).setValue('000000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Il vous reste 2 essai(s).');
    await wrapper.findComponent(CodeInput).setValue('123456');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('#act-pw').setValue('court');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await wrapper.get('#act-pw').setValue('un mot de passe solide');
    await wrapper.get('#act-pw2').setValue('un mot de passe solide');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path === '/auth/activation/password')?.body).toMatchObject({ ticket: 'TICKET' });
    expect(wrapper.text()).toContain('Votre mot de passe est enregistré.');
  });

  it('réinitialisation : appelle /auth/password/forgot', async () => {
    const calls = mockApi({ 'POST /auth/password/forgot': { channel: 'email', masked_to: 'j***@x.cm', expires_in: 300, resend_in: 60 } });
    const { wrapper } = await mountView(ActivationView, { mode: 'reset' }, '/mot-de-passe-oublie');
    await wrapper.get('#act-id').setValue('joseph@x.cm');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls[0]?.path).toBe('/auth/password/forgot');
  });

  it('double authentification : code, confiance 30 jours, essais restants', async () => {
    signIn({ ...teacherMe, two_factor: { enabled: true, required: true, verified: false } });
    const calls = mockApi({
      'POST /auth/2fa/verify': (c) => (c.body?.code === '111111' ? { verified: true, trusted: true } : jsonResponse({ type: 't', title: 'Code incorrect', status: 422, detail: 'Code incorrect.', attempts_left: 2, trace_id: 'x' }, 422)),
      'GET /me': { data: teacherMe },
    });
    const { wrapper, router } = await mountView(TwoFactorView, {}, '/double-authentification');
    await wrapper.findComponent(CodeInput).setValue('000000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Code incorrect. Il vous reste 2 essai(s).');
    await wrapper.findComponent(CodeInput).setValue('111111');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), { timeout: 8000 });
    expect(calls.filter((c) => c.path === '/auth/2fa/verify').at(-1)?.body).toEqual({ code: '111111', method: 'totp', trust_device: true });
  });

  it('activation de la 2FA : clé groupée, codes de secours affichés une seule fois', async () => {
    signIn({ ...teacherMe, two_factor: { enabled: false, required: true, verified: false } });
    mockApi({
      'POST /auth/2fa/setup': { secret: 'JBSWY3DPEHPK3PXP', secret_grouped: 'JBSW Y3DP EHPK 3PXP', otpauth_uri: 'otpauth://totp/SCHOLARIS:x?secret=JBSWY3DPEHPK3PXP', required_for: [{ code: 'enseignant', label: 'Enseignant' }] },
      'POST /auth/2fa/confirm': { two_factor_enabled: true, backup_codes: ['AAAA1111', 'BBBB2222'] },
    });
    const { wrapper } = await mountView(TwoFactorSetupView, {}, '/double-authentification/activation');
    expect(wrapper.text()).toContain('JBSW Y3DP EHPK 3PXP');
    expect(wrapper.text()).toContain('obligatoire pour votre rôle : Enseignant');
    await wrapper.findComponent(CodeInput).setValue('123456');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('AAAA1111');
    expect(wrapper.text()).toContain('BBBB2222');
    expect(wrapper.findAll('button').find((b) => b.text() === 'Continuer')?.attributes('disabled')).toBeDefined();
  });

  it('sécurité : liste les appareils et révoque un autre appareil', async () => {
    signIn();
    let devices = [
      { id: 'D1', name: 'Chrome · Windows', location: 'Douala', last_seen_at: '2026-10-09T08:00:00+01:00', trusted_until: null, current: true },
      { id: 'D2', name: 'Samsung A14', location: 'Yaoundé', last_seen_at: '2026-10-08T18:00:00+01:00', trusted_until: null, current: false },
    ];
    const calls = mockApi({
      'GET /me/devices': () => ({ data: devices }),
      'DELETE /me/devices/D2': () => {
        devices = devices.slice(0, 1);
        return new Response(null, { status: 204 });
      },
    });
    const { wrapper } = await mountView(SecurityView);
    expect(wrapper.text()).toContain('Celui-ci');
    await wrapper.findAll('button').find((b) => b.text() === 'Révoquer')?.trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'DELETE' && c.path === '/me/devices/D2')).toBe(true);
    expect(wrapper.text()).not.toContain('Samsung A14');
  });
});

describe('cadre de l\'application', () => {
  it('filtre le menu selon le rôle et bascule le contexte et la langue', async () => {
    signIn(teacherMe, [tile(), tile({ id: 'E2', name: 'IPES', type: 'higher', roles: [{ code: 'enseignant', label: 'Enseignant' }] })]);
    const calls = mockApi({ 'POST /me/context': { data: { context: tile({ id: 'E2', name: 'IPES' }), role: 'enseignant' } } });
    const router = (await mountView(SecurityView, {}, '/securite')).router;
    const { mount } = await import('@vue/test-utils');
    const wrapper = mount(AppShell, { global: { plugins: [router] }, slots: { default: '<p>contenu</p>' } });
    await flushPromises();
    const nav = wrapper.get('nav').text();
    expect(nav).toContain('Appel');
    expect(nav).toContain('Emploi du temps');
    expect(nav).not.toContain('Frais et paiements');
    expect(nav).not.toContain('Tableau de bord');
    expect(wrapper.get('main').text()).toBe('contenu');

    await wrapper.findAll('.lang button')[1]?.trigger('click');
    expect(wrapper.get('nav').text()).toContain('Roll call');

    await wrapper.get('#ctx-est').setValue('E2');
    await flushPromises();
    expect(calls.find((c) => c.path === '/me/context')?.body).toEqual({ establishment_id: 'E2' });
    expect(useContextStore().activeId).toBe('E2');

    setLocale('fr');
  });
});
