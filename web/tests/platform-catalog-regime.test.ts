import { beforeEach, describe, expect, it } from 'vitest';
import PublicCatalogView from '@/views/platform/PublicCatalogView.vue';
import AccessRequestView from '@/views/platform/AccessRequestView.vue';
import MyApplicationsView from '@/views/platform/MyApplicationsView.vue';
import { groupByLevel, withSchoolType } from '@/views/platform/catalogGroups';
import type { PublicApplication } from '@/api/platform';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe } from './helpers';

beforeEach(freshEnvironment);

const app = (key: string, target: PublicApplication['target'], sector: 'private' | 'public', label: string, over: Partial<PublicApplication> = {}): PublicApplication => ({
  key, label, target, family: target, sector, status: 'coming_soon', version: '0.1.0', monthly_price_xaf: sector === 'public' ? 30000 : 60000, trial_days: 90, ...over,
});

// Ordre renvoyé par le serveur : niveau puis régime (le front le retrouve même si l'ordre est brouillé).
const catalog: PublicApplication[] = [
  app('higher', 'higher', 'private', 'IPES - Enseignement supérieur', { status: 'available', version: '1.0.0' }),
  app('nursery', 'nursery', 'private', 'École maternelle'),
  app('public_nursery', 'nursery', 'public', 'École maternelle', { status: 'available' }),
  app('primary', 'primary', 'private', 'École primaire', { status: 'available' }),
  app('public_primary', 'primary', 'public', 'École primaire'),
  app('secondary', 'secondary', 'private', 'Enseignement secondaire'),
];

const receipt = () => jsonResponse({ data: { tracking_token: 'a'.repeat(48), status: 'pending', message: 'ok' } }, 202);

async function fillIdentity(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], name: string, city: string, region: string): Promise<void> {
  await wrapper.get('#ar-institution_name').setValue(name);
  await wrapper.get('#ar-city').setValue(city);
  await wrapper.get('#ar-region').setValue(region);
  await wrapper.get('#ar-contact_first_name').setValue('Awa');
  await wrapper.get('#ar-contact_last_name').setValue('Ngono');
  await wrapper.get('#ar-contact_email').setValue('awa@ecole.cm');
}

