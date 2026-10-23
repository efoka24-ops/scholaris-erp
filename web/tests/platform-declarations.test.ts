import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { actions, normalizeCameroonPhone, type MyRegularisation, type RegularisationDeclaration, type RegularisationItem } from '@/api/platform';
import { formatDate } from '@/lib/format';
import { setLocale } from '@/i18n';
import AppShell from '@/components/AppShell.vue';
import RegularisationBanner from '@/components/RegularisationBanner.vue';
import RegularisationView from '@/views/platform/RegularisationView.vue';
import PlatformRegularisationView from '@/views/platform/PlatformRegularisationView.vue';
import PlatformSettingsView from '@/views/platform/PlatformSettingsView.vue';
import { clearRegularisation } from '@/views/platform/regularisationGate';
import { clearPending } from '@/views/platform/useSensitiveAction';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(() => {
  freshEnvironment();
  clearPending();
  clearRegularisation();
});

const directorMe: Me = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Emmanuel', last_name: 'Foka', greeting: 'Emmanuel', context: { establishment_id: null, role: null } };
const directorTile = tile({ type: 'higher', roles: [{ code: 'directeur', label: 'Directeur' }] });

const item = (over: Partial<RegularisationItem> = {}): RegularisationItem => ({
  tenant_id: 'T1', tenant_name: 'ETS FOKA', app_key: 'ipes', label: 'IPES', state: 'suspended', level: 'read_only', bucket: 'regularisation', origin: 'auto',
  due_on: '2026-12-30', period_from: '2026-12-31', since: '2027-01-14', days_late: 16, days_elapsed: 2, months: 1, monthly_total_xaf: 60000, base_price_xaf: 60000,
  options: [], amount_due_xaf: 60000, balance_xaf: 60000, credit_xaf: 0, blocks_on: null, days_left: null, promise_until: null, promise_reason: null, receipts: [], ...over,
});

const pastDueItem = (): RegularisationItem => item({ state: 'past_due', level: null, bucket: 'past_due', blocks_on: '2027-01-14', days_left: 5 });

const declaration = (over: Partial<RegularisationDeclaration> = {}): RegularisationDeclaration => ({
  id: 'D1', tenant_id: 'T1', tenant_name: 'ETS FOKA', app_key: 'ipes', label: 'IPES', kind: 'total', channel: 'orange_money', payer_phone_masked: '+2376*****345',
  reference: 'OM240301.AB12', declared_amount_xaf: 60000, balance_xaf: 60000, state: 'declared', verified_via: null, confirmed_amount_xaf: null, decision_reason: null,
  declared_at: '2027-01-22T09:00:00+01:00', expires_on: '2027-01-29', decided_at: null, ...over,
});

const mine = (over: Partial<MyRegularisation> = {}): MyRegularisation => ({
  mode: 'read_only', locked: false, can_pay: true, items: [item()], contact: { name: 'TRU GROUP', email: 'contact@tru.test', phone: '+237699000001' },
  payment_numbers: [{ channel: 'orange_money', phone: '+237699000002', holder: 'TRU GROUP SARL' }, { channel: 'mtn_momo', phone: '+237670000003', holder: 'TRU GROUP SARL' }],
  requests: [], partial_min_xaf: 1000, tolerance_xaf: 100, expiry_days: 7, ...over,
});

const asPlatformAdmin = (): void => {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = platformMe.id;
};

