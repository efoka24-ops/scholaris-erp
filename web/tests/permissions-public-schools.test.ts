import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { permissionsForRoles, ROLE_PERMISSIONS } from '@/lib/permissions';

// Parité du miroir front avec la migration qui sème les rôles des écoles publiques (T610). Vitest s'exécute depuis web/.
const MIGRATION = resolve(process.cwd(), '../database/migrations/202610152351_roles_public_schools.sql');

// Migrations ultérieures de droits des écoles publiques recopiées dans le miroir (lot P2, section maternelle du directeur).
const LATER = ['202610231301_roles_public_primary_p2.sql', '202610231380_roles_public_nursery_director.sql'].map((f) =>
  resolve(process.cwd(), `../database/migrations/${f}`),
);

function seeded(): Record<string, string[]> {
  const sql = [MIGRATION, ...LATER].map((f) => readFileSync(f, 'utf8')).join('\n');
  const grants: Record<string, string[]> = {};
  for (const m of sql.matchAll(/INSERT INTO role_permissions \(role, permission\) VALUES \('([^']+)', '([^']+)'\);/g)) {
    (grants[m[1] as string] ??= []).push(m[2] as string);
  }
  return grants;
}

describe('permissions des écoles publiques (miroir de la migration)', () => {
  const grants = seeded();

  it('chaque rôle semé a exactement les permissions de la migration, y compris le rôle « parent » (R13) pour ses reçus APEE', () => {
    expect(Object.keys(grants)).toHaveLength(18);
    for (const [role, permissions] of Object.entries(grants)) {
      const mirrored = role === 'parent' ? (ROLE_PERMISSIONS.parent ?? []).filter((p) => p.startsWith('public_')) : (ROLE_PERMISSIONS[role] ?? []);
      expect([...mirrored].sort(), role).toEqual([...permissions].sort());
    }
  });

  it('le rôle « parent » garde ses permissions de libre-service', () => {
    expect(permissionsForRoles(['parent'])).toEqual(new Set(['self.read', 'documents.request', 'public_schools.apee.read_receipts']));
  });

  it("seul l'administrateur fonctionnel peut rattacher les écoles aux territoires", () => {
    const holders = Object.keys(ROLE_PERMISSIONS).filter((r) => (ROLE_PERMISSIONS[r] ?? []).includes('organisation.territories.manage'));
    expect(holders).toEqual(['pub_functional_admin']);
  });

  it('la liste des non-contributeurs APEE est réservée au bureau de l’APEE', () => {
    const holders = Object.keys(ROLE_PERMISSIONS).filter((r) => (ROLE_PERMISSIONS[r] ?? []).includes('public_schools.apee.non_payers.read'));
    expect(holders).toEqual(['pub_apee_board']);
  });

  it('le titulaire de classe n’a aucun accès nominatif hors de ses élèves ni aux comptes de l’APEE', () => {
    const teacher = permissionsForRoles(['pub_class_teacher']);
    expect(teacher.has('public_schools.attendance.write')).toBe(true);
    for (const forbidden of ['public_schools.pupils.named.read', 'public_schools.apee.read', 'public_schools.apee.non_payers.read']) {
      expect(teacher.has(forbidden), forbidden).toBe(false);
    }
  });
});
