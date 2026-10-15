import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CodeInput from '@/components/CodeInput.vue';
import FamilyPortalView from '@/views/FamilyPortalView.vue';
import VerificationView from '@/views/VerificationView.vue';
import GradeHistory from '@/components/GradeHistory.vue';
import GradeImportPanel from '@/components/GradeImportPanel.vue';
import EnrollmentWizardView from '@/views/EnrollmentWizardView.vue';
import ReportCardsView from '@/views/ReportCardsView.vue';
import NurseryDashboardView from '@/views/nursery/NurseryDashboardView.vue';
import NurseryLogbookView from '@/views/nursery/NurseryLogbookView.vue';
import NurseryPickupView from '@/views/nursery/NurseryPickupView.vue';
import { jsonResponse, flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);

const directorMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ roles: [{ code: 'directeur', label: 'Directeur' }] });

describe('code à 6 chiffres en 6 cases', () => {
  it('affiche six cases, avance automatiquement et publie le code complet', async () => {
    const wrapper = mount(CodeInput, { props: { modelValue: '' }, attachTo: document.body });
    const boxes = wrapper.findAll('input');
    expect(boxes).toHaveLength(6);
    expect(wrapper.attributes('role')).toBe('group');
    expect(boxes[0]?.attributes('aria-label')).toBe('Chiffre 1');
    expect(boxes[0]?.attributes('autocomplete')).toBe('one-time-code');

    await boxes[0]?.setValue('7');
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['7']);
    await wrapper.setProps({ modelValue: '73091' });
    await boxes[5]?.setValue('2');
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['730912']);
    expect(wrapper.emitted('complete')?.at(-1)).toEqual(['730912']);
    wrapper.unmount();
  });

  it('accepte le collage du code entier et ignore tout ce qui n\'est pas un chiffre', async () => {
    const wrapper = mount(CodeInput, { props: { modelValue: '' } });
    const paste = new Event('paste', { bubbles: true, cancelable: true }) as Event & { clipboardData?: unknown };
    paste.clipboardData = { getData: () => '730 912' };
    await wrapper.findAll('input')[2]?.element.dispatchEvent(paste);
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['730912']);
    await wrapper.findAll('input')[0]?.setValue('a');
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['']);
  });

  it('Retour arrière sur une case vide efface le chiffre précédent', async () => {
    const wrapper = mount(CodeInput, { props: { modelValue: '73' } });
    await wrapper.findAll('input')[2]?.trigger('keydown', { key: 'Backspace' });
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['7']);
  });
});

describe('portail famille : barre d\'onglets du bas', () => {
  const portal = { guarantor_id: 'G1', family_balance_xaf: 35000, next_due: { available: false, reason: 'x' }, children: [] };

  it('propose Accueil, Paiements, Bulletins, Messages et ouvre le paiement depuis l\'onglet', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'parent' } }, [tile({ roles: [{ code: 'parent', label: 'Parent' }] })], 'parent');
    mockApi({ 'GET /portal/family': { data: portal } });
    const { wrapper } = await mountView(FamilyPortalView);
    const bar = wrapper.get('nav.tabbar');
    expect(bar.attributes('aria-label')).toBe('Navigation du portail famille');
    expect(bar.findAll('.tb').map((tab) => tab.text())).toEqual(['Accueil', 'Paiements', 'Bulletins', 'Messages']);
    expect(bar.findAll('a.tb')[1]?.attributes('href')).toBe('/documents');
    expect(bar.find('button.tb').attributes('type')).toBe('button');
  });
});

