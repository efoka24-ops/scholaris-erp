// Aides communes aux écrans de la Maternelle privée : action avec erreur affichable, permissions, identifiant client d'idempotence.
import { ref } from 'vue';
import { useContextStore } from '@/stores/context';
import { ulid } from '@/lib/ulid';

/** Exécute une action en gardant l'erreur (pour ErrorNotice) et l'état « en cours » ; renvoie undefined en cas d'échec. */
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
  return { can: (...perms: readonly string[]): boolean => ctx.can(...perms) };
}

/** Identifiant d'idempotence d'un geste (rejouer le même geste ne crée jamais de doublon côté serveur). */
export const newUuid = (): string => ulid();

/** Date du jour AAAA-MM-JJ. */
export { todayIso as today } from '@/lib/format';

/** Âge lisible à partir d'un nombre de mois. */
export function ageLabel(months: number | null, yearsUnit: string, monthsUnit: string): string {
  if (months === null) return '';
  const years = Math.floor(months / 12);
  const rest = months % 12;
  return years > 0 ? `${years} ${yearsUnit} ${rest} ${monthsUnit}` : `${rest} ${monthsUnit}`;
}
