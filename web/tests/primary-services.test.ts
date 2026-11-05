import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { setLocale } from '@/i18n';
import ServicesView from '@/views/primary/ServicesView.vue';
import PickupView from '@/views/primary/PickupView.vue';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });
const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-07', ends_on: '2027-06-11', status: 'open', closed_at: null };
const structures = [
  { id: 'L-ID-1', org_unit_id: null, kind: 'level', name: 'CE1', capacity: null },
  { id: 'C-ID-1', org_unit_id: null, kind: 'class', name: 'CE1 A', capacity: 60 },
];
const plan = { id: 'PL1', service_kind: 'canteen', label: 'Cantine midi', fee_code: 'CANTINE', billing_cycle: 'term', capacity: 2, route_label: null, active: true, subscribers: 2 };
const plans = { data: { kinds: { canteen: { fr: 'Cantine', en: 'Canteen' }, transport: { fr: 'Transport', en: 'Transport' } }, plans: [plan] } };
const subscription = (over: Record<string, unknown> = {}) => ({ id: 'SU1', plan_id: 'PL1', person_id: 'A1', academic_year_id: 'Y1', starts_on: '2026-10-01', ends_on: null, status: 'active', name: 'NOM Awa', ...over });
const problem = (status: number, detail: string) => jsonResponse({ type: 'https://api.scholaris.cm/errors/conflict', title: 'Refus', status, detail, trace_id: 'TR-SVC' }, status);

function connect(permissions: string[], extra: Record<string, unknown> = {}): ReturnType<typeof mockApi> {
  signIn(teacherMe, [tile({ type: 'primary', permissions, roles: [{ code: 'primary_directeur', label: 'Directeur' }] })], 'primary_directeur');
  return mockApi({
    'GET /academic-years': page([year]), 'GET /periods': page([]), 'GET /structures': page(structures),
    ...extra,
  } as Parameters<typeof mockApi>[0]);
}

beforeEach(() => {
  freshEnvironment();
  setLocale('fr');
});

describe('routes services et sortie sécurisée', () => {
  it('sont réservées aux établissements primaires et à leurs permissions', () => {
    const expected: Record<string, [string, string]> = { 'pri-services': ['/primaire/services', 'primary.services.read'], 'pri-pickup': ['/primaire/sortie-securisee', 'primary.pickup.read'] };
    for (const [name, [path, perm]] of Object.entries(expected)) {
      const route = routes.find((r) => r.name === name);
      expect(route?.path).toBe(path);
      expect(route?.meta?.establishmentTypes).toEqual(['primary']);
      expect(route?.meta?.perms as string[]).toContain(perm);
    }
  });
});

