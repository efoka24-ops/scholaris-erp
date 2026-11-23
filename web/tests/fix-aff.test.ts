import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import BillingView from '@/views/BillingView.vue';
import PersonView from '@/views/PersonView.vue';
import ServicesView from '@/views/secondary/services/ServicesView.vue';
import ReportCardView from '@/views/secondary/councils/ReportCardView.vue';
import ExamsView from '@/views/public-schools/ExamsView.vue';
import { settingLabel, settingValue } from '@/views/public-schools/settingsDisplay';
import { DOCUMENT_STATUSES, ENROLLMENT_STATUSES, documentStatusLabel, enrollmentStatusLabel } from '@/lib/peopleStatus';
import { formatDateTime } from '@/lib/format';
import { setLocale, translate, type MessageKey } from '@/i18n';
import { permissionsForRoles } from '@/lib/permissions';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// FIX-AFF : défauts d'affichage relevés pendant la mise à jour des guides (G-MAJ). Données fictives.
beforeEach(freshEnvironment);

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

describe('défaut 1 : journal des consultations de l’infirmerie', () => {
  it('affiche le nom de l’auteur et celui de l’élève, jamais l’identifiant technique', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef' } }, [tile({ permissions: ['secondary.settings.manage'] })], 'sec_chef');
    mockApi({
      'GET /secondary/setup/profile': {
        data: {
          establishment_id: 'E1', subtype: 'lycee', stored: true, profile_version: 1, confirmation: 'confirmed', ownership: 'private', ownership_options: [],
          cycles: ['first'], teaching_kinds: ['general'], sections: ['fr'], head_title: null, modules: [{ code: 'infirmary', state: 'active', default_for_type: false }],
        },
      },
      'GET /secondary/services/infirmary/access-log': page([
        { id: 'L1', person_id: 'P-AWA', student_name: 'Fictive Awa', user_id: '01M77ZZZZZZZZZZZZZZZZZZZZZ', user_name: 'Paul Soignant', access_kind: 'read_visits', object_id: null, created_at: '2026-11-12T10:40:00+01:00' },
        { id: 'L2', person_id: null, user_id: '01M88ZZZZZZZZZZZZZZZZZZZZZ', user_name: null, access_kind: 'read_visits', object_id: null, created_at: '2026-11-12T10:41:00+01:00' },
      ]),
    });
    const { wrapper } = await mountView(ServicesView);
    const text = wrapper.text();
    expect(text).toContain('Paul Soignant · Liste des passages · Fictive Awa');
    expect(text).toContain('Compte sans fiche personne');
    expect(text).not.toContain('01M77');
    expect(text).not.toContain('01M88');
  });
});

describe('défaut 2 : en-tête de la caisse', () => {
  it('relit les espèces encaissées après un encaissement (totaux du détail de la caisse)', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'caissier' } }, [tile({ roles: [{ code: 'caissier', label: 'Caissier' }] })], 'caissier');
    let collected = 0;
    const calls = mockApi({
      'GET /cash-sessions': page([{ id: 'CS1', register_no: 1, status: 'open', opened_at: '2026-11-12T08:00:00+01:00', float_xaf: 10000 }]),
      'GET /cash-sessions/CS1': () => ({ data: { id: 'CS1', register_no: 1, status: 'open', opened_at: '2026-11-12T08:00:00+01:00', float_xaf: 10000, cash_collected_xaf: collected, receipts_count: collected > 0 ? 1 : 0, expected_now_xaf: 10000 + collected } }),
      'POST /cash/receipts': () => {
        collected += 25000;
        return { data: { id: 'PAY1', receipt_number: 'R-26-00001', channel: 'cash', amount_xaf: 25000, status: 'confirmed' } };
      },
    });
    const { wrapper } = await mountView(BillingView, {}, '/facturation?tab=cash');
    expect(wrapper.text()).toContain('Espèces encaissées : 0 FCFA');
    await wrapper.get('#b-ref').setValue('FAM-0001');
    await wrapper.get('#b-amt').setValue('25000');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('R-26-00001');
    expect(wrapper.text()).toContain('Espèces encaissées : 25 000 FCFA');
    expect(calls.filter((c) => c.method === 'GET' && c.path === '/cash-sessions/CS1').length).toBeGreaterThanOrEqual(2);
  });
});

