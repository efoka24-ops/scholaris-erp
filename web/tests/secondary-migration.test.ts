import { describe, expect, it } from 'vitest';
import { applicable, MIGRATION_KINDS, type MigrationBatch } from '@/api/secondaryMigration';
import { secondaryMigrationEn } from '@/i18n/secondary-migration-en';
import { secondaryMigrationFr } from '@/i18n/secondary-migration-fr';
import { MENU } from '@/views/secondary/menu';
import { secondaryRoutes } from '@/views/secondary/routes';

// Lot E4-C : reprise de données d'un ancien logiciel (données fictives).
function batch(status: MigrationBatch['status'], valid: number, error: number): MigrationBatch {
  return {
    id: 'b1', kind: 'classes', academic_year_id: 'y1', entry_date: null, format: 'csv', file_name: 'classes.csv', status,
    summary: { total: valid + error, valid, duplicate: 0, error, applied: 0 }, unknown_columns: [], created_by: 'u1', created_at: '2026-11-09T08:00:00+01:00', applied_at: null,
  };
}

describe('reprise de données du secondaire', () => {
  it('un lot ne s’applique que sans erreur, avec au moins une ligne nouvelle, et une seule fois', () => {
    expect(applicable(batch('simulated', 3, 0))).toBe(true);
    expect(applicable(batch('simulated', 3, 1))).toBe(false);
    expect(applicable(batch('simulated', 0, 0))).toBe(false);
    expect(applicable(batch('applied', 3, 0))).toBe(false);
  });

  it('ordre des jeux, textes FR et EN complets, écran et entrée de menu déclarés', () => {
    expect(MIGRATION_KINDS).toEqual(['classes', 'students', 'balances']);
    expect(Object.keys(secondaryMigrationEn).sort()).toEqual(Object.keys(secondaryMigrationFr).sort());
    for (const k of MIGRATION_KINDS) expect(secondaryMigrationFr).toHaveProperty(`secmg.kind.${k}`);
    expect(secondaryRoutes.map((r) => r.name)).toContain('sec-migration');
    const entry = MENU.find((e) => e.name === 'sec-migration');
    expect(entry?.perms).toEqual(expect.arrayContaining(['secondary.enrol.manage', 'secondary.billing.manage', 'secondary.settings.manage']));
    expect(entry?.modules).toBeUndefined();
  });
});
