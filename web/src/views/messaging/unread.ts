import { defineStore } from 'pinia';
import { ref } from 'vue';
import { CHAT_ROLES, fetchUnread } from '@/api/messaging';
import { useContextStore } from '@/stores/context';

/**
 * Compteur de messages non lus de la barre du haut (établissement actif). Relu à l'ouverture d'un écran et par les écrans
 * de messagerie ; aucune interrogation en arrière-plan. Une erreur (compte sans personne, 403) remet le compteur à zéro.
 */
export const useUnreadStore = defineStore('messaging-unread', () => {
  const count = ref(0);
  let inFlight: Promise<void> | null = null;

  function eligible(): boolean {
    const ctx = useContextStore();
    return !!ctx.establishmentId && CHAT_ROLES.includes(ctx.activeRole ?? '');
  }

  async function refresh(): Promise<void> {
    if (!eligible()) {
      count.value = 0;
      return;
    }
    if (inFlight) return inFlight;
    inFlight = fetchUnread()
      .then((n) => {
        count.value = n;
      })
      .catch(() => {
        count.value = 0;
      })
      .finally(() => {
        inFlight = null;
      });
    return inFlight;
  }

  return { count, eligible, refresh };
});
