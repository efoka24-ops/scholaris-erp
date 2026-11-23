import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import type { MorningPackage } from '@/api/publicNursery';
import { idbAll } from '@/offline/db';
import { todayIso } from '@/lib/format';
import GateOfflineView from '@/views/public-nursery/GateOfflineView.vue';
import { publicNurseryRoutes } from '@/views/public-nursery/routes';
import { GateRefusal, atomNow, discardGate, flushGate, listGateQueue, loadPackage, packageState, recordGate } from '@/views/public-nursery/offlineGate';
import { lockVault, setupPin, wipeVault } from '@/views/public-schools/vault';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile, type RecordedCall } from './helpers';

// C-111 (T735) : écran de porte hors ligne de la maternelle publique. Réseau coupé simulé (fetch qui échoue), données fictives.

const FAST = 1000;
const today = todayIso();
const pkg = (over: Partial<MorningPackage> = {}): MorningPackage => ({
  date: today,
  generated_at: new Date().toISOString(),
  max_age_days: 1,
  direction: [{ name: 'Dora Directrice', phone: '+237600000099' }],
  children: [
    {
      id: 'L1',
      name: 'Lina Fictive',
      absent: false,
      authorized: [
        { id: 'A1', name: 'Tante Rose', relationship: 'tante', grant_type: 'permanent', valid_on: null },
        { id: 'A2', name: 'Oncle Paul', relationship: 'oncle', grant_type: 'day', valid_on: today },
      ],
      restrictions: [{ restricted_pickup_id: 'A2', restricted_person_id: null, restricted_name: 'Oncle Paul' }],
    },
    { id: 'L2', name: 'Bao Fictif', absent: true, authorized: [], restrictions: [] },
  ],
  ...over,
});
const down = (): never => {
  throw new TypeError('Failed to fetch');
};
const find = (calls: RecordedCall[], method: string, path: string): RecordedCall[] => calls.filter((c) => c.method === method && c.path === path);

beforeEach(async () => {
  freshEnvironment();
  await wipeVault();
  lockVault();
  await setupPin('123456', FAST);
  signIn(teacherMe, [tile({ type: 'nursery', permissions: ['public_nursery.pickup.handover'] })]);
});

describe('porte hors ligne : paquet du matin', () => {
  it('garde le paquet chiffré et le relit sans réseau', async () => {
    mockApi({ 'GET /public-nursery/pickup/package': { data: pkg() } });
    expect((await loadPackage('E1', today)).fromCache).toBe(false);
    expect(JSON.stringify(await idbAll('cache'))).not.toContain('Tante Rose');
    mockApi({ 'GET /public-nursery/pickup/package': down });
    const offline = await loadPackage('E1', today);
    expect(offline.fromCache).toBe(true);
    expect(offline.pkg.children[0]?.authorized[0]?.name).toBe('Tante Rose');
  });

  it('passe en mode doute si le paquet est trop ancien ou d’un autre jour', () => {
    expect(packageState(pkg(), today).doubt).toBe(false);
    const old = new Date(Date.now() - 3 * 86_400_000).toISOString();
    expect(packageState(pkg({ generated_at: old }), today)).toMatchObject({ doubt: true, reason: 'too_old' });
    expect(packageState(pkg({ date: '2020-01-01' }), today)).toMatchObject({ doubt: true, reason: 'other_day' });
    expect(atomNow(new Date())).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
  });
});

