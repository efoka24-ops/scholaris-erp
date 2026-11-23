import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { en } from '@/i18n/en';
import { fr } from '@/i18n/fr';
import PersonView from '@/views/PersonView.vue';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// GAR-FORM : ajouter, changer ou retirer un parent ou un garant sur la fiche d'un élève déjà inscrit. Données fictives.
beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const eleve = { id: 'P-ELEVE', first_name: 'Amina', last_name: 'Fictive', birth_date: '2014-03-12', birth_place: null, phone: null, email: null, national_id: null };
const pere = { id: 'P-PERE', first_name: 'Ali', last_name: 'Fictif-Pere', phone: '+237690000002' };
const oncle = { id: 'P-ONCLE', first_name: 'Paul', last_name: 'Fictif-Oncle', phone: '+237690000003', birth_date: null, birth_place: null, email: null, national_id: null };

const asSecretariat = (permissions: string[]): void =>
  signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'secretariat' } }, [tile({ permissions })], 'secretariat');

/** Relations simulées : un état modifiable que POST et DELETE font évoluer, comme le serveur. */
function api(initial: Array<{ id: string; kind: string; person: typeof pere }>) {
  let rows = initial.map((r) => ({ ...r, as: 'subject' }));
  let seq = 0;
  const calls = mockApi({
    'GET /people/P-ELEVE': { data: eleve },
    'GET /people/P-ELEVE/enrollments': { data: [{ id: 'EN1', status: 'active', internal_number: 'INS-0001' }] },
    'GET /people/P-ELEVE/documents': { data: [] },
    'GET /consents': { data: [] },
    'GET /people/P-ELEVE/relations': () => ({ data: rows }),
    'GET /people': { data: [oncle], meta: { next_cursor: null, per_page: 50 } },
    'POST /people': (c) => ({ data: { ...oncle, id: 'P-NOUVEAU', ...(c.body as object) } }),
    'POST /people/P-ELEVE/relations': (c) => {
      const body = c.body as { related_person_id: string; kind: string };
      const person = body.related_person_id === 'P-ONCLE' ? oncle : body.related_person_id === 'P-PERE' ? pere : { ...oncle, id: body.related_person_id };
      seq += 1;
      rows = [...rows, { id: `RN${seq}`, kind: body.kind, as: 'subject', person: { id: person.id, first_name: person.first_name, last_name: person.last_name, phone: person.phone } }];
      return new Response(JSON.stringify({ data: { id: `RN${seq}`, person_id: 'P-ELEVE', related_person_id: body.related_person_id, kind: body.kind } }), { status: 201, headers: { 'Content-Type': 'application/json' } });
    },
    // Retrait d'un lien existant : 204 sans corps, comme RelationController::destroy.
    ...Object.fromEntries(
      initial.map((r) => [
        `DELETE /people/P-ELEVE/relations/${r.id}`,
        () => {
          rows = rows.filter((x) => x.id !== r.id);
          return new Response(null, { status: 204 });
        },
      ]),
    ),
  });
  return calls;
}

