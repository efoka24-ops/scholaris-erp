import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { routes } from '@/router/routes';
import { guard } from '@/router';
import { fr } from '@/i18n/fr';
import { en } from '@/i18n/en';
import { platformRegularisationFr } from '@/i18n/platform-regularisation-fr';
import { platformRegularisationEn } from '@/i18n/platform-regularisation-en';
import { actions, type MyRegularisation, type RegularisationItem } from '@/api/platform';
import RegularisationView from '@/views/platform/RegularisationView.vue';
import PlatformRegularisationView from '@/views/platform/PlatformRegularisationView.vue';
import ClientBillingPanel from '@/views/platform/ClientBillingPanel.vue';
import { clearRegularisation } from '@/views/platform/regularisationGate';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(() => {
  freshEnvironment();
  clearPending();
  clearRegularisation();
});

const directorMe: Me = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Emmanuel', last_name: 'Foka', greeting: 'Emmanuel', context: { establishment_id: null, role: null } };
const directorTile = tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] });

const item = (over: Partial<RegularisationItem> = {}): RegularisationItem => ({
  tenant_id: 'T1', tenant_name: 'ETS FOKA', app_key: 'ipes', label: 'IPES', state: 'suspended', level: 'locked', bucket: 'regularisation', origin: 'auto',
  due_on: '2026-12-30', period_from: '2026-12-31', since: '2027-01-14', days_late: 16, days_elapsed: 2, months: 1, monthly_total_xaf: 65000, base_price_xaf: 60000,
  options: [{ option_key: 'sms', label_fr: 'SMS aux parents', label_en: 'Parent SMS', price_xaf: 5000 }], amount_due_xaf: 65000, promise_until: null, promise_reason: null,
  receipts: [{ id: 'P0', receipt_number: 'SCH-2026-000001', amount_xaf: 60000, paid_at: '2026-11-30T10:00:00+01:00' }], ...over,
});

const mine = (over: Partial<MyRegularisation> = {}): MyRegularisation => ({
  mode: 'locked', locked: true, can_pay: true, items: [item()], contact: { name: 'TRU GROUP', email: 'contact@tru.test', phone: '+237600000000' }, ...over,
});

async function runGuard(path: string): Promise<unknown> {
  const router = createRouter({ history: createMemoryHistory(), routes });
  return guard(router.resolve(path) as never);
}

describe('mode régularisation : garde de routes', () => {
  it('niveau « bloqué » : toute page renvoie vers l’écran de régularisation, qui reste ouvert', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine() } });
    expect(await runGuard('/')).toEqual({ name: 'regularisation' });
    expect(await runGuard('/applications')).toEqual({ name: 'regularisation' });
    expect(await runGuard('/regularisation')).toBe(true);
  });

  it('lecture seule ou à jour : aucune redirection', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ mode: 'read_only', locked: false }) } });
    expect(await runGuard('/')).toBe(true);
  });

  it('erreur réseau ou route absente : la navigation n’est jamais bloquée côté navigateur', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({});
    expect(await runGuard('/')).toBe(true);
  });

  it('l’administrateur de plateforme ne passe jamais par ce contrôle', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = platformMe.id;
    const calls = mockApi({});
    expect(await runGuard('/platform')).toBe(true);
    expect(calls.some((c) => c.path === '/me/regularisation')).toBe(false);
  });
});

describe('écran de régularisation du client', () => {
  it('affiche le montant dû, le détail, la période, les jours de retard, le reçu et le contact ; la direction peut payer', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({
      'GET /me/regularisation': { data: mine() },
      'POST /applications/ipes/payments': { data: { id: 'P1', reference: 'PLT-1' } },
    });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    const text = wrapper.text();
    expect(text).toContain('Régularisation de paiement');
    expect(text).toContain('IPES');
    expect(text).toContain('SMS aux parents');
    expect(text).toContain('16 j');
    expect(text).toContain('SCH-2026-000001');
    expect(text).toContain('TRU GROUP');
    expect(wrapper.find('a[href="tel:+237600000000"]').exists()).toBe(true);
    expect(text).toContain('Orange Money');
    expect(text).toContain('MTN');

    const orange = wrapper.findAll('button').find((b) => b.text().includes('Orange Money'))!;
    await orange.trigger('click');
    await wrapper.find('input[type="tel"]').setValue('+237 690 00 00 00');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    const pay = calls.find((c) => c.method === 'POST');
    expect(pay?.body).toEqual({ months: 1, channel: 'orange_money', payer_phone: '+237690000000' });
    expect(pay?.headers['Idempotency-Key']).toBeTruthy();
    wrapper.unmount();
  });

  it('un utilisateur sans droit de paiement voit le message et le contact, sans bouton de paiement', async () => {
    signIn(teacherMe, [tile()], 'teacher');
    mockApi({ 'GET /me/regularisation': { data: mine({ can_pay: false }) } });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    expect(wrapper.text()).toContain('Seuls la direction');
    expect(wrapper.text()).toContain('TRU GROUP');
    expect(wrapper.findAll('button').some((b) => b.text().includes('Orange Money'))).toBe(false);
    wrapper.unmount();
  });

  it('client à jour : message clair et lien vers l’accueil', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ mode: 'ok', locked: false, items: [] }) } });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    expect(wrapper.text()).toContain('Aucun paiement en attente');
    wrapper.unmount();
  });
});

