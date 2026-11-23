import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { permissionsForRoles, ROLE_PERMISSIONS } from '@/lib/permissions';

const MIGRATION = resolve(process.cwd(), '../database/migrations/202610161240_roles_public_nursery.sql');

function seeded(): Record<string, string[]> {
  const sql = readFileSync(MIGRATION, 'utf8');
  const grants: Record<string, string[]> = {};
  for (const m of sql.matchAll(/INSERT INTO role_permissions \(role, permission\) VALUES \('([^']+)', '([^']+)'\);/g)) {
    (grants[m[1] as string] ??= []).push(m[2] as string);
  }
  return grants;
}

describe('permissions de la maternelle publique (miroir de la migration)', () => {
  const grants = seeded();

  it('chaque rôle semé a exactement les permissions de la migration', () => {
    expect(Object.keys(grants).sort()).toEqual(['pn_assistant', 'pn_educator']);
    for (const [role, permissions] of Object.entries(grants)) {
      expect([...((ROLE_PERMISSIONS as Record<string, string[]>)[role] ?? [])].sort()).toEqual([...permissions].sort());
    }
  });

  it('le rôle éducatrice possède bien les permissions de suivi et de sortie', () => {
    const educator = permissionsForRoles(['pn_educator']);
    for (const permission of ['public_nursery.pickup.handover', 'public_nursery.dev.observe', 'public_nursery.lifebook.write', 'public_nursery.need.report', 'public_nursery.transition.opinion']) {
      expect(educator.has(permission), permission).toBe(true);
    }
  });

  it('le rôle aide maternelle garde seulement la remise et la prise en charge d’arrivée', () => {
    expect(permissionsForRoles(['pn_assistant'])).toEqual(new Set(['public_nursery.pickup.handover', 'public_nursery.arrival.record']));
  });
});