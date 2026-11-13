import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it } from 'vitest';
import { en } from '@/i18n/en';
import { fr } from '@/i18n/fr';
import { permissionsForRoles } from '@/lib/permissions';
import { childrenOf, guardiansOf, type RelationRow } from '@/lib/personRelations';
import PersonView from '@/views/PersonView.vue';
import ReferentialView from '@/views/public-schools/ReferentialView.vue';
import EnrolmentView from '@/views/secondary/enrolment/EnrolmentView.vue';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// FIX-R32 : défauts relevés pendant la mise à jour des guides (G-MAJ2). Données fictives.
beforeEach(freshEnvironment);

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });

describe('défaut 1 : paramètres en vigueur du primaire public', () => {
  const longSource = 'Cahier des charges du primaire public, section évaluation, valeur de départ à confirmer par le ministère (exemple fictif)';

  it('le tableau se lit sans défilement horizontal : colonnes à largeur fixe, retour à la ligne, cartes sur écran étroit', async () => {
    const roles = ['pub_school_director'];
    signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles(roles)], roles: roles.map((code) => ({ code, label: code })) })], roles[0]);
    mockApi({
      'GET /public-primary/settings': { data: [{ key: 'public_primary.referential.school_may_manage', value: false, default: true, status: 'to_confirm', source: longSource }] },
    });
    const { wrapper } = await mountView(ReferentialView);
    await flushPromises();

    const table = wrapper.get('table.rf-settings');
    expect(table.findAll('col')).toHaveLength(4);
    const cells = table.findAll('tbody td');
    expect(cells.map((c) => c.attributes('data-label'))).toEqual(['Valeur', 'Statut', 'Source']);
    expect(cells[2]?.text()).toBe(longSource);

    // Le style global des tableaux (white-space: nowrap) est levé pour ce tableau, et une présentation en cartes existe.
    const css = readFileSync(resolve(__dirname, '../src/views/public-schools/ReferentialView.vue'), 'utf8').split('<style scoped>')[1] ?? '';
    expect(css).toMatch(/\.rf-settings \{ table-layout: fixed; \}/);
    expect(css).toMatch(/\.rf-settings th, \.rf-settings td \{ white-space: normal;/);
    expect(css).toMatch(/@media \(max-width: 720px\)[\s\S]*content: attr\(data-label\)/);
  });
});

