import { ref } from 'vue';
import { useHigherStore } from './store';

/**
 * Exécute un appel IPES en mémorisant « en cours » et la dernière erreur (affichée par ErrorNotice, RFC 9457 avec trace_id).
 * Un 423 (abonnement suspendu) bascule tous les écrans en lecture seule.
 */
export function useHigherAction() {
  const higher = useHigherStore();
  const busy = ref(false);
  const error = ref<unknown>(null);
  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    busy.value = true;
    error.value = null;
    try {
      return await fn();
    } catch (e) {
      higher.noteError(e);
      error.value = e;
      return undefined;
    } finally {
      busy.value = false;
    }
  }
  return { busy, error, run };
}
