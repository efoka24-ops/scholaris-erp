import { describe, expect, it } from 'vitest';
import { ULID_PATTERN, ulid } from '@/lib/ulid';
import { contrastWithBlack, formatXaf, todayIso } from '@/lib/format';

describe('ULID', () => {
  it('produit 26 caractères Crockford, triables par date', () => {
    const a = ulid(1_700_000_000_000);
    const b = ulid(1_700_000_001_000);
    expect(a).toMatch(ULID_PATTERN);
    expect(a < b).toBe(true);
  });

  it('ne se répète pas', () => {
    const set = new Set(Array.from({ length: 500 }, () => ulid()));
    expect(set.size).toBe(500);
  });
});

describe('formats', () => {
  it('formate un montant entier en FCFA', () => {
    expect(formatXaf(35000)).toBe('35 000 FCFA');
  });
  it('donne la date locale AAAA-MM-JJ', () => {
    expect(todayIso(new Date(2026, 9, 9, 23, 59))).toBe('2026-10-09');
  });
  it("vérifie le contraste du noir sur l'accent par défaut (≥ 4,5:1)", () => {
    expect(contrastWithBlack('#F26B1D')).toBeGreaterThanOrEqual(4.5);
    expect(contrastWithBlack('#1a1a80')).toBeLessThan(4.5);
  });
});
