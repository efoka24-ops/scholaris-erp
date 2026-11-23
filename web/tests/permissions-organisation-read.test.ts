import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { permissionsForRoles } from '@/lib/permissions';

// C-115 (défaut C110-PR1) : miroir de database/migrations/202611080101_roles_organisation_read.sql. Vitest s'exécute depuis web/.
const SQL = readFileSync(resolve(process.cwd(), '../database/migrations/202611080101_roles_organisation_read.sql'), 'utf8');

describe('lecture de l’organisation par les rôles des applications', () => {
  const roles = [...SQL.matchAll(/VALUES \('([a-z_]+)', 'organisation\.read'\);/g)].map((m) => m[1] as string);

  it('chaque rôle de la migration lit l’organisation dans le miroir, sans la gérer', () => {
    expect(roles).toEqual(expect.arrayContaining(['primary_directeur', 'primary_secretaire', 'nursery_directeur', 'sec_chef_etablissement', 'sec_censeur']));
    for (const role of roles) {
      const set = permissionsForRoles([role]);
      expect(set.has('organisation.read'), role).toBe(true);
      expect(set.has('organisation.manage'), role).toBe(false);
    }
  });

  it('la migration n’accorde que organisation.read', () => {
    expect([...SQL.matchAll(/INSERT INTO role_permissions \(role, permission\) VALUES \('[a-z_]+', '([a-z_.]+)'\);/g)].every((m) => m[1] === 'organisation.read')).toBe(true);
  });
});