describe('bandeau d’abonnement', () => {
  it('impayé dans le délai de grâce : date de blocage, jours restants et bouton Régulariser pour le responsable', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ mode: 'past_due', items: [pastDueItem()] }) } });
    const { wrapper } = await mountView(RegularisationBanner, {}, '/');
    const region = wrapper.find('[role="region"]');
    expect(region.exists()).toBe(true);
    expect(region.attributes('aria-label')).toBe('Situation de l’abonnement');
    expect(wrapper.text()).toContain(`Abonnement en retard : régularisez avant le ${formatDate('2027-01-14', 'fr')} pour éviter la suspension.`);
    expect(wrapper.text()).toContain('(5 jour(s) restant(s))');
    const link = wrapper.find('a');
    expect(link.text()).toBe('Régulariser');
    expect(link.attributes('href')).toBe('/regularisation');
    wrapper.unmount();
  });

  it('lecture seule : message « lecture et export seulement » ; en anglais aussi', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine() } });
    const { wrapper } = await mountView(RegularisationBanner, {}, '/');
    expect(wrapper.text()).toContain('Abonnement suspendu : lecture et export seulement.');
    wrapper.unmount();

    clearRegularisation();
    setLocale('en');
    const second = await mountView(RegularisationBanner, {}, '/');
    expect(second.wrapper.text()).toContain('Subscription suspended: read and export only.');
    expect(second.wrapper.find('a').text()).toBe('Regularise');
    second.wrapper.unmount();
  });

  it('une personne non concernée par le paiement voit un message d’information, sans bouton', async () => {
    signIn(teacherMe, [tile()], 'enseignant');
    mockApi({ 'GET /me/regularisation': { data: mine({ can_pay: false }) } });
    const { wrapper } = await mountView(RegularisationBanner, {}, '/');
    expect(wrapper.text()).toContain('Abonnement suspendu');
    expect(wrapper.text()).toContain('seul le responsable peut régulariser');
    expect(wrapper.find('a').exists()).toBe(false);
    wrapper.unmount();
  });

  it('signale une déclaration de paiement en cours de vérification', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ requests: [declaration()] }) } });
    const { wrapper } = await mountView(RegularisationBanner, {}, '/');
    expect(wrapper.text()).toContain('Votre paiement déclaré est en cours de vérification.');
    wrapper.unmount();
  });

  it('rien quand l’abonnement est à jour, sur l’écran de régularisation, pour la plateforme ou hors session', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ mode: 'ok', items: [] }) } });
    const ok = await mountView(RegularisationBanner, {}, '/');
    expect(ok.wrapper.find('[role="region"]').exists()).toBe(false);
    ok.wrapper.unmount();

    clearRegularisation();
    mockApi({ 'GET /me/regularisation': { data: mine() } });
    const onScreen = await mountView(RegularisationBanner, {}, '/regularisation');
    expect(onScreen.wrapper.find('[role="region"]').exists()).toBe(false);
    onScreen.wrapper.unmount();

    clearRegularisation();
    asPlatformAdmin();
    const calls = mockApi({ 'GET /me/regularisation': { data: mine() } });
    const admin = await mountView(RegularisationBanner, {}, '/platform');
    expect(admin.wrapper.find('[role="region"]').exists()).toBe(false);
    expect(calls.some((c) => c.path === '/me/regularisation')).toBe(false);
    admin.wrapper.unmount();
  });

  it('cache court : un changement d’écran ne relit pas la situation à chaque fois', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /me/regularisation': { data: mine() } });
    const { wrapper, router } = await mountView(RegularisationBanner, {}, '/');
    await router.push('/applications');
    await flushPromises();
    await router.push('/');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/me/regularisation')).toHaveLength(1);
    expect(wrapper.text()).toContain('lecture et export seulement');
    wrapper.unmount();
  });

  it('est monté dans le cadre de l’application (AppShell)', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine() } });
    const { wrapper } = await mountView(AppShell, {}, '/applications');
    expect(wrapper.text()).toContain('Abonnement suspendu : lecture et export seulement.');
    wrapper.unmount();
  });
});