describe('Services aux familles', () => {
  const base = {
    'GET /primary/service-plans': plans,
    'GET /primary/service-plans/PL1/subscriptions': { data: { items: [subscription(), subscription({ id: 'SU2', person_id: 'A2', name: 'NOM Bello', starts_on: '2026-10-20' })] } },
    'GET /primary/service-plans/PL1/candidates': { data: { items: [{ person_id: 'A1', name: 'NOM Awa', academic_year_id: 'Y1', subscribed: true }, { person_id: 'A3', name: 'NOM Cathy', academic_year_id: 'Y1', subscribed: false }] } },
    'GET /primary/service-plans/PL1/attendance': { data: { plan, date: '2026-10-21', items: [{ subscription_id: 'SU1', person_id: 'A1', name: 'NOM Awa', present: null }, { subscription_id: 'SU2', person_id: 'A2', name: 'NOM Bello', present: false }] } },
  };

  it('liste les forfaits, signale la capacité atteinte et ouvre les abonnés', async () => {
    connect(['primary.services.read', 'primary.services.manage'], base);
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    expect(wrapper.get('[data-test="plan-PL1"]').text()).toContain('Cantine midi');
    expect(wrapper.get('[data-test="plan-PL1"]').text()).toContain('2 abonné(s) / 2');
    expect(wrapper.get('[data-test="plan-PL1"]').text()).toContain('Complet');
    await wrapper.get('[data-test="open-PL1"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="sub-A1"]').text()).toContain('NOM Awa');
    expect(wrapper.find('[data-test="subscribe"]').exists()).toBe(true);
    const options = wrapper.findAll('#sv-learner option').map((o) => o.text());
    expect(options).toContain('NOM Cathy');
    expect(options).not.toContain('NOM Awa');
  });

  it('abonne un élève, termine un abonnement et affiche le refus de capacité', async () => {
    const calls = connect(['primary.services.read', 'primary.services.manage'], {
      ...base,
      'POST /primary/service-plans/PL1/subscriptions': (c: { body?: Record<string, unknown> }) => (c.body?.person_id === 'A3' ? problem(409, 'Capacité du service atteinte.') : { data: subscription({ id: 'SU3', person_id: 'A9' }) }),
      'POST /primary/service-subscriptions/SU1/end': { data: subscription({ status: 'ended', ends_on: '2026-10-25' }) },
    });
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    await wrapper.get('[data-test="open-PL1"]').trigger('click');
    await flushPromises();
    await wrapper.get('#sv-learner').setValue('A3');
    await wrapper.get('#sv-start').setValue('2026-10-22');
    await wrapper.get('[data-test="subscribe"]').trigger('submit');
    await flushPromises();
    const post = calls.find((c) => c.method === 'POST' && c.path.endsWith('/subscriptions'));
    expect(post?.body).toEqual({ person_id: 'A3', academic_year_id: 'Y1', starts_on: '2026-10-22' });
    expect(wrapper.get('.notice--error').text()).toContain('Capacité du service atteinte');

    await wrapper.get('[data-test="end-A1"]').trigger('click');
    await wrapper.get('input[type="date"][id="sv-end-SU1"]').setValue('2026-10-25');
    await wrapper.get('[data-test="end-yes-A1"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path === '/primary/service-subscriptions/SU1/end')?.body).toEqual({ ends_on: '2026-10-25' });
    expect(wrapper.get('[data-test="notice"]').text()).toContain('Abonnement terminé');
  });

  it('facture un cycle une seule fois et le dit', async () => {
    const calls = connect(['primary.services.read', 'primary.services.manage'], { ...base, 'POST /primary/service-plans/PL1/invoice': { data: { invoiced: 0, already: 2 } } });
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    await wrapper.get('[data-test="open-PL1"]').trigger('click');
    await flushPromises();
    await wrapper.get('#sv-key').setValue('2026-T1');
    await wrapper.get('#sv-due').setValue('2026-10-31');
    await wrapper.get('[data-test="billing"] form').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/invoice'))?.body).toEqual({ cycle_key: '2026-T1', due_on: '2026-10-31' });
    expect(wrapper.get('[data-test="invoiced"]').text()).toContain('0 facture(s) émise(s), 2 déjà facturée(s)');
  });

  it('saisit la présence du jour : seules les marques saisies partent, « non saisi » reste visible', async () => {
    const calls = connect(['primary.services.read', 'primary.services.manage'], { ...base, 'PUT /primary/service-plans/PL1/attendance': { data: { saved: 2 } } });
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    await wrapper.get('[data-test="open-PL1"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-test="att-A1"]').text()).toContain('Non saisi');
    expect(wrapper.get('[data-test="att-A2"]').text()).not.toContain('Non saisi');
    await wrapper.get('[data-test="att-A1"] input[value="true"]').setValue(true);
    await wrapper.get('[data-test="save-attendance"]').trigger('click');
    await flushPromises();
    const put = calls.find((c) => c.method === 'PUT' && c.path.endsWith('/attendance'));
    expect(put?.body?.entries).toEqual([{ subscription_id: 'SU1', present: true }, { subscription_id: 'SU2', present: false }]);
    expect(wrapper.get('[data-test="notice"]').text()).toContain('Présence enregistrée (2)');
  });

  it('en lecture seule : pas de formulaire de création, d\'abonnement ni de saisie ; écran en anglais', async () => {
    setLocale('en');
    connect(['primary.services.read'], base);
    const { wrapper } = await mountView(ServicesView, {}, '/primaire/services');
    expect(wrapper.find('[data-test="new-plan"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Family services');
    await wrapper.get('[data-test="open-PL1"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-test="subscribe"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="billing"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="save-attendance"]').exists()).toBe(false);
    expect(wrapper.html()).not.toContain('v-html');
  });
});

