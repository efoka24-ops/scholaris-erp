// Lots E3-A et E3-D : lecture des tableaux du bulletin, valeurs propres des lignes du conseil, menu (conseils, bulletins, orientation),
// textes fr/en alignés.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import ClassCouncilView from '@/views/secondary/councils/ClassCouncilView.vue';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';
import { cellText, lineExtras, parseDocTable, rowCells } from '@/api/secondaryCouncils';
import { secondaryCouncilsFr } from '@/i18n/secondary-councils-fr';
import { secondaryCouncilsEn } from '@/i18n/secondary-councils-en';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import { secondaryRoutes } from '@/views/secondary/routes';

describe('conseil de classe et bulletins du secondaire', () => {
  it('relit un tableau du bulletin, refuse une valeur mal formée', () => {
    const table = parseDocTable(JSON.stringify({ columns: [{ label: '' }], rows: [{ kind: 'total', cells: ['TOTAL 1', { text: '11,30', colspan: 2 }] }, ['Maths', '7,75']] }));
    expect(table).not.toBeNull();
    expect(rowCells(table!.rows[0]!).kind).toBe('total');
    expect(rowCells(table!.rows[1]!).cells.map(cellText)).toEqual(['Maths', '7,75']);
    expect(cellText({ text: '11,30', colspan: 2 })).toBe('11,30');
    expect(parseDocTable('texte libre')).toBeNull();
    expect(parseDocTable(JSON.stringify({ rows: [] }))).toBeNull();
  });

  it('décode les valeurs propres en objet ou en texte JSON', () => {
    expect(lineExtras({ line_extras: { distinction_refused: true } }).distinction_refused).toBe(true);
    expect(lineExtras({ line_extras: '{"subject_alerts":["m"]}' }).subject_alerts).toEqual(['m']);
    expect(lineExtras({ line_extras: null })).toEqual({});
    expect(lineExtras({ line_extras: '{' })).toEqual({});
  });

  it('le menu suit les droits et le module orientation', () => {
    const hasRoute = (): boolean => true;
    const censor = visibleEntries(null, (...p) => p.includes('secondary.council.prepare'), hasRoute, MENU).map((e) => e.name);
    expect(censor).toEqual(expect.arrayContaining(['sec-councils', 'sec-report-cards']));
    const teacher = visibleEntries(null, (...p) => p.includes('secondary.reportcard.read'), hasRoute, MENU).map((e) => e.name);
    expect(teacher).toContain('sec-report-cards');
    expect(teacher).not.toContain('sec-councils');
    const withoutModule = visibleEntries(null, (...p) => p.includes('secondary.orientation.manage'), hasRoute, MENU).map((e) => e.name);
    expect(withoutModule).not.toContain('sec-orientation');
  });

  it('chaque entrée du menu est unique et les écrans sont routés', () => {
    const names = MENU.map((e) => e.name);
    expect(new Set(names).size).toBe(names.length);
    const routed = secondaryRoutes.map((r) => r.name);
    expect(routed).toEqual(expect.arrayContaining(['sec-councils', 'sec-report-cards', 'sec-orientation']));
  });

  it('les textes existent dans les deux langues', () => {
    expect(Object.keys(secondaryCouncilsEn).sort()).toEqual(Object.keys(secondaryCouncilsFr).sort());
  });
});

// C-118 : la liste des périodes du conseil affiche « Trimestre 1 », plus « trimester 1 ».
describe('écran Conseil de classe : périodes lisibles', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });

  it('nomme le trimestre et l’envoie comme libellé du conseil', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_censeur' } }, [tile({ roles: [{ code: 'sec_censeur', label: 'Censeur' }], permissions: ['secondary.council.prepare'] })], 'sec_censeur');
    const calls = mockApi({
      'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
      'GET /secondary/councils/report-card-settings': { data: {} },
      'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /periods': page([{ id: 'T1', academic_year_id: 'y1', kind: 'trimester', ordinal: 1, parent_id: null, starts_on: '2026-09-01', ends_on: '2026-12-20' }, { id: 'S1', academic_year_id: 'y1', kind: 'sequence', ordinal: 1, parent_id: 'T1', starts_on: '2026-09-01', ends_on: '2026-10-15' }]),
      'GET /class-councils': page([]),
      'POST /class-councils': { data: { id: 'cc1', status: 'open', period_label: 'Trimestre 1', lines: [] } },
    });
    const { wrapper } = await mountView(ClassCouncilView);
    await flushPromises();
    const options = wrapper.findAll('option').map((o) => o.text());
    expect(options).toContain('Trimestre 1');
    expect(options.join(' ')).not.toMatch(/trimester|Séquence/);
    await wrapper.findAll('button').find((b) => b.text() === secondaryCouncilsFr['secc.open'])?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/class-councils')?.body).toMatchObject({ period_label: 'Trimestre 1' });
  });
});

// C-118 : « Pour tout le niveau » publie aussi, sur chaque classe d'une série à coefficients propres, sa version de règles.
describe('écran Conseil de classe : règles du niveau et séries à coefficients propres', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });

  it('publie la version de la classe de série C, sans version du niveau quand le niveau n’a pas de coefficient', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_censeur' } }, [tile({ roles: [{ code: 'sec_censeur', label: 'Censeur' }], permissions: ['secondary.council.prepare'] })], 'sec_censeur');
    const line = { subject_id: 'm1', code: 'MATH', label: 'Mathématiques', coefficient: 5, role: 'official', counts_in_average: true, version_no: 1, origin: 'establishment' };
    const calls = mockApi({
      'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
      'GET /secondary/councils/report-card-settings': { data: {} },
      'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: 'stc', exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /periods': page([]),
      'GET /class-councils': page([]),
      'GET /secondary/councils/rule-draft': { data: {
        class_id: 's2a', academic_year_id: 'y1', level_code: '2NDE', stream_id: null, scope: 'level', structure_id: null, label: 'Règles 2NDE', from_example: true, example_notice: 'exemple',
        coefficients: [], definition: { strategy: 'secondary' },
        stream_overrides: [{ structure_id: 's2a', class_label: '2nde A', stream_id: 'stc', label: 'Règles 2nde A (série propre)', coefficients: [line], definition: { strategy: 'secondary', strategy_options: { coefficients: { m1: { coefficient: 5 } } } } }],
      } },
      'POST /calculation-rules': { data: { id: 'r1' } },
    });
    const { wrapper } = await mountView(ClassCouncilView);
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === secondaryCouncilsFr['secc.prepareDraft'])?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('2nde A');
    expect(wrapper.text()).toContain('Mathématiques');
    await wrapper.findAll('button').find((b) => b.text() === secondaryCouncilsFr['secc.publishRules'])?.trigger('click');
    await flushPromises();
    const posts = calls.filter((c) => c.method === 'POST' && c.path === '/calculation-rules');
    expect(posts.map((p) => (p.body as { structure_id: string }).structure_id)).toEqual(['s2a']);
    expect(wrapper.text()).toContain(secondaryCouncilsFr['secc.rulesPublished']);
  });
});
