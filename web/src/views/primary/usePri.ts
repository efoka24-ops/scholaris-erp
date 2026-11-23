// Aides communes aux écrans de l'école primaire privée : action avec erreur affichable, année scolaire en cours, permissions, noms de structures.
import { computed, ref } from 'vue';
import { listStructures, listYears, type AcademicYear, type Structure } from '@/api/admin';
import { todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';

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

/** Année en cours : celle dont les dates couvrent aujourd'hui, sinon la plus récente ouverte, sinon la première. */
export function pickYear(years: AcademicYear[], today = todayIso()): AcademicYear | null {
  return years.find((y) => y.starts_on <= today && today <= y.ends_on) ?? years.find((y) => y.status === 'open') ?? years[0] ?? null;
}

export async function currentYear(): Promise<AcademicYear | null> {
  return pickYear(await listYears());
}

export function usePermissions() {
  const ctx = useContextStore();
  const can = (...perms: readonly string[]): boolean => ctx.can(...perms);
  const establishmentId = computed(() => ctx.establishmentId);
  return { can, establishmentId };
}

/** Niveaux et classes de l'établissement, indexés par identifiant (noms affichés à la place des identifiants). */
export async function structureIndex(): Promise<{ list: Structure[]; byId: Record<string, Structure> }> {
  const list = await listStructures();
  const byId: Record<string, Structure> = {};
  for (const s of list) byId[s.id] = s;
  return { list, byId };
}
