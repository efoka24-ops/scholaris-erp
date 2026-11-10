// Lot E2-A (front) : aides de l'emploi du temps et du cahier de textes, entrées de menu, textes FR/EN complets.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import TimetableView from '@/views/secondary/timetable/TimetableView.vue';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';
import { byDay, formatRate, newClientUuid, type Entry } from '@/api/secondaryTimetable';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import { secondaryTimetableFr } from '@/i18n/secondary-timetable-fr';
import { secondaryTimetableEn } from '@/i18n/secondary-timetable-en';

const entry = (id: string, weekday: number, start: string): Entry => ({
  id, academic_year_id: 'y', slot_id: 's', assignment_id: 'a', class_id: 'c', group_code: null, subject_ref: 'm', subject_label: 'Maths',
  teacher_person_id: 'p', room_id: null, weekday, start_time: start, end_time: start, valid_from: '2026-09-07', valid_to: null, origin: 'manual',
});

describe('emploi du temps et cahier de textes du secondaire', () => {
  it('groupe les créneaux par jour, lundi d’abord, dans l’ordre des heures', () => {
    const days = byDay([entry('3', 3, '10:00'), entry('1', 1, '09:00'), entry('2', 1, '07:30')]);
    expect(days.map((d) => d.weekday)).toEqual([1, 3]);
    expect(days[0]?.entries.map((e) => e.id)).toEqual(['2', '1']);
  });

  it('affiche le taux de couverture en pourcentage, ou un tiret sans progression', () => {
    expect(formatRate(0.425)).toContain('42,5');
    expect(formatRate(null)).toBe('—');
  });

  it('crée un identifiant de séance hors ligne accepté par le serveur', () => {
    const uuid = newClientUuid();
    expect(uuid).toMatch(/^[A-Za-z0-9-]{8,40}$/);
    expect(newClientUuid()).not.toBe(uuid);
  });

  it('montre les entrées au censeur et à l’enseignant, pas au parent', () => {
    const names = (perms: string[]) => visibleEntries(null, (...p) => p.some((x) => perms.includes(x)), () => true, MENU).map((e) => e.name);
    expect(names(['secondary.timetable.manage'])).toContain('sec-timetable');
    expect(names(['secondary.lesson.log'])).toEqual(expect.arrayContaining(['sec-timetable', 'sec-syllabus']));
    expect(names(['secondary.inspection.read'])).toContain('sec-syllabus');
    expect(names(['secondary.family.read'])).not.toContain('sec-syllabus');
  });

  it('a chaque texte en français et en anglais', () => {
    expect(Object.keys(secondaryTimetableEn).sort()).toEqual(Object.keys(secondaryTimetableFr).sort());
  });
});

// C-118 : l'affectation choisit l'enseignant dans une liste de noms (GET /secondary/timetable/teachers), jamais un identifiant brut.
describe('écran Emploi du temps : enseignant choisi dans une liste', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });

  it('propose les enseignants par leur nom et envoie l’identifiant choisi', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_censeur' } }, [tile({ roles: [{ code: 'sec_censeur', label: 'Censeur' }], permissions: ['secondary.timetable.manage', 'secondary.timetable.read'] })], 'sec_censeur');
    const created = { id: 'as1', academic_year_id: 'y1', class_id: 's2a', group_code: null, subject_ref: 'm1', subject_code: 'MATH', subject_label: 'Mathématiques', teacher_person_id: 'p-t1', teacher_kind: 'permanent', room_kind: 'classroom', room_id: null, status: 'active' };
    const calls = mockApi({
      'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
      'GET /secondary/setup/subjects': page([{ id: 'm1', code: 'MATH', label: 'Mathématiques' }]),
      'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /secondary/timetable/assignments': page([]),
      'GET /secondary/timetable/bands': { data: { bands: [] } },
      'GET /secondary/timetable/changes': page([]),
      'GET /secondary/timetable': { data: { entries: [] } },
      'GET /secondary/timetable/teachers': { data: [{ person_id: 'p-t1', name: 'Paul Fictif-Professeur', grade: 'Professeur certifié', teaches: true, assigned: false }] },
      'POST /secondary/timetable/assignments': { data: created },
    });
    const { wrapper } = await mountView(TimetableView);
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === secondaryTimetableFr['sectt.tab.assignments'])?.trigger('click');
    await flushPromises();
    const teacher = wrapper.get('#as-teacher');
    expect(teacher.element.tagName).toBe('SELECT');
    expect(teacher.text()).toContain('Paul Fictif-Professeur · Professeur certifié');
    await wrapper.get('#as-class').setValue('s2a');
    await wrapper.get('#as-subject').setValue('m1');
    await teacher.setValue('p-t1');
    await wrapper.get('#as-teacher').element.closest('form')?.dispatchEvent(new Event('submit'));
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/secondary/timetable/assignments')?.body).toMatchObject({ teacher_person_id: 'p-t1', class_id: 's2a', subject_ref: 'm1' });
    expect(wrapper.text()).toContain('2nde A · Mathématiques · Paul Fictif-Professeur');
    expect(wrapper.text()).not.toContain('· p-t1');
  });
});