describe('page publique de vérification', () => {
  const doc = { status: 'valid', type: 'bulletin', type_label: 'Bulletin · 1er trimestre', establishment: 'Collège Les Palmiers', holder: 'F••• Nadia', year: 2026, number: 'CLP-26-B-004812', issued_on: '2026-10-08', fingerprint: '9f3e…a21c' };

  it('document authentique : état, champs de la maquette, aucune donnée en plus', async () => {
    const calls = mockApi({ 'GET /public/verify/tok1': { data: doc } });
    const { wrapper } = await mountView(VerificationView, { token: 'tok1' }, '/verification/tok1');
    expect(wrapper.text()).toContain('Document authentique');
    expect(wrapper.text()).toContain('Émis et toujours valide');
    for (const text of ['Bulletin · 1er trimestre', 'Collège Les Palmiers', 'F••• Nadia', '2026', 'CLP-26-B-004812', '9f3e…a21c', 'Comparez avec le papier présenté']) expect(wrapper.text()).toContain(text);
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined();
  });

  it('document révoqué : motif et remplacement affichés', async () => {
    mockApi({ 'GET /public/verify/tok2': { data: { ...doc, status: 'revoked', revoke_reason: 'Mauvaise classe', replaced_by_number: 'CLP-26-B-004900' } } });
    const { wrapper } = await mountView(VerificationView, { token: 'tok2' }, '/verification/tok2');
    expect(wrapper.text()).toContain('Document révoqué');
    expect(wrapper.text()).toContain('Mauvaise classe');
    expect(wrapper.text()).toContain('CLP-26-B-004900');
  });

  it('code inconnu : « Document introuvable » sans fiche', async () => {
    mockApi({ 'GET /public/verify/nope': () => jsonResponse({ type: 'about:blank', title: 'x', status: 404, detail: 'Ce document n\'a pas été émis par SCHOLARIS.', trace_id: 'T' }, 404) });
    const { wrapper } = await mountView(VerificationView, { token: 'nope' }, '/verification/nope');
    expect(wrapper.text()).toContain('Document introuvable');
    expect(wrapper.find('dl.kv').exists()).toBe(false);
  });
});

describe('notes : historique d\'une note et import CSV', () => {
  it('historique : chaque événement avec auteur, motif et approbation', async () => {
    mockApi({
      'GET /grades/G1/events': { data: [
        { id: 'V1', event_kind: 'entry', old_score: null, new_score: 12, old_absence: null, new_absence: null, author: 'U1', reason: null, approved_by: null, happened_at: '2026-10-08T09:00:00+01:00' },
        { id: 'V2', event_kind: 'correction', old_score: 12, new_score: 14, old_absence: null, new_absence: null, author: 'U1', reason: 'Erreur de report', approved_by: 'U2', happened_at: '2026-10-09T09:00:00+01:00' },
      ] },
    });
    const wrapper = mount(GradeHistory, { props: { gradeId: 'G1', title: 'Nadia · Devoir' } });
    await flushPromises();
    const items = wrapper.findAll('ol.timeline li');
    expect(items).toHaveLength(2);
    expect(items[0]?.text()).toContain('Saisie');
    expect(items[1]?.text()).toContain('Correction');
    expect(items[1]?.text()).toContain('12 → 14');
    expect(items[1]?.text()).toContain('Motif : Erreur de report');
    expect(items[1]?.text()).toContain('approuvé par U2');
  });

  it('import : envoie le CSV tel quel, affiche le bilan puis les lignes refusées', async () => {
    const calls = mockApi({
      'POST /grades/import': (c) => (c.query.assessment_id === 'A1' ? { data: { created: 3, updated: 1, unchanged: 0 } } : jsonResponse({ type: 'x', title: 'x', status: 422, detail: 'd', errors: { file: ['Ligne 4 : 22 dépasse le maximum de 20.'] } }, 422)),
    });
    const columns = [{ id: 'A1', subject_id: null, title: 'Devoir', kind: 'homework', weight: 1, max_score: 20, status: 'open', grading_mode: 'score', anonymous: false }];
    const wrapper = mount(GradeImportPanel, { props: { columns } });
    await wrapper.get('#imp-assessment').setValue('A1');
    const input = wrapper.get('input[type="file"]');
    const file = new File(['person_id;note\nP1;12\n'], 'notes.csv', { type: 'text/csv' });
    // jsdom n'implémente pas toujours Blob.text() : on le fournit ; le navigateur réel l'a.
    Object.defineProperty(file, 'text', { value: async () => 'person_id;note\nP1;12\n' });
    Object.defineProperty(input.element, 'files', { value: [file] });
    await input.trigger('change');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Importer')?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/grades/import')?.query.assessment_id).toBe('A1');
    expect(wrapper.text()).toContain('3 créée(s), 1 modifiée(s), 0 inchangée(s)');
    expect(wrapper.emitted('imported')).toHaveLength(1);

    await wrapper.get('#imp-assessment').setValue('A1');
    mockApi({ 'POST /grades/import': () => jsonResponse({ type: 'x', title: 'x', status: 422, detail: 'd', errors: { file: ['Ligne 4 : 22 dépasse le maximum de 20.'] } }, 422) });
    await wrapper.findAll('button').find((b) => b.text() === 'Importer')?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Fichier refusé');
    expect(wrapper.text()).toContain('Ligne 4 : 22 dépasse le maximum de 20.');
  });
});