describe('porte hors ligne : file locale et synchronisation', () => {
  it('refuse hors ligne une remise à une personne absente du paquet, restreinte, ou en mode doute', async () => {
    const context = { pkg: pkg(), doubt: false };
    const base = { type: 'handover' as const, learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today };
    await expect(recordGate('E1', { ...base, pickup_id: 'INCONNU' }, context)).rejects.toBeInstanceOf(GateRefusal);
    await expect(recordGate('E1', { ...base, pickup_id: 'A2' }, context)).rejects.toMatchObject({ code: 'not_authorized' });
    await expect(recordGate('E1', { ...base, pickup_id: 'A1' }, { ...context, doubt: true })).rejects.toMatchObject({ code: 'doubt' });
    await expect(recordGate('E1', { type: 'hold', learner_id: 'L1', learner_name: 'Lina', school_day: today, remark: ' ' }, context)).rejects.toMatchObject({ code: 'remark_required' });
    expect(await listGateQueue()).toEqual([]);
  });

  it('garde les gestes sans réseau puis les rejoue au retour, avec le même identifiant client, une seule fois', async () => {
    const context = { pkg: pkg(), doubt: false };
    await recordGate('E1', { type: 'arrival', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today }, context);
    await recordGate('E1', { type: 'handover', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today, pickup_id: 'A1' }, context);
    expect(JSON.stringify(await idbAll('cache'))).not.toContain('Lina');

    mockApi({ 'POST /public-nursery/pickup/arrivals': down, 'POST /public-nursery/pickup/handovers': down });
    expect(await flushGate()).toMatchObject({ sent: 0, offline: true });
    const queued = await listGateQueue();
    expect(queued.map((i) => [i.type, i.status])).toEqual([['arrival', 'pending'], ['handover', 'pending']]);

    const calls = mockApi({
      'POST /public-nursery/pickup/arrivals': { data: { id: 'EV1' } },
      'POST /public-nursery/pickup/handovers': { data: { status: 'handed_over', reason: 'authorized', event: {} } },
    });
    expect(await flushGate()).toMatchObject({ sent: 2, conflicts: 0, offline: false });
    const arrival = find(calls, 'POST', '/public-nursery/pickup/arrivals')[0];
    expect(arrival?.body).toMatchObject({ learner_id: 'L1', school_day: today, client_uuid: queued[0]?.client_uuid });
    expect(String(arrival?.body?.occurred_at)).toMatch(/[+-]\d{2}:\d{2}$/);
    expect(find(calls, 'POST', '/public-nursery/pickup/handovers')[0]?.body).toEqual({
      learner_id: 'L1', school_day: today, client_uuid: queued[1]?.client_uuid, origin: 'offline', pickup_id: 'A1', declared_name: 'Tante Rose',
    });
    expect(arrival?.headers['X-Establishment-Id']).toBe('E1');
    expect(await listGateQueue()).toEqual([]);
    expect(await flushGate()).toMatchObject({ sent: 0 });
  });

  it('affiche les conflits : remise refusée par le serveur, autorisation périmée ; un refus attendu est simplement envoyé', async () => {
    const context = { pkg: pkg(), doubt: false };
    await recordGate('E1', { type: 'handover', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today, pickup_id: 'A1' }, context);
    await recordGate('E1', { type: 'refusal', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today, declared_name: 'Inconnu' }, context);
    let handovers = 0;
    mockApi({
      'POST /public-nursery/pickup/handovers': (call) => {
        handovers++;
        if (call.body?.pickup_id) return jsonResponse({ data: { status: 'refused', reason: 'restricted', event: {} } }, 422);
        return jsonResponse({ data: { status: 'refused', reason: 'unauthorized', event: {} } }, 422);
      },
    });
    expect(await flushGate()).toMatchObject({ sent: 1, conflicts: 1 });
    expect(handovers).toBe(2);
    const [conflict] = await listGateQueue();
    expect(conflict).toMatchObject({ type: 'handover', status: 'conflict', reason: 'restricted' });

    await discardGate(conflict!.client_uuid);
    await recordGate('E1', { type: 'handover', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today, pickup_id: 'A1' }, context);
    mockApi({ 'POST /public-nursery/pickup/handovers': { data: { status: 'handed_over', reason: 'stale_authorization', event: {} } } });
    expect(await flushGate()).toMatchObject({ conflicts: 1 });
    expect((await listGateQueue())[0]).toMatchObject({ status: 'conflict', reason: 'stale_authorization' });
  });
});

describe('porte hors ligne : écran', () => {
  it('la route est réservée aux maternelles et à la porte', () => {
    const route = publicNurseryRoutes.find((r) => r.name === 'pn-gate-offline');
    expect(route?.meta?.establishmentTypes).toEqual(['nursery']);
    expect(route?.meta?.perms).toContain('public_nursery.pickup.handover');
  });

  it('télécharge le paquet, garde une arrivée sans réseau puis la synchronise ; mode doute sans remise', async () => {
    mockApi({ 'GET /public-nursery/pickup/package': { data: pkg() } });
    const { wrapper } = await mountView(GateOfflineView, {}, '/maternelle/porte-hors-ligne');
    await wrapper.get('[data-test="download"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="package"]').text()).toContain('2 enfants');
    expect(wrapper.find('[data-test="doubt"]').exists()).toBe(false);
    const options = wrapper.get('[data-test="person-L1"]').findAll('option').map((o) => o.text());
    expect(options.some((o) => o.includes('Tante Rose'))).toBe(true);
    expect(options.some((o) => o.includes('Oncle Paul')), 'Personne restreinte : jamais proposée.').toBe(false);

    mockApi({ 'POST /public-nursery/pickup/arrivals': down });
    await wrapper.get('[data-test="arrive-L1"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="pending"]').text()).toContain('1 geste');
    expect(wrapper.get('[data-test="notice"]').text()).toContain('Toujours hors ligne');

    const calls = mockApi({ 'POST /public-nursery/pickup/arrivals': { data: { id: 'EV1' } } });
    window.dispatchEvent(new Event('online'));
    await flushPromises();
    expect(find(calls, 'POST', '/public-nursery/pickup/arrivals')).toHaveLength(1);
    expect(wrapper.get('[data-test="pending"]').text()).toContain('0 geste');
    wrapper.unmount();
  });

  it('en mode doute, affiche la direction et bloque la remise', async () => {
    mockApi({ 'GET /public-nursery/pickup/package': { data: pkg({ date: '2020-01-01' }) } });
    const { wrapper } = await mountView(GateOfflineView, {}, '/maternelle/porte-hors-ligne');
    await wrapper.get('[data-test="download"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="doubt"]').text()).toContain('+237600000099');
    expect(wrapper.get('[data-test="handover-L1"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('montre un conflit et permet de le retirer', async () => {
    await recordGate('E1', { type: 'handover', learner_id: 'L1', learner_name: 'Lina Fictive', school_day: today, pickup_id: 'A1' }, { pkg: pkg(), doubt: false });
    mockApi({ 'POST /public-nursery/pickup/handovers': jsonResponse({ data: { status: 'refused', reason: 'restricted', event: {} } }, 422) });
    window.confirm = () => true;
    const { wrapper } = await mountView(GateOfflineView, {}, '/maternelle/porte-hors-ligne');
    await flushPromises();
    expect(wrapper.get('[data-test="conflicts"]').text()).toContain('restriction de garde');
    await wrapper.get('[data-test="conflicts"] button').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="conflicts"]').exists()).toBe(false);
    wrapper.unmount();
  });
});
