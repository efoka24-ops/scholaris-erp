import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import type { Dashboard, DashboardClass, SheetFollowUp } from '@/api/primary';
import { routes } from '@/router/routes';
import { permissionsForRoles } from '@/lib/permissions';
import { BADGE_TONE, entryPercent, sheetBadge, watchFirst } from '@/views/primary/dashboardModel';
import DashboardView from '@/views/primary/DashboardView.vue';
import { flushPromises, freshEnvironment, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

const page = (items: unknown[]) => ({ data: items, meta: { next_cursor: null, per_page: 50 } });
const sheet = (over: Partial<SheetFollowUp> = {}, current: Partial<NonNullable<SheetFollowUp['current']>> | null = {}): SheetFollowUp => ({
  current: current === null ? null : { period_id: 'P-T1', expected: 9, entered: 3, status: 'open', deadline: null, late: false, ...current },
  overdue_periods: [],
  late: false,
  ...over,
});
const row = (over: Partial<DashboardClass>): DashboardClass => ({
  class_id: 'C-ID-1', name: 'CE1 A', level_id: 'L1', capacity: 40, learners: 3, over_capacity: false, absent_morning: 1, absent_afternoon: 2,
  late_payers: 2, late_balance_xaf: 17000, sheet: sheet(), ...over,
});

const dashboard: Dashboard = {
  date: '2026-10-21',
  period: { id: 'P-T1', kind: 'trimester', starts_on: '2026-09-07', ends_on: '2026-12-18', academic_year_id: 'Y1' },
  perimeter: 'establishment',
  totals: { classes: 2, learners: 5, absent_morning: 1, absent_afternoon: 2, late_payers: 2, late_balance_xaf: 17000, sheets_late: 1 },
  classes: [
    row({ class_id: 'C-ID-2', name: 'CM2 B', learners: 2, absent_morning: 0, absent_afternoon: 0, late_payers: 0, late_balance_xaf: 0, sheet: sheet({}, { expected: 6, entered: 6 }) }),
    row({ sheet: sheet({ late: true }, { deadline: '2026-10-15', late: true }) }),
  ],
};
const year = { id: 'Y1', label: '2026-2027', starts_on: '2026-09-01', ends_on: '2027-07-31', status: 'open', closed_at: null };
const periods = [{ id: 'P-T1', academic_year_id: 'Y1', kind: 'trimester', ordinal: 1, starts_on: '2026-09-07', ends_on: '2026-12-18' }];
const report = {
  academic_year_id: 'Y1', ready: false, blocking_count: 2,
  checks: [
    { key: 'primary.competency_sheets', label: 'Feuilles de compétences non verrouillées', status: 'blocking', blocking_count: 1, detail: '1 feuille(s) de compétences à verrouiller dans 1 classe(s).' },
    { key: 'primary.promotion_map', label: 'Table de passage incohérente', status: 'blocking', blocking_count: 1, detail: null },
  ],
  sheets_to_lock: [{ class_id: 'C-ID-1', name: 'CE1 A', periods: ['P-T1'] }],
  promotion_map_errors: [{ code: 'missing_next_class', class_structure_id: 'C-ID-2', target_structure_id: null }],
};

function connect(role: string): ReturnType<typeof mockApi> {
  signIn(teacherMe, [tile({ type: 'primary', permissions: [...permissionsForRoles([role])], roles: [{ code: role, label: role }] })], role);
  return mockApi({
    'GET /primary/dashboard': { data: dashboard },
    'GET /academic-years': page([year]),
    'GET /periods': page(periods),
    'GET /primary/closure/check': { data: report },
  });
}

beforeEach(() => freshEnvironment());

describe('tableau de bord du primaire : état des feuilles', () => {
  it('distingue en retard, à terminer, verrouillée, complète, en cours et sans saisie', () => {
    expect(sheetBadge(sheet({ late: true }, { late: true }))).toBe('late');
    expect(sheetBadge(sheet({ late: true, overdue_periods: ['P-OLD'] }))).toBe('overdue');
    expect(sheetBadge(sheet({}, { status: 'locked' }))).toBe('locked');
    expect(sheetBadge(sheet({}, { entered: 9 }))).toBe('complete');
    expect(sheetBadge(sheet())).toBe('progress');
    expect(sheetBadge(sheet({}, { expected: 0, entered: 0 }))).toBe('none');
    expect(sheetBadge(sheet({}, null))).toBe('none');
    expect(BADGE_TONE.late).toBe('warning');
  });

  it('calcule le pourcentage affiché et classe les classes à surveiller d’abord', () => {
    expect(entryPercent(sheet())).toBe(33);
    expect(entryPercent(sheet({}, { expected: 0, entered: 0 }))).toBeNull();
    expect(watchFirst(dashboard.classes).map((c) => c.name)).toEqual(['CE1 A', 'CM2 B']);
  });
});

describe('route et permissions', () => {
  it('le tableau de bord est réservé aux établissements primaires et à primary.dashboard.read', () => {
    const route = routes.find((r) => r.name === 'pri-dashboard');
    expect(route?.path).toBe('/primaire/tableau-de-bord');
    expect(route?.meta?.establishmentTypes).toEqual(['primary']);
    expect(route?.meta?.perms).toEqual(['primary.dashboard.read']);
    expect(permissionsForRoles(['primary_directeur']).has('primary.dashboard.read')).toBe(true);
    expect(permissionsForRoles(['enseignant']).has('primary.dashboard.read')).toBe(false);
  });
});

describe('écran du directeur', () => {
  it('affiche des noms (jamais des identifiants), les totaux, les retards et les contrôles de clôture', async () => {
    const calls = connect('primary_directeur');
    const { wrapper } = await mountView(DashboardView);
    const text = wrapper.text();
    expect(text).toContain('Tableau de bord');
    expect(text).toContain('Situation au');
    expect(text).toContain('Trimestre 1');
    expect(text).toContain('Absents ce matin');
    expect(text).toContain('CE1 A');
    expect(text).toContain('CM2 B');
    expect(text).toContain('En retard');
    expect(text).toContain('Complète');
    expect(text).toContain('avant le');
    expect(text).not.toContain('C-ID-1');
    expect(text).not.toContain('P-T1');
    expect(calls.some((c) => c.path === '/primary/closure/check')).toBe(false);

    await wrapper.findAll('button').find((b) => b.text().includes('Vérifier avant la clôture'))?.trigger('click');
    await flushPromises();
    const after = wrapper.text();
    expect(calls.find((c) => c.path === '/primary/closure/check')?.query.academic_year_id).toBe('Y1');
    expect(after).toContain('2 point(s) à régler');
    expect(after).toContain('Feuilles de compétences à verrouiller');
    expect(after).toContain('CE1 A : Trimestre 1');
    expect(after).toContain('CM2 B : aucune classe suivante');
    expect(after).not.toContain('C-ID-2');
  });

  it('un périmètre limité à des classes le dit et masque les contrôles de clôture à qui n’y a pas droit', async () => {
    signIn(teacherMe, [tile({ type: 'primary', permissions: ['primary.dashboard.read'], roles: [{ code: 'x', label: 'x' }] })], 'enseignant');
    mockApi({
      'GET /primary/dashboard': { data: { ...dashboard, perimeter: 'classes' } },
      'GET /academic-years': page([year]),
      'GET /periods': page(periods),
    });
    const { wrapper } = await mountView(DashboardView);
    expect(wrapper.text()).toContain('seulement les classes de votre périmètre');
  });
});
