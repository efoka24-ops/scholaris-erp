import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { routes } from '@/router/routes';
import { higherDocumentsRoutes } from '@/views/higher/documents/routes';
import { higherPortalRoutes } from '@/views/higher/portals/routes';
import { higherTeachingExtraRoutes } from '@/views/higher/teaching-extra/routes';
import { higherSittingRoutes } from '@/views/higher/sitting/routes';
import { higherDeliberationRoutes } from '@/views/higher/deliberation/routes';
import { clearHigherNames, requestNames } from '@/views/higher/useHigherNames';
import DocumentsView from '@/views/higher/documents/DocumentsView.vue';
import SchoolProfileView from '@/views/higher/documents/SchoolProfileView.vue';
import StudentPortalView from '@/views/higher/portals/StudentPortalView.vue';
import GuarantorPortalView from '@/views/higher/portals/GuarantorPortalView.vue';
import DelegatesView from '@/views/higher/teaching-extra/DelegatesView.vue';
import TimetablePrintView from '@/views/higher/teaching-extra/TimetablePrintView.vue';
import SittingView from '@/views/higher/sitting/SittingView.vue';
import JuryView from '@/views/higher/deliberation/JuryView.vue';
import { flushPromises, freshEnvironment, mockApi } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

const ALL_ROUTES = [...routes, ...higherDocumentsRoutes, ...higherPortalRoutes, ...higherTeachingExtraRoutes, ...higherSittingRoutes, ...higherDeliberationRoutes];
const names = (list: Array<[string, string, string, string | null]>) => ({ data: list.map(([person_id, first_name, last_name, matricule]) => ({ person_id, first_name, last_name, matricule })) });

async function mountAt(component: Component, path: string, props: Record<string, unknown> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: ALL_ROUTES });
  await router.push(path);
  await router.isReady();
  const wrapper = mount(component, { props, global: { plugins: [router] } });
  await flushPromises();
  return { wrapper, router };
}

const doc = (over: Record<string, unknown> = {}) => ({
  id: 'D1', document_id: 'SD1', code: 'DOC-06', number: 'CS-EC-2026-000001', verification_token: 'TOK1', person_id: 'P-MARIE', school_id: 'S1', program_id: null,
  level_id: null, locale: 'fr', profile_version: 1, status: 'valid', cancel_reason: null, replaced_by: null, duplicate_count: 0, issued_at: '2026-10-10T10:00:00Z', ...over,
});
const catalog = { data: [
  { code: 'DOC-06', prefix: 'CS', label_fr: 'Certificat de scolarité', label_en: 'Certificate of enrolment', module: 'enrolment', signer_role: 'higher_dir_ecole', qr: true, blockable: false, issued_by_socle: false, number_template: null, variables: [] },
  { code: 'DOC-27', prefix: 'REC', label_fr: 'Reçu', label_en: 'Receipt', module: 'billing', signer_role: null, qr: true, blockable: false, issued_by_socle: true, number_template: null, variables: [] },
] };

beforeEach(() => {
  freshEnvironment();
  clearHigherNames();
});
afterEach(() => vi.restoreAllMocks());

