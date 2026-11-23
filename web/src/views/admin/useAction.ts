import { ref } from 'vue';

/** Exécute une action asynchrone en mémorisant l'état « en cours » et la dernière erreur (affichée par ErrorNotice). */
export function useAction() {
  const busy = ref(false);
  const error = ref<unknown>(null);
  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    busy.value = true;
    error.value = null;
    try {
      return await fn();
    } catch (e) {
      error.value = e;
      return undefined;
    } finally {
      busy.value = false;
    }
  }
  return { busy, error, run };
}
