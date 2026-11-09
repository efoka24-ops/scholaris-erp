import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { planTotal, validInstallments } from '@/api/secondaryFees';
import { matchesPattern } from '@/api/secondaryCarteScolaire';
import { secondaryFeesFr } from '@/i18n/secondary-fees-fr';
import { secondaryFeesEn } from '@/i18n/secondary-fees-en';
import { secondaryRoutes } from '@/views/secondary/routes';
import FeesView from '@/views/secondary/fees/FeesView.vue';
import { freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

// Lot E1-B : aides des écrans Frais et Carte scolaire du secondaire (données fictives).
describe('frais et carte scolaire du secondaire', () => {
  it('contrôle les échéances d’un plan de paiement : dates croissantes, montants entiers positifs', () => {
    expect(validInstallments([{ due_on: '2026-11-01', amount: 30000 }, { due_on: '2026-12-01', amount: 20000 }])).toBe(true);
    expect(validInstallments([{ due_on: '2026-12-01', amount: 30000 }, { due_on: '2026-11-01', amount: 20000 }])).toBe(false);
    expect(validInstallments([{ due_on: '2026-11-01', amount: 0 }])).toBe(false);
    expect(validInstallments([])).toBe(false);
    expect(planTotal([{ due_on: '2026-11-01', amount: 30000 }, { due_on: '2026-12-01', amount: 20000 }])).toBe(50000);
  });

  it('contrôle le matricule avec le format paramétré (le serveur reste juge)', () => {
    expect(matchesPattern(' fict 000123 ', '[A-Z0-9][A-Z0-9-]{5,29}')).toBe(true);
    expect(matchesPattern('x!', '[A-Z0-9][A-Z0-9-]{5,29}')).toBe(false);
    expect(matchesPattern('MAT123456', 'MAT[0-9]{6}')).toBe(true);
    expect(matchesPattern('', '.+')).toBe(false);
  });

  it('textes FR et EN complets et écrans déclarés', () => {
    expect(Object.keys(secondaryFeesEn).sort()).toEqual(Object.keys(secondaryFeesFr).sort());
    const names = secondaryRoutes.map((r) => r.name);
    expect(names).toContain('sec-fees');
    expect(names).toContain('sec-cartescolaire');
  });
});

// C-117 : frais facturés de l'établissement (/fee-items du socle) depuis l'écran Frais : un frais hors catalogue d'un
// établissement public est refusé par la garde du secondaire, avec le motif du serveur (RG-FIN-2).
describe('écran Frais : frais facturés', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });
  const overview = { mode: 'public_catalog', kinds: ['apee', 'other'], refused_kinds: [], blocked_document_types: [], allocation_rule: '', allocation_kind_order: null, allocation_sibling_order: null, reminder_offsets: null, presets: {}, confirmation: 'to_confirm' };
  const reason = "Frais absent du catalogue des frais autorisés de l'établissement public (RG-FIN-2). Une demande d'ajout est adressée au client (référence R1).";

  it('liste les frais et affiche le refus d’un frais hors catalogue au public', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_intendant' } }, [tile({ roles: [{ code: 'sec_intendant', label: 'Intendant' }], permissions: ['secondary.billing.manage', 'secondary.fees.read', 'billing.manage'] })], 'sec_intendant');
    const calls = mockApi({
      'GET /secondary/fees/overview': { data: overview },
      'GET /secondary/fees/catalog': page([]),
      'GET /secondary/fees/requests': page([]),
      'GET /fee-items': page([{ id: 'f1', kind: 'apee', code: 'APEE', name: 'Contribution APEE', amount_xaf: 10000, active: true }]),
      'POST /fee-items': () => jsonResponse({ type: 'https://api.scholaris.cm/errors/fee-not-authorized', title: 'Frais non autorisé', status: 422, detail: reason, errors: { kind: [reason] } }, 422),
    });
    const { wrapper } = await mountView(FeesView);
    await flushPromises();
    expect(wrapper.text()).toContain('Contribution APEE');
    await wrapper.get('#sf-i-kind').setValue('other');
    await wrapper.get('#sf-i-name').setValue('Frais informatiques');
    await wrapper.get('#sf-i-amount').setValue('5000');
    await wrapper.get('form[aria-labelledby="sf-items-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/fee-items')?.body).toEqual({ kind: 'other', name: 'Frais informatiques', amount_xaf: 5000 });
    expect(wrapper.text()).toContain('Frais absent du catalogue');
  });
});