describe('Documents officiels IPES', () => {
  it('affiche le catalogue, le registre avec les noms, et émet un document (clé d\'idempotence)', async () => {
    const calls = setup(['higher.access', 'higher.document.read', 'documents.issue'], {
      'GET /higher/documents/catalog': catalog,
      'GET /higher/documents': { data: [doc()] },
      'GET /higher/directory/names': names([['P-MARIE', 'Marie', 'Nkoulou', 'IPES-25-0001']]),
      'GET /people': page([{ id: 'P-MARIE', first_name: 'Marie', last_name: 'Nkoulou' }]),
      'POST /higher/documents/issue': { data: doc({ id: 'D2', number: 'CS-EC-2026-000002', verification_token: 'TOK2' }) },
    });
    const { wrapper } = await mountAt(DocumentsView, '/superieur/documents');
    expect(wrapper.get('[data-test="catalog"]').text()).toContain('Certificat de scolarité');
    expect(wrapper.get('[data-test="holder"]').text()).toBe('Marie Nkoulou · IPES-25-0001');
    expect(wrapper.findAll('#dc-code option').map((o) => o.text())).not.toContain('DOC-27 · Reçu');

    await wrapper.get('[data-test="issue"]').trigger('submit');
    expect(wrapper.text()).toContain('Choisissez un document.');
    await wrapper.get('#dc-code').setValue('DOC-06');
    await wrapper.get('#dc-school').setValue('S1');
    await wrapper.get('#dc-person').setValue('Marie');
    await wrapper.get('#dc-person').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await wrapper.findAll('[data-test="issue"] .hi-list button')[0]!.trigger('click');
    await wrapper.get('[data-test="issue"]').trigger('submit');
    await flushPromises();
    const post = find(calls, 'POST', '/higher/documents/issue');
    expect(post?.body).toEqual({ code: 'DOC-06', person_id: 'P-MARIE', school_id: 'S1' });
    expect(Object.keys(post?.headers ?? {}).some((h) => h.toLowerCase() === 'idempotency-key')).toBe(true);
    expect(wrapper.get('[data-test="issued"]').text()).toContain('CS-EC-2026-000002');
  });

  it('annule avec motif obligatoire et passe en lecture seule sur 423', async () => {
    const calls = setup(['higher.access', 'higher.document.read', 'documents.issue'], {
      'GET /higher/documents/catalog': catalog,
      'GET /higher/documents': { data: [doc()] },
      'POST /higher/documents/D1/cancel': { data: doc({ status: 'cancelled', cancel_reason: 'Erreur de filière' }) },
      'POST /higher/documents/D1/duplicate': problem(423, 'Abonnement suspendu : lecture seule.'),
    });
    const { wrapper } = await mountAt(DocumentsView, '/superieur/documents');
    await wrapper.get('[data-test="cancel"]').trigger('click');
    await wrapper.get('[data-test="cancel-form"]').trigger('submit');
    expect(wrapper.text()).toContain('motif');
    expect(find(calls, 'POST', '/higher/documents/D1/cancel')).toBeUndefined();
    await wrapper.get('#dc-reason-D1').setValue('Erreur de filière');
    await wrapper.get('[data-test="cancel-form"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/documents/D1/cancel')?.body).toEqual({ reason: 'Erreur de filière' });
    expect(wrapper.text()).toContain('Annulé');
  });

  it('bascule en lecture seule quand le duplicata reçoit 423', async () => {
    setup(['higher.access', 'higher.document.read', 'documents.issue'], {
      'GET /higher/documents/catalog': catalog,
      'GET /higher/documents': { data: [doc()] },
      'POST /higher/documents/D1/duplicate': problem(423, 'Abonnement suspendu : lecture seule.'),
    });
    const { wrapper } = await mountAt(DocumentsView, '/superieur/documents');
    await wrapper.get('[data-test="duplicate"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="readonly-banner"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="issue"]').exists()).toBe(false);
  });
});

