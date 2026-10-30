import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import AssiduityView from '@/views/higher/teaching/AssiduityView.vue';
import ReplacementsView from '@/views/higher/teaching/ReplacementsView.vue';
import DisciplineView from '@/views/higher/teaching/DisciplineView.vue';
import { flushPromises, freshEnvironment, mountView } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const curriculumRoutes = {
  'GET /higher/curricula': page([{ id: 'C1', title: 'Licence 1 Informatique', version: 1, status: 'published', level_id: 'L1' }]),
  'GET /higher/curricula/C1': { data: { id: 'C1', semesters: [{ id: 'SM1', ordinal: 1, label: 'S1', units: [{ id: 'U1', elements: [{ id: 'E1', code: 'INF101', title: 'Algorithmique' }] }] }], check: { errors: [], warnings: [], semesters: [] } } },
};
const students = [
  { person_id: 'P1', matricule: 'IUC26-001', enrolment_id: 'IN1', sessions: 20, unjustified: 7, rate_percent: 35, status: 'barred', absences: 8, lates: 1 },
  { person_id: 'P2', matricule: 'IUC26-002', enrolment_id: 'IN2', sessions: 20, unjustified: 0, rate_percent: 0, status: 'ok', absences: 0, lates: 0 },
];

async function pickTarget(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']): Promise<void> {
  await wrapper.get('#as-grp').setValue('G1');
  await wrapper.get('#as-el-cur').setValue('C1');
  await flushPromises();
  await wrapper.get('#as-el-el').setValue('E1');
  await wrapper.get('#as-year').setValue('Y1');
  await wrapper.get('form').trigger('submit');
  await flushPromises();
}

describe('IPES assiduité', () => {
  it('affiche les taux, transmet la liste et lève une interdiction', async () => {
    const calls = setup(['higher.attendance.read', 'attendance.justify', 'higher.enrolment.derogate'], {
      ...curriculumRoutes,
      'GET /higher/attendance/assiduity': { element_id: 'E1', group_id: 'G1', max_percent: 30, alert_percent: 25, students },
      'GET /higher/attendance/barred-lists': page([]),
      'POST /higher/attendance/barred-lists': { id: 'BL1', group_id: 'G1', barred_count: 1, entries: [], transmitted_at: '2026-10-15' },
      'POST /higher/attendance/barred-lifts': { id: 'LF1' },
    });
    const { wrapper } = await mountView(AssiduityView);
    await pickTarget(wrapper);
    expect(find(calls, 'GET', '/higher/attendance/assiduity')?.query).toMatchObject({ element_id: 'E1', group_id: 'G1', academic_year_id: 'Y1' });
    expect(wrapper.findAll('[data-test="assiduity-row"]')).toHaveLength(2);
    expect(wrapper.text()).toContain('Non autorisé à composer');
    expect(wrapper.text()).toContain('au-delà de 30 %');

    await wrapper.findAll('button').find((b) => b.text() === 'Transmettre au service des examens')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/attendance/barred-lists')?.body).toEqual({ element_id: 'E1', group_id: 'G1', academic_year_id: 'Y1' });

    await wrapper.findAll('button').find((b) => b.text() === 'Lever l’interdiction')!.trigger('click');
    await wrapper.get('#lift-reason').setValue('Hospitalisation attestée');
    await wrapper.get('[data-test="lift"] .btn--ink').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/attendance/barred-lifts')?.body).toMatchObject({ person_id: 'P1', element_id: 'E1', reason: 'Hospitalisation attestée' });
  });
  it('affiche l’erreur de validation (422)', async () => {
    setup(['higher.attendance.read'], { ...curriculumRoutes, 'GET /higher/attendance/barred-lists': page([]), 'GET /higher/attendance/assiduity': problem(422, 'Paramètres manquants.') });
    const { wrapper } = await mountView(AssiduityView);
    await pickTarget(wrapper);
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.findAll('button').some((b) => b.text() === 'Transmettre au service des examens')).toBe(false);
  });
});

describe('IPES remplacements et discipline', () => {
  it('programme une épreuve de remplacement', async () => {
    const rep = { id: 'RP1', person_id: 'P1', element_id: 'E1', group_id: 'G1', academic_year_id: 'Y1', absence_date: '2026-10-01', justification_ref: 'CM-12', reason: 'Malade', exam_date: null, start_time: null, end_time: null, room_id: null, status: 'requested' };
    const calls = setup(['higher.exam.manage'], { 'GET /higher/attendance/replacement-exams': page([rep]), 'POST /higher/attendance/replacement-exams/RP1/schedule': { ...rep, status: 'scheduled' } });
    const { wrapper } = await mountView(ReplacementsView);
    expect(wrapper.text()).toContain('Demandée');
    await wrapper.findAll('button').find((b) => b.text() === 'Programmer')!.trigger('click');
    await wrapper.get('#rs-date').setValue('2026-11-05');
    await wrapper.get('[data-test="schedule-replacement"] .btn--ink').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/attendance/replacement-exams/RP1/schedule')?.body).toEqual({ exam_date: '2026-11-05', start_time: '08:00', end_time: '10:00', room_id: null });
  });
  it('liste les sanctions puis signe le PV d’un conseil', async () => {
    const council = { id: 'CD1', person_id: 'P1', reason: 'Fraude', incident_ref: null, hearing_date: '2026-10-20', members: ['A', 'B', 'C'], minutes_text: 'PV', decision: 'permanent_exclusion', decision_reason: 'Fraude avérée', exclusion_from: null, exclusion_to: null, status: 'held' };
    const calls = setup(['attendance.discipline.manage', 'higher.council.manage'], {
      'GET /higher/discipline/sanctions': page([{ id: 'SA1', person_id: 'P2', sanction_kind: 'warning', reason: 'Retards', starts_on: null, ends_on: null, council_id: null, status: 'effective', document_number: null, decided_at: '2026-10-10' }]),
      'GET /higher/discipline/councils': page([council]),
      'POST /higher/discipline/councils/CD1/sign': { ...council, status: 'signed', enrolment_excluded: false, exclusion_pending: true },
    });
    const { wrapper } = await mountView(DisciplineView);
    expect(wrapper.text()).toContain('Avertissement');
    expect(wrapper.findAll('#sa-kind option').map((o) => o.text())).not.toContain('Exclusion définitive');
    await wrapper.get('[data-tab="councils"]').trigger('click');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Signer le PV')!.trigger('click');
    await wrapper.get('[data-test="sign-council"] .btn--ink').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/discipline/councils/CD1/sign')).toBeDefined();
  });
});
