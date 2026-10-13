import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { http } from '@/api/http';
import { probeTenantAdmin } from '@/api/admin';
import type { ContextTile } from '@/api/types';
import { permissionsForRoles } from '@/lib/permissions';
import { contrastWithBlack } from '@/lib/format';

export const DEFAULT_ACCENT = '#F26B1D';
export const GROUP_ID = 'group';

export const useContextStore = defineStore('context', () => {
  const tiles = ref<ContextTile[]>([]);
  const activeId = ref<string | null>(null);
  const activeRole = ref<string | null>(null);
  const loaded = ref(false);
  /** Vrai si la personne administre le client (liste des établissements lisible) alors qu'aucun rôle n'est encore actif : super administrateur sans établissement. */
  const tenantAdmin = ref(false);

  const activeTile = computed(() => tiles.value.find((t) => t.id === activeId.value) ?? null);
  const isGroup = computed(() => activeTile.value?.kind === 'group');
  /** Identifiant envoyé en X-Establishment-Id ; null pour la vue de groupe. */
  const establishmentId = computed(() => (activeTile.value && activeTile.value.kind === 'establishment' ? activeTile.value.id : null));
  const establishmentType = computed(() => activeTile.value?.type ?? null);
  const roles = computed(() => activeTile.value?.roles ?? []);
  const accent = computed(() => {
    const a = activeTile.value?.accent;
    // Garde-fou : le texte sur l'accent est toujours noir ; sous 4,5:1 on retombe sur l'accent par défaut.
    return a && contrastWithBlack(a) >= 4.5 ? a : DEFAULT_ACCENT;
  });
  const permissions = computed<Set<string>>(() => {
    const given = activeTile.value?.permissions;
    if (given && given.length > 0) return new Set(given);
    const base = permissionsForRoles(activeRole.value ? [activeRole.value] : []);
    if (base.size === 0 && tenantAdmin.value) {
      // Sans établissement actif, seuls les écrans d'administration du client sont ouverts.
      return new Set([...permissionsForRoles(['super_admin'])].filter((p) => /^(organisation|settings|identity)\./.test(p)));
    }
    return base;
  });

  function can(...anyOf: string[]): boolean {
    if (anyOf.length === 0) return true;
    return anyOf.some((p) => permissions.value.has(p));
  }

  async function load(serverContext?: { establishment_id: string | null; role: string | null }): Promise<ContextTile[]> {
    const res = await http.get<{ data: ContextTile[] }>('/me/contexts');
    tiles.value = res.data;
    loaded.value = true;
    const serverId = serverContext?.establishment_id ?? (serverContext?.role && tiles.value.some((t) => t.kind === 'group') ? GROUP_ID : null);
    if (serverId && tiles.value.some((t) => t.id === serverId)) {
      activeId.value = serverId;
      activeRole.value = serverContext?.role ?? tiles.value.find((t) => t.id === serverId)?.roles[0]?.code ?? null;
    } else if (tiles.value.length === 1 && tiles.value[0]) {
      // Une seule possibilité : la personne arrive directement dans son application.
      await switchTo(tiles.value[0].id);
    }
    // Sonde (une requête) pour les personnes qui peuvent administrer le client : sans rôle actif, ou avec un rôle de niveau client.
    if (!activeRole.value || activeRole.value === 'super_admin' || activeRole.value === 'direction_groupe') tenantAdmin.value = await probeTenantAdmin();
    return tiles.value;
  }

  async function switchTo(id: string, role?: string): Promise<void> {
    const res = await http.post<{ data: { context: ContextTile; role: string } }>('/me/context', { establishment_id: id, ...(role ? { role } : {}) });
    activeId.value = id;
    activeRole.value = res.data.role;
    const idx = tiles.value.findIndex((t) => t.id === id);
    if (idx >= 0) tiles.value[idx] = { ...tiles.value[idx], ...res.data.context } as ContextTile;
  }

  function clear(): void {
    tiles.value = [];
    activeId.value = null;
    activeRole.value = null;
    loaded.value = false;
    tenantAdmin.value = false;
  }

  return { tenantAdmin, tiles, activeId, activeRole, loaded, activeTile, isGroup, establishmentId, establishmentType, roles, accent, permissions, can, load, switchTo, clear };
});
