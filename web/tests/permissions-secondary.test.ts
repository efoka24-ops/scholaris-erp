import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { permissionsForRoles, ROLE_PERMISSIONS } from '@/lib/permissions';

// Parité du miroir front avec la migration des rôles du secondaire (T808). Vitest s'exécute depuis web/.
const MIGRATION = resolve(process.cwd(), '../database/migrations/202610281601_roles_secondary.sql');

function seeded(): Record<string, string[]> {
  const grants: Record<string, string[]> = {};
  for (const m of readFileSync(MIGRATION, 'utf8').matchAll(/INSERT INTO role_permissions \(role, permission\) VALUES \('([^']+)', '([^']+)'\);/g)) {
    (grants[m[1] as string] ??= []).push(m[2] as string);
  }
  return grants;
}

describe('permissions du secondaire (miroir de la migration)', () => {
  const grants = seeded();

  it('23 rôles sec_* et le rôle commun « enseignant » ont exactement les permissions de la migration', () => {
    expect(Object.keys(grants).filter((r) => r.startsWith('sec_'))).toHaveLength(23);
    expect(grants.sec_enseignant).toBeUndefined();
    for (const [role, permissions] of Object.entries(grants)) {
      const mirrored = role === 'enseignant' ? (ROLE_PERMISSIONS.enseignant ?? []).filter((p) => p.startsWith('secondary.')) : (ROLE_PERMISSIONS[role] ?? []);
      expect([...mirrored].sort(), role).toEqual([...permissions].sort());
    }
  });

  it("l'enseignant garde ses droits des autres applications et reçoit l'appel et la saisie des notes du secondaire", () => {
    const set = permissionsForRoles(['enseignant']);
    for (const p of ['assessment.enter', 'primary.journal.write', 'secondary.attendance.take', 'secondary.grades.enter', 'secondary.lesson.log']) {
      expect(set.has(p), p).toBe(true);
    }
    expect(set.has('secondary.grades.lock')).toBe(false);
  });

  it('les comptes externes ne lisent que', () => {
    for (const role of ['sec_inspecteur', 'sec_delegation', 'sec_brigade_controle', 'sec_reseau']) {
      for (const p of ROLE_PERMISSIONS[role] ?? []) expect(p.endsWith('.read'), `${role} ${p}`).toBe(true);
    }
  });
});
