import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import PickupView from '@/views/nursery-private/PickupView.vue';
import CallView from '@/views/nursery-private/CallView.vue';
import EnrolView from '@/views/nursery-private/EnrolView.vue';
import ObserveView from '@/views/nursery-private/ObserveView.vue';
import FamilyView from '@/views/nursery-private/FamilyView.vue';
import RegisterView from '@/views/nursery-private/RegisterView.vue';
import { flushOutbox, listOutbox } from '@/views/nursery-private/outbox';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

beforeEach(() => freshEnvironment());

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 200 } });
const find = (calls: RecordedCall[], method: string, path: string): RecordedCall | undefined => calls.find((c) => c.method === method && c.path === path);
const section = { id: 'S1', label: 'Petite section', capacity: 20, enrolled: 2, sort_order: 1, subsystem: 'fr' };

function connect(perms: string[], routes: Record<string, unknown>, role = 'nursery_educator'): RecordedCall[] {
  signIn(teacherMe, [tile({ type: 'nursery', permissions: perms, roles: [{ code: role, label: role }] })], role);
  return mockApi({ 'GET /nursery/sections': page([section]), ...routes } as Parameters<typeof mockApi>[0]);
}

function pkg(over: Record<string, unknown> = {}) {
  return {
    section_id: 'S1', section_label: 'Petite section', date: '2026-10-21', generated_at: new Date().toISOString(), max_age_days: 1, read_days: 30,
    direction: [{ name: 'Mme Dora', phone: '+237600000099' }],
    children: [{
      id: 'C1', name: 'Ada Test', absent: false,
      authorized: [
        { id: 'A1', learner_id: 'C1', name: 'Tante Rose', relationship: 'tante', phone: '600000001', has_photo: false, grant_type: 'permanent', valid_on: null, status: 'active' },
        { id: 'A2', learner_id: 'C1', name: 'Papa Visé', relationship: 'père', phone: null, has_photo: false, grant_type: 'permanent', valid_on: null, status: 'active' },
      ],
      restrictions: [{ restricted_pickup_id: 'A2', restricted_person_id: null, restricted_name: 'Papa Visé' }],
    }],
    ...over,
  };
}

async function pickSection(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']): Promise<void> {
  await wrapper.get('#pk-section').setValue('S1');
  await flushPromises();
}

