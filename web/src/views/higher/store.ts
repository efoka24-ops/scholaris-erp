// État partagé des écrans de l'application Supérieur (IPES) : droit d'usage (/me/applications), profil Higher (/higher/me),
// sélecteur « École » (RG-ORG-3), mode lecture seule (abonnement suspendu : 423) et référentiels mis en cache (écoles,
// départements, filières, niveaux, groupes, années) pour afficher des libellés plutôt que des identifiants.
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, http } from '@/api/http';
import { fetchMyApplications, type EntitlementState } from '@/api/platform';
import { academic, fetchHigherMe, isSuspended, type Department, type Group, type HigherMe, type Level, type Program, type Q, type School } from '@/api/higher';
import { useContextStore } from '@/stores/context';

/** États de l'abonnement qui rendent l'application visible (essai, actif, paiement en retard dans le délai de grâce). */
export const HIGHER_VISIBLE_STATES: EntitlementState[] = ['trial', 'active', 'past_due'];
export const ALL_SCHOOLS = 'all';
const SCHOOL_KEY = 'scholaris.higher.school';

export interface YearRef {
  id: string;
  label: string;
  status?: string;
  starts_on?: string;
}

type RefKind = 'schools' | 'departments' | 'programs' | 'levels' | 'groups' | 'years';

