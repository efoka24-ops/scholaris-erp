// Lot E3-E (front) : personnel et paie simplifiée du secondaire (calculs d'affichage, menu, textes FR/EN complets).
import { describe, expect, it } from 'vitest';
import { hoursOf, netOf, PAYABLE_STATUSES, STAFF_CATEGORIES } from '@/views/secondary/staff/staffApi';
import { MENU, visibleEntries } from '@/views/secondary/menu';
import type { SecondaryProfile } from '@/api/secondaryProfile';
import { secondaryStaffFr } from '@/i18n/secondary-staff-fr';
import { secondaryStaffEn } from '@/i18n/secondary-staff-en';

const names = (perms: string[], profile: SecondaryProfile | null = null): string[] =>
  visibleEntries(profile, (...p) => p.some((x) => perms.includes(x)), () => true, MENU).map((e) => e.name);

describe('personnel et paie simplifiée du secondaire', () => {
  it('calcule le net comme le serveur : brut moins retenues', () => {
    expect(netOf(250000, [{ label: 'Avance', amount: 15000 }, { label: 'Retenue', amount: 5000 }])).toBe(230000);
    expect(netOf(10000, [{ label: 'Trop', amount: 12000 }])).toBe(-2000);
  });

  it('affiche les heures à partir des minutes', () => {
    expect(hoursOf(90)).toBe('1.50');
  });

  it('ne propose jamais le fonctionnaire à la paie', () => {
    expect(PAYABLE_STATUSES).not.toContain('civil_servant');
  });

  it('montre le personnel au censeur, la paie seulement avec le module payroll_simple', () => {
    expect(names(['secondary.syllabus.visa'])).toContain('sec-staff');
    expect(names(['secondary.family.read'])).not.toContain('sec-staff');
    expect(names(['secondary.billing.manage'])).not.toContain('sec-payroll');
    const privateProfile = { modules: [{ code: 'payroll_simple', state: 'active' }] } as unknown as SecondaryProfile;
    expect(names(['secondary.billing.manage'], privateProfile)).toContain('sec-payroll');
    expect(names(['secondary.settings.manage'], privateProfile)).toContain('sec-payroll');
  });

  it('nomme chaque catégorie du personnel, enseignant ou non', () => {
    const keys = Object.keys(secondaryStaffFr);
    for (const c of STAFF_CATEGORIES) expect(keys).toContain(`secst.category.${c}`);
  });

  it('a chaque texte en français et en anglais', () => {
    expect(Object.keys(secondaryStaffEn).sort()).toEqual(Object.keys(secondaryStaffFr).sort());
  });
});
