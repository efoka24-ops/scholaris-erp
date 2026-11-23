import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import GradesView from '@/views/secondary/grades/GradesView.vue';
import { periodName } from '@/views/secondary/periodName';
import { useI18n } from '@/i18n';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';
import { letterFor, nextStage, onStep } from '@/api/secondaryGrades';
import { secondaryGradesEn } from '@/i18n/secondary-grades-en';
import { secondaryGradesFr } from '@/i18n/secondary-grades-fr';
import { MENU } from '@/views/secondary/menu';
import { S } from '@/views/secondary/access';

// Notes du secondaire (lot E2-C) : contrôles d'écran identiques au serveur, cycle proposé, lettres [EN], menu et textes bilingues.
describe('notes du secondaire', () => {
  it('contrôle le pas de saisie comme le serveur', () => {
    expect(onStep(12.25, 0.25)).toBe(true);
    expect(onStep(12.3, 0.25)).toBe(false);
    expect(onStep(12.37, null)).toBe(true);
    expect(onStep(12.375, null)).toBe(false);
  });

  it('propose l\'étape suivante du cycle', () => {
    expect(['draft', 'submitted', 'validated', 'locked', 'published'].map(nextStage)).toEqual(['submitted', 'validated', 'locked', 'published', null]);
    expect(nextStage('not_evaluated')).toBeNull();
  });

  it('donne une lettre seulement si l\'établissement a une table', () => {
    expect(letterFor(15, [])).toBeNull();
    const bands = [{ min: 10, letter: 'C' }, { min: 16, letter: 'A' }, { min: 13, letter: 'B' }];
    expect([letterFor(17, bands), letterFor(13, bands), letterFor(9.5, bands), letterFor(null, bands)]).toEqual(['A', 'B', null, null]);
  });

  it('ajoute l\'entrée Notes au menu avec les droits de notes', () => {
    const entry = MENU.find((e) => e.name === 'sec-grades');
    expect(entry?.perms).toEqual(S.grades);
    expect(S.grades).toContain('secondary.grades.enter');
  });

  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(secondaryGradesEn).sort()).toEqual(Object.keys(secondaryGradesFr).sort());
  });
});

// C-118 : périodes lisibles (« Trimestre 1 », « Séquence 2 ») et « Soumettre au censeur » seulement si le cycle des notes est préréglé.
describe('écran Notes : périodes lisibles et cycle préréglé', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });
  const settings = (lifecycle: boolean): Record<string, unknown> => ({
    values: {}, configured: { 'assessment.lifecycle': lifecycle }, origin_of_examples: 'exemple', step: 0.25, absence_codes: [], offline_entry: false, letter_scale: [], lock_requires_second_factor: false,
  });
  const world = (lifecycle: boolean): ReturnType<typeof mockApi> => mockApi({
    'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
    'GET /periods': page([
      { id: '01T1', kind: 'trimester', ordinal: 1, parent_id: null },
      { id: '01S1', kind: 'sequence', ordinal: 1, parent_id: '01T1' },
      { id: '01S2', kind: 'sequence', ordinal: 2, parent_id: '01T1' },
    ]),
    'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null }]),
    'GET /secondary/grades/sheet': { data: { class: { id: 's2a', label: '2nde A', academic_year_id: 'y1', level_code: '2NDE', stream_id: null, section_code: 'fr' }, period_id: '01S1', learners: [], subjects: [{ subject_id: 'm1', local_subject_id: 'l1', code: 'MATH', label: 'Mathématiques', coefficient: 5, role: 'official', counts_in_average: true, can_enter: true }], settings: settings(lifecycle) } },
    'GET /grade-sheets': { data: { columns: [{ id: 'a1', subject_id: 'm1', title: 'Devoir 1', kind: 'test', weight: 1, max_score: 20, status: 'draft', grading_mode: 'numeric', anonymous: false }], rows: [], counters: { errors: 0, missing: 0, absent_justified: 0 }, rules_version: 1 } },
    'POST /secondary/grades/entry-checks': { data: { allowed: true, role: 'teacher', offline_entry: false } },
  });
  const teacher = (): void => signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'enseignant' } }, [tile({ roles: [{ code: 'enseignant', label: 'Enseignant' }], permissions: ['secondary.grades.enter', 'secondary.grades.read'] })], 'enseignant');

  it('nomme les périodes par leur nature et leur rang', () => {
    const { t } = useI18n();
    expect(periodName({ id: 'x', kind: 'trimester', ordinal: 1 }, t)).toBe('Trimestre 1');
    expect(periodName({ id: 'x', kind: 'sequence', ordinal: 2 }, t)).toBe('Séquence 2');
    expect(periodName({ id: 'x', label: 'Examen blanc' }, t)).toBe('Examen blanc');
    expect(periodName({ id: '01ABC' }, t)).toBe('01ABC');
  });

  it('liste « Séquence 1 », « Séquence 2 » et propose la soumission quand le cycle est préréglé', async () => {
    teacher();
    world(true);
    const { wrapper } = await mountView(GradesView);
    await flushPromises();
    const options = wrapper.get('#secg-period').findAll('option').map((o) => o.text());
    expect(options).toEqual(expect.arrayContaining(['Séquence 1', 'Séquence 2']));
    expect(options.join(' ')).not.toContain('01S1');
    await wrapper.get('#secg-class').setValue('s2a');
    await wrapper.get('#secg-period').setValue('01S1');
    await flushPromises();
    expect(wrapper.text()).toContain('Soumettre au censeur');
  });

  it('sans cycle préréglé : pas de « Soumettre au censeur », message et lien vers le préréglage', async () => {
    teacher();
    world(false);
    const { wrapper } = await mountView(GradesView);
    await flushPromises();
    await wrapper.get('#secg-class').setValue('s2a');
    await wrapper.get('#secg-period').setValue('01S1');
    await flushPromises();
    expect(wrapper.text()).not.toContain('Soumettre au censeur');
    expect(wrapper.text()).toContain(secondaryGradesFr['secg.cycleMissing']);
    expect(wrapper.text()).toContain(secondaryGradesFr['secg.cycleAskHead']);
    expect(wrapper.find('[data-test="cycle-preset-link"]').exists()).toBe(false);
  });

  it('sans cycle préréglé, le chef d’établissement va au préréglage en un clic', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef_etablissement' } }, [tile({ roles: [{ code: 'sec_chef_etablissement', label: 'Chef' }], permissions: ['secondary.grades.read', 'secondary.settings.manage'] })], 'sec_chef_etablissement');
    const calls = world(false);
    const { wrapper } = await mountView(GradesView);
    await flushPromises();
    await wrapper.get('#secg-class').setValue('s2a');
    await wrapper.get('#secg-period').setValue('01S1');
    await flushPromises();
    await wrapper.get('[data-test="cycle-preset-link"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'GET' && c.path === '/secondary/grades/settings')).toBe(true);
  });
});
