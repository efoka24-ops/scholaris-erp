import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import type { Council, CouncilLine, ReportCard, ReportSnapshot } from '@/api/primary';
import { routes } from '@/router/routes';
import { setLocale } from '@/i18n';
import BulletinCard from '@/views/primary/BulletinCard.vue';
import CouncilView from '@/views/primary/CouncilView.vue';
import FamilyView from '@/views/primary/FamilyView.vue';
import ReportCardsView from '@/views/primary/ReportCardsView.vue';
import ExamView from '@/views/primary/ExamView.vue';
import ClosureView from '@/views/primary/ClosureView.vue';
import { formatAverage, pickTrimester, sequencesOf } from '@/views/primary/usePicker';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });
const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-07', ends_on: '2027-06-11', status: 'open', closed_at: null };
const periods = [
  { id: 'P-T1', academic_year_id: 'Y1', kind: 'trimester', ordinal: 1, starts_on: '2026-09-07', ends_on: '2026-11-27' },
  { id: 'P-S1', academic_year_id: 'Y1', kind: 'sequence', ordinal: 1, starts_on: '2026-09-07', ends_on: '2026-10-12' },
  { id: 'P-T3', academic_year_id: 'Y1', kind: 'trimester', ordinal: 3, starts_on: '2027-03-08', ends_on: '2027-06-11' },
];
const structures = [
  { id: 'L-ID-1', org_unit_id: null, kind: 'level', name: 'CM2', capacity: null },
  { id: 'C-ID-1', org_unit_id: null, kind: 'class', name: 'CM2 A', capacity: 60 },
];
const line = (over: Partial<CouncilLine>): CouncilLine => ({
  id: 'LN-1', person_id: 'PER-ID-1', name: 'NOM Awa', average: 14.5, rank: 1, class_size: 2, subjects: [], annual_average: 13, annual_rank: 1, terms: [],
  absences_half_days: 3, unjustified_half_days: 1, lates: 0, conduct_code: 'good', competencies: { acquired: 2 }, appraisal: null, remediation_flag: false,
  remediation_note: null, repeater: false, proposal: 'admitted', decision: 'admitted', reason: null, ...over,
});
const council: Council = {
  id: 'CO-ID-1', class_structure_id: 'C-ID-1', level_structure_id: 'L-ID-1', academic_year_id: 'Y1', year_label: '2026-2027', period_id: 'P-T3',
  period_label: 'Trimestre 3', term_ordinal: 3, is_final_term: true, starts_on: '2027-03-08', ends_on: '2027-06-11', held_on: null, status: 'open',
  numeric_enabled: true, summary: { learners: 2, with_average: 2, class_average: 11.25, decisions: {}, remediation: 0, absences_half_days: 4 }, minutes: null, validated_at: null,
  class_name: 'CM2 A', show_rank: true,
  conduct_scale: [{ code: 'good', label_fr: 'Bonne', label_en: 'Good', sort_order: 1 }],
  competency_scale: [{ code: 'acquired', label_fr: 'Acquis', label_en: 'Acquired', sort_order: 1 }],
  decisions: [
    { code: 'admitted', label_fr: 'Admis(e) en classe supérieure', label_en: 'Promoted' },
    { code: 'adjourned', label_fr: 'Redouble', label_en: 'Repeats the class' },
    { code: 'cycle_end', label_fr: 'Fin de cycle', label_en: 'End of cycle' },
  ],
  lines: [line({}), line({ id: 'LN-2', person_id: 'PER-ID-2', name: 'NOM Bello', average: 8, rank: 2, proposal: 'adjourned', decision: 'adjourned' })],
};
const snapshot: ReportSnapshot = {
  kind: 'term', locale: 'fr', school: 'École fictive', class_name: 'CM2 A', level_name: 'CM2', academic_year: '2026-2027', period_label: 'Trimestre 1',
  learner: { person_id: 'PER-ID-1', name: 'NOM Awa' }, repeater: false,
  numeric: { scale: 20, average: 14.5, rank: null, class_size: null, subjects: [{ subject_id: 'S1', name: 'Lecture', code: 'LECT', average: 15 }] }, show_rank: false,
  competencies: [{ domain_id: 'D1', label: 'Langues', items: [{ competency_id: 'K1', code: 'LECT1', label: 'Lire un mot', value: 'acquired', value_label: 'Acquis' }] }],
  scale: [{ code: 'acquired', label: 'Acquis' }], attendance: { absences_half_days: 2, unjustified_half_days: 1, lates: 0 },
  conduct: { code: 'good', label: 'Bonne' }, appraisal: '<b>Très bien</b>', remediation: { flag: false, note: null, sessions: 2, minutes: 90, open_plans: ['Lecture'] },
  decision: null, is_final_term: false, number: 'EF-26-B-000001',
};
const card = (over: Partial<ReportCard> = {}): ReportCard => ({
  id: 'RC-ID-1', council_id: 'CO-ID-1', person_id: 'PER-ID-1', name: 'NOM Awa', class_structure_id: 'C-ID-1', period_id: 'P-T1', kind: 'term', locale: 'fr', revision: 1,
  status: 'issued', document_id: 'D1', number: 'EF-26-B-000001', verification_token: 'tok', error_message: null, issued_at: '2026-11-28', published_at: null, superseded_by: null, ...over,
});