export const useHigherStore = defineStore('higher', () => {
  const me = ref<HigherMe | null>(null);
  const meError = ref<unknown>(null);
  const entitlement = ref<EntitlementState | null>(null);
  const entitlementKnown = ref(false);
  const loadedFor = ref<string | null>(null);
  /** Une écriture a reçu 423 : l'abonnement est suspendu, les écrans passent en lecture seule. */
  const suspendedSeen = ref(false);
  const selectedSchool = ref<string>(ALL_SCHOOLS);

  const schools = ref<School[]>([]);
  const departments = ref<Department[]>([]);
  const programs = ref<Program[]>([]);
  const levels = ref<Level[]>([]);
  const groups = ref<Group[]>([]);
  const years = ref<YearRef[]>([]);
  const loadedRefs = ref<Set<RefKind>>(new Set());

  /** Application visible pour ce client : état connu par /me/applications, sinon celui que donne /higher/me. */
  const visible = computed(() => {
    if (entitlementKnown.value) return !!entitlement.value && HIGHER_VISIBLE_STATES.includes(entitlement.value);
    const state = me.value?.application.state ?? null;
    return !!state && HIGHER_VISIBLE_STATES.includes(state);
  });
  const readOnly = computed(() => suspendedSeen.value || me.value?.application.writable === false || entitlement.value === 'suspended');
  const showSelector = computed(() => !!me.value?.school_selector);
  /** Écoles proposées par le sélecteur : toutes celles du périmètre (lecture de /higher/schools, filtrée par l'API). */
  const selectableSchools = computed(() => {
    if (!me.value) return [];
    if (me.value.all_schools) return schools.value;
    const allowed = new Set(me.value.school_ids);
    const known = schools.value.filter((s) => allowed.has(s.id));
    // École sans droit de lecture du paramétrage : on propose quand même son identifiant.
    for (const id of me.value.school_ids) if (!known.some((s) => s.id === id)) known.push({ id, code: '', name: id, director_person_id: null });
    return known;
  });

  async function ensure(force = false): Promise<void> {
    const ctx = useContextStore();
    const est = ctx.establishmentId;
    if (!est) return;
    if (!force && loadedFor.value === est) return;
    loadedFor.value = est;
    restoreSchool(est);
    meError.value = null;
    try {
      const apps = await fetchMyApplications();
      const mine = apps.opened.find((a) => a.app_key === 'higher' && (!a.establishments || a.establishments.some((e) => e.id === est)))
        ?? apps.opened.find((a) => a.app_key === 'higher');
      entitlement.value = mine?.state ?? null;
      entitlementKnown.value = true;
    } catch {
      entitlementKnown.value = false;
    }
    try {
      me.value = await fetchHigherMe();
    } catch (e) {
      me.value = null;
      meError.value = e;
    }
    if (me.value?.school_selector) await loadRefs('schools');
  }

  function restoreSchool(est: string): void {
    selectedSchool.value = ALL_SCHOOLS;
    try {
      const raw = sessionStorage.getItem(`${SCHOOL_KEY}.${est}`);
      if (raw) selectedSchool.value = raw;
    } catch {
      /* stockage indisponible : toutes les écoles */
    }
  }

  function selectSchool(id: string): void {
    selectedSchool.value = id || ALL_SCHOOLS;
    try {
      const est = useContextStore().establishmentId;
      if (est) sessionStorage.setItem(`${SCHOOL_KEY}.${est}`, selectedSchool.value);
    } catch {
      /* sans conséquence */
    }
  }

  /** Paramètre du sélecteur « École » pour les listes ; vide pour « Toutes les écoles ». */
  function scope(): Q {
    return selectedSchool.value !== ALL_SCHOOLS && showSelector.value ? { school_id: selectedSchool.value } : {};
  }
  const scopedSchoolId = computed(() => (selectedSchool.value !== ALL_SCHOOLS && showSelector.value ? selectedSchool.value : null));

  /** À appeler sur toute erreur d'écriture : un 423 bascule les écrans en lecture seule. */
  function noteError(e: unknown): void {
    if (isSuspended(e)) suspendedSeen.value = true;
  }

  async function loadRefs(...kinds: RefKind[]): Promise<void> {
    await Promise.all(
      kinds.map(async (kind) => {
        if (loadedRefs.value.has(kind)) return;
        try {
          switch (kind) {
            case 'schools':
              schools.value = await academic.schools();
              break;
            case 'departments':
              departments.value = await academic.departments();
              break;
            case 'programs':
              programs.value = await academic.programs();
              break;
            case 'levels':
              levels.value = await academic.levels();
              break;
            case 'groups':
              groups.value = await academic.groups();
              break;
            case 'years':
              years.value = await http.all<YearRef>('/academic-years', { sort: '-starts_on' });
              break;
          }
          loadedRefs.value = new Set([...loadedRefs.value, kind]);
        } catch (e) {
          // Référentiel illisible pour ce rôle : les écrans affichent alors les identifiants.
          if (!(e instanceof ApiError)) throw e;
        }
      }),
    );
  }

  function invalidate(...kinds: RefKind[]): void {
    const next = new Set(loadedRefs.value);
    for (const k of kinds) next.delete(k);
    loadedRefs.value = next;
  }

  const byId = <T extends { id: string }>(list: T[], id: string | null | undefined): T | undefined => (id ? list.find((x) => x.id === id) : undefined);
  const schoolName = (id: string | null | undefined): string => {
    const s = byId(schools.value, id);
    return s ? (s.code ? `${s.code} · ${s.name}` : s.name) : (id ?? '—');
  };
  const programName = (id: string | null | undefined): string => {
    const p = byId(programs.value, id);
    return p ? `${p.code} · ${p.name}` : (id ?? '—');
  };
  const levelName = (id: string | null | undefined): string => byId(levels.value, id)?.code ?? id ?? '—';
  const groupName = (id: string | null | undefined): string => byId(groups.value, id)?.name ?? id ?? '—';
  const yearName = (id: string | null | undefined): string => byId(years.value, id)?.label ?? id ?? '—';
  const departmentName = (id: string | null | undefined): string => {
    const d = byId(departments.value, id);
    return d ? `${d.code} · ${d.name}` : (id ?? '—');
  };

  function clear(): void {
    me.value = null;
    meError.value = null;
    entitlement.value = null;
    entitlementKnown.value = false;
    loadedFor.value = null;
    suspendedSeen.value = false;
    selectedSchool.value = ALL_SCHOOLS;
    loadedRefs.value = new Set();
  }

  return {
    me, meError, entitlement, entitlementKnown, suspendedSeen, selectedSchool, schools, departments, programs, levels, groups, years,
    visible, readOnly, showSelector, selectableSchools, scopedSchoolId,
    ensure, selectSchool, scope, noteError, loadRefs, invalidate, clear,
    schoolName, programName, levelName, groupName, yearName, departmentName,
  };
});