describe('Maternelle privée : sortie sécurisée', () => {
  it('remet l’enfant par l’IDENTIFIANT de l’autorisation, jamais par un nom, et masque la personne visée par une restriction', async () => {
    const calls = connect(['nursery.pickup.handover'], {
      'GET /nursery/sections/S1/pickup-package': { data: pkg() },
      'POST /nursery/pickup/handovers': { data: { status: 'handed_over', reason: 'authorized', stale_authorization: false, duplicate: false, event: {}, alerts: null } },
    });
    const { wrapper } = await mountView(PickupView);
    await pickSection(wrapper);
    expect(wrapper.text()).toContain('Tante Rose');
    expect(wrapper.text()).toContain('Restriction de garde');
    const buttons = wrapper.findAll('button').filter((b) => b.text().startsWith('Remettre à'));
    expect(buttons.map((b) => b.text())).toEqual(['Remettre à Tante Rose']);
    await buttons[0]!.trigger('click');
    await flushPromises();
    const body = find(calls, 'POST', '/nursery/pickup/handovers')?.body;
    expect(body?.pickup_id).toBe('A1');
    expect(body?.learner_id).toBe('C1');
    expect(body).not.toHaveProperty('declared_name');
    expect(wrapper.text()).toContain('Ada Test a été remis');
  });

  it('affiche le motif quand le serveur refuse la remise (422) et ne dit jamais « remis »', async () => {
    connect(['nursery.pickup.handover'], {
      'GET /nursery/sections/S1/pickup-package': { data: pkg() },
      'POST /nursery/pickup/handovers': jsonResponse({ type: 'x', title: 'Remise refusée', status: 422, detail: 'refusée', code: 'authorization_not_valid', trace_id: 'T' }, 422),
    });
    const { wrapper } = await mountView(PickupView);
    await pickSection(wrapper);
    await wrapper.findAll('button').find((b) => b.text() === 'Remettre à Tante Rose')!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Remise refusée pour Ada Test');
    expect(wrapper.text()).toContain('autorisation retirée, expirée ou d’un autre jour');
    expect(wrapper.text()).not.toContain('a été remis');
  });

  it('« personne non listée » enregistre un refus avec le nom donné, sans remettre l’enfant', async () => {
    const calls = connect(['nursery.pickup.handover'], {
      'GET /nursery/sections/S1/pickup-package': { data: pkg() },
      'POST /nursery/pickup/refusals': { data: { status: 'refused', reason: 'unauthorized', stale_authorization: false, duplicate: false, event: {}, alerts: { sent: 2, queued: 0, blocked: 0, failed: 0 } } },
    });
    const { wrapper } = await mountView(PickupView);
    await pickSection(wrapper);
    await wrapper.findAll('button').find((b) => b.text() === 'Personne non listée')!.trigger('click');
    await wrapper.get('#pk-decl-C1').setValue('Inconnu Test');
    (wrapper.get('#pk-decl-C1').element as HTMLInputElement).form!.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/pickup/refusals')?.body?.declared_name).toBe('Inconnu Test');
    expect(find(calls, 'POST', '/nursery/pickup/handovers')).toBeUndefined();
    expect(wrapper.text()).toContain('2 alerte(s) envoyée(s)');
  });

  it('passe en mode « doute » quand le paquet est trop ancien : aucune remise sans appel à la direction', async () => {
    connect(['nursery.pickup.handover'], { 'GET /nursery/sections/S1/pickup-package': { data: pkg({ generated_at: '2020-01-01T08:00:00+01:00' }) } });
    const { wrapper } = await mountView(PickupView);
    await pickSection(wrapper);
    expect(wrapper.text()).toContain('ne remettez aucun enfant sans avoir appelé la direction');
    expect(wrapper.text()).toContain('+237600000099');
    const hand = wrapper.findAll('button').find((b) => b.text() === 'Remettre à Tante Rose')!;
    expect(hand.attributes('disabled')).toBeDefined();
  });

  it('hors ligne : garde le geste sur l’appareil sans doublon puis le rejoue avec origin=offline et le même client_uuid', async () => {
    let online = false;
    const calls = connect(['nursery.pickup.handover'], {
      'GET /nursery/sections/S1/pickup-package': () => {
        if (!online) throw new TypeError('network');
        return { data: pkg() };
      },
      'POST /nursery/pickup/handovers': { data: { status: 'handed_over', reason: 'authorized', stale_authorization: false, duplicate: false, event: {}, alerts: null } },
    });
    // 1) en ligne : le paquet est gardé sur l'appareil
    online = true;
    const first = await mountView(PickupView);
    await pickSection(first.wrapper);
    first.wrapper.unmount();
    // 2) hors ligne : le paquet vient du cache et la remise est mise en file
    online = false;
    const { wrapper } = await mountView(PickupView);
    await pickSection(wrapper);
    expect(wrapper.text()).toContain('Hors ligne');
    await wrapper.findAll('button').find((b) => b.text() === 'Remettre à Tante Rose')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/pickup/handovers')).toBeUndefined();
    expect(wrapper.text()).toContain('Geste gardé sur l’appareil');
    const queued = await listOutbox('E1');
    expect(queued).toHaveLength(1);
    // 3) retour du réseau : rejeu avec le client_uuid du geste
    const report = await flushOutbox('E1');
    expect(report.sent).toBe(1);
    const replay = find(calls, 'POST', '/nursery/pickup/handovers');
    expect(replay?.body?.origin).toBe('offline');
    expect(replay?.body?.client_uuid).toBe(queued[0]!.client_uuid);
    expect(replay?.body?.pickup_id).toBe('A1');
    expect(await listOutbox('E1')).toHaveLength(0);
  });
});