function connect(permissions: string[], extra: Record<string, unknown> = {}): ReturnType<typeof mockApi> {
  signIn(teacherMe, [tile({ type: 'primary', permissions, roles: [{ code: 'primary_directeur', label: 'Directeur' }] })], 'primary_directeur');
  return mockApi({
    'GET /academic-years': page([year]),
    'GET /periods': page(periods),
    'GET /structures': page(structures),
    ...extra,
  } as Parameters<typeof mockApi>[0]);
}

beforeEach(() => freshEnvironment());

describe('routes du lot P-002', () => {
  it('chaque écran est réservé aux établissements primaires et à ses permissions', () => {
    const expected: Record<string, [string, string]> = {
      'pri-council': ['/primaire/conseils', 'primary.council.read'], 'pri-cards': ['/primaire/bulletins', 'primary.report_card.read'],
      'pri-remediation': ['/primaire/remediation', 'primary.remediation.read'], 'pri-exam': ['/primaire/examen', 'primary.exam.read'],
      'pri-exports': ['/primaire/exports', 'primary.tutelage.export'], 'pri-closure': ['/primaire/cloture', 'primary.promotion.decide'], 'pri-family': ['/primaire/famille', 'self.read'],
    };
    for (const [name, [path, perm]] of Object.entries(expected)) {
      const route = routes.find((r) => r.name === name);
      expect(route?.path).toBe(path);
      expect(route?.meta?.establishmentTypes).toEqual(['primary']);
      expect(route?.meta?.perms as string[]).toContain(perm);
    }
  });
});

describe('aides', () => {
  it('trimestre en cours, séquences incluses, moyenne au format de la langue', () => {
    const t = periods.filter((p) => p.kind === 'trimester') as Parameters<typeof pickTrimester>[0];
    expect(pickTrimester(t, '2026-10-01')?.id).toBe('P-T1');
    expect(pickTrimester(t, '2027-01-10')?.id).toBe('P-T1');
    expect(sequencesOf(t[0]!, periods as Parameters<typeof sequencesOf>[1]).map((p) => p.id)).toEqual(['P-S1']);
    expect(formatAverage(14.5, 'fr')).toBe('14,50');
    expect(formatAverage(14.5, 'en')).toBe('14.50');
    expect(formatAverage(null, 'fr')).toBe('—');
  });
});

