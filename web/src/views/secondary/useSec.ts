// Aides communes aux écrans du secondaire : action avec erreur affichable (ErrorNotice) et permissions du rôle actif.
import { ref } from 'vue';
import { useContextStore } from '@/stores/context';

export function useAction() {
  const error = ref<unknown>(null);
  const busy = ref(false);
  async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
    busy.value = true;
    error.value = null;
    try {
      return await action();
    } catch (e) {
      error.value = e;
      return undefined;
    } finally {
      busy.value = false;
    }
  }
  return { error, busy, run };
}

export function usePermissions() {
  const ctx = useContextStore();
  const can = (...perms: readonly string[]): boolean => ctx.can(...perms);
  return { can };
}