describe('Profil documentaire de l\'école', () => {
  it('signale l\'absence de profil, valide le code et enregistre une version', async () => {
    const calls = setup(['higher.access', 'higher.document.read', 'documents.templates.manage'], {
      'GET /higher/documents/schools/S1/profile': problem(404, 'Aucun profil.'),
      'PUT /higher/documents/schools/S1/profile': { data: { school_id: 'S1', version: 1, school_code: 'EC', display_name: 'École de commerce', header_text: null, logo_url: null, primary_color: '#0B3D2E', default_locale: 'fr', created_at: '2026-10-15' } },
    });
    const { wrapper } = await mountAt(SchoolProfileView, '/superieur/documents/profil');
    expect(wrapper.find('[data-test="no-profile"]').exists()).toBe(true);
    await wrapper.get('#sp-code').setValue('ec!');
    await wrapper.get('[data-test="profile-form"]').trigger('submit');
    expect(wrapper.text()).toContain('Code de 1 à 10 majuscules ou chiffres.');
    expect(find(calls, 'PUT', '/higher/documents/schools/S1/profile')).toBeUndefined();
    await wrapper.get('#sp-code').setValue('EC');
    await wrapper.get('#sp-name').setValue('École de commerce');
    await wrapper.get('[data-test="profile-form"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'PUT', '/higher/documents/schools/S1/profile')?.body).toEqual({ school_code: 'EC', display_name: 'École de commerce', header_text: null, logo_url: null, primary_color: '#0B3D2E', default_locale: 'fr' });
    expect(wrapper.get('[data-test="saved"]').text()).toContain('Version 1');
  });
});

const finance = (over: Record<string, unknown> = {}) => ({
  account_id: 'A1', academic_year_id: 'Y1', school_id: 'S1', program_id: null, net_xaf: 500000, paid_xaf: 170000, balance_xaf: 330000, up_to_date: true, payer_name: null,
  installments: [{ id: 'I1', label: 'Tranche 2', due_on: '2026-12-01', amount_xaf: 165000, paid_xaf: 0, remaining_xaf: 165000, status: 'pending', overdue: false }], ...over,
});

describe('Portails étudiant et garant', () => {
  it('l\'étudiant voit son reste à payer, ses reçus et paie par mobile money', async () => {
    const calls = setup(['self.read'], {
      'GET /higher/portal/student': { data: {
        person_id: 'P-MARIE', full_name: 'Marie Nkoulou', application: { available: false }, enrolments: [{ id: 'EN1', establishment_id: 'E1', structure_id: 'G1', academic_year_id: 'Y1', status: 'active', matricule: 'IPES-25-0001' }],
        finance: [finance()], receipts: [{ payment_id: 'PAY1', receipt_number: 'REC-EC-2026-000010', amount_xaf: 170000, channel: 'orange_money', status: 'confirmed', recorded_at: '2026-10-10T10:00:00Z' }],
        documents: [doc()], document_requests: { path: '/api/v1/document-requests', always_available: ['DOC-06'] }, timetable: { available: false },
      } },
      'POST /higher/portal/student/payments': { data: { payment: { status: 'pending' } } },
    });
    const { wrapper } = await mountAt(StudentPortalView, '/superieur/mon-espace');
    expect(wrapper.get('[data-test="balance"]').text().replace(/\s/g, '')).toContain('330000');
    expect(wrapper.get('[data-test="receipts"]').text()).toContain('REC-EC-2026-000010');
    expect(wrapper.get('[data-test="enrolments"]').text()).toContain('IPES-25-0001');
    await wrapper.get('#sp-pay-amount').setValue('400 000');
    await wrapper.get('#sp-pay-phone').setValue('12');
    await wrapper.get('[data-test="mobile-pay"]').trigger('submit');
    expect(wrapper.text()).toContain('dépasse le reste à payer');
    expect(find(calls, 'POST', '/higher/portal/student/payments')).toBeUndefined();
    await wrapper.get('#sp-pay-amount').setValue('50 000');
    await wrapper.get('#sp-pay-phone').setValue('+237 670 000 000');
    await wrapper.get('[data-test="mobile-pay"]').trigger('submit');
    await flushPromises();
    const post = find(calls, 'POST', '/higher/portal/student/payments');
    expect(post?.body).toEqual({ account_id: 'A1', amount_xaf: 50000, channel: 'orange_money', payer_phone: '+237670000000' });
    expect(wrapper.find('[data-test="pay-pending"]').exists()).toBe(true);
  });

  it('le garant voit les frais mais pas les notes sans consentement, et paie pour l\'étudiant', async () => {
    const calls = setup(['self.read'], {
      'GET /higher/portal/guarantor': { data: { guarantor_id: 'P-PAPA', students: [{
        person_id: 'P-MARIE', full_name: 'Marie Nkoulou', enrolments: [], finance: [finance()], receipts: [], consent: false,
        attendance: { available: false, reason: 'consent_required' }, grades: { available: false, reason: 'consent_required' },
      }] } },
      'POST /higher/portal/student/payments': { data: { payment: { status: 'pending' } } },
    });
    const { wrapper } = await mountAt(GuarantorPortalView, '/superieur/espace-garant');
    expect(wrapper.find('[data-test="consent-required"]').exists()).toBe(true);
    expect(wrapper.get('[data-test="balance"]').text().replace(/\s/g, '')).toContain('330000');
    await wrapper.get('#gp-P-MARIE-amount').setValue('20000');
    await wrapper.get('#gp-P-MARIE-phone').setValue('+237690000002');
    await wrapper.get('[data-test="mobile-pay"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/portal/student/payments')?.body).toMatchObject({ student_id: 'P-MARIE', amount_xaf: 20000 });
  });
});