describe('bulletin', () => {
  it('affiche matières, compétences, absences, remédiation ; aucun rang si masqué ; aucun HTML interprété', () => {
    setLocale('fr');
    const wrapper = mount(BulletinCard, { props: { snapshot } });
    const text = wrapper.text();
    expect(text).toContain('NOM Awa');
    expect(text).toContain('Lecture');
    expect(text).toContain('14,50');
    expect(text).toContain('Acquis');
    expect(text).toContain('2 demi-journée(s)');
    expect(text).toContain('Séances suivies : 2 (90 min)');
    expect(text).not.toContain('Rang');
    expect(text).toContain('<b>Très bien</b>');
    expect(wrapper.find('b').exists()).toBe(false);
  });
});

describe('conseil des maîtres', () => {
  it('affiche les noms et les propositions, jamais les identifiants, et exige un motif si la décision change', async () => {
    const calls = connect(['primary.council.preside', 'primary.council.read'], {
      'GET /primary/councils': { data: { items: [{ ...council, lines: undefined }] } },
      'GET /primary/councils/CO-ID-1': { data: council },
      'PATCH /primary/councils/CO-ID-1/lines/LN-2': { data: council },
      'POST /primary/councils/CO-ID-1/validate': { data: { ...council, status: 'validated', minutes: 'Procès-verbal du conseil' } },
    });
    const { wrapper } = await mountView(CouncilView);
    await flushPromises();
    const select = wrapper.find('#co-term');
    await select.setValue('P-T3');
    await flushPromises();
    const text = wrapper.text();
    expect(text).toContain('CM2 A · Trimestre 3');
    expect(text).toContain('NOM Awa');
    expect(text).toContain('Proposition : Redouble');
    expect(text).not.toContain('PER-ID-1');
    expect(text).not.toContain('C-ID-1');

    await wrapper.find('#co-dc-LN-2').setValue('admitted');
    expect(wrapper.find('#co-rs-LN-2').exists()).toBe(true);
    await wrapper.find('#co-rs-LN-2').setValue('Progrès constants');
    const save = wrapper.findAll('button').filter((b) => b.text() === 'Enregistrer')[1];
    await save?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toMatchObject({ decision: 'admitted', reason: 'Progrès constants' });

    await wrapper.findAll('button').find((b) => b.text().includes('Valider le conseil'))?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Procès-verbal du conseil');
  });
});

describe('bulletins', () => {
  it('émet en lot puis publie, et affiche la copie figée', async () => {
    const calls = connect(['primary.report_card.sign', 'primary.report_card.read'], {
      'GET /primary/councils': { data: { items: [{ ...council, status: 'validated' }] } },
      'GET /primary/councils/CO-ID-1': { data: { ...council, status: 'validated' } },
      'GET /primary/councils/CO-ID-1/report-cards': { data: { items: [] } },
      'POST /primary/councils/CO-ID-1/report-cards/issue': { data: { issued: 2, failed: 0, skipped: 0, items: [card(), card({ id: 'RC-ID-2', person_id: 'PER-ID-2', name: 'NOM Bello' })] } },
      'POST /primary/councils/CO-ID-1/report-cards/publish': { data: { published: 2, notified: 2, items: [card({ status: 'published' })] } },
      'GET /primary/report-cards/RC-ID-1': { data: card({ snapshot }) },
    });
    const { wrapper } = await mountView(ReportCardsView, {}, '/primaire/bulletins?council=CO-ID-1');
    await wrapper.findAll('button').find((b) => b.text() === 'Émettre les bulletins')?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('2 émis, 0 en échec');
    expect(wrapper.text()).toContain('EF-26-B-000001');
    await wrapper.findAll('button').find((b) => b.text().startsWith('Publier aux familles'))?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('2 famille(s) prévenue(s)');
    await wrapper.findAll('button').find((b) => b.text() === 'Voir')?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Bulletin émis');
    expect(calls.some((c) => c.path === '/primary/councils/CO-ID-1/report-cards/publish')).toBe(true);
  });
});

