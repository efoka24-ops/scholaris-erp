import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import BillingView from '@/views/BillingView.vue';
import FeesView from '@/views/secondary/fees/FeesView.vue';
import ReportCardView from '@/views/secondary/councils/ReportCardView.vue';
import WorkshopsView from '@/views/secondary/workshops/WorkshopsView.vue';
import { S } from '@/views/secondary/access';
import { secondaryRoutes } from '@/views/secondary/routes';
import { filterStudents, studentText } from '@/views/secondary/studentDirectory';
import { permissionsForRoles } from '@/lib/permissions';
import { unavailableKey } from '@/api/secondaryDocuments';
import { invoiceTitle } from '@/api/secondaryPortals';
import { cellText, rowCells } from '@/api/secondaryCouncils';
import type { DirectoryStudent } from '@/api/secondaryEnrolment';
import { fr } from '@/i18n/fr';
import { en } from '@/i18n/en';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// FIX-SEC : défauts de l'application Secondaire relevés pendant les captures du guide (G-SEC). Données fictives.
beforeEach(freshEnvironment);

const pupils: DirectoryStudent[] = [
  { person_id: 'P-ISSA', last_name: 'Fictif-Lyceen', first_name: 'Issa', full_name: 'Fictif-Lyceen Issa', class_structure_id: 'C-2A', class_label: '2nde A', academic_year_id: 'Y1' },
  { person_id: 'P-AICHA', last_name: 'Ébodé', first_name: 'Aïcha', full_name: 'Ébodé Aïcha', class_structure_id: 'C-1C', class_label: '1re C', academic_year_id: 'Y1' },
];

function routeAllows(name: string, role: string): boolean {
  const perms = permissionsForRoles([role]);
  const route = secondaryRoutes.find((r) => r.name === name);
  return ((route?.meta?.perms as string[] | undefined) ?? []).some((p) => perms.has(p));
}

describe('défaut 1 et 2 : caissier du secondaire et encaissement par élève', () => {
  it('le caissier lit le profil (menu des modules) et ouvre l’écran Frais', () => {
    const cashier = permissionsForRoles(['sec_caissier']);
    expect(S.profileRead.some((p) => cashier.has(p))).toBe(true);
    expect(routeAllows('sec-fees', 'sec_caissier')).toBe(true);
  });

  it('écran Frais du caissier : élève choisi par son nom puis caisse du socle, sans lecture refusée', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_caissier' } }, [tile({ roles: [{ code: 'sec_caissier', label: 'Caissier' }] })], 'sec_caissier');
    const calls = mockApi({
      'GET /secondary/fees/overview': { data: { mode: 'public_catalog', kinds: ['tuition'], refused_kinds: [], blocked_document_types: [], allocation_rule: '', allocation_kind_order: null, allocation_sibling_order: null, reminder_offsets: null, blocks: null, presets: {}, confirmation: 'to_confirm' } },
      'GET /secondary/enrolment/directory': { data: { items: pupils } },
    });
    const { wrapper } = await mountView(FeesView);
    expect(calls.some((c) => c.path === '/secondary/fees/catalog' || c.path === '/secondary/fees/requests')).toBe(false);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    const select = wrapper.get('select#sf-cash-student');
    expect(select.text()).toContain('Fictif-Lyceen Issa · 2nde A');
    expect(select.text()).not.toContain('P-ISSA');
    await select.setValue('P-ISSA');
    await flushPromises(1);
    const link = wrapper.findAll('a').find((a) => a.text() === 'Encaisser pour cet élève');
    expect(link?.attributes('href')).toContain('learner=P-ISSA');
  });

  it('la caisse ouverte pour un élève encaisse sur ses factures (learner_id), sans référence famille à saisir', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'caissier' } }, [tile({ roles: [{ code: 'caissier', label: 'Caissier' }] })], 'caissier');
    const calls = mockApi({
      'GET /cash-sessions': { data: [{ id: 'CS1', register_no: 1, status: 'open', opened_at: '2026-11-11T08:00:00+01:00', float_xaf: 0, cash_collected_xaf: 0 }], meta: { next_cursor: null } },
      'POST /cash/receipts': { data: { id: 'PAY1', receipt_number: 'R-SEC-26-00001', channel: 'cash', amount_xaf: 25000, status: 'confirmed' } },
    });
    const { wrapper } = await mountView(BillingView, {}, '/facturation?tab=cash&learner=P-ISSA&learner_name=Fictif-Lyceen%20Issa');
    expect(wrapper.text()).toContain('Élève : Fictif-Lyceen Issa');
    expect(wrapper.find('#b-ref').exists()).toBe(false);
    await wrapper.get('#b-amt').setValue('25000');
    await wrapper.get('form.card').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path === '/cash/receipts');
    expect(post?.body).toEqual({ amount_xaf: 25000, learner_id: 'P-ISSA' });
    expect(wrapper.text()).toContain('R-SEC-26-00001');
  });
});