describe('déclaration d’un paiement (écran de régularisation)', () => {
  const open = async (data: MyRegularisation, extra: Record<string, unknown> = {}) => {
    signIn(directorMe, [directorTile], 'directeur');
    const calls = mockApi({ 'GET /me/regularisation': { data }, ...extra } as never);
    const view = await mountView(RegularisationView, {}, '/regularisation');
    await view.wrapper.findAll('button').find((b) => b.text() === 'Déclarer mon paiement')!.trigger('click');
    await flushPromises();
    return { ...view, calls };
  };

  it('affiche où payer, valide numéro, montant et référence, puis envoie la déclaration avec une clé d’idempotence', async () => {
    const created = declaration();
    const { wrapper, calls } = await open(mine(), { 'POST /me/regularisation/declarations': () => jsonResponse({ data: created }, 201) });
    const text = wrapper.text();
    expect(text).toContain('+237699000002');
    expect(text).toContain('TRU GROUP SARL');
    expect(text).toContain('Déclarer mon paiement');

    // Formulaire vide ou invalide : aucune requête.
    await wrapper.find('form[aria-labelledby]').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Numéro mobile camerounais attendu');
    expect(wrapper.text()).toContain('La référence est obligatoire');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    // Moyen MTN : son numéro de paiement est affiché.
    const mtn = wrapper.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'mtn_momo')!;
    await mtn.setValue(true);
    expect(wrapper.text()).toContain('+237670000003');

    const form = wrapper.find('form[aria-labelledby]');
    await form.find('input[type="tel"]').setValue('699 01 23 45');
    await form.find('input[type="text"]').setValue('MTN 998877');
    await form.trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.path).toBe('/me/regularisation/declarations');
    expect(post?.body).toMatchObject({ app_key: 'ipes', kind: 'total', channel: 'mtn_momo', payer_phone: '+237699012345', reference: 'MTN 998877' });
    expect(post?.body).not.toHaveProperty('amount_xaf');
    expect(String(post?.body?.idempotency_key).length).toBeGreaterThanOrEqual(8);
    expect(wrapper.text()).toContain('Déclaration enregistrée');
    wrapper.unmount();
  });

  it('paiement partiel : montant contrôlé entre le minimum et le solde moins un', async () => {
    const { wrapper, calls } = await open(mine(), { 'POST /me/regularisation/declarations': () => jsonResponse({ data: declaration({ kind: 'partial', declared_amount_xaf: 20000 }) }, 201) });
    const form = wrapper.find('form[aria-labelledby]');
    await form.findAll('input[type="radio"]').find((i) => (i.element as HTMLInputElement).value === 'partial')!.setValue(true);
    await form.find('input[type="tel"]').setValue('699 01 23 45');
    await form.find('input[type="text"]').setValue('OM240301.AB12');

    for (const bad of ['500', '60000', '12.5', '']) {
      await form.find('input[type="number"]').setValue(bad);
      await form.trigger('submit');
      await flushPromises();
      expect(wrapper.text()).toContain('Saisissez un montant entier entre');
    }
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await form.find('input[type="number"]').setValue('20000');
    await form.trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toMatchObject({ kind: 'partial', amount_xaf: 20000, channel: 'orange_money' });
    wrapper.unmount();
  });

  it('une erreur réseau conserve la clé d’idempotence : le renvoi ne crée pas de doublon', async () => {
    let attempts = 0;
    const { wrapper, calls } = await open(mine(), {
      'POST /me/regularisation/declarations': () => {
        attempts += 1;
        return attempts === 1 ? jsonResponse({ type: 'about:blank', title: 'Erreur', status: 500, detail: 'Erreur interne', trace_id: 'T' }, 500) : jsonResponse({ data: declaration() }, 201);
      },
    });
    const form = wrapper.find('form[aria-labelledby]');
    await form.find('input[type="tel"]').setValue('699 01 23 45');
    await form.find('input[type="text"]').setValue('OM240301.AB12');
    await form.trigger('submit');
    await flushPromises();
    await form.trigger('submit');
    await flushPromises();
    const keys = calls.filter((c) => c.method === 'POST').map((c) => c.body?.idempotency_key);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    wrapper.unmount();
  });

  it('une déclaration en cours remplace le bouton ; son état est suivi (déclarée, confirmée, rejetée avec motif, expirée)', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({
      'GET /me/regularisation': {
        data: mine({
          requests: [
            declaration({ id: 'D1' }),
            declaration({ id: 'D2', state: 'verified', verified_via: 'webhook', confirmed_amount_xaf: 59950, reference: 'REF-0002' }),
            declaration({ id: 'D3', state: 'rejected', decision_reason: 'Aucun paiement sur le relevé', reference: 'REF-0003' }),
            declaration({ id: 'D4', state: 'expired', reference: 'REF-0004' }),
          ],
        }),
      },
    });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    const text = wrapper.text();
    expect(wrapper.findAll('button').some((b) => b.text() === 'Déclarer mon paiement')).toBe(false);
    expect(text).toContain('Une déclaration est déjà en cours de vérification');
    expect(text).toContain('Déclarée, en cours de vérification');
    expect(text).toContain('Confirmée');
    expect(text).toContain('Confirmé automatiquement.');
    expect(text).toContain('Motif : Aucun paiement sur le relevé');
    expect(text).toContain('Non confirmée à temps');
    expect(text).toContain('+2376*****345');
    expect(text).not.toContain('699012345');
    wrapper.unmount();
  });

  it('déclaration annulée (paiement confirmé puis annulé) : libellé « Annulée » et motif, en français et en anglais', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    const cancelled = declaration({ id: 'D5', state: 'cancelled', decision_reason: 'Paiement annulé : relevé erroné', reference: 'REF-0005' });
    mockApi({ 'GET /me/regularisation': { data: mine({ requests: [cancelled] }) } });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    const tag = wrapper.findAll('.tag').find((t) => t.text() === 'Annulée');
    expect(tag?.classes()).toContain('tag--warning');
    expect(wrapper.text()).toContain('Annulée par la plateforme. Paiement annulé : relevé erroné — le montant reste dû');
    expect(wrapper.text()).not.toContain('rg.decl.');
    expect(wrapper.text()).not.toContain('Non confirmée à temps');
    wrapper.unmount();

    clearRegularisation();
    setLocale('en');
    const second = await mountView(RegularisationView, {}, '/regularisation');
    expect(second.wrapper.text()).toContain('Cancelled');
    expect(second.wrapper.text()).toContain('Cancelled by the platform. Paiement annulé : relevé erroné — the amount is still due');
    expect(second.wrapper.text()).not.toContain('rg.decl.');
    second.wrapper.unmount();
  });

  it('sans droit de paiement : ni bouton de déclaration, ni numéros de paiement', async () => {
    signIn(teacherMe, [tile()], 'enseignant');
    mockApi({ 'GET /me/regularisation': { data: mine({ can_pay: false }) } });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    expect(wrapper.findAll('button').some((b) => b.text() === 'Déclarer mon paiement')).toBe(false);
    expect(wrapper.text()).not.toContain('+237699000002');
    wrapper.unmount();
  });

  it('le crédit d’un paiement partiel réduit le solde affiché', async () => {
    signIn(directorMe, [directorTile], 'directeur');
    mockApi({ 'GET /me/regularisation': { data: mine({ items: [item({ credit_xaf: 20000, balance_xaf: 40000 })] }) } });
    const { wrapper } = await mountView(RegularisationView, {}, '/regularisation');
    expect(wrapper.find('.amount__value').text().replace(/\s/g, '')).toContain('40000');
    expect(wrapper.text()).toContain('Déjà versé');
    wrapper.unmount();
  });
});