describe('portail parent et examen', () => {
  it('le parent consulte le bulletin publié et le résultat publié', async () => {
    connect(['self.read'], {
      'GET /primary/family/children': { data: { items: [{ person_id: 'PER-ID-1', name: 'Awa NOM', class: { id: 'C-ID-1', name: 'CM2 A' } }] } },
      'GET /primary/family/children/PER-ID-1/report-cards': { data: { items: [card({ status: 'published', snapshot })] } },
      'GET /primary/family/children/PER-ID-1/exams': { data: { items: [{ exam: 'CEP', exam_code: 'cep', written_on: null, sub_centre: 'Sous-centre fictif', candidate_number: 'CEP-0001', outcome: 'passed', outcome_detail: null }] } },
    });
    const { wrapper } = await mountView(FamilyView);
    expect(wrapper.text()).toContain('Admis');
    await wrapper.findAll('button').find((b) => b.text().includes('Bulletin trimestriel'))?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Lire un mot');
  });

  it('l’examen liste la session, ses candidats et leurs résultats', async () => {
    connect(['primary.exam.read'], {
      'GET /primary/exam-catalogue': { data: { kinds: { cep: { fr: 'CEP', en: 'CEP' } }, fields: [], outcomes: ['pending', 'passed', 'failed', 'absent'] } },
      'GET /primary/exam-sessions': { data: { items: [{ id: 'EX-1', academic_year_id: 'Y1', exam_code: 'cep', label: 'CEP 2027', level_structure_id: 'L-ID-1', centre_label: null, sub_centre_label: 'Sous-centre fictif', registration_opens_on: null, registration_closes_on: null, written_on: null, fee_code: null, status: 'closed', results_published_at: null, stats: { registered: 1, withdrawn: 0, passed: 1, failed: 0, absent: 0, pending: 0, pass_rate: 100 } }] } },
      'GET /primary/exam-sessions/EX-1/candidates': { data: { items: [{ id: 'CA-1', person_id: 'PER-ID-1', name: 'NOM Awa', class_structure_id: 'C-ID-1', candidate_number: 'CEP-0001', sub_centre_label: null, registration_status: 'registered', fields: {}, outcome: 'passed', outcome_detail: null }] } },
    });
    const { wrapper } = await mountView(ExamView);
    const text = wrapper.text();
    expect(text).toContain('CEP 2027');
    expect(text).toContain('NOM Awa · CEP-0001');
    expect(text).toContain('réussite 100 %');
    expect(text).not.toContain('Créer la session');
  });

  it('la clôture montre les contrôles, le passage et les paramètres à confirmer', async () => {
    connect(['primary.promotion.decide'], {
      'GET /primary/settings/defaults': { data: { items: [{ key: 'primary.class_size_cap', label: 'Plafond d’élèves par classe', source: 'arrêté du 14 août 2026', default: 60, current: 60, customised: false, to_confirm: true }] } },
      'GET /primary/closure/check': { data: { academic_year_id: 'Y1', ready: false, blocking_count: 1, checks: [{ key: 'primary.councils', label: 'x', status: 'blocking', blocking_count: 1, detail: null }], sheets_to_lock: [], promotion_map_errors: [], councils_missing: [{ class_id: 'C-ID-1', name: 'CM2 A', validated: 2, expected: 3, open: 1 }], report_cards_missing: [] } },
      'GET /primary/closure/promotion-preview': { data: { academic_year_id: 'Y1', classes: [{ class_id: 'C-ID-1', council_status: 'validated', next_class_id: null, missing_next_class: true, admitted: 1, adjourned: 1, cycle_end: 0, undecided: 0 }], totals: { admitted: 1, adjourned: 1, cycle_end: 0, undecided: 0, no_next_class: 1 } } },
    });
    const { wrapper } = await mountView(ClosureView);
    const text = wrapper.text();
    expect(text).toContain('Conseils des maîtres validés');
    expect(text).toContain('2 conseil(s) validé(s) sur 3');
    expect(text).toContain('Classe suivante manquante');
    expect(text).toContain('À confirmer');
    expect(text).not.toContain('C-ID-1');
  });
});