describe('Maternelle privée : appel, inscription, observation, registre', () => {
  it('l’appel envoie seulement les exceptions (tous présents par défaut)', async () => {
    const calls = connect(['attendance.take'], {
      'GET /nursery/sections/S1/roll': page([
        { child_id: 'C1', full_name: 'Ada Test', status: 'expected', arrived_at: null },
        { child_id: 'C2', full_name: 'Bao Test', status: 'expected', arrived_at: null },
      ]),
      'POST /nursery/sections/S1/call': { data: { present: 1, late: 0, absent: 1, status: 'validated' } },
    });
    const { wrapper } = await mountView(CallView);
    await wrapper.get('#ca-section').setValue('S1');
    await flushPromises();
    await wrapper.findAll('button.toggle')[1]!.trigger('click');
    await wrapper.findAll('button').find((b) => b.text().startsWith('Valider l’appel'))!.trigger('click');
    await flushPromises();
    const body = find(calls, 'POST', '/nursery/sections/S1/call')?.body;
    expect(body?.exceptions).toEqual([{ child_id: 'C2', status: 'A' }]);
    expect(body?.validate).toBe(true);
    expect(String(body?.client_uuid)).toHaveLength(26);
    expect(wrapper.text()).toContain('1 présents, 0 en retard, 1 absents');
  });

  it('l’inscription envoie l’enfant, le parent garant et l’accord photo, et montre les avertissements', async () => {
    const calls = connect(['nursery.enrol'], {
      'POST /nursery/enrolments': { data: { child_id: 'C9', enrollment_id: 'EN9', section_id: 'S1', warnings: [{ code: 'over_capacity', detail: 'La section dépasse son effectif maximal (20).' }] } },
    });
    const { wrapper } = await mountView(EnrolView);
    await wrapper.get('#en-first').setValue('Lina');
    await wrapper.get('#en-last').setValue('Fictive');
    await wrapper.get('#en-birth').setValue('2023-02-01');
    await wrapper.get('#en-section').setValue('S1');
    await wrapper.get('#en-pf').setValue('Marie');
    await wrapper.get('#en-pl').setValue('Fictive');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const body = find(calls, 'POST', '/nursery/enrolments')?.body as Record<string, unknown>;
    expect(body.section_id).toBe('S1');
    expect(body.photo_consent).toBe(true);
    expect((body.guardians as { kind: string }[])[0]?.kind).toBe('financial_guarantor');
    expect(wrapper.text()).toContain('Enfant inscrit');
    expect(wrapper.text()).toContain('effectif maximal');
  });

  it('l’observation propose les niveaux de /settings, sans note, et envoie un client_uuid', async () => {
    const calls = connect(['nursery.dev.observe'], {
      'GET /nursery/frames': page([{ id: 'F1', scope: 'tenant', origin: 'custom', version: 1, label_fr: 'Réf', label_en: 'Ref', status: 'active', to_validate: false }]),
      'GET /nursery/frames/F1': { data: { id: 'F1', scope: 'tenant', origin: 'custom', version: 1, label_fr: 'Réf', label_en: 'Ref', status: 'active', to_validate: false, domains: [{ id: 'D1', code: 'LANG', label_fr: 'Langage', label_en: 'Language', items: [{ id: 'I1', code: 'L1', label_fr: 'S’exprime', label_en: 'Expresses', active: true }] }] } },
      'GET /nursery/settings': { data: { 'nursery.dev.levels': ['acquired', 'in_progress', 'not_yet'] } },
      'GET /nursery/children': page([{ id: 'C1', full_name: 'Ada Test', first_name: 'Ada', last_name: 'Test', section_id: 'S1', section_label: 'Petite' }]),
      'GET /nursery/observations/coverage': page([]),
      'POST /nursery/observations': { data: { id: 'O1' } },
    });
    const { wrapper } = await mountView(ObserveView);
    await wrapper.get('#ob-section').setValue('S1');
    await flushPromises();
    await wrapper.get('#ob-child').setValue('C1');
    await wrapper.get('#ob-domain').setValue('D1');
    await wrapper.get('#ob-item').setValue('I1');
    const levels = wrapper.findAll('.levels button').map((b) => b.text());
    expect(levels).toEqual(['Acquis', 'En cours', 'Pas encore']);
    expect(wrapper.text()).not.toMatch(/moyenne|rang |total|%/i);
    await wrapper.findAll('.levels button')[1]!.trigger('click');
    await flushPromises();
    const body = find(calls, 'POST', '/nursery/observations')?.body;
    expect(body).toMatchObject({ learner_id: 'C1', item_id: 'I1', level_code: 'in_progress' });
    expect(String(body?.client_uuid)).toHaveLength(26);
    expect(wrapper.text()).toContain('Observation enregistrée');
  });

  it('le registre du jour montre sorties, refus et enfants encore à l’école', async () => {
    connect(['nursery.register.read'], {
      'GET /nursery/register': { data: { date: '2026-10-21', totals: { children: 2, present: 2, absent: 0, left: 1, still_at_school: 1 }, sections: [{ id: 'S1', label: 'Petite section', children: [
        { id: 'C1', name: 'Ada Test', status: 'left', arrived_at: '2026-10-21T07:55:00+01:00', left_at: '2026-10-21T16:05:00+01:00', left_to: 'Tante Rose', kept: false, stale: false, refusals: 1, alerts: [] },
        { id: 'C2', name: 'Bao Test', status: 'still_at_school', arrived_at: '2026-10-21T07:50:00+01:00', left_at: null, left_to: null, kept: false, stale: false, refusals: 0, alerts: ['parent'] },
      ] }] } },
    });
    const { wrapper } = await mountView(RegisterView);
    const text = wrapper.text();
    expect(text).toContain('Tante Rose');
    expect(text).toContain('1 refus');
    expect(text).toContain('Encore à l’école');
    expect(text).toContain('1 sortis, 1 encore à l’école');
  });
});