describe('défaut 3 : date d’émission du bulletin', () => {
  const builtAt = '2026-11-12T10:38:56+01:00';
  async function preview(): Promise<string> {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_censeur' } }, [tile({ roles: [{ code: 'sec_censeur', label: 'Censeur' }] })], 'sec_censeur');
    mockApi({
      'GET /secondary/councils/CN1/report-cards/P-ISSA': { data: { council_id: 'CN1', person_id: 'P-ISSA', status: 'issued', variables: { secondary_last_name: 'Fictif', secondary_first_names: 'Issa' }, snapshot: { built_at: builtAt, variables: {} } } },
    });
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/secondaire/bulletins?council=CN1&person=P-ISSA');
    await router.isReady();
    const wrapper = mount(ReportCardView, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper.text();
  }

  it('formate la date selon la langue de l’interface (fr puis en)', async () => {
    const fr = await preview();
    expect(fr).toContain(`le ${formatDateTime(builtAt, 'fr')}`);
    expect(fr).not.toContain('T10:38');
    setLocale('en');
    const en = await preview();
    expect(en).toContain(formatDateTime(builtAt, 'en'));
    expect(en).not.toContain('T10:38');
  });
});

describe('défaut 4 : états d’inscription et des pièces traduits', () => {
  it('fiche personne : « Sous réserve » au lieu de « conditional », pièces traduites', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'secretariat' } }, [tile()], 'secretariat');
    mockApi({
      'GET /people/P1': { data: { id: 'P1', first_name: 'Awa', last_name: 'Fictive', birth_date: '2015-03-01', birth_place: null, phone: null, email: null, national_id: null } },
      'GET /people/P1/enrollments': { data: [{ id: 'EN1', internal_number: 'INS-0001', status: 'conditional' }, { id: 'EN2', internal_number: 'INS-0002', status: 'withdrawn' }] },
      'GET /people/P1/documents': { data: [{ id: 'D1', kind: 'Acte de naissance', status: 'expiring_soon', expires_on: null }] },
      'GET /consents': { data: [] },
    });
    const { wrapper } = await mountView(PersonView, { id: 'P1' });
    const text = wrapper.text();
    expect(text).toContain('Sous réserve');
    expect(text).toContain('Retirée');
    expect(text).toContain('Expire bientôt');
    expect(text).not.toMatch(/conditional|withdrawn|expiring_soon/);
  });

  it('chaque état a un libellé en français et en anglais, distinct de la clé', () => {
    for (const s of ENROLLMENT_STATUSES) {
      for (const lang of ['fr', 'en'] as const) {
        const key = `people.enrollmentStatus.${s}` as MessageKey;
        expect(translate(key, {}, lang), `${lang} ${s}`).not.toBe(key);
      }
      expect(enrollmentStatusLabel(s, (k) => translate(k, {}, 'fr'))).not.toBe(s);
    }
    for (const s of DOCUMENT_STATUSES) expect(documentStatusLabel(s, (k) => translate(k, {}, 'en'))).not.toBe(s);
    expect(enrollmentStatusLabel('future_state', (k) => translate(k))).toBe('future_state');
  });
});