describe('défaut 2 : parents et garants sur la fiche personne', () => {
  const rows: RelationRow[] = [
    { id: 'R1', kind: 'parent', as: 'subject', person: { id: 'P-MERE', first_name: 'Hawa', last_name: 'Fictive-Mere', phone: '+237690000001' } },
    { id: 'R2', kind: 'financial_guarantor', as: 'subject', person: { id: 'P-MERE', first_name: 'Hawa', last_name: 'Fictive-Mere', phone: '+237690000001' } },
    { id: 'R3', kind: 'guardian', as: 'subject', person: { id: 'P-ONCLE', first_name: 'Paul', last_name: 'Fictif-Oncle', phone: null } },
    { id: 'R4', kind: 'parent', as: 'related', person: { id: 'P-FILS', first_name: 'Moussa', last_name: 'Fictif-Fils', phone: null } },
  ];

  it('regroupe les liens par personne (parent et garant financier sur une seule ligne)', () => {
    expect(guardiansOf(rows)).toEqual([
      { personId: 'P-MERE', name: 'FICTIVE-MERE Hawa', phone: '+237690000001', kinds: ['parent'], financialGuarantor: true },
      { personId: 'P-ONCLE', name: 'FICTIF-ONCLE Paul', phone: null, kinds: ['guardian'], financialGuarantor: false },
    ]);
    expect(childrenOf(rows).map((c) => c.personId)).toEqual(['P-FILS']);
  });

  it('la fiche d’un élève montre ses responsables avec lien, téléphone et garant financier ; celle d’un parent, ses enfants', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'secretariat' } }, [tile({ permissions: ['people.read'] })], 'secretariat');
    const calls = mockApi({
      'GET /people/P-ELEVE': { data: { id: 'P-ELEVE', first_name: 'Amina', last_name: 'Fictive', birth_date: '2014-03-12', birth_place: null, phone: null, email: null, national_id: null } },
      'GET /people/P-ELEVE/enrollments': { data: [] },
      'GET /people/P-ELEVE/documents': { data: [] },
      'GET /consents': { data: [] },
      'GET /people/P-ELEVE/relations': { data: rows.filter((r) => r.as === 'subject') },
      'GET /people/P-MERE': { data: { id: 'P-MERE', first_name: 'Hawa', last_name: 'Fictive-Mere', birth_date: null, birth_place: null, phone: '+237690000001', email: null, national_id: null } },
      'GET /people/P-MERE/enrollments': { data: [] },
      'GET /people/P-MERE/documents': { data: [] },
      'GET /people/P-MERE/relations': {
        data: [
          { id: 'R1', kind: 'parent', as: 'related', person: { id: 'P-ELEVE', first_name: 'Amina', last_name: 'Fictive', phone: null } },
          { id: 'R2', kind: 'financial_guarantor', as: 'related', person: { id: 'P-ELEVE', first_name: 'Amina', last_name: 'Fictive', phone: null } },
        ],
      },
    });
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    expect(calls.some((c) => c.method === 'GET' && c.path === '/people/P-ELEVE/relations')).toBe(true);
    const text = wrapper.text();
    expect(text).toContain('Parents et garants');
    const guardians = wrapper.get('[data-test="family-guardians"]');
    const lines = guardians.findAll('li').map((li) => li.text());
    expect(lines[0]).toContain('FICTIVE-MERE Hawa');
    expect(lines[0]).toContain('Parent');
    expect(lines[0]).toContain('+237690000001');
    expect(lines[0]).toContain('Garant financier : Oui');
    expect(lines[1]).toContain('FICTIF-ONCLE Paul');
    expect(lines[1]).toContain('Tuteur');
    expect(lines[1]).toContain('Garant financier : Non');
    expect(guardians.find('a').attributes('href')).toBe('/personnes/P-MERE');

    // Fiche du parent : ses enfants.
    await wrapper.setProps({ id: 'P-MERE' });
    await flushPromises();
    const children = wrapper.get('[data-test="family-children"]');
    expect(children.text()).toContain('FICTIVE Amina');
    expect(children.text()).toContain('Parent');
    expect(children.text()).toContain('Garant financier : Oui');
    expect(wrapper.find('[data-test="family-guardians"]').exists()).toBe(false);
  });

  it('chaque texte ajouté existe en français et en anglais', () => {
    const keys = Object.keys(fr).filter((k) => k.startsWith('people.family') || k.startsWith('people.kind.') || k === 'sece.closeDossier');
    expect(keys.length).toBeGreaterThan(10);
    for (const k of keys) expect(en[k as keyof typeof en]).toBeTruthy();
  });
});