describe('Maternelle privée : espace des parents', () => {
  it('montre le texte d’une publication mais retient les photos sans accord de tous les enfants visibles', async () => {
    connect([], {
      'GET /nursery/portal/children': page([{ id: 'C1', full_name: 'Ada Test', section_id: 'S1', section_label: 'Petite section' }]),
      'GET /nursery/portal/pickups': page([]),
      'GET /nursery/portal/progress-books': page([]),
      'GET /nursery/portal/lifebook': page([{ id: 'E1', title: 'Sortie au jardin', body: 'Nous avons planté des graines.', activity_on: '2026-10-20', media: [], withheld_media: 2 }]),
      'GET /nursery/portal/needs': page([]),
      'GET /nursery/portal/balance': { data: { learner_id: 'C1', balance_xaf: 12000, in_arrears: true } },
    }, 'parent');
    const { wrapper } = await mountView(FamilyView);
    await flushPromises();
    const text = wrapper.text();
    expect(text).toContain('Nous avons planté des graines.');
    expect(text).toContain('2 photo(s) non affichée(s)');
    expect(text).toContain('En retard');
    expect(wrapper.find('img').exists()).toBe(false);
  });

  it('un parent ajoute une personne autorisée pour son enfant et voit la demande en attente', async () => {
    const calls = connect([], {
      'GET /nursery/portal/children': page([{ id: 'C1', full_name: 'Ada Test', section_id: 'S1', section_label: 'Petite section' }]),
      'GET /nursery/portal/pickups': page([]),
      'GET /nursery/portal/progress-books': page([]),
      'GET /nursery/portal/lifebook': page([]),
      'GET /nursery/portal/needs': page([]),
      'GET /nursery/portal/balance': { data: { learner_id: 'C1', balance_xaf: 0, in_arrears: false } },
      'POST /nursery/portal/pickups': { data: { status: 'pending', request_id: 'R1' } },
    }, 'parent');
    const { wrapper } = await mountView(FamilyView);
    await flushPromises();
    await wrapper.get('#fa-name').setValue('Cousin Test');
    await wrapper.get('#fa-rel').setValue('cousin');
    await wrapper.get('form.grid2').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/nursery/portal/pickups')?.body).toMatchObject({ learner_id: 'C1', name: 'Cousin Test', grant_type: 'permanent' });
    expect(wrapper.text()).toContain('l’école doit la valider');
  });
});
