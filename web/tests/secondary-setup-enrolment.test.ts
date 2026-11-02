import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fileFormat, templateCsv } from '@/api/secondaryEnrolment';
import { groupByLevel, officialTotal, parseRuleValue, type Coefficient } from '@/api/secondarySetup';
import { en } from '@/i18n/en';
import { fr } from '@/i18n/fr';
import { secondarySetupFr } from '@/i18n/secondary-setup-fr';
import { secondaryRoutes } from '@/views/secondary/routes';
import { MENU } from '@/views/secondary/menu';
import { ageAt, dossierFromForm, emptyEnrolForm, isMinor } from '@/views/secondary/enrolment/enrolmentModel';
import SetupView from '@/views/secondary/setup/SetupView.vue';
import EnrolmentView from '@/views/secondary/enrolment/EnrolmentView.vue';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// E1-A (T820 à T827, T978) : paramétrage et inscriptions du secondaire. Données fictives.
beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const line = (over: Partial<Coefficient>): Coefficient => ({
  id: 'c', academic_year_id: 'y', subject_ref: 's', subject_code: 'MATH', subject_label: 'Maths', level_code: '6E', stream_id: null, coefficient: 4,
  weekly_hours: 5, subject_role: 'official', counts_in_average: true, origin: 'example', confirmation: 'to_confirm', locked: false, source_ref: null,
  version_no: 1, valid_from: '2026-09-01', change_reason: 'exemple', changed_by: 'u', created_at: '', superseded_at: null, ...over,
});

describe('paramétrage (modèle)', () => {
  it('regroupe par niveau et série ; la matière de projet ne compte pas dans le total officiel (RG-ORG-4)', () => {
    const lines = [line({ id: 'a' }), line({ id: 'b', coefficient: 1, subject_role: 'project', counts_in_average: false }), line({ id: 'c', level_code: '2NDE', stream_id: 'st-c', coefficient: 5 })];
    const groups = groupByLevel(lines);
    expect(groups.map((g) => g.key)).toEqual(['6E|', '2NDE|st-c']);
    expect(officialTotal(groups[0]?.lines ?? [])).toBe(4);
  });

  it('valeur de règle saisie en JSON', () => {
    expect(parseRuleValue('{"6E":{"max_age":13}}')).toEqual({ ok: true, value: { '6E': { max_age: 13 } } });
    expect(parseRuleValue('{oups').ok).toBe(false);
  });
});

describe('inscriptions (modèle)', () => {
  it('âge révolu et mineur', () => {
    expect(ageAt('2014-03-12', '2026-03-11')).toBe(11);
    expect(ageAt('2014-03-12', '2026-03-12')).toBe(12);
    expect(isMinor('2014-03-12', '2026-09-07')).toBe(true);
    expect(isMinor('', '2026-09-07')).toBe(false);
  });

  it('corps d\'API-01 : champs vides retirés, garant et pièces', () => {
    const form = { ...emptyEnrolForm('2026-09-07'), last_name: ' NGONO ', first_name: 'Aïcha', birth_date: '2014-03-12', class_id: 'cl', guardian_last_name: 'NGONO', guardian_phone: '+237600000000', documents: 'acte, bulletin ,' };
    const body = dossierFromForm(form);
    expect(body.identity).toEqual({ last_name: 'NGONO', first_name: 'Aïcha', birth_date: '2014-03-12' });
    expect(body.guardians?.[0]?.identity?.phone).toBe('+237600000000');
    expect(body.documents_checked).toEqual(['acte', 'bulletin']);
    expect(body.national_id).toBeUndefined();
    expect(body.age_decision_reason).toBeUndefined();
  });

  it('modèle CSV neutralisé et format de fichier', () => {
    const csv = templateCsv({ columns: ['nom', 'prenoms'], required: ['nom'], sample: [['=SOMME(A1)', 'Aïcha; Marie']], separator: ';', encoding: 'UTF-8' });
    expect(csv.startsWith(`${String.fromCharCode(0xfeff)}nom;prenoms\r\n`)).toBe(true);
    expect(csv).toContain("'=SOMME(A1)");
    expect(csv).toContain('"Aïcha; Marie"');
    expect(fileFormat('eleves.XLSX')).toBe('xlsx');
    expect(fileFormat('eleves.csv')).toBe('csv');
    expect(fileFormat('eleves.pdf')).toBeNull();
  });
});