describe('défaut 5 : paramètres en vigueur du référentiel public', () => {
  // Clés de src/Modules/Apps/PublicPrimary/Support/Rules.php.
  const KEYS = [
    'documents.letterhead', 'evaluation.scale', 'evaluation.occasions', 'evaluation.mastery_min_success', 'evaluation.mention_rule', 'evaluation.perfection_only_if_a',
    'evaluation.trimester_mention', 'evaluation.annual_mention', 'evaluation.rank_enabled', 'evaluation.units_per_year', 'referential.school_may_manage',
    'evaluation.evaluation_week_days', 'evaluation.numeric_scores_enabled', 'evaluation.numeric_scale_max', 'remediation.minutes_per_week', 'remediation.forms',
    'promotion.policy', 'promotion.appeal_days', 'exams.catalogue', 'exams.subjects', 'exams.coefficients', 'exams.pass_mark', 'exams.birth_act_alert_month',
    'exams.functions', 'exams.vacation_rates', 'exams.attestation_signer',
  ].map((k) => `public_primary.${k}`);

  it('chaque paramètre a un libellé lisible en fr et en en', () => {
    for (const key of KEYS) {
      expect(settingLabel(key, 'fr'), key).not.toContain('public_primary.');
      expect(settingLabel(key, 'en'), key).not.toContain('public_primary.');
    }
    expect(settingLabel('public_primary.documents.letterhead', 'fr')).toBe('En-tête et bas de page des bulletins');
    expect(settingLabel('public_primary.new_key', 'fr')).toBe('public_primary.new_key');
  });

  it('valeurs mises en forme, jamais de JSON brut', () => {
    const letterhead = { authority_fr: '', authority_en: '', contacts: '', footer_fr: 'Bulletin délivré sans rature.', footer_en: 'Issued without erasures.' };
    expect(settingValue('public_primary.documents.letterhead', letterhead, 'fr')).toEqual(['Bas de page (français) : Bulletin délivré sans rature.', 'Bas de page (anglais) : Issued without erasures.']);
    const scale = [{ code: 'A', label_fr: 'Acquis', label_en: 'Achieved' }, { code: 'NA', label_fr: 'Non acquis', label_en: 'Not achieved' }];
    expect(settingValue('public_primary.evaluation.scale', scale, 'fr')).toEqual(['A : Acquis', 'NA : Non acquis']);
    expect(settingValue('public_primary.evaluation.scale', scale, 'en')).toEqual(['A : Achieved', 'NA : Not achieved']);
    expect(settingValue('public_primary.evaluation.rank_enabled', false, 'fr')).toEqual(['Non']);
    expect(settingValue('public_primary.evaluation.rank_enabled', true, 'en')).toEqual(['Yes']);
    expect(settingValue('public_primary.promotion.appeal_days', 8, 'fr')).toEqual(['8 jours']);
    expect(settingValue('public_primary.remediation.forms', ['need_group', 'peer_tutoring'], 'en')).toEqual(['Need group', 'Peer tutoring']);
    expect(settingValue('public_primary.exams.subjects', [], 'fr')).toEqual(['Néant']);
    expect(settingValue('public_primary.exams.pass_mark', null, 'en')).toEqual(['None']);
    expect(settingValue('public_primary.evaluation.trimester_mention', 'last_unit', 'fr')).toEqual(['Mention de la dernière unité du trimestre']);
    expect(settingValue('public_primary.exams.birth_act_alert_month', 1, 'fr')).toEqual(['janvier']);
    expect(settingValue('public_primary.exams.birth_act_alert_month', 1, 'en')).toEqual(['January']);
    expect(settingValue('public_primary.evaluation.mention_rule', { all: 'A', some: 'ECA', none: 'NA' }, 'fr')[0]).toBe('Tous les critères minimaux maîtrisés : A');
    const rates = settingValue('public_primary.exams.vacation_rates', { half_day: 1500, copy: 60, director_per_candidate: 50, per_function: [] }, 'fr');
    expect(rates).toEqual(['Vacation par demi-journée : 1 500 FCFA', 'Correction par copie : 60 FCFA', 'Directeur d’école, par candidat : 50 FCFA', 'Montants par fonction : Néant']);
    const functions = settingValue('public_primary.exams.functions', { marker: { label_fr: 'Correcteur', label_en: 'Marker' } }, 'en');
    expect(functions).toEqual(['Marker']);
    const catalogue = settingValue('public_primary.exams.catalogue', { cep: { label_fr: 'Certificat d’études primaires (CEP)', label_en: 'FSLC (French)', levels: ['CM2'] } }, 'fr');
    expect(catalogue).toEqual(['Certificat d’études primaires (CEP)']);
    for (const lines of [rates, functions, catalogue]) for (const line of lines) expect(line).not.toMatch(/[{}[\]"]/);
  });
});

describe('défaut 6 : ajout d’un candidat à un examen du public', () => {
  it('le directeur choisit la classe puis l’élève par son nom ; aucun identifiant à saisir', async () => {
    signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(['pub_school_director']), 'public_primary.exams.write'], roles: [{ code: 'pub_school_director', label: 'Directeur' }] })], 'pub_school_director');
    const session = { id: 'S1', exam_code: 'cep', year_label: '2026-2027', label: 'CEP', subsystem: 'francophone', level_codes: ['CM2'], kind: 'certificate', written_on: null, state: 'open' };
    const list = { id: 'L1', establishment_id: 'E1', session_id: 'S1', state: 'draft', current_step: 'school', return_reason: null, frozen_at: null, amendments_count: 0 };
    const calls = mockApi({
      'GET /academic-years': page([{ id: 'Y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open' }]),
      'GET /public/classes': { data: [
        { structure_id: 'C-CM2', establishment_id: 'E1', label: 'CM2 A', level_codes: ['CM2'], twinned: false, shift: null, homeroom_staff_id: null },
        { structure_id: 'C-CE1', establishment_id: 'E1', label: 'CE1 A', level_codes: ['CE1'], twinned: false, shift: null, homeroom_staff_id: null },
      ] },
      'GET /public/classes/C-CM2/pupils': page([
        { person_id: 'P-ZOE', last_name: 'Zambo', first_name: 'Zoé', learner_number: 'SYN-002' },
        { person_id: 'P-AMI', last_name: 'Abena', first_name: 'Aminata', learner_number: 'SYN-001' },
      ]),
      'GET /public-primary/exams': page([session]),
      'GET /public-primary/exams/S1': { data: { ...session, list, counts: {} } },
      'GET /public-primary/exams/S1/candidates': page([]),
      'GET /public-primary/exams/S1/results': { data: { session, summary: { candidates: 0, present: 0, passed: 0, failed: 0, absent: 0, success_rate: null, pending: 0 } } },
      'POST /public-primary/exams/S1/candidates': { data: { id: 'K1' } },
    });
    const { wrapper } = await mountView(ExamsView);
    await flushPromises();
    expect(wrapper.text()).not.toContain('Identifiant de l');
    const classSelect = wrapper.get('select#ex-class');
    expect(classSelect.findAll('option').map((o) => o.text())).toEqual(['Choisir…', 'CM2 A']);
    await classSelect.setValue('C-CM2');
    await flushPromises();
    const pupilSelect = wrapper.get('select#ex-person');
    expect(pupilSelect.findAll('option').map((o) => o.text())).toEqual(['Choisir…', 'ABENA Aminata · SYN-001', 'ZAMBO Zoé · SYN-002']);
    await pupilSelect.setValue('P-ZOE');
    await wrapper.get('select#ex-class').element.closest('form')?.dispatchEvent(new Event('submit'));
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/public-primary/exams/S1/candidates');
    expect(post?.body).toEqual({ person_id: 'P-ZOE', structure_id: 'C-CM2' });
  });
});
