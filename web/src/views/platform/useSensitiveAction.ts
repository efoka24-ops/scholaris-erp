import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isRecentTwoFactorRequired, runPlatformAction, type PlatformAction } from '@/api/platform';

/**
 * Actions sensibles de l'administrateur de plateforme. Sur un 403 « two-factor-recent-required », l'action est mise de
 * côté (sessionStorage, 10 minutes au plus), la personne ressaisit son code sur l'écran de double authentification
 * existant, puis revient ici et confirme la reprise de l'action (jamais rejouée sans un clic).
 */
const STORAGE_KEY = 'scholaris.platform.pending-action';
const MAX_AGE_MS = 10 * 60 * 1000;

export interface PendingAction extends PlatformAction {
  /** Libellé de l'action, affiché lors de la reprise. */
  label: string;
  /** Page où l'action a été demandée ; la reprise n'est proposée que sur cette page. */
  returnTo: string;
  savedAt: number;
  /** Faux : jamais mise de côté ni rejouée (voir `run`). */
  replayable?: boolean;
}

export function savePending(action: PendingAction): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(action));
  } catch {
    /* stockage indisponible : la personne refera l'action après la double authentification */
  }
}

export function loadPending(now = Date.now()): PendingAction | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as PendingAction;
    if (!value || typeof value.path !== 'string' || !value.path.startsWith('/platform/') || now - value.savedAt > MAX_AGE_MS) {
      clearPending();
      return null;
    }
    return value;
  } catch {
    return null;
  }
}

export function clearPending(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* sans conséquence */
  }
}

export function useSensitiveAction() {
  const router = useRouter();
  const route = useRoute();
  const busy = ref(false);
  const error = ref<unknown>(null);
  /** Action refusée faute de double authentification récente (proposition de ressaisir le code). */
  const needsTwoFactor = ref<PendingAction | null>(null);

  /**
   * `replayable` faux : l'action n'est jamais mise de côté (sa réponse doit être affichée, ex. un code d'accès, ou son
   * corps contient un secret) ; après la double authentification, la personne relance l'action elle-même.
   */
  async function run<T>(action: PlatformAction, label: string, replayable = true): Promise<T | undefined> {
    busy.value = true;
    error.value = null;
    needsTwoFactor.value = null;
    try {
      return await runPlatformAction<T>(action);
    } catch (e) {
      if (isRecentTwoFactorRequired(e)) {
        needsTwoFactor.value = { ...action, body: replayable ? action.body : {}, label, returnTo: route.fullPath, savedAt: Date.now(), replayable };
      } else {
        error.value = e;
      }
      return undefined;
    } finally {
      busy.value = false;
    }
  }

  async function revalidate(): Promise<void> {
    const pending = needsTwoFactor.value;
    if (!pending) return;
    if (pending.replayable !== false) savePending({ ...pending, savedAt: Date.now() });
    await router.push({ name: 'twofactor', query: { redirect: pending.returnTo } });
  }

  function dismiss(): void {
    needsTwoFactor.value = null;
  }

  return { busy, error, needsTwoFactor, run, revalidate, dismiss };
}