describe('Délégués et emploi du temps imprimable', () => {
  it('liste les délégués par leur nom, en désigne et en retire', async () => {
    const calls = setup(['higher.access', 'scheduling.manage'], {
      'GET /higher/teaching/group-delegates': page([{ id: 'DL1', group_id: 'G1', person_id: 'P-PAUL', designated_by: 'U1', created_at: '2026-10-01T08:00:00Z' }]),
      'GET /higher/directory/names': names([['P-PAUL', 'Paul', 'Etoa', 'IPES-25-0002']]),
      'GET /people': page([{ id: 'P-AWA', first_name: 'Awa', last_name: 'Bello' }]),
      'POST /higher/teaching/group-delegates': { id: 'DL2', group_id: 'G1', person_id: 'P-AWA', designated_by: 'U1', created_at: '2026-10-15T08:00:00Z' },
      'DELETE /higher/teaching/group-delegates/DL1': { deleted: true },
    });
    const { wrapper } = await mountAt(DelegatesView, '/superieur/enseignement/delegues');
    expect(wrapper.get('[data-test="name"]').text()).toBe('Paul Etoa · IPES-25-0002');
    await wrapper.get('[data-test="designate"]').trigger('submit');
    expect(wrapper.text()).toContain('Choisissez l\'étudiant.');
    await wrapper.get('#dl-group').setValue('G1');
    await wrapper.get('#dl-person').setValue('Awa');
    await wrapper.get('#dl-person').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await wrapper.findAll('[data-test="designate"] .hi-list button')[0]!.trigger('click');
    await wrapper.get('[data-test="designate"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/teaching/group-delegates')?.body).toEqual({ group_id: 'G1', person_id: 'P-AWA' });
    await wrapper.get('[data-delegate="DL1"] [data-test="remove"]').trigger('click');
    await flushPromises();
    expect(find(calls, 'DELETE', '/higher/teaching/group-delegates/DL1')).toBeDefined();
  });

  it('met en page la semaine d\'un groupe avec le nom des enseignants et lance l\'impression', async () => {
    setup(['higher.access', 'higher.timetable.read'], {
      'GET /higher/timetable': { entries: [{ id: 'T1', slot_id: 'SL1', assignment_id: 'AS1', school_id: 'S1', group_id: 'G1', group_ids: ['G1'], element_id: 'EL1', element_title: 'Comptabilité générale', person_id: 'P-TEACH', room_id: 'R1', weekday: 1, start_time: '08:00', end_time: '10:00', day_period: null, session_kind: 'cm', valid_from: '2026-10-01', valid_to: null, headcount: 40 }], changes: [] },
      'GET /higher/directory/names': names([['P-TEACH', 'Joseph', 'Ateba', null]]),
    });
    const print = vi.fn();
    vi.stubGlobal('print', print);
    const { wrapper } = await mountAt(TimetablePrintView, '/superieur/enseignement/emploi-du-temps/impression');
    expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);
    await wrapper.get('#tp-group').setValue('G1');
    await flushPromises();
    const slot = wrapper.get('[data-test="slot"]').text();
    expect(slot).toContain('Comptabilité générale');
    expect(slot).toContain('Joseph Ateba');
    expect(slot).toContain('A101');
    await wrapper.get('[data-test="print"]').trigger('click');
    expect(print).toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});

const jury = (over: Record<string, unknown> = {}) => ({ data: {
  id: 'J1', school_id: 'S1', program_id: 'PR1', level_id: 'L1', structure_id: 'G1', academic_year_id: 'Y1', period_id: 'S1P', period_label: 'S1', exam_session: 'normal',
  regime: 'lmd', president_person_id: 'P-PRES', quorum_min: 1, agenda: null, status: 'simulated', deliberation_ref: null, minutes_fingerprint: null, minutes_version: 0,
  signed_at: null, published_at: null, reopen_count: 0, created_at: '2026-10-01', updated_at: '2026-10-01',
  members: [{ person_id: 'P-PRES', name: null, member_role: 'president', present: true, signed: false }], present_count: 1, quorum_reached: true, ...over,
} });
const sitting = (over: Record<string, unknown> = {}) => ({ data: {
  id: 'DLB1', structure_id: 'G1', period_id: 'S1P', exam_session: 'normal', period_label: 'S1', status: 'draft', president_id: 'P-PRES', members_expected: 1, members_present: ['P-PRES'],
  quorum_reached: true, minutes_fingerprint: null, minutes_version: 0, reopen_reason: null, closed_at: null, lines: [], ...over,
} });
const DEL_PERMS = ['higher.access', 'higher.deliberation.read', 'higher.deliberation.prepare'];

