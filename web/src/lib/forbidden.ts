// IPES-ADMIN : motif d'un refus d'écran d'application, pour remplacer « Accès refusé » par un message utile.
//  - no_app_role : aucun rôle de la personne dans l'établissement ne porte de droit de l'application (hors simple accès) ;
//  - other_role : un autre rôle de la personne dans l'établissement ouvre l'écran (le rôle actif ne le permet pas).
import type { RoleRef } from '@/api/types';
import { permissionsForRoles } from '@/lib/permissions';

/** Préfixes des permissions des applications, par type d'établissement. */
export const APP_PERMISSION_PREFIXES: Record<string, readonly string[]> = {
  higher: ['higher'],
  secondary: ['secondary'],
  primary: ['primary', 'public_primary', 'public_schools'],
  nursery: ['nursery', 'public_nursery'],
};

export type ForbiddenReason = { reason: 'no_app_role' } | { reason: 'other_role'; role: string } | null;

function permissionsOf(role: RoleRef): Set<string> {
  const all = permissionsForRoles([role.code]);
  for (const p of role.permissions ?? []) all.add(p);
  return all;
}

/**
 * @param types types d'établissement de l'écran (meta.establishmentTypes) ; sans type, ce n'est pas un écran d'application
 * @param perms permissions de l'écran (l'une suffit)
 * @param roles rôles de la personne dans l'établissement actif
 */
export function forbiddenReason(types: readonly string[] | undefined, perms: readonly string[], roles: readonly RoleRef[], activeRole: string | null): ForbiddenReason {
  if (!types || types.length === 0) return null;
  const other = roles.find((r) => r.code !== activeRole && perms.some((p) => permissionsOf(r).has(p)));
  if (other) return { reason: 'other_role', role: other.code };
  const prefixes = types.flatMap((t) => APP_PERMISSION_PREFIXES[t] ?? []);
  if (prefixes.length === 0) return null;
  const hasAppRole = roles.some((r) => [...permissionsOf(r)].some((p) => prefixes.some((x) => p.startsWith(`${x}.`) && p !== `${x}.access`)));
  return hasAppRole ? null : { reason: 'no_app_role' };
}