describe('catalogue par niveau et par régime', () => {
  it('regroupe en une entrée par niveau, dans l\'ordre supérieur, maternelle, primaire, secondaire, privé avant public', () => {
    const groups = groupByLevel([catalog[5]!, catalog[4]!, catalog[3]!, catalog[2]!, catalog[1]!, catalog[0]!]);
    expect(groups.map((g) => g.level)).toEqual(['higher', 'nursery', 'primary', 'secondary']);
    expect(groups.map((g) => g.regimes.map((r) => r.app.key))).toEqual([['higher'], ['nursery', 'public_nursery'], ['primary', 'public_primary'], ['secondary']]);
    expect(groups[1]?.label).toBe('École maternelle');
  });

  it('tolère une réponse sans family ni sector (privé, niveau = cible)', () => {
    const groups = groupByLevel([{ key: 'higher', label: 'IPES', target: 'higher', status: 'available' }]);
    expect(groups[0]?.level).toBe('higher');
    expect(groups[0]?.regimes[0]?.sector).toBe('private');
  });

  it('consigne le type d\'établissement avant le message', () => {
    expect(withSchoolType('cetic', '')).toBe('Type d\'établissement : CETIC (collège d\'enseignement technique et commercial)');
    expect(withSchoolType('college', 'Bonjour')).toBe('Type d\'établissement : Collège\nBonjour');
    expect(withSchoolType('', 'Bonjour')).toBe('Bonjour');
  });

  it('page Catalogue : une carte par niveau, le régime choisi change prix, statut et clé demandée', async () => {
    mockApi({ 'GET /public/applications': { data: catalog } });
    const { wrapper } = await mountView(PublicCatalogView, {}, '/applications-disponibles');
    expect(wrapper.findAll('article')).toHaveLength(4);
    expect(wrapper.text()).not.toContain('module commun');

    const nursery = wrapper.get('article[data-level="nursery"]');
    // Le premier régime disponible est proposé d'emblée : maternelle publique (la privée est « bientôt »).
    expect(nursery.text()).toContain('30 000');
    expect(nursery.get('a').attributes('href')).toBe('/demande-acces?application=public_nursery');
    await nursery.findAll('input[type="radio"]')[0]!.setValue(true);
    await flushPromises(2);
    expect(nursery.text()).toContain('Bientôt disponible');
    expect(nursery.find('a').exists()).toBe(false);
    expect(nursery.text()).toContain('60 000');

    const primary = wrapper.get('article[data-level="primary"]');
    expect(primary.get('a').attributes('href')).toBe('/demande-acces?application=primary');
    await primary.findAll('input[type="radio"]')[1]!.setValue(true);
    await flushPromises(2);
    expect(primary.text()).toContain('30 000');
    expect(primary.text()).toContain('Bientôt disponible');
  });

  it('demande d\'accès : niveau puis régime, la clé du régime est envoyée', async () => {
    const calls = mockApi({ 'GET /public/applications': { data: catalog }, 'POST /public/access-requests': receipt });
    const { wrapper } = await mountView(AccessRequestView, {}, '/demande-acces');
    await wrapper.get('#ar-target_type').setValue('primary');
    await flushPromises(2);
    const radios = wrapper.findAll('input[name="ar-regime"]');
    expect(radios).toHaveLength(2);
    expect(wrapper.text()).toContain('Privé');
    expect(wrapper.text()).toContain('Public');
    await radios[1]!.setValue(true);
    await fillIdentity(wrapper, 'École publique de Garoua', 'Garoua', 'Nord');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.body).toMatchObject({ target_type: 'primary', app_key: 'public_primary' });
    expect(wrapper.find('#ar-school_type').exists()).toBe(false);
  });

  it('demande d\'accès : secondaire, le type d\'établissement est consigné dans le message', async () => {
    const withPublic = [...catalog, app('public_secondary', 'secondary', 'public', 'Enseignement secondaire')];
    const calls = mockApi({ 'GET /public/applications': { data: withPublic }, 'POST /public/access-requests': receipt });
    const { wrapper } = await mountView(AccessRequestView, {}, '/demande-acces');
    await wrapper.get('#ar-target_type').setValue('secondary');
    await flushPromises(2);
    await wrapper.findAll('input[name="ar-regime"]')[1]!.setValue(true);
    await flushPromises(2);
    const options = wrapper.findAll('#ar-school_type option').map((o) => o.text()).join('|');
    expect(options).toContain('Lycée technique');
    expect(options).toContain('CETIC');
    await wrapper.get('#ar-school_type').setValue('technical');
    await fillIdentity(wrapper, 'Lycée de Maroua', 'Maroua', 'Extrême-Nord');
    await wrapper.get('#ar-message').setValue('Effectif : 800 élèves');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.body).toMatchObject({ app_key: 'public_secondary', message: 'Type d\'établissement : Lycée technique\nEffectif : 800 élèves' });
  });

  it('Mes applications : le catalogue client est regroupé par niveau avec choix du régime', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } }, [], 'directeur');
    mockApi({ 'GET /me/applications': { data: [], meta: { catalog: catalog.filter((a) => a.key !== 'higher') } } });
    const { wrapper } = await mountView(MyApplicationsView, {}, '/applications');
    expect(wrapper.findAll('li[data-level]')).toHaveLength(3);
    const primary = wrapper.get('li[data-level="primary"]');
    expect(primary.findAll('input[type="radio"]')).toHaveLength(2);
    expect(primary.text()).toContain('60 000');
    await primary.findAll('input[type="radio"]')[1]!.setValue(true);
    await flushPromises(2);
    expect(primary.text()).toContain('30 000');
  });
});
