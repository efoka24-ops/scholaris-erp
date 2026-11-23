import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { setLocale, translate } from '@/i18n';
import FamilyPortalView from '@/views/secondary/portals/FamilyPortalView.vue';
import { publishSummary } from '@/views/primary/fixes';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// D-BUL (décisions du propriétaire du 12 novembre 2026). Données fictives.
const t = (key: Parameters<typeof translate>[0], params?: Record<string, string | number>) => translate(key, params);

describe('D-BUL 1. résumé de publication des bulletins', () => {
  beforeEach(() => setLocale('fr'));

  it('nomme le consentement retiré, refus explicite que le SMS essentiel respecte', () => {
    const text = publishSummary(t, { published: 2, notified: 1, families: 2, not_notified: { consent_revoked: 1 } });
    expect(text).toContain('1 famille(s) prévenue(s) par SMS sur 2');
    expect(text).toContain('1 ayant retiré leur consentement aux communications');
    expect(text).not.toContain('consent_revoked');
  });
});

describe('D-BUL 2. référence de paiement famille au portail parent du secondaire', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());

  const child = {
    person: { id: 'p1', name: 'Fictif-Lyceen Issa' }, access: 'full', national_id_registered: true, balance_xaf: 0, fees_mode: 'fees_private',
    enrollment: { id: 'en1', status: 'active', class: { id: 'c', name: '2nde A' }, level: null, academic_year: '2026-2027' }, invoices: [],
  };
  const portal = (reference: string | null): Record<string, unknown> => ({ data: { guardian_id: 'g1', can_pay: false, family_balance_xaf: 0, payment_reference: reference, children: [child] } });
  const parent = () => signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_parent' } }, [tile({ roles: [{ code: 'sec_parent', label: 'Parent' }] })], 'sec_parent');

  it('affiche la référence du parent connecté', async () => {
    parent();
    mockApi({ 'GET /secondary/portal/family': portal('FAM-0587') });
    const { wrapper } = await mountView(FamilyPortalView);
    await flushPromises();
    expect(wrapper.get('[data-test="family-reference"]').text()).toContain('FAM-0587');
  });

  it('dit où la demander quand elle n’est pas encore attribuée', async () => {
    parent();
    mockApi({ 'GET /secondary/portal/family': portal(null) });
    const { wrapper } = await mountView(FamilyPortalView);
    await flushPromises();
    expect(wrapper.find('[data-test="family-reference"]').exists()).toBe(false);
    expect(wrapper.get('[data-test="family-reference-none"]').text()).toContain('demandez-la à la caisse');
  });
});
