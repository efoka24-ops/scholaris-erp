import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { permissionsForRoles, ROLE_PERMISSIONS } from '@/lib/permissions';
import { nurseryPrivateRoutes } from '@/views/nursery-private/routes';

const ROOT = resolve(process.cwd(), '..');
const MIGRATIONS = ['database/migrations/202610220801_roles_nursery.sql', 'database/migrations/202610231303_roles_nursery_services_consents.sql'].map((m) => resolve(ROOT, m));
const FOLDER = resolve(process.cwd(), 'src/views/nursery-private');

function seeded(): Record<string, string[]> {
  const grants: Record<string, string[]> = {};
  for (const m of MIGRATIONS.map((f) => readFileSync(f, 'utf8')).join('\n').matchAll(/INSERT INTO role_permissions \(role, permission\) VALUES \('([^']+)', '([^']+)'\);/g)) {
    (grants[m[1] as string] ??= []).push(m[2] as string);
  }
  return grants;
}

describe('permissions de la maternelle privée (miroir de la migration de rôles)', () => {
  it('chaque rôle semé a exactement les permissions de la migration', () => {
    const grants = seeded();
    expect(Object.keys(grants).sort()).toEqual(['nursery_assistant', 'nursery_cantine', 'nursery_directeur', 'nursery_educator', 'nursery_promoteur', 'nursery_secretaire']);
    for (const [role, permissions] of Object.entries(grants)) {
      expect([...((ROLE_PERMISSIONS as Record<string, string[]>)[role] ?? [])].sort(), role).toEqual([...permissions].sort());
    }
  });

  it('le rôle cantine ne lit que les allergies et note la présence au service ; l’aide ne remet ni n’observe', () => {
    expect(permissionsForRoles(['nursery_cantine'])).toEqual(new Set(['nursery.allergy.read', 'nursery.service.attend']));
    const aide = permissionsForRoles(['nursery_assistant']);
    expect(aide.has('nursery.pickup.handover')).toBe(true);
    expect(aide.has('nursery.dev.observe')).toBe(false);
    expect(aide.has('nursery.pickup.restrict')).toBe(false);
  });

  it('seule la directrice saisit les restrictions de garde et valide les carnets', () => {
    for (const role of ['nursery_educator', 'nursery_assistant', 'nursery_secretaire', 'nursery_promoteur', 'nursery_cantine']) {
      const perms = permissionsForRoles([role]);
      expect(perms.has('nursery.pickup.restrict'), role).toBe(false);
      expect(perms.has('nursery.dev.validate'), role).toBe(false);
    }
    expect(permissionsForRoles(['nursery_directeur']).has('nursery.pickup.restrict')).toBe(true);
  });
});

describe('menu de la maternelle privée : aucun écran public (T1070)', () => {
  it('toutes les routes sont réservées aux établissements nursery et aux permissions nursery.* (ou au rôle parent)', () => {
    expect(nurseryPrivateRoutes.length).toBeGreaterThan(10);
    for (const route of nurseryPrivateRoutes) {
      expect(route.path.startsWith('/maternelle-privee'), String(route.name)).toBe(true);
      expect(route.meta?.establishmentTypes, String(route.name)).toEqual(['nursery']);
      const perms = route.meta?.perms ?? [];
      expect(perms.length > 0 || route.meta?.roles?.includes('parent') === true, String(route.name)).toBe(true);
      for (const permission of perms) expect(/^(nursery\.|attendance\.take|billing\.|reporting\.)/.test(permission), `${String(route.name)} : ${permission}`).toBe(true);
    }
  });

  it('aucun fichier de l’espace ne cite une maternelle publique ni n’utilise v-html', () => {
    const files = readdirSync(FOLDER).filter((f) => /\.(vue|ts)$/.test(f));
    expect(files.length).toBeGreaterThan(15);
    for (const file of files) {
      const source = readFileSync(resolve(FOLDER, file), 'utf8');
      expect(source, file).not.toMatch(/public[_-]nursery|public-schools|pn\./);
      expect(source, file).not.toContain('v-html');
    }
  });
});