describe('inscription en 5 étapes', () => {
  it('parcourt identité, parents, filière, pièces et validation ; propose la reprise d\'un doublon', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /academic-years': { data: [{ id: 'Y1', label: '2026-2027' }] },
      'GET /structures': { data: [{ id: 'S1', name: 'Tle D', capacity: 40 }] },
      'POST /people': (c) => {
        if (c.body?.first_name === 'Christelle' && !c.body?.confirm_different) {
          return jsonResponse({ type: 'https://x/duplicate-person', title: 'Doublon', status: 409, detail: 'dup', trace_id: 'T', duplicates: [{ person: { id: 'P0', first_name: 'Christelle', last_name: 'Ewane', birth_date: '2007-03-14' }, label: '3 critères sur 3', history: ['Ancienne élève du Collège Les Palmiers'] }] }, 409);
        }
        return { data: { id: c.body?.first_name === 'Paul' ? 'P2' : 'P1' } };
      },
      'POST /people/P0/relations': { data: {} },
      'POST /people/P1/relations': { data: {} },
      'POST /enrollments': { data: { id: 'EN1' } },
      'GET /people/P0/documents': { data: [{ kind: 'birth_certificate' }] },
      'POST /invoices': { data: { id: 'I1', net_xaf: 310000, lines: [{ label: "Frais d'inscription", net_xaf: 50000 }] } },
      'POST /payment-references': { data: { reference: 'FAM-0587' } },
      'POST /enrollments/EN1/validate': { data: { status: 'active' } },
    });
    const { wrapper } = await mountView(EnrollmentWizardView, {}, '/inscription');
    expect(wrapper.findAll('ol.steps li')).toHaveLength(5);

    await wrapper.get('#en-last').setValue('Ewane');
    await wrapper.get('#en-first').setValue('Christelle');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Une personne existe peut-être déjà dans le groupe');
    expect(wrapper.text()).toContain('3 critères sur 3');
    const same = wrapper.findAll('button').find((b) => b.text().includes('reprendre sa fiche'));
    await same?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Parents & garant');

    await wrapper.get('#rl-last').setValue('Ewane');
    await wrapper.get('#rl-first').setValue('Paul');
    await wrapper.get('#rl-kind').setValue('financial_guarantor');
    await wrapper.get('form.grid2').trigger('submit');
    await flushPromises();
    expect(calls.some((c) => c.path === '/people/P0/relations' && c.body?.kind === 'financial_guarantor' && c.body?.related_person_id === 'P2')).toBe(true);

    await wrapper.findAll('button').find((b) => b.text().startsWith('Continuer : filière'))?.trigger('click');
    await wrapper.get('#tr-year').setValue('Y1');
    await wrapper.get('#tr-structure').setValue('S1');
    await wrapper.findAll('button').find((b) => b.text().startsWith('Continuer : pièces'))?.trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/enrollments')?.body).toEqual({ person_id: 'P0', structure_id: 'S1', academic_year_id: 'Y1' });
    expect(wrapper.text()).toContain('1 / 7');
    expect(wrapper.text()).toContain('Acte de naissance');

    await wrapper.findAll('button').find((b) => b.text().startsWith('Continuer : frais'))?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('FAM-0587');
    expect(wrapper.text()).toContain("Frais d'inscription");
    await wrapper.findAll('button').find((b) => b.text() === "Valider l'inscription")?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Inscription validée');
  });
});