describe('GAR-FORM : liens familiaux modifiables sur la fiche', () => {
  it("l'oncle remplace le père comme garant financier : recherche, choix, nouveau lien puis retrait de l'ancien", async () => {
    asSecretariat(['people.manage']);
    const calls = api([
      { id: 'R1', kind: 'parent', person: pere },
      { id: 'R2', kind: 'financial_guarantor', person: pere },
    ]);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    const form = wrapper.get('[data-test="family-add"]');
    await form.get('[data-test="add-kind"]').setValue('financial_guarantor');
    await form.get('[data-test="add-q"]').setValue('6900');
    await form.get('[data-test="add-search"]').trigger('click');
    await flushPromises();
    expect(calls.some((c) => c.method === 'GET' && c.path === '/people' && c.query['filter[q]'] === '6900')).toBe(true);
    await wrapper.get('[data-test="add-results"] button').trigger('click');
    expect(wrapper.get('[data-test="add-chosen"]').text()).toContain('FICTIF-ONCLE Paul');
    await wrapper.get('[data-test="add-replace"]').setValue(true);
    await wrapper.get('[data-test="family-add"]').trigger('submit');
    await flushPromises();

    expect(confirm).toHaveBeenCalledOnce();
    const writes = calls.filter((c) => c.method !== 'GET').map((c) => `${c.method} ${c.path}`);
    expect(writes).toEqual(['POST /people/P-ELEVE/relations', 'DELETE /people/P-ELEVE/relations/R2']);
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ related_person_id: 'P-ONCLE', kind: 'financial_guarantor' });
    // Aucune écriture côté facturation : les factures émises ne sont pas réécrites.
    expect(calls.some((c) => c.path.startsWith('/invoices') || c.path.startsWith('/payment-references'))).toBe(false);

    const lines = wrapper.get('[data-test="family-guardians"]').findAll('li').map((li) => li.text());
    expect(lines.find((l) => l.includes('FICTIF-PERE Ali'))).toContain('Garant financier : Non');
    expect(lines.find((l) => l.includes('FICTIF-ONCLE Paul'))).toContain('Garant financier : Oui');
    expect(wrapper.text()).toContain('Lien enregistré.');
    expect(wrapper.get('[data-test="family-guarantor-info"]').text()).toContain('les factures déjà émises ne changent pas');
    expect(wrapper.get('[data-test="family-guarantor-info"]').text()).toContain('la caisse la lui émet');
  });

  it('crée une nouvelle personne (nom, prénom, téléphone) puis la lie comme contact d’urgence', async () => {
    asSecretariat(['people.manage']);
    const calls = api([{ id: 'R1', kind: 'parent', person: pere }]);
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    await wrapper.get('[data-test="add-kind"]').setValue('emergency');
    await wrapper.get('[data-test="mode-new"]').setValue(true);
    await wrapper.get('[data-test="add-last"]').setValue('Fictive-Tante');
    await wrapper.get('[data-test="add-first"]').setValue('Rose');
    await wrapper.get('[data-test="add-phone"]').setValue('+237690000004');
    await wrapper.get('[data-test="family-add"]').trigger('submit');
    await flushPromises();

    const posts = calls.filter((c) => c.method === 'POST');
    expect(posts.map((c) => c.path)).toEqual(['/people', '/people/P-ELEVE/relations']);
    expect(posts[0]?.body).toEqual({ last_name: 'Fictive-Tante', first_name: 'Rose', phone: '+237690000004' });
    expect(posts[1]?.body).toEqual({ related_person_id: 'P-NOUVEAU', kind: 'emergency' });
    expect(wrapper.get('[data-test="family-guardians"]').text()).toContain("Contact d'urgence");
  });

  it('retire un lien après confirmation, avertit quand c’est le dernier responsable, et ne fait rien si on annule', async () => {
    asSecretariat(['people.manage']);
    const calls = api([{ id: 'R1', kind: 'guardian', person: pere }]);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    await wrapper.get('[data-test="link-R1"] [data-test="link-remove"]').trigger('click');
    await flushPromises();
    const message = String(confirm.mock.calls[0]?.[0]);
    expect(message).toContain('Retirer le lien « Tuteur » de FICTIF-PERE Ali');
    expect(message).toContain('dernier parent, tuteur ou garant financier');
    expect(message).toContain('plus personne ne recevra les relances');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);

    confirm.mockReturnValue(true);
    await wrapper.get('[data-test="link-R1"] [data-test="link-remove"]').trigger('click');
    await flushPromises();
    expect(calls.filter((c) => c.method === 'DELETE').map((c) => c.path)).toEqual(['/people/P-ELEVE/relations/R1']);
    expect(wrapper.text()).toContain('Lien retiré.');
    expect(wrapper.find('[data-test="family-no-responsible"]').exists()).toBe(true);
  });

  it('change le type d’un lien : nouveau type enregistré, ancien retiré', async () => {
    asSecretariat(['people.manage']);
    const calls = api([
      { id: 'R1', kind: 'parent', person: pere },
      { id: 'R2', kind: 'authorized_pickup', person: oncle },
    ]);
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    await wrapper.get('[data-test="link-R2"] select').setValue('guardian');
    await wrapper.get('[data-test="link-R2"] [data-test="link-change"]').trigger('click');
    await flushPromises();
    const writes = calls.filter((c) => c.method !== 'GET');
    expect(writes.map((c) => `${c.method} ${c.path}`)).toEqual(['POST /people/P-ELEVE/relations', 'DELETE /people/P-ELEVE/relations/R2']);
    expect(writes[0]?.body).toEqual({ related_person_id: 'P-ONCLE', kind: 'guardian' });
    expect(wrapper.text()).toContain('Lien modifié.');
  });

  it('en lecture seule (people.read) : ni formulaire ni bouton de retrait', async () => {
    asSecretariat(['people.read']);
    api([{ id: 'R1', kind: 'parent', person: pere }]);
    const { wrapper } = await mountView(PersonView, { id: 'P-ELEVE' }, '/personnes/P-ELEVE');
    await flushPromises();

    expect(wrapper.get('[data-test="family-guardians"]').text()).toContain('FICTIF-PERE Ali');
    expect(wrapper.find('[data-test="family-add"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="link-remove"]').exists()).toBe(false);
    expect(wrapper.get('[data-test="family-readonly"]').text()).toContain('Lecture seule');
  });

  it('chaque texte ajouté existe en français et en anglais', () => {
    const keys = Object.keys(fr).filter((k) => k.startsWith('people.family'));
    expect(keys.length).toBeGreaterThan(30);
    for (const k of keys) expect(en[k as keyof typeof en]).toBeTruthy();
  });
});
