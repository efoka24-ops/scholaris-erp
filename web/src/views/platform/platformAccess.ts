import { reactive } from 'vue';
import { fetchPlatformMe } from '@/api/platformBackoffice';
import { platformPermissionsFor, type PlatformRole } from '@/lib/permissions';

/**
 * Rôle et permissions `platform.*` du membre de la plateforme connecté (C-112), pour filtrer menus et écrans. Source :
 * GET /platform/me (permissions effectives) ; à défaut, miroir par rôle de lib/permissions.ts. Le serveur refuse de toute
 * façon (403) une action hors du périmètre du rôle.
 */
export const platformAccess = reactive<{ loadedFor: string | null; role: PlatformRole | null; permissions: string[] }>({
  loadedFor: null,
  role: null,
  permissions: [],
});

let inflight: Promise<void> | null = null;

export async function ensurePlatformAccess(userId: string): Promise<void> {
  if (platformAccess.loadedFor === userId) return;
  inflight ??= (async () => {
    try {
      const me = await fetchPlatformMe();
      platformAccess.role = me.role;
      platformAccess.permissions = me.permissions.length > 0 ? me.permissions : [...platformPermissionsFor(me.role)];
      platformAccess.loadedFor = userId;
    } catch {
      // Hors ligne ou erreur : on ne restreint pas l'interface (le serveur reste la barrière), on réessaiera.
      platformAccess.role = null;
      platformAccess.permissions = [];
    } finally {
      inflight = null;
    }
  })();
  await inflight;
}

/** Vrai si le membre détient au moins une des permissions ; tant que rien n'est chargé, l'interface ne restreint pas. */
export function platformCan(...permissions: string[]): boolean {
  if (permissions.length === 0 || platformAccess.loadedFor === null) return true;
  return permissions.some((p) => platformAccess.permissions.includes(p));
}

export function clearPlatformAccess(): void {
  platformAccess.loadedFor = null;
  platformAccess.role = null;
  platformAccess.permissions = [];
}
