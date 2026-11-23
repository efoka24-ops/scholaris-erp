import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import TeachersView from '@/views/higher/teaching/TeachersView.vue';
import RoomsView from '@/views/higher/teaching/RoomsView.vue';
import AssignmentsView from '@/views/higher/teaching/AssignmentsView.vue';
import TimetableView from '@/views/higher/teaching/TimetableView.vue';
import ConsumptionView from '@/views/higher/teaching/ConsumptionView.vue';
import SessionLogsView from '@/views/higher/teaching/SessionLogsView.vue';
import HoursView from '@/views/higher/teaching/HoursView.vue';
import ExamsView from '@/views/higher/teaching/ExamsView.vue';
import { higherTeachingRoutes } from '@/views/higher/teaching/routes';
import { higherTeachingFr } from '@/i18n/higher-teaching-fr';
import { higherTeachingEn } from '@/i18n/higher-teaching-en';
import { flushPromises, freshEnvironment, mountView } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const teacher = { id: 'T1', person_id: 'P-VAC', staff_id: null, school_id: 'S1', department_id: null, teacher_status: 'vacataire', grade: 'Assistant', diplomas: ['Master'], origin_establishment: 'Université de Douala', public_servant: true, employer_authorization_ref: null, employer_authorization_document: null, employer_authorization_until: null };
const assignment = { id: 'A1', school_id: 'S1', department_id: 'D1', program_id: 'PR1', level_id: 'L1', group_id: 'G1', academic_year_id: 'Y1', curriculum_id: 'C1', element_id: 'E1', element_code: 'INF101', element_title: 'Algorithmique', person_id: 'P-TEACH', staff_id: null, session_kind: 'cm', planned_minutes: 1800, status: 'active' };
const entry = { id: 'EN1', slot_id: null, assignment_id: 'A1', school_id: 'S1', group_id: 'G1', group_ids: ['G1'], element_id: 'E1', element_title: 'Algorithmique', person_id: 'P-TEACH', room_id: 'R1', weekday: 1, start_time: '08:00', end_time: '10:00', day_period: 'day', session_kind: 'cm', valid_from: '2026-09-01', valid_to: null, headcount: 35 };

describe('IPES enseignement : routes et textes', () => {
  it('déclare les routes sous /superieur, gardées et réservées aux IPES', () => {
    expect(higherTeachingRoutes).toHaveLength(11);
    for (const r of higherTeachingRoutes) {
      expect(r.path.startsWith('/superieur/')).toBe(true);
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
      expect(r.meta?.perms?.length).toBeGreaterThan(0);
    }
  });
  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(higherTeachingEn).sort()).toEqual(Object.keys(higherTeachingFr).sort());
  });
});

describe('IPES enseignants et salles', () => {
  it('liste les dossiers et signale une autorisation d’employeur manquante', async () => {
    setup(['staff.manage'], { 'GET /higher/teaching/teachers': page([teacher]) });
    const { wrapper } = await mountView(TeachersView);
    expect(wrapper.text()).toContain('Vacataire');
    expect(wrapper.find('[data-test="auth-missing"]').exists()).toBe(true);
    expect(wrapper.find('form').exists()).toBe(true);
  });
  it('affiche l’erreur RFC 9457 avec sa trace', async () => {
    setup(['staff.read'], { 'GET /higher/teaching/teachers': problem(500, 'Panne du serveur.') });
    const { wrapper } = await mountView(TeachersView);
    expect(wrapper.find('[role="alert"]').text()).toContain('TR-HT');
    expect(wrapper.find('form').exists()).toBe(false); // lecture seule : pas de formulaire
  });
  it('crée une salle', async () => {
    const calls = setup(['scheduling.manage'], { 'POST /higher/teaching/rooms': { id: 'R2', school_id: 'S1', code: 'B2', name: 'Salle B2', capacity: 30, active: true } });
    const { wrapper } = await mountView(RoomsView);
    await wrapper.get('#rm-school').setValue('S1');
    await wrapper.get('#rm-code').setValue('B2');
    await wrapper.get('#rm-name').setValue('Salle B2');
    await wrapper.get('#rm-cap').setValue('30');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/teaching/rooms')?.body).toEqual({ school_id: 'S1', code: 'B2', name: 'Salle B2', capacity: 30 });
  });
});

describe('IPES affectations', () => {
  it('liste et met fin à une affectation', async () => {
    const calls = setup(['higher.assignment.manage'], { 'GET /higher/teaching/assignments': page([assignment]), 'POST /higher/teaching/assignments/A1/end': { ...assignment, status: 'ended' } });
    const { wrapper } = await mountView(AssignmentsView);
    expect(wrapper.text()).toContain('INF101 · Algorithmique');
    expect(wrapper.text()).toContain('30 h');
    const end = wrapper.findAll('button').find((b) => b.text() === 'Mettre fin');
    await end!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/teaching/assignments/A1/end')).toBeDefined();
  });
  it('passe en lecture seule sur 423', async () => {
    setup(['higher.assignment.manage'], { 'GET /higher/teaching/assignments': page([assignment]), 'POST /higher/teaching/assignments/A1/end': problem(423, 'Abonnement suspendu.') });
    const { wrapper } = await mountView(AssignmentsView);
    await wrapper.findAll('button').find((b) => b.text() === 'Mettre fin')!.trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="readonly-banner"]').exists()).toBe(true);
    expect(wrapper.findAll('button').some((b) => b.text() === 'Mettre fin')).toBe(false);
  });
});