describe('paiements déclarés à vérifier (super admin)', () => {
  const setup = async (extra: Record<string, unknown> = {}) => {
    asPlatformAdmin();
    const calls = mockApi({
      'GET /platform/regularisation': { data: [], meta: { total: 0, amount_due_xaf: 0, counts: { past_due: 0, regularisation: 0, promise: 0 } } },
      'GET /platform/regularisation/declarations': (c: { query: Record<string, string> }) => ({
        data: c.query['filter[state]'] === 'verified' ? [] : [declaration(), declaration({ id: 'D2', channel: 'mtn_momo', kind: 'partial', declared_amount_xaf: 20000, reference: 'MTN-77', review_note: 'Écart de montant : reçu 50000 FCFA, déclaré 60000 FCFA.' })],
        meta: { total: 2, counts: { declared: 2, verified: 0, rejected: 0, expired: 0 } },
      }),
      ...extra,
    } as never);
    const view = await mountView(PlatformRegularisationView, {}, '/platform/a-regulariser');
    return { ...view, calls };
  };

  it('liste les demandes avec les éléments déclarés (numéro masqué), la note de rapprochement et les boutons Confirmer / Rejeter', async () => {
    const { wrapper } = await setup();
    const text = wrapper.text();
    expect(text).toContain('Paiements déclarés à vérifier');
    expect(text).toContain('OM240301.AB12');
    expect(text).toContain('+2376*****345');
    expect(text).toContain('Partiel');
    expect(text).toContain('À vérifier : Écart de montant');
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Confirmer le paiement')).toHaveLength(2);
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Rejeter')).toHaveLength(2);
    wrapper.unmount();
  });

  it('filtre par état et par moyen, et exporte', async () => {
    const { wrapper, calls } = await setup({ 'GET /platform/regularisation/declarations/export': () => new Response('a;b', { status: 200, headers: { 'Content-Type': 'text/csv' } }) });
    const verified = wrapper.findAll('input[name="rg-dadm-state"]').find((i) => (i.element as HTMLInputElement).value === 'verified')!;
    await verified.setValue(true);
    await flushPromises();
    expect(calls.filter((c) => c.path === '/platform/regularisation/declarations').at(-1)?.query['filter[state]']).toBe('verified');
    expect(wrapper.text()).toContain('Aucun paiement déclaré.');
    await wrapper.find('#rg-dadm-channel').setValue('mtn_momo');
    await flushPromises();
    expect(calls.filter((c) => c.path === '/platform/regularisation/declarations').at(-1)?.query['filter[channel]']).toBe('mtn_momo');
    wrapper.unmount();
  });

  it('filtre « Annulée » : libellé, compteur (0 si le serveur ne le renvoie pas) et requête filtrée', async () => {
    const { wrapper, calls } = await setup();
    const radio = wrapper.findAll('input[name="rg-dadm-state"]').find((i) => (i.element as HTMLInputElement).value === 'cancelled');
    expect(radio).toBeDefined();
    expect(wrapper.text()).toContain('Annulée (0)');
    expect(wrapper.text()).not.toContain('rg.decl.');
    await radio!.setValue(true);
    await flushPromises();
    expect(calls.filter((c) => c.path === '/platform/regularisation/declarations').at(-1)?.query['filter[state]']).toBe('cancelled');
    wrapper.unmount();
  });

  it('confirmer : montant réellement reçu obligatoire, puis appel de la route de confirmation', async () => {
    const { wrapper, calls } = await setup({ 'POST /platform/regularisation/declarations/D1/confirm': { data: { ...declaration({ state: 'verified' }), unlocked: true } } });
    await wrapper.findAll('button').filter((b) => b.text() === 'Confirmer le paiement')[0]!.trigger('click');
    await wrapper.find('#rg-dadm-recv-D1').setValue('0');
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Saisissez un montant entier supérieur à zéro');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await wrapper.find('#rg-dadm-recv-D1').setValue('59950');
    await wrapper.find('#rg-dadm-ref-D1').setValue('OM240301.AB12');
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post?.path).toBe('/platform/regularisation/declarations/D1/confirm');
    expect(post?.body).toEqual({ received_amount_xaf: 59950, reference: 'OM240301.AB12' });
    expect(wrapper.text()).toContain('Paiement confirmé : l’accès du client est rétabli.');
    wrapper.unmount();
  });

  it('paiement partiel confirmé : message dédié et promesse de paiement facultative', async () => {
    const { wrapper, calls } = await setup({ 'POST /platform/regularisation/declarations/D2/confirm': { data: { ...declaration({ id: 'D2', state: 'verified', kind: 'partial' }), unlocked: false } } });
    await wrapper.findAll('button').filter((b) => b.text() === 'Confirmer le paiement')[1]!.trigger('click');
    const until = new Date(Date.now() + 10 * 86_400_000).toISOString().slice(0, 10);
    await wrapper.find('#rg-dadm-promise-D2').setValue(until);
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ received_amount_xaf: 20000, promise_until: until });
    expect(wrapper.text()).toContain('Paiement partiel enregistré');
    wrapper.unmount();
  });

  it('rejeter : motif obligatoire, puis appel de la route de rejet', async () => {
    const { wrapper, calls } = await setup({ 'POST /platform/regularisation/declarations/D1/reject': { data: declaration({ state: 'rejected' }) } });
    await wrapper.findAll('button').filter((b) => b.text() === 'Rejeter')[0]!.trigger('click');
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Le motif est obligatoire');
    await wrapper.find('#rg-dadm-reason-D1').setValue('Aucun paiement de 60 000 FCFA sur le relevé');
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ reason: 'Aucun paiement de 60 000 FCFA sur le relevé' });
    expect(wrapper.text()).toContain('Demande rejetée');
    wrapper.unmount();
  });

  it('confirmation récente exigée : l’action est mise de côté et la double authentification proposée', async () => {
    const { wrapper } = await setup({
      'POST /platform/regularisation/declarations/D1/confirm': () => jsonResponse({ type: 'https://api.scholaris.cm/errors/two-factor-recent-required', title: '2FA', status: 403, detail: 'Validez à nouveau', trace_id: 'T' }, 403),
    });
    await wrapper.findAll('button').filter((b) => b.text() === 'Confirmer le paiement')[0]!.trigger('click');
    await wrapper.find('form.panel').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Double authentification à confirmer');
    expect(wrapper.findAll('button').some((b) => b.text() === 'Ressaisir mon code')).toBe(true);
    wrapper.unmount();
  });
});

