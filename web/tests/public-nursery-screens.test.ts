import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import NurseryPickupView from '@/views/nursery/NurseryPickupView.vue';
import ObservationView from '@/views/public-nursery/ObservationView.vue';
import LifeBookView from '@/views/public-nursery/LifeBookView.vue';
import TransitionLiaisonView from '@/views/public-nursery/TransitionLiaisonView.vue';
import { todayIso } from '@/lib/format';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

beforeEach(() => freshEnvironment());

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 200 } });
const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);

function connect(perms: string[], routes: Record<string, unknown>): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'public_nursery', permissions: perms })]);
  return mockApi(routes as Parameters<typeof mockApi>[0]);
}

const child = { id: 'L1', first_name: 'Aïcha', last_name: 'Mballa' };
const today = todayIso();

describe('Maternelle publique : sortie sécurisée de l’enfant', () => {
  const pickups = [
    { id: 'A1', learner_id: 'L1', pickup_name: 'Marie Ngono', relationship: 'Mère', phone: '600000001', grant_type: 'permanent', valid_on: null, status: 'active' },
    { id: 'A2', learner_id: 'L1', pickup_name: 'Paul Essomba', relationship: 'Oncle', phone: null, grant_type: 'day', valid_on: today, status: 'active' },
    { id: 'A3', learner_id: 'L1', pickup_name: 'Hier Seulement', relationship: null, phone: null, grant_type: 'day', valid_on: '2020-01-01', status: 'active' },
    { id: 'A4', learner_id: 'L1', pickup_name: 'Personne Retirée', relationship: null, phone: null, grant_type: 'permanent', valid_on: null, status: 'revoked' },
  ];

  it('ne montre que les personnes autorisées aujourd’hui et rappelle de vérifier le visage', async () => {
    const calls = connect(['attendance.discipline.manage'], { 'GET /people': page([child]), 'GET /authorized-pickups': page(pickups) });
    const { wrapper } = await mountView(NurseryPickupView);
    await wrapper.get('#pk-learner').setValue('L1');
    await flushPromises();
    expect(find(calls, 'GET', '/authorized-pickups')?.query['filter[learner_id]']).toBe('L1');
    const text = wrapper.text();
    expect(text).toContain('Marie Ngono');
    expect(text).toContain('Paul Essomba');
    expect(text).not.toContain('Hier Seulement');
    expect(text).not.toContain('Personne Retirée');
    expect(text).toContain('Comparez le visage à la photo');
  });

  it('prévient de ne pas remettre l’enfant quand personne n’est autorisé', async () => {
    connect([], { 'GET /people': page([child]), 'GET /authorized-pickups': page([pickups[2], pickups[3]]) });
    const { wrapper } = await mountView(NurseryPickupView);
    await wrapper.get('#pk-learner').setValue('L1');
    await flushPromises();
    expect(wrapper.text()).toContain('ne remettez pas l\'enfant et appelez la direction');
  });

  it('cache la gestion des autorisations sans permission', async () => {
    connect([], { 'GET /people': page([child]), 'GET /authorized-pickups': page(pickups) });
    const { wrapper } = await mountView(NurseryPickupView);
    await wrapper.get('#pk-learner').setValue('L1');
    await flushPromises();
    expect(wrapper.find('#pk-name').exists()).toBe(false);
    expect(wrapper.findAll('button').some((b) => b.text() === 'Retirer l\'autorisation')).toBe(false);
  });

  it('ajoute une personne pour le jour seulement et retire une autorisation', async () => {
    const calls = connect(['attendance.discipline.manage'], {
      'GET /people': page([child]),
      'GET /authorized-pickups': page(pickups),
      'POST /authorized-pickups': { id: 'A5' },
      'POST /authorized-pickups/A1/revoke': { id: 'A1', status: 'revoked' },
    });
    const { wrapper } = await mountView(NurseryPickupView);
    await wrapper.get('#pk-learner').setValue('L1');
    await flushPromises();

    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await wrapper.get('#pk-name').setValue('  Jean Fotso ');
    await wrapper.get('#pk-type').setValue('day');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/authorized-pickups')?.body).toEqual({ learner_id: 'L1', pickup_name: 'Jean Fotso', grant_type: 'day', valid_on: today });

    await wrapper.findAll('button').find((b) => b.text() === 'Retirer l\'autorisation')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/authorized-pickups/A1/revoke')).toBeDefined();
  });
});

