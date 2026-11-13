import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import DashboardView from '@/views/higher/dashboards/DashboardView.vue';
import DashboardsIndexView from '@/views/higher/dashboards/DashboardsIndexView.vue';
import { higherDashboardsRoutes } from '@/views/higher/dashboards/routes';
import { higherDashboardsFr } from '@/i18n/higher-dashboards-fr';
import { higherDashboardsEn } from '@/i18n/higher-dashboards-en';
import { BOARD_KINDS, type Board } from '@/api/higher-dashboards';
import { flushPromises, freshEnvironment, mountView } from './helpers';
import { find, setup } from './higher-teaching-helpers';

beforeEach(() => freshEnvironment());

const row = (over: Record<string, unknown>) => ({ suppressed: false, ...over });

const enrolment: Board = {
  board: 'enrolment', generated_at: '2026-10-16T08:00:00+01:00', filters: { school_id: 'all', program_id: null, academic_year_id: null, from: null, to: null },
  schools: [], school_selector: false, suppressed_below: 5,
  cards: [{ key: 'applications', value: 10, unit: 'count' }, { key: 'enrolled', value: null, unit: 'count' }, { key: 'admission_rate', value: 80, unit: 'percent' }],
  tables: [{
    key: 'funnel', columns: ['school', 'program', 'applications', 'admitted', 'admission_rate', 'enrolled'],
    rows: [
      row({ school: 'EC - École de commerce', program: 'CF - Comptabilité', applications: 10, admitted: 8, admission_rate: 80, enrolled: 5 }),
      row({ school: 'EC - École de commerce', program: 'DR - Droit', applications: null, admitted: null, admission_rate: null, enrolled: null, suppressed: true }),
    ],
  }],
  notes: [],
};

describe('IPES tableaux de bord : routes et textes', () => {
  it('déclare les routes sous /superieur, gardées et réservées aux IPES', () => {
    expect(higherDashboardsRoutes).toHaveLength(2);
    for (const r of higherDashboardsRoutes) {
      expect(r.path.startsWith('/superieur/')).toBe(true);
      expect(r.beforeEnter).toBeTypeOf('function');
      expect(r.meta?.establishmentTypes).toEqual(['higher']);
      expect(r.meta?.perms).toEqual(['higher.dashboard.read']);
    }
  });
  it('a les mêmes clés en français et en anglais, et un texte pour chaque tableau', () => {
    expect(Object.keys(higherDashboardsEn).sort()).toEqual(Object.keys(higherDashboardsFr).sort());
    for (const kind of BOARD_KINDS) {
      expect(higherDashboardsFr).toHaveProperty(`hd.kind.${kind}`);
      expect(higherDashboardsFr).toHaveProperty(`hd.chart.${kind}`);
    }
  });
});

describe('IPES tableaux de bord : écrans', () => {
  it('ne propose que les tableaux dont la permission de donnée est détenue', async () => {
    setup(['higher.dashboard.read', 'higher.billing.read'], {});
    const { wrapper } = await mountView(DashboardsIndexView);
    expect(wrapper.find('[data-test="board-finance"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="board-attendance"]').exists()).toBe(false);
  });

  it('affiche cartes, graphique et tableau, avec « < 5 » pour les effectifs masqués', async () => {
    setup(['higher.dashboard.read', 'higher.enrolment.read'], { 'GET /higher/dashboards/enrolment': { data: enrolment } });
    const { wrapper } = await mountView(DashboardView, { kind: 'enrolment' });
    expect(wrapper.get('[data-test="card-applications"]').text()).toBe('10');
    expect(wrapper.get('[data-test="card-enrolled"]').text()).toBe('< 5');
    expect(wrapper.get('[data-test="card-admission_rate"]').text()).toContain('80');
    const rows = wrapper.get('[data-test="table-funnel"]').findAll('[data-test="row"]');
    expect(rows).toHaveLength(2);
    expect(rows[0]!.text()).toContain('CF - Comptabilité');
    expect(rows[1]!.text()).toContain('< 5');
    expect(wrapper.find('[data-test="chart"]').text()).toContain('CF - Comptabilité');
    expect(wrapper.find('[data-test="chart"]').text()).not.toContain('DR - Droit');
    expect(wrapper.find('[data-test="suppressed-note"]').exists()).toBe(true);
  });

  it('transmet la période et l’année au serveur', async () => {
    const calls = setup(['higher.dashboard.read', 'higher.enrolment.read'], { 'GET /higher/dashboards/enrolment': { data: enrolment } });
    const { wrapper } = await mountView(DashboardView, { kind: 'enrolment' });
    await wrapper.get('#db-from').setValue('2026-09-01');
    await wrapper.get('#db-year').setValue('AY-2026');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const last = [...calls].reverse().find((c) => c.method === 'GET' && c.path === '/higher/dashboards/enrolment');
    expect(last?.query).toMatchObject({ from: '2026-09-01', academic_year_id: 'AY-2026' });
    expect(find(calls, 'GET', '/higher/dashboards/enrolment')).toBeDefined();
  });
});