describe('Séance de délibération du socle', () => {
  it('ouvre la séance (création, présents, démarrage) puis renvoie vers la 2FA à la clôture', async () => {
    const calls = setup(DEL_PERMS, {
      'GET /higher/deliberation/juries/J1': jury(),
      'GET /deliberations': page([]),
      'POST /deliberations': sitting(),
      'POST /deliberations/DLB1/attendance': sitting(),
      'POST /deliberations/DLB1/start': sitting({ status: 'open' }),
      'POST /deliberations/DLB1/close': problem(403, 'Opération sensible : confirmez votre double authentification puis recommencez.'),
    });
    const { wrapper } = await mountAt(SittingView, '/superieur/deliberation/jurys/J1/seance', { id: 'J1' });
    expect(wrapper.find('[data-test="no-sitting"]').exists()).toBe(true);
    await wrapper.get('[data-test="open"]').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/deliberations')?.body).toEqual({ structure_id: 'G1', period_id: 'S1P', period_label: 'S1', exam_session: 'normal', president_id: 'P-PRES', members_expected: 1 });
    expect(find(calls, 'POST', '/deliberations/DLB1/attendance')?.body).toEqual({ members_present: ['P-PRES'] });
    expect(wrapper.get('[data-test="status"]').text()).toBe('En séance');
    await wrapper.get('[data-test="close"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="tfa"]').exists()).toBe(true);
    expect(wrapper.find('.notice--error').exists()).toBe(false);
  });

  it('exige un motif d\'au moins 10 caractères pour rouvrir', async () => {
    const calls = setup(DEL_PERMS, {
      'GET /higher/deliberation/juries/J1': jury({ status: 'closed', deliberation_ref: 'DLB1' }),
      'GET /deliberations/DLB1': sitting({ status: 'closed', minutes_fingerprint: 'abcdef0123456789abcdef', minutes_version: 1 }),
      'POST /deliberations/DLB1/reopen': sitting({ status: 'open', reopen_reason: 'Erreur de saisie en UE 3' }),
    });
    const { wrapper } = await mountAt(SittingView, '/superieur/deliberation/jurys/J1/seance', { id: 'J1' });
    await wrapper.get('#st-reason').setValue('Erreur');
    await wrapper.get('[data-test="reopen"]').trigger('submit');
    expect(wrapper.text()).toContain('au moins 10 caractères');
    expect(find(calls, 'POST', '/deliberations/DLB1/reopen')).toBeUndefined();
    await wrapper.get('#st-reason').setValue('Erreur de saisie en UE 3');
    await wrapper.get('[data-test="reopen"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/deliberations/DLB1/reopen')?.body).toEqual({ reason: 'Erreur de saisie en UE 3' });
    expect(wrapper.get('[data-test="done"]').text()).toBe('Séance rouverte.');
  });

  it('la fiche du jury relie la séance et nomme les membres', async () => {
    setup(DEL_PERMS, { 'GET /higher/deliberation/juries/J1': jury(), 'GET /higher/directory/names': names([['P-PRES', 'Jean', 'Mbarga', null]]) });
    const { wrapper } = await mountAt(JuryView, '/superieur/deliberation/jurys/J1', { id: 'J1' });
    expect(wrapper.find('[data-test="sitting-link"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Jean Mbarga');
  });
});

describe('useHigherNames', () => {
  it('regroupe les demandes par lots de 100 et ne redemande jamais un identifiant connu', async () => {
    setup(['higher.access']);
    const calls = mockApi({ 'GET /higher/directory/names': (c: { query: Record<string, string> }) => names((c.query.ids ?? '').split(',').map((id) => [id, 'P', id, null] as [string, string, string, null])) });
    const ids = Array.from({ length: 150 }, (_, i) => `P${i}`);
    await Promise.all([requestNames(ids.slice(0, 80)), requestNames(ids.slice(60))]);
    const first = calls.filter((c) => c.path === '/higher/directory/names');
    expect(first).toHaveLength(2);
    expect(first[0]!.query.ids!.split(',')).toHaveLength(100);
    await requestNames(['P1', 'P149']);
    expect(calls.filter((c) => c.path === '/higher/directory/names')).toHaveLength(2);
  });
});