describe('routes, menu et textes', () => {
  it('les écrans Paramétrage et Inscriptions ont leur route et leur entrée de menu', () => {
    const routed = new Set(secondaryRoutes.map((r) => r.name));
    expect(routed.has('sec-setup')).toBe(true);
    expect(routed.has('sec-enrolment')).toBe(true);
    expect(MENU.map((e) => e.name)).toEqual(expect.arrayContaining(['sec-setup', 'sec-enrolment']));
  });

  it('chaque texte existe en français et en anglais', () => {
    for (const key of Object.keys(secondarySetupFr) as (keyof typeof secondarySetupFr)[]) {
      expect(fr[key]).toBeTruthy();
      expect(en[key]).toBeTruthy();
    }
  });
});

describe('écrans', () => {
  const asHead = (permissions: string[]): void => signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef_etablissement' } }, [tile({ roles: [{ code: 'sec_chef_etablissement', label: 'Chef' }], permissions })], 'sec_chef_etablissement');
  const year = { id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null };
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });

  it('paramétrage : charge les niveaux d\'exemple et affiche « à confirmer »', async () => {
    asHead(['secondary.settings.manage']);
    const level = { id: 'l1', code: '6E', label: 'Sixième', cycle_code: 'first', section_code: 'fr', teaching_kind: 'general', sort_order: 0, exam_code: null, structure_id: 's1', origin: 'example', confirmation: 'to_confirm', active: true };
    const calls = mockApi({
      'GET /academic-years': page([year]),
      'GET /secondary/setup/levels': page([]),
      'POST /secondary/setup/levels/examples': { data: [level] },
      'GET /secondary/setup/streams': page([]),
      'GET /secondary/setup/subjects': page([]),
      'GET /secondary/setup/coefficients/freezes': page([]),
      'GET /secondary/setup/rooms': page([]),
      'GET /secondary/setup/rules': { data: {} },
      'GET /secondary/setup/classes': page([]),
      'GET /secondary/setup/coefficients': page([]),
    });
    const { wrapper } = await mountView(SetupView);
    const button = wrapper.findAll('button').find((b) => b.text() === fr['secs.loadExamples']);
    expect(button).toBeTruthy();
    await button?.trigger('click');
    await new Promise((r) => setTimeout(r, 0));
    expect(calls.some((c) => c.method === 'POST' && c.path === '/secondary/setup/levels/examples')).toBe(true);
    expect(wrapper.text()).toContain('Sixième');
    expect(wrapper.text()).toContain(fr['secs.toConfirm']);
    expect(wrapper.html()).not.toContain('v-html');
  });

  it('inscriptions : liste des élèves en attente de matricule', async () => {
    asHead(['secondary.enrol.manage', 'secondary.enrol.read']);
    const row = {
      id: 'r1', person_id: 'p1', enrollment_id: 'en1', class_structure_id: 's6a', class_id: 'c6a', level_code: '6E', academic_year_id: 'y1', entry_kind: 'new',
      entry_date: '2026-09-07', repeater: false, age_alert: false, age_decision_reason: null, exclusion_decision_reason: null, decided_by: null, documents_checked: [],
      created_at: '', last_name: 'NGONO', first_name: 'Aïcha', gender: 'female', national_id_status: 'pending',
    };
    mockApi({
      'GET /academic-years': page([year]),
      'GET /secondary/setup/classes': page([{ id: 'c6a', structure_id: 's6a', academic_year_id: 'y1', label: '6e A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'first', level_id: 'l1', level_code: '6E', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /secondary/enrolment/students': page([row]),
    });
    const { wrapper } = await mountView(EnrolmentView);
    expect(wrapper.text()).toContain('NGONO Aïcha');
    expect(wrapper.text()).toContain(fr['sece.nid.pending']);
    expect(wrapper.text()).toContain('6e A');
  });
});
