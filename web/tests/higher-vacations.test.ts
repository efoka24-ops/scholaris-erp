import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import StatementsView from '@/views/higher/vacations/StatementsView.vue';
import StatementView from '@/views/higher/vacations/StatementView.vue';
import MyVacationsView from '@/views/higher/vacations/MyVacationsView.vue';
import { higherVacationsRoutes } from '@/views/higher/vacations/routes';
import { higherVacationsFr } from '@/i18n/higher-vacations-fr';
import { higherVacationsEn } from '@/i18n/higher-vacations-en';
import { formatHetd, formatHours } from '@/api/higher-vacations';
import { flushPromises, freshEnvironment, mountView } from './helpers';
import { find, page, problem, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

// TST-11 : assistant, L2, novembre, 6 000 FCFA/HETD ; 18 h CM, 12 h TD, 6 h TP = 43,5 HETD = 261 000 FCFA.
const tst11 = {
  id: 'V1', school_id: 'S1', department_id: 'D1', person_id: 'P-TEACH', period_month: '2026-11', grade: 'assistant', status: 'draft',
  cm_minutes: 1080, td_minutes: 720, tp_minutes: 360, other_minutes: 0, cm_hetd_milli: 27000, td_hetd_milli: 12000, tp_hetd_milli: 4500, other_hetd_milli: 0,
  hetd_milli: 43500, hetd: 43.5, hourly_rate: 6000, gross_amount: 261000, deductions: [], deductions_amount: 0, net_amount: 261000,
  excluded_minutes: 0, pending_sessions: 0, hold_reasons: [], rejection_reason: null, payment_method: null, payment_reference: null, paid_on: null,
  statement_document_id: null, payslip_document_id: null,
  lines: [{ id: 'L1', session_log_id: 'S1', element_code: 'MF', element_title: 'Mathématiques financières', group_id: 'G1', level_label: 'L2', session_kind: 'cm', held_on: '2026-11-02', minutes: 180, hetd_milli: 4500, hourly_rate: 6000, payable: true, refusal: null }],
};

describe('IPES vacations : routes, textes, formats', () => {
  it('déclare les routes sous /superieur, gardées et réservées aux IPES', () => {
    expect(higherVacationsRoutes).toHaveLength(3);
    for (const r of higherVacationsRoutes) {
      expect(r.path.startsWith('/superieur/')).toBe(true);
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
    }
  });
  it('a les mêmes clés en français et en anglais', () => {
    expect(Object.keys(higherVacationsEn).sort()).toEqual(Object.keys(higherVacationsFr).sort());
  });
  it('formate HETD et heures à la française', () => {
    expect(formatHetd(43500)).toBe('43,5');
    expect(formatHours(1080)).toBe('18 h');
    expect(formatHours(90)).toBe('1 h 30');
  });
});

describe('IPES états mensuels', () => {
  it('liste les états avec les montants à la française et établit un état', async () => {
    const calls = setup(['higher.hours.validate', 'higher.vacation.read'], {
      'GET /higher/vacations/statements': page([tst11]),
      'GET /higher/vacations/to-prepare': { data: [{ person_id: 'P-2', school_id: 'S1', department_id: 'D1', period_month: '2026-11', statement_id: null, statement_status: null }] },
      'POST /higher/vacations/statements': { data: [tst11] },
    });
    const { wrapper } = await mountView(StatementsView);
    expect(wrapper.get('[data-test="statement-row"]').text()).toContain('261 000 FCFA');
    expect(wrapper.get('[data-test="statement-row"]').text()).toContain('43,5');
    await wrapper.findAll('button').find((b) => b.text() === 'Établir l’état')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/vacations/statements')?.body).toMatchObject({ person_id: 'P-2', department_id: 'D1' });
    expect(wrapper.get('[data-test="prepared"]').text()).toContain('1 état');
  });
  it('affiche le refus RG-ENS-1 du serveur', async () => {
    setup(['higher.hours.validate'], {
      'GET /higher/vacations/statements': page([]),
      'GET /higher/vacations/to-prepare': { data: [{ person_id: 'P-2', school_id: 'S1', department_id: 'D1', period_month: '2026-11', statement_id: null, statement_status: null }] },
      'POST /higher/vacations/statements': problem(409, 'RG-ENS-1 : aucune heure validée.'),
    });
    const { wrapper } = await mountView(StatementsView);
    await wrapper.findAll('button').find((b) => b.text() === 'Établir l’état')!.trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').text()).toContain('RG-ENS-1');
  });
});

describe('IPES état de vacation', () => {
  it('montre le détail TST-11 et vise pour l’école', async () => {
    const calls = setup(['higher.vacation.validate_school', 'higher.vacation.read'], {
      'GET /higher/vacations/statements/V1': tst11,
      'POST /higher/vacations/statements/V1/validate-school': { ...tst11, status: 'school_validated' },
    });
    const { wrapper } = await mountView(StatementView, { id: 'V1' });
    expect(wrapper.get('[data-test="hetd-total"]').text()).toBe('43,5');
    expect(wrapper.get('[data-test="gross"]').text()).toBe('261 000 FCFA');
    expect(wrapper.text()).toContain('Aucune retenue');
    await wrapper.findAll('button').find((b) => b.text() === 'Viser (école)')!.trigger('click');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/vacations/statements/V1/validate-school')).toBeDefined();
  });
  it('signale le solde retenu (RG-ENS-5)', async () => {
    setup(['higher.vacation.validate'], { 'GET /higher/vacations/statements/V1': { ...tst11, status: 'school_validated', hold_reasons: ['RG-ENS-5 : solde retenu, saisie des notes de l’EC MF incomplète.'] } });
    const { wrapper } = await mountView(StatementView, { id: 'V1' });
    expect(wrapper.get('[data-test="hold"]').text()).toContain('RG-ENS-5');
  });
  it('enregistre le paiement du comptable', async () => {
    const calls = setup(['higher.vacation.pay', 'higher.vacation.read'], {
      'GET /higher/vacations/statements/V1': { ...tst11, status: 'approved' },
      'POST /higher/vacations/statements/V1/pay': { ...tst11, status: 'paid' },
    });
    const { wrapper } = await mountView(StatementView, { id: 'V1' });
    await wrapper.get('#hv-ref').setValue('OM-123');
    await wrapper.get('[data-test="pay-form"]').trigger('submit');
    await flushPromises();
    expect(find(calls, 'POST', '/higher/vacations/statements/V1/pay')?.body).toEqual({ payment_method: 'mobile_money', payment_reference: 'OM-123' });
  });
  it('n’offre aucune action à l’enseignant', async () => {
    setup(['higher.vacation.read_own'], { 'GET /higher/vacations/statements/V1': tst11 });
    const { wrapper } = await mountView(StatementView, { id: 'V1' });
    expect(wrapper.get('[data-test="actions"]').findAll('button')).toHaveLength(0);
    expect(wrapper.find('[data-test="pay-form"]').exists()).toBe(false);
  });
});

describe('IPES mes vacations', () => {
  it('montre les états de l’enseignant et le paiement', async () => {
    const calls = setup(['higher.vacation.read_own'], {
      'GET /higher/vacations/mine': page([{ ...tst11, status: 'paid', payment_method: 'mobile_money', payment_reference: 'OM-123', paid_on: '2026-12-05' }]),
    });
    const { wrapper } = await mountView(MyVacationsView);
    expect(wrapper.findAll('[data-test="my-statement"]')).toHaveLength(1);
    expect(wrapper.get('[data-test="net"]').text()).toBe('261 000 FCFA');
    expect(wrapper.get('[data-test="paid"]').text()).toContain('OM-123');
    expect(find(calls, 'GET', '/higher/vacations/mine')).toBeDefined();
  });
});
