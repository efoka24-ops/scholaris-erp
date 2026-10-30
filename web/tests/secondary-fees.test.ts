import { describe, expect, it } from 'vitest';
import { planTotal, validInstallments } from '@/api/secondaryFees';
import { matchesPattern } from '@/api/secondaryCarteScolaire';
import { secondaryFeesFr } from '@/i18n/secondary-fees-fr';
import { secondaryFeesEn } from '@/i18n/secondary-fees-en';
import { secondaryRoutes } from '@/views/secondary/routes';

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