describe('IPES emploi du temps', () => {
  it('montre mon emploi du temps et renvoie à l’appel du socle', async () => {
    const calls = setup(['higher.session_log.write', 'attendance.take'], { 'GET /higher/timetable': { entries: [entry], changes: [] } });
    const { wrapper } = await mountView(TimetableView);
    const call = find(calls, 'GET', '/higher/timetable');
    expect(call?.query.person_id).toBe('P-TEACH');
    expect(wrapper.findAll('[data-test="tt-slot"]')).toHaveLength(1);
    expect(wrapper.get('[data-test="call-roll"]').attributes('href')).toBe('/appel');
  });
  it('vérifie les conflits d’un nouveau créneau', async () => {
    const calls = setup(['scheduling.manage', 'higher.timetable.read'], {
      'GET /higher/timetable/conflicts': { data: [{ kind: 'room', entry_ids: ['EN1', 'EN2'], message: 'Salle déjà occupée (lun. 08:00).' }] },
      'GET /higher/teaching/assignments': page([assignment]),
      'POST /higher/timetable/check': { conflicts: [{ kind: 'teacher', message: 'Enseignant déjà en cours.' }], headcount: 35, room_capacity: 120 },
    });
    const { wrapper } = await mountView(TimetableView);
    expect(wrapper.get('[data-test="conflicts"]').text()).toContain('Salle déjà occupée');
    await wrapper.get('#sl-asg').setValue('A1');
    await wrapper.findAll('button').find((b) => b.text() === 'Vérifier les conflits')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/timetable/check')?.body).toMatchObject({ assignment_id: 'A1', weekday: 1, start_time: '08:00', end_time: '10:00' });
    expect(wrapper.get('[data-test="slot-conflicts"]').text()).toContain('Enseignant déjà en cours.');
  });
  it('affiche la consommation des volumes', async () => {
    const row = { planned: 1800, scheduled: 1200, held: 600, logged: 600, remaining: 1200, overrun: 0 };
    setup(['higher.timetable.read'], { 'GET /higher/timetable/consumption': { data: [{ element_id: 'E1', element_code: 'INF101', element_title: 'Algo', group_id: 'G1', curriculum_id: 'C1', by_kind: { cm: row }, total: row }], meta: {} } });
    const { wrapper } = await mountView(ConsumptionView);
    expect(wrapper.findAll('[data-test="vol-row"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('30 h');
  });
});

describe('IPES cahier de textes, heures et examens', () => {
  const log = { id: 'LG1', assignment_id: 'A1', entry_id: null, group_id: 'G1', element_id: 'E1', person_id: 'P-TEACH', session_kind: 'cm', held_on: '2026-10-12', start_time: '08:00', end_time: '10:00', minutes: 120, content: 'Tris', homework: null, attendance_session_id: null, teacher_signed_at: null, countersigned_by: null, countersigner_role: null, countersigned_at: null, status: 'draft', validated_at: null };
  it('l’enseignant voit ses séances (mine=1) et signe', async () => {
    const calls = setup(['higher.session_log.write'], { 'GET /higher/session-logs': page([log]), 'GET /higher/teaching/assignments': page([assignment]), 'POST /higher/session-logs/LG1/sign': { ...log, status: 'signed' } });
    const { wrapper } = await mountView(SessionLogsView);
    expect(find(calls, 'GET', '/higher/session-logs')?.query.mine).toBe('1');
    await wrapper.findAll('button').find((b) => b.text() === 'Signer')!.trigger('click');
    await wrapper.get('[data-test="sign-log"] .btn--ink').trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/session-logs/LG1/sign')).toBeDefined();
  });
  it('valide un mois et signale les séances en attente', async () => {
    const calls = setup(['higher.hours.validate'], {
      'GET /higher/teaching/hours': { data: [{ person_id: 'P-TEACH', month: '2026-10', logs: 3, minutes: { cm: 240, td: 120, tp: 0, other: 0 }, by_status: { countersigned: 2, signed: 1 }, hetd_milli: 480000 }] },
      'GET /higher/teaching/hours-validations': page([]),
      'POST /higher/teaching/hours-validations': { validations: [], pending: [{ id: 'LG9', held_on: '2026-10-20', status: 'signed' }] },
    });
    const { wrapper } = await mountView(HoursView);
    await wrapper.findAll('button').find((b) => b.text() === 'Valider le mois')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/teaching/hours-validations')?.body?.person_id).toBe('P-TEACH');
    expect(wrapper.get('[data-test="hours-pending"]').text()).toContain('1 séance');
  });
  it('publie une épreuve', async () => {
    const exam = { id: 'X1', school_id: 'S1', group_id: 'G1', academic_year_id: 'Y1', element_id: 'E1', element_title: 'Algorithmique', exam_session: 'normal', exam_date: '2026-12-10', start_time: '08:00', end_time: '10:00', room_id: 'R1', supervisors: [], paper_ref: null, status: 'planned', convocations_count: 0 };
    const calls = setup(['higher.exam.manage'], { 'GET /higher/exam-schedules': page([exam]), 'GET /higher/curricula': page([]), 'POST /higher/exam-schedules/X1/publish': { ...exam, status: 'published' } });
    const { wrapper } = await mountView(ExamsView);
    expect(wrapper.text()).toContain('Planifiée');
    await wrapper.findAll('button').find((b) => b.text() === 'Publier')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/exam-schedules/X1/publish')).toBeDefined();
  });
});
