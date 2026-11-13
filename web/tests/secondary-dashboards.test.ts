// Lot E4-A : affichage des objectifs, menu Tableaux de bord selon les droits, textes fr/en alignés.
import { describe, expect, it } from 'vitest';
import { formatObjective, objectiveState, type Objective } from '@/api/secondaryDashboards';
import { secondaryDashboardsFr } from '@/i18n/secondary-dashboards-fr';
import { secondaryDashboardsEn } from '@/i18n/secondary-dashboards-en';
import { MENU, visibleEntries } from '@/views/secondary/menu';

const base: Objective = {
  code: 'O4', label: 'Pension recouvrée', available: true, value: 70, unit: 'percent', target: 90, direction: 'min', met: false,
  reason: null, detail: {}, source: 'cdc', confirmation: 'to_confirm',
};

describe('pilotage du secondaire', () => {
  it('formate pourcentages et heures sans zéros inutiles', () => {
    expect(formatObjective(70, 'percent', 'fr')).toBe('70 %');
    expect(formatObjective(0.5, 'hours_per_student', 'fr')).toBe('0,5');
    expect(formatObjective(null, 'count', 'fr')).toBe('—');
  });

  it('dit si un objectif est atteint, manqué, sans comparaison ou indisponible', () => {
    expect(objectiveState(base)).toBe('missed');
    expect(objectiveState({ ...base, met: true })).toBe('met');
    expect(objectiveState({ ...base, met: null })).toBe('open');
    expect(objectiveState({ ...base, available: false, value: null, reason: 'not_applicable' })).toBe('unavailable');
  });

  it('le menu Tableaux de bord suit les droits', () => {
    const hasRoute = (): boolean => true;
    const names = (perm: string): string[] => visibleEntries(null, (...p) => p.includes(perm), hasRoute, MENU).map((e) => e.name);
    expect(names('secondary.dashboard.read')).toContain('sec-dashboards');
    expect(names('secondary.finance.read')).toContain('sec-dashboards');
    expect(names('secondary.network.read')).toContain('sec-dashboards');
    expect(names('secondary.family.read')).not.toContain('sec-dashboards');
  });

  it('les textes existent dans les deux langues', () => {
    expect(Object.keys(secondaryDashboardsEn).sort()).toEqual(Object.keys(secondaryDashboardsFr).sort());
  });
});
