import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { isApplicationRole, pickDefaultRole, saveRoleChoice } from '@/lib/defaultRole';
import type { ContextTile } from '@/api/types';
import { mockApi, teacherMe, tile, freshEnvironment } from './helpers';

// ROLE-DEFAUT : à l'ouverture d'un établissement, le rôle d'application le plus large est actif plutôt que « Super administrateur ».
beforeEach(() => {
  freshEnvironment();
  localStorage.clear();
});
afterEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

const superAdmin = { code: 'super_admin', label: 'Super administrateur', permissions: ['organisation.manage'] };
const academic = { code: 'higher_dir_academique', label: 'Directeur académique', permissions: ['higher.access', 'higher.settings.read'] };
const functional = { code: 'higher_admin_fonct', label: 'Administrateur fonctionnel', permissions: ['higher.access'] };
const chef = { code: 'sec_chef_etablissement', label: "Chef d'établissement" };

const adminMe = { ...teacherMe, id: 'U-ADMIN', context: { establishment_id: null, role: null } } as unknown as typeof teacherMe;

/** Connexion fraîche : jeton sans contexte, tuiles données ; le serveur renvoie le rôle demandé, ou le premier de la tuile. */
async function login(tiles: ContextTile[]) {
  useAuthStore().me = adminMe;
  const calls = mockApi({
    'GET /me/contexts': { data: tiles },
    'POST /me/context': (c) => {
      const t = tiles.find((x) => x.id === c.body?.establishment_id)!;
      return { data: { context: t, role: (c.body?.role as string | undefined) ?? t.roles[0]!.code } };
    },
    'GET /establishments': { data: [] },
  });
  const ctx = useContextStore();
  await ctx.load(adminMe.context as { establishment_id: string | null; role: string | null });
  return { ctx, calls, posts: () => calls.filter((c) => c.method === 'POST' && c.path === '/me/context') };
}

describe('rôle actif par défaut à la connexion', () => {
  it('premier chargement : le rôle d’application le plus large est choisi (IPES : R03)', async () => {
    const { ctx, posts } = await login([tile({ type: 'higher', roles: [superAdmin, functional, academic] })]);
    expect(posts()[0]?.body).toEqual({ establishment_id: 'E1', role: 'higher_dir_academique' });
    expect(ctx.activeRole).toBe('higher_dir_academique');
  });

  it('un choix enregistré est respecté, y compris le retour à « Super administrateur »', async () => {
    saveRoleChoice('U-ADMIN', 'E1', 'super_admin');
    const { ctx, posts } = await login([tile({ type: 'higher', roles: [superAdmin, academic] })]);
    expect(posts()[0]?.body).toEqual({ establishment_id: 'E1', role: 'super_admin' });
    expect(ctx.activeRole).toBe('super_admin');
  });

  it('le choix fait dans le sélecteur est mémorisé pour la connexion suivante', async () => {
    const tiles = [tile({ type: 'higher', roles: [superAdmin, academic] })];
    const first = await login(tiles);
    expect(first.ctx.activeRole).toBe('higher_dir_academique');
    await first.ctx.switchTo('E1', 'super_admin');
    freshEnvironment();
    const second = await login(tiles);
    expect(second.ctx.activeRole).toBe('super_admin');
  });

  it('aucun rôle d’application : rien ne change (le serveur garde son choix)', async () => {
    const { ctx, posts } = await login([tile({ roles: [superAdmin, { code: 'directeur', label: 'Directeur' }] })]);
    expect(posts()[0]?.body).toEqual({ establishment_id: 'E1' });
    expect(ctx.activeRole).toBe('super_admin');
  });

  it('plusieurs établissements : le rôle vient de l’établissement ouvert', async () => {
    const tiles = [
      tile({ id: 'E1', type: 'higher', roles: [superAdmin, academic] }),
      tile({ id: 'E2', name: 'Lycée de Garoua', type: 'secondary', roles: [superAdmin, { code: 'sec_professeur_principal', label: 'Professeur principal' }, chef] }),
    ];
    const { ctx, posts } = await login(tiles);
    expect(posts()).toHaveLength(0); // plusieurs tuiles : la personne choisit son établissement
    await ctx.switchTo('E2');
    expect(posts()[0]?.body).toEqual({ establishment_id: 'E2', role: 'sec_chef_etablissement' });
    expect(ctx.activeRole).toBe('sec_chef_etablissement');
    saveRoleChoice('U-ADMIN', 'E2', 'super_admin'); // un choix pour E2 ne vaut pas pour E1
    await ctx.switchTo('E1');
    expect(ctx.activeRole).toBe('higher_dir_academique');
  });

  it('un jeton qui porte déjà un rôle (rechargement de page) est conservé', async () => {
    useAuthStore().me = adminMe;
    const calls = mockApi({ 'GET /me/contexts': { data: [tile({ type: 'higher', roles: [superAdmin, academic] })] }, 'GET /establishments': { data: [] } });
    const ctx = useContextStore();
    await ctx.load({ establishment_id: 'E1', role: 'super_admin' });
    expect(ctx.activeRole).toBe('super_admin');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });
});

describe('choix du rôle d’application', () => {
  it('exclut les rôles du client et de la plateforme', () => {
    expect(isApplicationRole('super_admin')).toBe(false);
    expect(isApplicationRole('direction_groupe')).toBe(false);
    expect(isApplicationRole('platform_admin')).toBe(false);
    expect(isApplicationRole('enseignant')).toBe(false);
    expect(isApplicationRole('nursery_directeur')).toBe(true);
    expect(isApplicationRole('primary_directeur')).toBe(true);
  });

  it('préfère les rôles de direction, sinon le rôle qui ouvre le plus d’écrans', () => {
    expect(pickDefaultRole([superAdmin, { code: 'primary_secretaire', label: 'S' }, { code: 'primary_directeur', label: 'D' }])).toBe('primary_directeur');
    expect(pickDefaultRole([{ code: 'higher_x', label: 'X', permissions: ['a'] }, { code: 'higher_y', label: 'Y', permissions: ['a', 'b', 'c'] }])).toBe('higher_y');
    expect(pickDefaultRole([superAdmin])).toBeNull();
  });
});
