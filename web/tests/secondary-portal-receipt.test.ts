import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import FamilyPortalView from '@/views/secondary/portals/FamilyPortalView.vue';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// C-118 : après un paiement confirmé du portail parent, le reçu numéroté s'affiche (montant, opérateur, numéro) au lieu d'un
// formulaire simplement refermé. Données fictives.
describe('portail famille du secondaire : reçu après paiement', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());

  const invoice = { id: 'i1', reference: 'FAC-0001', net_xaf: 10000, paid_xaf: 0, balance_xaf: 10000, installments: [] };
  const child = {
    person: { id: 'p1', name: 'Fictif-Lyceen Issa' }, access: 'full', national_id_registered: true, balance_xaf: 10000, fees_mode: 'fees_public_catalog',
    enrollment: { id: 'en1', status: 'active', class: { id: 'c', name: '2nde A' }, level: null, academic_year: '2026-2027' }, invoices: [invoice],
  };
  const portal = (balance: number): Record<string, unknown> => ({ data: { guardian_id: 'g1', can_pay: true, family_balance_xaf: balance, children: [{ ...child, balance_xaf: balance, invoices: balance > 0 ? [invoice] : [] }] } });
  const payment = (status: string, receipt: string | null): Record<string, unknown> => ({ data: { id: 'pay1', status, amount_xaf: 10000, channel: 'orange_money', invoice_id: 'i1', receipt_number: receipt, child_id: 'p1', replayed: false } });

  it('« Vérifier l’état » jusqu’à la confirmation, puis reçu numéroté affiché', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_parent' } }, [tile({ roles: [{ code: 'sec_parent', label: 'Parent' }] })], 'sec_parent');
    let paid = false;
    mockApi({
      'GET /secondary/portal/family': () => portal(paid ? 0 : 10000),
      'POST /secondary/portal/family/children/p1/payments': payment('pending', null),
      'GET /secondary/portal/family/children/p1/payments/pay1': () => {
        paid = true;
        return payment('confirmed', 'REC-2026-000042');
      },
    });
    const { wrapper } = await mountView(FamilyPortalView);
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Payer')?.trigger('click');
    await wrapper.get('#sf-phone').setValue('+237699000101');
    await wrapper.get('form[aria-labelledby="sf-pay"]').trigger('submit');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Vérifier l’état')?.trigger('click');
    await flushPromises();
    expect(wrapper.find('form[aria-labelledby="sf-pay"]').exists()).toBe(false);
    const receipt = wrapper.get('section[aria-labelledby="sf-receipt"]');
    expect(receipt.text()).toContain('Reçu n° REC-2026-000042');
    expect(receipt.text()).toContain('Orange Money');
    expect(receipt.text()).toMatch(/10\s000/);
  });

  it('paiement confirmé dès l’envoi : le reçu s’affiche aussitôt', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_parent' } }, [tile({ roles: [{ code: 'sec_parent', label: 'Parent' }] })], 'sec_parent');
    mockApi({
      'GET /secondary/portal/family': portal(10000),
      'POST /secondary/portal/family/children/p1/payments': payment('confirmed', 'REC-2026-000043'),
    });
    const { wrapper } = await mountView(FamilyPortalView);
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === 'Payer')?.trigger('click');
    await wrapper.get('#sf-phone').setValue('+237699000101');
    await wrapper.get('form[aria-labelledby="sf-pay"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('section[aria-labelledby="sf-receipt"]').text()).toContain('Reçu n° REC-2026-000043');
  });
});