describe('réglages : contact, numéros de paiement et vérification', () => {
  const settings = {
    access_code_ttl_minutes: { value: 1440, default: 1440, min: 5, max: 10080, updated_at: null },
    regularisation_default_level: { value: 0, default: 0, min: 0, max: 1, updated_at: null },
    regularisation_request_expiry_days: { value: 7, default: 7, min: 1, max: 60, updated_at: null },
    regularisation_amount_tolerance_xaf: { value: 100, default: 100, min: 0, max: 10000, updated_at: null },
    regularisation_partial_min_xaf: { value: 1000, default: 1000, min: 100, max: 1000000, updated_at: null },
    regularisation_partial_mode: { value: 0, default: 0, min: 0, max: 1, updated_at: null },
  };
  const contact = {
    stored: { name: null, email: null, phone: null, payments: [] },
    effective: { name: 'TRU GROUP', email: 'env@tru.test', phone: null, payments: [] },
    updated_at: null,
  };
  const setup = async (extra: Record<string, unknown> = {}) => {
    asPlatformAdmin();
    const calls = mockApi({ 'GET /platform/settings': { data: settings }, 'GET /platform/settings/contact': { data: contact }, ...extra } as never);
    const view = await mountView(PlatformSettingsView, {}, '/platform/settings');
    return { ...view, calls };
  };

  it('contact : validation (e-mail, téléphones, bénéficiaire), normalisation +237 et envoi des deux numéros de paiement', async () => {
    const saved = { ...contact, stored: { name: 'TRU GROUP', email: 'paiements@tru.test', phone: '+237699000001', payments: [] } };
    const { wrapper, calls } = await setup({ 'PATCH /platform/settings/contact': { data: saved } });
    expect(wrapper.text()).toContain('Valeur actuellement affichée : env@tru.test');

    await wrapper.find('#rg-c-email').setValue('pas-un-email');
    await wrapper.find('#rg-c-phone').setValue('12345');
    await wrapper.find('#rg-c-orange_money-phone').setValue('699 00 00 02');
    await wrapper.find('form[aria-labelledby="rg-contact-title"]').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Adresse e-mail invalide.');
    expect(wrapper.text()).toContain('Numéro mobile camerounais attendu');
    expect(wrapper.text()).toContain('Indiquez le nom du bénéficiaire');
    expect(calls.some((c) => c.method === 'PATCH' && c.path === '/platform/settings/contact')).toBe(false);

    await wrapper.find('#rg-c-name').setValue('TRU GROUP');
    await wrapper.find('#rg-c-email').setValue('paiements@tru.test');
    await wrapper.find('#rg-c-phone').setValue('6 99 00 00 01');
    await wrapper.find('#rg-c-orange_money-holder').setValue('TRU GROUP SARL');
    await wrapper.find('#rg-c-mtn_momo-phone').setValue('+237 670 00 00 03');
    await wrapper.find('#rg-c-mtn_momo-holder').setValue('Emmanuel F.');
    await wrapper.find('form[aria-labelledby="rg-contact-title"]').trigger('submit');
    await flushPromises();
    const patch = calls.find((c) => c.method === 'PATCH' && c.path === '/platform/settings/contact');
    expect(patch?.body).toEqual({
      name: 'TRU GROUP', email: 'paiements@tru.test', phone: '+237699000001',
      payments: [{ channel: 'orange_money', phone: '+237699000002', holder: 'TRU GROUP SARL' }, { channel: 'mtn_momo', phone: '+237670000003', holder: 'Emmanuel F.' }],
    });
    expect(wrapper.text()).toContain('Contact enregistré.');
    wrapper.unmount();
  });

  it('champs vides : envoyés à null (repli sur le serveur)', async () => {
    const { wrapper, calls } = await setup({ 'PATCH /platform/settings/contact': { data: contact } });
    await wrapper.find('form[aria-labelledby="rg-contact-title"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ name: null, email: null, phone: null, payments: [] });
    wrapper.unmount();
  });

  it('vérification des paiements déclarés : bornes contrôlées puis réglages envoyés', async () => {
    const { wrapper, calls } = await setup({ 'PATCH /platform/settings': { data: settings } });
    expect(wrapper.text()).toContain('Vérification des paiements déclarés');
    await wrapper.find('#rg-v-expiry').setValue('0');
    await wrapper.find('form[aria-labelledby="rg-verify-title"]').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Valeur hors bornes (1 à 60).');
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);

    await wrapper.find('#rg-v-expiry').setValue('14');
    await wrapper.find('#rg-v-tol').setValue('250');
    await wrapper.find('#rg-v-mode').setValue('1');
    await wrapper.find('form[aria-labelledby="rg-verify-title"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({
      regularisation_request_expiry_days: 14, regularisation_amount_tolerance_xaf: 250, regularisation_partial_min_xaf: 1000, regularisation_partial_mode: 1,
    });
    expect(wrapper.text()).toContain('Réglages de vérification enregistrés.');
    wrapper.unmount();
  });
});

