// Noms à la place des identifiants de personnes dans les écrans IPES : cache par établissement, demandes regroupées par lot
// (100 identifiants par appel à /higher/directory/names). Un identifiant inconnu, hors établissement ou refusé reste affiché tel quel.
import { reactive, watch, type WatchSource } from 'vue';
import { directory, NAMES_MAX, type PersonName } from '@/api/higher-directory';
import { useContextStore } from '@/stores/context';

const cache = reactive(new Map<string, PersonName | null>());
let cacheFor: string | null = null;
let pending = new Set<string>();
let scheduled: Promise<void> | null = null;

function resetIfNeeded(est: string | null): void {
  if (est === cacheFor) return;
  cacheFor = est;
  cache.clear();
  pending = new Set();
}

async function flush(): Promise<void> {
  const ids = [...pending];
  pending = new Set();
  scheduled = null;
  for (let i = 0; i < ids.length; i += NAMES_MAX) {
    const chunk = ids.slice(i, i + NAMES_MAX);
    try {
      const found = await directory.names(chunk);
      for (const p of found) cache.set(p.person_id, p);
    } catch {
      /* annuaire illisible : on garde les identifiants */
    }
    for (const id of chunk) if (!cache.has(id)) cache.set(id, null);
  }
}

/** Demande les noms manquants (regroupés au prochain tour de boucle) ; la promesse se résout quand le lot est chargé. */
export function requestNames(ids: Iterable<string | null | undefined>): Promise<void> {
  resetIfNeeded(useContextStore().establishmentId);
  for (const id of ids) if (id && !cache.has(id)) pending.add(id);
  if (pending.size === 0) return scheduled ?? Promise.resolve();
  scheduled ??= Promise.resolve().then(flush);
  return scheduled;
}

/** Vide le cache (tests, changement de contexte). */
export function clearHigherNames(): void {
  cache.clear();
  pending = new Set();
  scheduled = null;
  cacheFor = null;
}

export function useHigherNames(source?: WatchSource<Array<string | null | undefined>>) {
  /** « Prénom NOM », sinon l'identifiant. */
  const name = (id: string | null | undefined): string => {
    if (!id) return '—';
    const p = cache.get(id);
    return p ? `${p.first_name} ${p.last_name}`.trim() : id;
  };
  /** Nom suivi du matricule s'il existe. */
  const label = (id: string | null | undefined): string => {
    const p = id ? cache.get(id) : null;
    return p?.matricule ? `${name(id)} · ${p.matricule}` : name(id);
  };
  const known = (id: string | null | undefined): boolean => !!id && !!cache.get(id);
  if (source) watch(source, (ids) => void requestNames(ids), { immediate: true, deep: true });
  return { name, label, known, load: requestNames };
}