describe('Sortie sécurisée', () => {
  const day = (enabled: boolean, learners: unknown[] = []) => ({ data: { class: { id: 'C-ID-1', name: 'CE1 A' }, date: '2026-10-21', enabled, learners } });
  const learner = {
    person_id: 'A1', name: 'NOM Awa',
    authorised: [{ pickup_id: 'PK1', name: 'Mme Awa Mère', relationship: 'Mère', grant_type: 'permanent' }],
    events: [{ outcome: 'refused', pickup_id: null, presented_name: 'Inconnu', handled_at: '2026-10-21T12:30:00+01:00' }],
  };

  it('dit que la sortie n\'est pas activée pour le niveau', async () => {
    connect(['primary.pickup.read', 'primary.pickup.handover'], { 'GET /primary/classes/C-ID-1/pickups': day(false) });
    const { wrapper } = await mountView(PickupView, {}, '/primaire/sortie-securisee');
    expect(wrapper.get('[data-test="disabled"]').text()).toContain('pas activée');
  });

  it('remet l\'enfant à une personne autorisée et enregistre un refus signalé', async () => {
    const calls = connect(['primary.pickup.read', 'primary.pickup.handover'], {
      'GET /primary/classes/C-ID-1/pickups': day(true, [learner, { person_id: 'A2', name: 'NOM Bello', authorised: [], events: [] }]),
      'POST /primary/classes/C-ID-1/pickups/handover': { data: { id: 'LG1', person_id: 'A1', outcome: 'handed', pickup_id: 'PK1', presented_name: 'Mme Awa Mère', handled_at: '2026-10-21T16:00:00+01:00', notified: 0 } },
      'POST /primary/classes/C-ID-1/pickups/refusals': { data: { id: 'LG2', person_id: 'A2', outcome: 'refused', pickup_id: null, presented_name: 'Inconnu', handled_at: '2026-10-21T16:05:00+01:00', notified: 3 } },
    });
    const { wrapper } = await mountView(PickupView, {}, '/primaire/sortie-securisee');
    expect(wrapper.get('[data-test="learner-A1"]').text()).toContain('Mme Awa Mère · Mère');
    expect(wrapper.get('[data-test="events-A1"]').text()).toContain('Refus (Inconnu) à 12:30');
    expect(wrapper.get('[data-test="learner-A2"] [data-test="none-authorised"]').exists()).toBe(true);

    await wrapper.get('[data-test="handover-PK1"]').trigger('click');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/handover'))?.body).toEqual({ person_id: 'A1', pickup_id: 'PK1' });
    expect(wrapper.get('[data-test="notice"]').text()).toContain('Enfant remis à Mme Awa Mère');

    await wrapper.get('[data-test="refuse-open-A2"]').trigger('click');
    await wrapper.get('[data-test="refuse-form-A2"]').trigger('submit');
    expect(calls.some((c) => c.path.endsWith('/refusals'))).toBe(false);
    expect(wrapper.get('[data-test="refuse-form-A2"]').text()).toContain('Champ obligatoire');
    await wrapper.get('#pk-name-A2').setValue('Inconnu');
    await wrapper.get('[data-test="refuse-form-A2"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.path.endsWith('/refusals'))?.body).toEqual({ person_id: 'A2', presented_name: 'Inconnu', note: null });
    expect(wrapper.get('[data-test="notice"]').text()).toContain('3 personne(s) prévenue(s)');
  });

  it('en lecture seule (sans remise) : aucune action, mais les personnes autorisées restent visibles', async () => {
    connect(['primary.pickup.read'], { 'GET /primary/classes/C-ID-1/pickups': day(true, [learner]) });
    const { wrapper } = await mountView(PickupView, {}, '/primaire/sortie-securisee');
    expect(wrapper.get('[data-test="learner-A1"]').text()).toContain('Mme Awa Mère');
    expect(wrapper.find('[data-test="handover-PK1"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="refuse-open-A1"]').exists()).toBe(false);
  });
});