describe('défaut 3 : inscriptions du secondaire', () => {
  const asHead = (): void =>
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_chef_etablissement' } }, [tile({ roles: [{ code: 'sec_chef_etablissement', label: 'Chef' }], permissions: ['secondary.enrol.manage', 'secondary.enrol.read'] })], 'sec_chef_etablissement');
  const year = { id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null };
  const cls = { id: 'c6a', structure_id: 's6a', academic_year_id: 'y1', label: '6e A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'first', level_id: 'l1', level_code: '6E', stream_id: null, exam_code: null, capacity: null, head_teacher_person_id: null };
  const row = (id: string, last: string, first: string) => ({
    id: `r-${id}`, person_id: id, enrollment_id: `en-${id}`, class_structure_id: 's6a', class_id: 'c6a', level_code: '6E', academic_year_id: 'y1', entry_kind: 'new',
    entry_date: '2026-09-07', repeater: false, age_alert: false, age_decision_reason: null, exclusion_decision_reason: null, decided_by: null, documents_checked: [],
    created_at: '', last_name: last, first_name: first, gender: null, national_id_status: 'pending',
  });
  const dossier = (id: string, last: string, first: string) => ({
    person_id: id, last_name: last, first_name: first, gender: null, birth_date: '2015-05-12', national_id: null, national_id_status: 'pending', repeater: false,
    final_exclusion_on: null, readmission_year_id: null, special_situation: null, enrollments: [], records: [], transfers: [], financial_guarantors: [],
  });

  it('a) après une inscription, aucun dossier ne reste ouvert ; un dossier ouvert se ferme par « Fermer le dossier »', async () => {
    asHead();
    const students = [row('p1', 'Fictif-Lyceen', 'Issa')];
    mockApi({
      'GET /academic-years': page([year]),
      'GET /secondary/setup/classes': page([cls]),
      'GET /secondary/enrolment/students': () => page(students),
      'GET /secondary/enrolment/students/p1': { data: dossier('p1', 'Fictif-Lyceen', 'Issa') },
      'POST /secondary/enrolments': () => {
        students.push(row('p2', 'Fictif-Collegien', 'Moussa'));
        return { data: dossier('p2', 'Fictif-Collegien', 'Moussa') };
      },
    });
    const { wrapper } = await mountView(EnrolmentView);
    const tab = (label: string) => wrapper.findAll('nav.tabs button').find((b) => b.text() === label);

    await tab('Inscrire')?.trigger('click');
    await flushPromises();
    await wrapper.get('#en-last').setValue('Fictif-Collegien');
    await wrapper.get('#en-first').setValue('Moussa');
    await wrapper.get('#en-birth').setValue('2015-05-12');
    await wrapper.get('#en-class').setValue('c6a');
    await wrapper.get('form.grid').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Élève inscrit.');

    await tab('Élèves')?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Fictif-Collegien Moussa');
    expect(wrapper.find('#se-dossier').exists()).toBe(false);

    await wrapper.findAll('button').find((b) => b.text().includes('Fictif-Lyceen Issa'))?.trigger('click');
    await flushPromises();
    expect(wrapper.get('#se-dossier').text()).toBe('Fictif-Lyceen Issa');
    await wrapper.findAll('button').find((b) => b.text() === 'Fermer le dossier')?.trigger('click');
    expect(wrapper.find('#se-dossier').exists()).toBe(false);
  });

  it('b) l’élève de 6e A inscrit juste après un autre figure dans « Toutes les classes » : la réponse de l’inscription précédente n’efface plus sa saisie', async () => {
    asHead();
    const students: ReturnType<typeof row>[] = [];
    const bodies: Record<string, unknown>[] = [];
    let release: (() => void) | null = null;
    mockApi({
      'GET /academic-years': page([year]),
      'GET /secondary/setup/classes': page([cls]),
      'GET /secondary/enrolment/students': () => page(students),
      'POST /secondary/enrolments': (call) => {
        const identity = (call.body?.identity ?? {}) as { last_name?: string; first_name?: string };
        bodies.push(identity);
        const id = 'p' + String(bodies.length + 1);
        students.push(row(id, identity.last_name ?? '', identity.first_name ?? ''));
        const json = JSON.stringify({ data: dossier(id, identity.last_name ?? '', identity.first_name ?? '') });
        if (bodies.length !== 2) return { data: dossier(id, identity.last_name ?? '', identity.first_name ?? '') };
        // Deuxième inscription (Aminatou) : réponse lente, comme sur la pile réelle (corps livré quand le test le libère).
        const stream = new ReadableStream<Uint8Array>({ start(controller) { release = () => { controller.enqueue(new TextEncoder().encode(json)); controller.close(); }; } });
        return new Response(stream, { status: 201, headers: { 'Content-Type': 'application/json' } });
      },
    });
    const { wrapper } = await mountView(EnrolmentView);
    await wrapper.findAll('nav.tabs button').find((b) => b.text() === 'Inscrire')?.trigger('click');
    await flushPromises();
    const fill = async (last: string, first: string): Promise<void> => {
      await wrapper.get('#en-last').setValue(last);
      await wrapper.get('#en-first').setValue(first);
      await wrapper.get('#en-birth').setValue('2015-05-12');
      await wrapper.get('#en-class').setValue('c6a');
    };
    await fill('Fictif-Lyceen', 'Issa');
    await wrapper.get('form.grid').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Élève inscrit.');
    await fill('Fictive-Lyceenne', 'Aminatou');
    await wrapper.get('form.grid').trigger('submit');
    await flushPromises();
    // Pendant l'envoi : pas de message de réussite (ancien ou nouveau) et saisie impossible.
    expect(wrapper.text()).not.toContain('Élève inscrit.');
    expect(wrapper.get('fieldset.enrol-fields').attributes('disabled')).toBeDefined();
    expect(wrapper.get('fieldset.enrol-fields').find('#en-last').exists()).toBe(true);
    (release as unknown as () => void)();
    await flushPromises();
    expect(wrapper.text()).toContain('Élève inscrit.');

    await fill('Fictif-Collegien', 'Moussa');
    await wrapper.get('form.grid').trigger('submit');
    await flushPromises();
    expect(bodies.map((b) => b.first_name)).toEqual(['Issa', 'Aminatou', 'Moussa']);

    await wrapper.findAll('nav.tabs button').find((b) => b.text() === 'Élèves')?.trigger('click');
    await flushPromises();
    expect((wrapper.get('#se-class').element as HTMLSelectElement).value).toBe('');
    expect(wrapper.text()).toContain('Fictif-Collegien Moussa');
  });
});
