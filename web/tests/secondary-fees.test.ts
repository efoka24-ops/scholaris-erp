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

// C-118 : échéancier du frais pour une classe et facture de l'élève (socle Billing, /fee-schedules et /invoices) depuis l'écran Frais.
describe('écran Frais : échéancier et facture', () => {
  beforeEach(freshEnvironment);
  afterEach(() => vi.restoreAllMocks());
  const page = (data: unknown[]): { data: unknown[]; meta: { next_cursor: null; per_page: number } } => ({ data, meta: { next_cursor: null, per_page: 50 } });
  const overview = { mode: 'public_catalog', kinds: ['apee', 'other'], refused_kinds: [], blocked_document_types: [], allocation_rule: '', allocation_kind_order: null, allocation_sibling_order: null, reminder_offsets: null, presets: {}, confirmation: 'to_confirm' };

  it('crée l’échéancier de la classe puis établit la facture de l’élève', async () => {
    signIn({ ...teacherMe, context: { establishment_id: 'E1', role: 'sec_intendant' } }, [tile({ roles: [{ code: 'sec_intendant', label: 'Intendant' }], permissions: ['secondary.billing.manage', 'secondary.fees.read', 'billing.manage', 'secondary.enrol.read'] })], 'sec_intendant');
    const calls = mockApi({
      'GET /secondary/fees/overview': { data: overview },
      'GET /secondary/fees/catalog': page([]),
      'GET /secondary/fees/requests': page([]),
      'GET /fee-items': page([{ id: 'f1', kind: 'apee', code: 'APEE', name: 'Contribution APEE', amount_xaf: 10000, active: true }]),
      'GET /academic-years': page([{ id: 'y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null }]),
      'GET /secondary/setup/classes': page([{ id: 'c2a', structure_id: 's2a', academic_year_id: 'y1', label: '2nde A', section_code: 'fr', teaching_kind: 'general', cycle_code: 'second', level_id: 'l', level_code: '2NDE', stream_id: 'st', exam_code: null, capacity: null, head_teacher_person_id: null }]),
      'GET /secondary/enrolment/students': page([{ id: 'r1', person_id: 'p1', enrollment_id: 'en1', class_structure_id: 's2a', class_id: 'c2a', level_code: '2NDE', academic_year_id: 'y1', entry_kind: 'new', entry_date: '2026-09-07', repeater: false, age_alert: false, age_decision_reason: null, exclusion_decision_reason: null, decided_by: null, documents_checked: [], created_at: '', last_name: 'FICTIF', first_name: 'Issa', gender: 'male', national_id_status: 'recorded' }]),
      'GET /fee-schedules': page([]),
      'POST /fee-schedules': { data: { id: 'fs1', fee_item_id: 'f1', academic_year_id: 'y1', structure_id: 's2a', amount_xaf: 10000, terms: [{ due_on: '2026-12-15', amount: 10000 }] } },
      'POST /invoices': { data: { id: 'i1', learner_id: 'p1', status: 'issued', gross_xaf: 10000, discount_xaf: 0, net_xaf: 10000, paid_xaf: 0, balance_xaf: 10000 } },
    });
    const { wrapper } = await mountView(FeesView);
    await flushPromises();
    await wrapper.get('#sf-s-item').setValue('f1');
    await wrapper.get('#sf-s-class').setValue('s2a');
    expect((wrapper.get('#sf-s-amount').element as HTMLInputElement).value).toBe('10000');
    await wrapper.get('#sf-s-due').setValue('2026-12-15');
    await wrapper.get('form[aria-labelledby="sf-sched-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/fee-schedules')?.body).toEqual({ fee_item_id: 'f1', academic_year_id: 'y1', structure_id: 's2a', amount_xaf: 10000, terms: [{ due_on: '2026-12-15', amount: 10000 }] });
    expect(wrapper.text()).toContain(secondaryFeesFr['secf.scheduleAdded']);

    await wrapper.get('#sf-v-student').setValue('en1');
    await wrapper.get('form[aria-labelledby="sf-invoice-new"]').trigger('submit');
    await flushPromises();
    expect(calls.find((c) => c.method === 'POST' && c.path === '/invoices')?.body).toEqual({ enrollment_id: 'en1' });
    expect(wrapper.text()).toContain('FICTIF Issa');
    expect(wrapper.text()).toMatch(/10\s000 FCFA/);
  });
});