describe('bulletins et relevés', () => {
  it('liste les résultats puis affiche le bulletin sans inventer les colonnes absentes', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /structures': { data: [{ id: 'S1', name: '6e A' }] },
      'GET /periods': { data: [{ id: 'P1', label: 'Séquence 1' }] },
      'GET /results': { data: [{ id: 'R1', person_id: 'L1', structure_id: 'S1', period_id: 'P1', period_label: 'Séquence 1', average: 14.07, rank_in_class: 8, class_size: 46, credits_earned: null, credits_total: null, mention: 'Bien', decision: 'Tableau d\'honneur', details: [{ subject_id: 'M1', code: 'FRA', name: 'Français', average: 14.5 }], status: 'published', published_at: null }] },
      'GET /people': { data: [{ id: 'L1', first_name: 'Nadia', last_name: 'Foka', birth_date: '2015-05-02', birth_place: 'Douala' }] },
      'GET /people/L1': { data: { id: 'L1', first_name: 'Nadia', last_name: 'Foka', birth_date: '2015-05-02', birth_place: 'Douala' } },
    });
    const { wrapper } = await mountView(ReportCardsView, {}, '/bulletins');
    await wrapper.get('#rc-structure').setValue('S1');
    await wrapper.get('#rc-period').setValue('P1');
    await flushPromises();
    expect(wrapper.text()).toContain('FOKA Nadia');
    await wrapper.findAll('button').find((b) => b.text() === 'Voir le bulletin')?.trigger('click');
    await flushPromises();
    const sheet = wrapper.get('article.sheet');
    expect(sheet.text()).toContain('BULLETIN DE NOTES · Séquence 1');
    expect(sheet.text()).toContain('Français');
    expect(sheet.text()).toContain('14,07 / 20');
    expect(sheet.text()).toContain('8e / 46');
    expect(sheet.text()).toContain("Tableau d'honneur");
    expect(sheet.text()).not.toContain('Coef');
  });
});

describe('maternelle', () => {
  const nurseryMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
  const nurseryTile = tile({ type: 'nursery', roles: [{ code: 'directeur', label: 'Directeur' }] });

  it('sortie : liste les personnes autorisées du jour, jamais les autorisations d\'un autre jour', async () => {
    signIn(nurseryMe, [nurseryTile], 'directeur');
    const today = new Date().toISOString().slice(0, 10);
    mockApi({
      'GET /people': { data: [{ id: 'L1', first_name: 'Aminatou', last_name: 'B.' }] },
      'GET /authorized-pickups': { data: [
        { id: 'K1', learner_id: 'L1', pickup_name: 'Mariam B.', relationship: 'Mère', phone: null, grant_type: 'permanent', valid_on: null, status: 'active' },
        { id: 'K2', learner_id: 'L1', pickup_name: 'Hadja S.', relationship: 'Tante', phone: null, grant_type: 'day', valid_on: today, status: 'active' },
        { id: 'K3', learner_id: 'L1', pickup_name: 'Autre jour', relationship: 'Oncle', phone: null, grant_type: 'day', valid_on: '2020-01-01', status: 'active' },
        { id: 'K4', learner_id: 'L1', pickup_name: 'Retirée', relationship: null, phone: null, grant_type: 'permanent', valid_on: null, status: 'revoked' },
      ] },
    });
    const { wrapper } = await mountView(NurseryPickupView, {}, '/maternelle/sorties');
    await wrapper.get('#pk-learner').setValue('L1');
    await flushPromises();
    const names = wrapper.findAll('ul.people li').map((li) => li.text());
    expect(names).toHaveLength(2);
    expect(names[0]).toContain('Mariam B.');
    expect(names[0]).toContain('autorisée en permanence');
    expect(names[1]).toContain('Hadja S.');
    expect(wrapper.text()).toContain('Comparez le visage à la photo');
    expect(wrapper.text()).toContain('Bientôt disponible');
    expect(wrapper.text()).toContain('Confirmation de la remise');
  });

  it('tableau de bord et carnet : seules les données existantes, le reste annoncé honnêtement', async () => {
    signIn(nurseryMe, [nurseryTile], 'directeur');
    mockApi({ 'GET /authorized-pickups': { data: [{ status: 'active' }, { status: 'active' }] } });
    const dash = await mountView(NurseryDashboardView, {}, '/maternelle');
    expect(dash.wrapper.get('.big').text()).toBe('2');
    expect(dash.wrapper.findAll('section.soon')).toHaveLength(4);

    const book = await mountView(NurseryLogbookView, {}, '/maternelle/carnets');
    expect(book.wrapper.text()).toContain('Bientôt disponible');
    expect(book.wrapper.text()).toContain("n'a pas encore de service côté serveur");
  });
});