describe('Maternelle publique : observation de développement', () => {
  const frames = page([
    { id: 'F1', frame_code: 'CAD-1', label_fr: 'Cadre actif', label_en: 'Active frame', version: 2, status: 'active' },
    { id: 'F0', frame_code: 'CAD-0', label_fr: 'Cadre archivé', label_en: 'Archived frame', version: 1, status: 'archived' },
  ]);

  it('propose seulement les cadres actifs et envoie l’observation avec l’identité de l’observateur', async () => {
    const calls = connect(['public_nursery.dev.observe'], {
      'GET /public-nursery/development/frames': frames,
      'POST /public-nursery/sync/observations': { data: { id: 'OB1', level_code: 'acquired' } },
    });
    const { wrapper } = await mountView(ObservationView);
    const options = wrapper.findAll('#obs-frame option').map((o) => o.text());
    expect(options.join(' ')).toContain('Cadre actif · v2');
    expect(options.join(' ')).not.toContain('Cadre archivé');

    const submit = wrapper.get('button[type="submit"]');
    expect(submit.attributes('disabled')).toBeDefined();
    await wrapper.get('#obs-learner').setValue('L1');
    await wrapper.get('#obs-section').setValue('S1');
    await wrapper.get('#obs-domain').setValue('D1');
    await wrapper.get('#obs-item').setValue('I1');
    await wrapper.get('#obs-level').setValue('acquired');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(find(calls, 'POST', '/public-nursery/sync/observations')?.body).toMatchObject({
      learner_id: 'L1',
      structure_id: 'S1',
      observer_id: 'U1',
      frame_id: 'F1',
      domain_id: 'D1',
      item_id: 'I1',
      level_code: 'acquired',
    });
    expect(wrapper.get('pre').text()).toContain('OB1');
  });

  it('affiche l’erreur du serveur', async () => {
    connect(['public_nursery.dev.observe'], {
      'GET /public-nursery/development/frames': frames,
      'POST /public-nursery/sync/observations': jsonResponse({ type: 'about:blank', title: 'Interdit', status: 403, detail: 'Section non autorisée.', trace_id: 'TR1' }, 403),
    });
    const { wrapper } = await mountView(ObservationView);
    await wrapper.get('#obs-learner').setValue('L1');
    await wrapper.get('#obs-section').setValue('S9');
    await wrapper.get('#obs-domain').setValue('D1');
    await wrapper.get('#obs-item').setValue('I1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.find('pre').exists()).toBe(false);
  });
});

describe('Maternelle publique : cahier de vie', () => {
  const entry = {
    id: 'LB1',
    title: 'Atelier peinture <b>gras</b>',
    body: '<script>alert(1)</script>',
    activity_on: '2026-10-19',
    status: 'draft',
    media: [{ id: 'M1', media_url: 'x', status: 'pending_tagging', no_child_visible: 0, masked_from_id: null }],
  };

  it('ne charge rien sans section puis liste les entrées de la section', async () => {
    const calls = connect(['public_nursery.lifebook.write'], { 'GET /public-nursery/development/lifebook': page([entry]) });
    const { wrapper } = await mountView(LifeBookView);
    expect(calls.some((c) => c.path === '/public-nursery/development/lifebook')).toBe(false);
    expect(wrapper.text()).toContain('Aucune entrée publiée');

    await wrapper.get('#lb-section').setValue('S1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(find(calls, 'GET', '/public-nursery/development/lifebook')?.query).toEqual({ structure_id: 'S1' });
    expect(wrapper.text()).toContain('pending_tagging');
  });

  it('rend le contenu en texte, sans injecter de HTML', async () => {
    connect(['public_nursery.lifebook.write'], { 'GET /public-nursery/development/lifebook': page([entry]) });
    const { wrapper } = await mountView(LifeBookView);
    await wrapper.get('#lb-section').setValue('S1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('.entry script').exists()).toBe(false);
    expect(wrapper.find('.entry b').exists()).toBe(false);
    expect(wrapper.find('.entry').text()).toContain('<script>alert(1)</script>');
  });

  it('enregistre un brouillon, joint un média non étiqueté puis publie', async () => {
    const calls = connect(['public_nursery.lifebook.write'], {
      'GET /public-nursery/development/lifebook': page([entry]),
      'POST /public-nursery/development/lifebook': { data: { id: 'LB1' } },
      'POST /public-nursery/development/lifebook/LB1/media': { data: { id: 'M2' } },
      'POST /public-nursery/development/lifebook/LB1/publish': { data: { id: 'LB1', status: 'published' } },
    });
    const { wrapper } = await mountView(LifeBookView);
    await wrapper.get('#lb-section').setValue('S1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    await wrapper.get('#lb-title').setValue(' Sortie au jardin ');
    await wrapper.get('#lb-body').setValue('Les enfants ont planté.');
    await wrapper.findAll('button').find((b) => b.text() === 'Enregistrer le brouillon')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/development/lifebook')?.body).toMatchObject({ structure_id: 'S1', title: 'Sortie au jardin', body: 'Les enfants ont planté.' });

    await wrapper.get('#lb-content').setValue('BASE64DATA');
    await wrapper.get('#lb-childs').setValue('L1, L2');
    await wrapper.findAll('button').find((b) => b.text() === 'Ajouter un média')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/development/lifebook/LB1/media')?.body).toMatchObject({
      content: 'BASE64DATA',
      child_ids: ['L1', 'L2'],
      no_child_visible: false,
      tagging_done: false,
    });

    await wrapper.findAll('button').find((b) => b.text() === 'Publier l’entrée')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/development/lifebook/LB1/publish')).toBeDefined();
  });

  it('affiche le refus du serveur si le média n’est pas étiqueté à la publication', async () => {
    connect(['public_nursery.lifebook.write'], {
      'GET /public-nursery/development/lifebook': page([entry]),
      'POST /public-nursery/development/lifebook/LB1/publish': jsonResponse({ type: 'about:blank', title: 'Conflit', status: 409, detail: 'Étiquetage des médias incomplet.', trace_id: 'TR2' }, 409),
    });
    const { wrapper } = await mountView(LifeBookView);
    await wrapper.get('#lb-section').setValue('S1');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Publier l’entrée')!.trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
  });
});

describe('Maternelle publique : fiche de liaison', () => {
  it('affiche l’aperçu dans une iframe isolée, sans script ni origine', async () => {
    const sheet = { learner_id: 'L1', school_year: '2026-2027', path_summary: 'Parcours', strengths: 'Chant\nDessin', needs: 'Langage', status: 'draft', html: '<h1>Fiche</h1><script>alert(1)</script>' };
    connect(['public_nursery.transition.opinion'], { 'GET /public-nursery/transition/liaison': { data: sheet } });
    const { wrapper } = await mountView(TransitionLiaisonView);
    await wrapper.get('#ls-learner').setValue('L1');
    await wrapper.findAll('button').find((b) => b.text() === 'Rafraîchir la liste')!.trigger('click');
    await flushPromises();
    const frame = wrapper.get('iframe');
    expect(frame.attributes('sandbox')).toBe('');
    expect(frame.attributes('srcdoc')).toContain('<h1>Fiche</h1>');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.findAll('li').map((li) => li.text())).toEqual(expect.arrayContaining(['Chant', 'Dessin', 'Langage']));
  });
});