describe('défaut 3 : aperçu du bulletin', () => {
  it('affiche « calcul en cours » puis le bulletin, même avec des cellules nulles ou numériques', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_censeur' } }, [tile({ roles: [{ code: 'sec_censeur', label: 'Censeur' }] })], 'sec_censeur');
    const subjects = JSON.stringify({ columns: [{ label: '' }, { label: '' }], head: [[{ text: 'Matières', rowspan: 2 }, 'Coef'], []], rows: [{ kind: 'group', cells: [{ text: 'Groupe 1', colspan: 2 }] }, ['Mathématiques', 4, null]] });
    mockApi({
      'GET /secondary/councils/CN1/report-cards/P-ISSA': { data: { council_id: 'CN1', person_id: 'P-ISSA', status: 'validated', variables: { secondary_last_name: 'Fictif-Lyceen', secondary_first_names: 'Issa', secondary_class_name: '2nde A', secondary_academic_year: '2026-2027', secondary_subjects_table: subjects }, snapshot: null } },
    });
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/secondaire/bulletins?council=CN1&person=P-ISSA');
    await router.isReady();
    const wrapper = mount(ReportCardView, { global: { plugins: [router] } });
    expect(wrapper.text()).toContain('Calcul du bulletin en cours');
    await flushPromises();
    expect(wrapper.text()).toContain('Fictif-Lyceen Issa · 2nde A');
    expect(wrapper.text()).toContain('Mathématiques');
    expect(wrapper.text()).toContain('4');
    expect(cellText(null)).toBe('');
    expect(rowCells({ kind: 'total', cells: ['TOTAL', 12] }).cells.map(cellText)).toEqual(['TOTAL', '12']);
  });
});

describe('défauts 4 et 5 : noms au lieu d’identifiants, libellés traduits', () => {
  it('recherche d’un élève par son nom ou sa classe, sans accents, jamais par identifiant affiché', () => {
    expect(filterStudents(pupils, 'ebode').map((s) => s.person_id)).toEqual(['P-AICHA']);
    expect(filterStudents(pupils, '2nde').map((s) => s.person_id)).toEqual(['P-ISSA']);
    expect(filterStudents(pupils, '', 'C-1C').map((s) => s.person_id)).toEqual(['P-AICHA']);
    expect(studentText(pupils[0] as DirectoryStudent)).toBe('Fictif-Lyceen Issa · 2nde A');
  });

  it('la facture du portail famille est nommée par ses frais, sinon par sa référence ou son montant', () => {
    expect(invoiceTitle({ label: 'Frais de scolarité', reference: null }, 'Facture de 50 000 FCFA')).toBe('Frais de scolarité');
    expect(invoiceTitle({ label: null, reference: null }, 'Facture de 50 000 FCFA')).toBe('Facture de 50 000 FCFA');
  });

  it('aucun libellé d’écran du secondaire ne demande un identifiant, en français comme en anglais', () => {
    const keys = ['secsv.personId', 'secf.personId', 'secfr.year', 'secdb.year', 'secdb.claims.class', 'secdb.claims.person', 'secdb.reports.previous', 'sectt.teacher', 'sectt.room', 'sectt.replacement'] as const;
    for (const key of keys) {
      expect(fr[key], key).not.toMatch(/identifiant/i);
      expect(en[key], key).not.toMatch(/identifier/i);
    }
  });

  it('états du conseil, types de bulletin et périodes traduits dans les deux langues', () => {
    const keys = ['secc.councilStatus.open', 'secc.councilStatus.validated', 'secc.reportType.secondary_report_trimester', 'secc.reportType.secondary_report_annual', 'secs.roles.official', 'secb.loading', 'sec.docs.teachingOff'] as const;
    for (const key of keys) {
      expect(fr[key], key).toBeTruthy();
      expect(en[key], key).toBeTruthy();
    }
    expect(fr['secc.letterheadHint']).not.toContain('secondary.report_card');
    expect(en['secc.letterheadHint']).not.toContain('secondary.report_card');
  });
});

describe('défaut 6 : catalogue des documents et ateliers vides', () => {
  it('un module actif n’est jamais dit « inactif » : motif propre à l’enseignement technique', () => {
    expect(unavailableKey({ unavailable_reason: 'teaching_kind_not_offered' })).toBe('sec.docs.teachingOff');
    expect(unavailableKey({ unavailable_reason: 'module_inactive' })).toBe('sec.docs.moduleOff');
  });

  it('sans atelier, le chef des travaux voit les étapes pour commencer', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef_travaux' } }, [tile({ roles: [{ code: 'sec_chef_travaux', label: 'Chef des travaux' }] })], 'sec_chef_travaux');
    mockApi({ 'GET /secondary/workshops': { data: [] } });
    const { wrapper } = await mountView(WorkshopsView);
    expect(wrapper.text()).toContain('Aucun atelier n’est encore créé.');
    expect(wrapper.text()).toContain('Créez l’atelier ci-dessus');
  });
});

describe('défaut 8 : administrateur du client sur Personnel et Paie', () => {
  it('le super administrateur (administration du personnel du socle) ouvre Personnel et Paie', () => {
    expect(routeAllows('sec-staff', 'super_admin')).toBe(true);
    expect(routeAllows('sec-payroll', 'super_admin')).toBe(true);
    expect(routeAllows('sec-home', 'super_admin')).toBe(true);
  });
});

