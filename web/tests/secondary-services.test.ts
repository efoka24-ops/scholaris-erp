import { describe, expect, it } from 'vitest';
import { groupOf, parseStops } from '@/api/secondaryServices';
import type { SecondaryProfile } from '@/api/secondaryProfile';
import { secondaryServicesEn } from '@/i18n/secondary-services-en';
import { secondaryServicesFr } from '@/i18n/secondary-services-fr';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import { secondaryRoutes } from '@/views/secondary/routes';

// Lot E4-B : services annexes, situation financière, vie scolaire des portails (données fictives).
function profile(states: Record<string, 'active' | 'read_only' | 'inactive'>): SecondaryProfile {
  return {
    establishment_id: 'e1', subtype: 'x', stored: true, profile_version: 1, confirmation: 'to_confirm', ownership: 'public', ownership_options: [],
    cycles: ['first'], teaching_kinds: ['general'], sections: ['fr'], head_title: null,
    modules: Object.entries(states).map(([code, state]) => ({ code: code as SecondaryProfile['modules'][number]['code'], state, default_for_type: false })),
  };
}

describe('services annexes et portails du secondaire', () => {
  it('nettoie les arrêts d’une ligne et range chaque nature dans son module', () => {
    expect(parseStops(' Carrefour \n\nMarché\nCarrefour')).toEqual(['Carrefour', 'Marché']);
    expect(parseStops(Array.from({ length: 50 }, (_, i) => `Arrêt ${i}`).join('\n'))).toHaveLength(40);
    expect(groupOf('boarding')).toBe('boarding');
    expect(groupOf('canteen')).toBe('canteen-transport');
    expect(groupOf('transport')).toBe('canteen-transport');
  });

  it('menu des services : présent si un module est actif ou coupé (lecture seule), absent sinon', () => {
    const can = (...perms: readonly string[]): boolean => perms.includes('secondary.infirmary.manage');
    const has = (): boolean => true;
    const entry = MENU.filter((e) => e.name === 'sec-services');
    expect(visibleEntries(profile({ infirmary: 'active' }), can, has, entry)).toHaveLength(1);
    expect(visibleEntries(profile({ infirmary: 'read_only' }), can, has, entry)).toHaveLength(1);
    expect(visibleEntries(profile({ infirmary: 'inactive' }), can, has, entry)).toHaveLength(0);
  });

  it('textes FR et EN complets et écrans déclarés', () => {
    expect(Object.keys(secondaryServicesEn).sort()).toEqual(Object.keys(secondaryServicesFr).sort());
    const names = secondaryRoutes.map((r) => r.name);
    expect(names).toEqual(expect.arrayContaining(['sec-services', 'sec-finance-reports', 'sec-teacher']));
  });
});
