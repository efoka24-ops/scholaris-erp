import { defineStore } from 'pinia';
import { ref } from 'vue';
import { ApiError, http } from '@/api/http';
import type { Me } from '@/api/types';

/**
 * Reconnaît l'administrateur de plateforme (compte du client technique `platform`, rôle `platform_admin`).
 *
 * GET /me n'expose ni le client (tenant_id) ni ce rôle. Tant que le champ `platform_admin` (booléen) n'y figure pas,
 * on le déduit sans risque : seul un compte dont la double authentification est exigée et validée, sans aucun
 * établissement, est sondé par une lecture non sensible (GET /platform/dashboard). 200 : administrateur de plateforme ;
 * 401, 403 ou 404 : compte d'un client. Cette déduction ne sert qu'à adapter l'interface : le serveur refuse de toute
 * façon les routes /platform/** aux comptes des clients et les routes des clients aux comptes de la plateforme.
 */
export const usePlatformStore = defineStore('platform', () => {
  const isAdmin = ref(false);
  /** Identifiant du compte pour lequel la décision a été prise (une seule sonde par compte et par session). */
  const checkedFor = ref<string | null>(null);

  async function ensure(me: Me | null, context: { loaded: boolean; tiles: number }): Promise<boolean> {
    if (!me) {
      clear();
      return false;
    }
    if (checkedFor.value === me.id) return isAdmin.value;

    const declared = (me as Me & { platform_admin?: unknown }).platform_admin;
    if (typeof declared === 'boolean') return decide(me.id, declared);

    // Rôle sensible : la double authentification de l'administrateur de plateforme est toujours exigée par le serveur.
    if (!me.two_factor.required || (context.loaded && context.tiles > 0)) return decide(me.id, false);
    // Avant la validation du code, la sonde serait refusée : on décidera après la double authentification.
    if (!me.two_factor.verified) return false;

    try {
      await http.get('/platform/dashboard', undefined, { establishmentId: false });
      return decide(me.id, true);
    } catch (e) {
      if (e instanceof ApiError && [401, 403, 404].includes(e.status) && !e.isTwoFactorRequired) return decide(me.id, false);
      // Réseau ou erreur du serveur : on ne conclut pas, la prochaine navigation réessaiera.
      isAdmin.value = false;
      return false;
    }
  }

  function decide(id: string, value: boolean): boolean {
    isAdmin.value = value;
    checkedFor.value = id;
    return value;
  }

  function clear(): void {
    isAdmin.value = false;
    checkedFor.value = null;
  }

  return { isAdmin, checkedFor, ensure, clear };
});