describe('contrat des appels', () => {
  it('les actions envoient les routes et les corps attendus', () => {
    expect(actions.confirmDeclaration('D1', { received_amount_xaf: 60000, reference: 'REF' })).toEqual({ method: 'POST', path: '/platform/regularisation/declarations/D1/confirm', body: { received_amount_xaf: 60000, reference: 'REF' } });
    expect(actions.rejectDeclaration('D1', 'Motif')).toEqual({ method: 'POST', path: '/platform/regularisation/declarations/D1/reject', body: { reason: 'Motif' } });
    expect(actions.updateContact({ name: null, email: null, phone: null, payments: [] })).toMatchObject({ method: 'PATCH', path: '/platform/settings/contact' });
  });

  it('numéro mobile camerounais normalisé en +237', () => {
    expect(normalizeCameroonPhone('699 01 23 45')).toBe('+237699012345');
    expect(normalizeCameroonPhone('237699012345')).toBe('+237699012345');
    expect(normalizeCameroonPhone('00237 6 99 01 23 45')).toBe('+237699012345');
    expect(normalizeCameroonPhone('+237 699-01-23-45')).toBe('+237699012345');
    expect(normalizeCameroonPhone('+33612345678')).toBeNull();
    expect(normalizeCameroonPhone('799012345')).toBeNull();
    expect(normalizeCameroonPhone('')).toBeNull();
  });
});
