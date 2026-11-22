import type { RoleRef } from '@/api/types';
import { permissionsForRoles } from '@/lib/permissions';

/**
 * Rôle actif par défaut à l'ouverture d'un établissement (ROLE-DEFAUT).
 *
 * L'administrateur du client reçoit les rôles de direction de ses applications (IPES-ADMIN) mais le serveur active le premier
 * rôle de la liste, souvent « Super administrateur ». Règle : le choix enregistré de la personne d'abord ; sinon le rôle
 * d'application le plus large ; sinon rien (le serveur garde son choix).
 */

/** Rôles de niveau client ou plateforme : jamais des rôles d'application. */
const NON_APPLICATION = new Set(['super_admin', 'direction_groupe']);

/** Rôles de direction préférés, dans cet ordre (IPES : R03 avant R20 ; puis chefs d'établissement et directeurs). */
const PREFERRED = [
  'higher_dir_academique',
  'higher_admin_fonct',
  'sec_chef_etablissement',
  'primary_directeur',
  'nursery_directeur',
  'pub_school_director',
  'higher_dir_ecole',
];

/** Rôle d'une application : code préfixé (higher_, sec_, primary_, nursery_, pub_, pn_…), hors rôles du client et de la plateforme. */
export function isApplicationRole(code: string): boolean {
  if (NON_APPLICATION.has(code) || code.startsWith('platform_')) return false;
  return /^[a-z]+_[a-z0-9_]+$/.test(code);
}

function breadth(role: RoleRef): number {
  const set = permissionsForRoles([role.code]);
  for (const p of role.permissions ?? []) set.add(p);
  return set.size;
}

/** Rôle d'application le plus large parmi ceux de l'établissement, ou null s'il n'y en a aucun. */
export function pickDefaultRole(roles: RoleRef[]): string | null {
  const apps = roles.filter((r) => isApplicationRole(r.code));
  if (apps.length === 0) return null;
  for (const code of PREFERRED) if (apps.some((r) => r.code === code)) return code;
  // Sinon le rôle qui ouvre le plus d'écrans (à égalité, l'ordre du serveur).
  let best = apps[0]!;
  for (const r of apps.slice(1)) if (breadth(r) > breadth(best)) best = r;
  return best.code;
}

const STORAGE_KEY = 'scholaris.role-choice';

function readAll(): Record<string, string> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, string>) : {};
  } catch {
    return {};
  }
}

/** Rôle choisi par la personne dans cet établissement (sur cet appareil), ou null. */
export function savedRoleChoice(userId: string | null | undefined, establishmentId: string): string | null {
  if (!userId) return null;
  const v = readAll()[`${userId}:${establishmentId}`];
  return typeof v === 'string' ? v : null;
}

/** Mémorise le rôle choisi par la personne (sélecteur « Rôle »), y compris un retour à « Super administrateur ». */
export function saveRoleChoice(userId: string | null | undefined, establishmentId: string, role: string): void {
  if (!userId) return;
  try {
    const all = readAll();
    all[`${userId}:${establishmentId}`] = role;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(all));
  } catch {
    // Stockage indisponible (navigation privée) : le choix vaut pour la session seulement.
  }
}

/** Rôle à demander au serveur à l'ouverture d'un établissement sans rôle explicite : choix enregistré, sinon rôle d'application par défaut. */
export function roleOnOpen(userId: string | null | undefined, establishmentId: string, roles: RoleRef[]): string | null {
  const saved = savedRoleChoice(userId, establishmentId);
  if (saved && roles.some((r) => r.code === saved)) return saved;
  return pickDefaultRole(roles);
}