describe('liste « À régulariser » et fiche client (super admin)', () => {
  it('liste les situations, filtre par catégorie et propose les actions rapides', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = platformMe.id;
    const calls = mockApi({
      'GET /platform/regularisation': (c) => ({
        data: c.query['filter[bucket]'] === 'promise' ? [] : [item({ bucket: 'regularisation' })],
        meta: { total: 1, amount_due_xaf: 65000, counts: { past_due: 0, regularisation: 1, promise: 0 } },
      }),
    });
    const { wrapper } = await mountView(PlatformRegularisationView, {}, '/platform/a-regulariser');
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.text()).toContain('ETS FOKA');
    expect(wrapper.text()).toContain('Débloquer');
    expect(wrapper.text()).toContain('Accorder un délai');
    expect(wrapper.text()).toContain('Saisir un paiement');

    const promise = wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'promise')!;
    await promise.setValue(true);
    await flushPromises();
    expect(calls.at(-1)?.query['filter[bucket]']).toBe('promise');
    wrapper.unmount();
  });

  it('délai accordé : date et motif obligatoires, puis appel PATCH/POST de la bonne route', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = platformMe.id;
    const calls = mockApi({
      'GET /platform/regularisation': { data: [item({ bucket: 'past_due', level: null, state: 'past_due' })], meta: { total: 1, amount_due_xaf: 65000, counts: { past_due: 1, regularisation: 0, promise: 0 } } },
      'POST /platform/clients/T1/regularisation/delay': { data: [] },
    });
    const { wrapper } = await mountView(PlatformRegularisationView, {}, '/platform/a-regulariser');
    const open = wrapper.findAll('button').find((b) => b.text().includes('Accorder un délai'))!;
    await open.trigger('click');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Le motif est obligatoire');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    const until = new Date(Date.now() + 10 * 86_400_000).toISOString().slice(0, 10);
    await wrapper.find('textarea').setValue('Promesse écrite du directeur');
    await wrapper.find('input[type="date"]').setValue(until);
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ until, reason: 'Promesse écrite du directeur', app_key: 'ipes' });
    wrapper.unmount();
  });

  it('fiche client : détail du montant dû, options, blocage en cours et actions de régularisation', async () => {
    signIn(platformMe, [], null);
    const p = usePlatformStore();
    p.isAdmin = true;
    p.checkedFor = platformMe.id;
    mockApi({});
    const app = {
      id: 'ENT1', tenant_id: 'T1', app_key: 'ipes', label: 'IPES', target: 'higher', state: 'suspended', source: 'access_request', started_on: '2026-10-01',
      trial_ends_on: '2026-12-29', trial_days_left: 0, paid_until: null, due_on: '2026-12-29', monthly_price_xaf: 60000, price_overridden: false, past_due_since: null,
      suspended_at: null, closed_at: null, state_reason: 'unpaid', writable: false, readable: false,
      terms: { trial_days: 90, trial_days_overridden: false, grace_days: 15, grace_days_overridden: false, billing_period_months: 3, billing_period_overridden: true, regularisation_level: 'locked', regularisation_level_overridden: true },
      options: [{ option_key: 'sms', label_fr: 'SMS aux parents', label_en: 'Parent SMS', price_xaf: 5000 }], options_total_xaf: 5000, monthly_total_xaf: 65000, promise_until: null,
      regularisation: { level: 'locked', origin: 'auto', since: '2027-01-14', days_elapsed: 2 },
    } as never;
    const { wrapper } = await mountView(ClientBillingPanel, { tenant: 'T1', clientName: 'ETS FOKA', app, serviceOptions: [] }, '/platform/clients/T1');
    const text = wrapper.text();
    expect(text).toContain('Tarif de base');
    expect(text).toContain('SMS aux parents');
    expect(text).toContain('Trimestrielle');
    expect(text).toContain('Accès bloqué');
    expect(text).toContain('Débloquer');
    expect(text).toContain('Accorder un délai');
    wrapper.unmount();
  });
});

describe('contrat des appels et textes', () => {
  it('les actions envoient le motif, la portée et les valeurs attendues', () => {
    expect(actions.lockClient('T1', 'Impayé', 'locked', 'ipes')).toEqual({ method: 'POST', path: '/platform/clients/T1/regularisation/lock', body: { reason: 'Impayé', level: 'locked', app_key: 'ipes' } });
    expect(actions.unlockClient('T1', 'Paiement annoncé', null, null).body).toEqual({ reason: 'Paiement annoncé' });
    expect(actions.grantDelay('T1', '2027-02-01', 'Promesse', null).body).toEqual({ until: '2027-02-01', reason: 'Promesse' });
    expect(actions.setTerms('T1', 'ipes', { grace_days: 3, billing_period_months: null }, 'Accord').body).toEqual({ grace_days: 3, billing_period_months: null, reason: 'Accord' });
    expect(actions.subscribeOption('T1', 'ipes', 'sms', 4000, 'Remise')).toMatchObject({ method: 'POST', path: '/platform/clients/T1/applications/ipes/options', body: { option_key: 'sms', price_xaf: 4000, reason: 'Remise' } });
    expect(actions.clientLevel('T1', null, 'Retour au défaut').body).toEqual({ level: null, reason: 'Retour au défaut' });
    expect(actions.deleteServiceOption('sms')).toMatchObject({ method: 'DELETE', path: '/platform/service-options/sms' });
  });

  it('chaque texte existe en français et en anglais, et est fusionné dans les dictionnaires', () => {
    expect(Object.keys(platformRegularisationEn).sort()).toEqual(Object.keys(platformRegularisationFr).sort());
    for (const key of Object.keys(platformRegularisationFr)) {
      expect((fr as Record<string, string>)[key]).toBeTruthy();
      expect((en as Record<string, string>)[key]).toBeTruthy();
    }
  });
});
