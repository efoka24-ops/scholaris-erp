// Circuits de validation EFFECTIFS de l'établissement actif, pour adapter les boutons des écrans.
// Le serveur est le juge (il applique le circuit réglé dans /administration/circuits) ; ce composable ne fait que montrer le bouton
// à la fonction désignée. Règle d'or : SANS réglage (étape « par défaut »), route indisponible ou circuit inconnu, l'affichage reste
// celui des droits historiques passés par l'écran, à l'identique.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { http } from '@/api/http';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';

export interface EffectiveStep {
  key: string;
  enabled: boolean;
  recent_confirmation: boolean;
  /** Niveau du dernier réglage de cette étape ; « default » = l'étape n'a jamais été réglée. */
  source: 'default' | 'platform' | 'client' | 'establishment';
  actor: { key: string; permission: string; role: string | null };
}

export interface EffectiveCircuit {
  id: string;
  application: string;
  key: string;
  customised: boolean;
  steps: EffectiveStep[];
}

/** Durée de validité du cache : un circuit réglé s'applique sans recharger l'application, sans interroger le serveur à chaque écran. */
export const WORKFLOW_CACHE_MS = 60_000;

export const useWorkflowStore = defineStore('workflow', () => {
  const circuits = ref<Record<string, EffectiveCircuit>>({});
  /** idle : jamais chargé ; ready : circuits lus ; unavailable : route indisponible (repli sur les droits historiques). */
  const state = ref<'idle' | 'ready' | 'unavailable'>('idle');
  let owner: string | null = null;
  let loadedAt = 0;
  let inflight: Promise<void> | null = null;

  function ownerKey(): string {
    const ctx = useContextStore();
    return `${useAuthStore().me?.id ?? ''}|${ctx.establishmentId ?? ''}`;
  }

  /** Charge (ou recharge si le cache est périmé, ou si `force`). Ne lève jamais : un échec = repli sur les droits historiques. */
  function load(force = false): Promise<void> {
    const ctx = useContextStore();
    const key = ownerKey();
    if (!ctx.establishmentId) return Promise.resolve();
    if (key !== owner) {
      owner = key;
      circuits.value = {};
      state.value = 'idle';
      loadedAt = 0;
      inflight = null;
    }
    if (inflight) return inflight;
    if (!force && state.value !== 'idle' && Date.now() - loadedAt < WORKFLOW_CACHE_MS) return Promise.resolve();
    const mine = key;
    inflight = (async () => {
      try {
        const all = await http.all<EffectiveCircuit>('/workflows/effective');
        if (owner !== mine) return;
        const next: Record<string, EffectiveCircuit> = {};
        for (const c of all) next[c.id] = c;
        circuits.value = next;
        state.value = 'ready';
      } catch {
        if (owner !== mine) return;
        circuits.value = {};
        state.value = 'unavailable';
      } finally {
        if (owner === mine) loadedAt = Date.now();
        inflight = null;
      }
    })();
    return inflight;
  }

  function reset(): void {
    circuits.value = {};
    state.value = 'idle';
    owner = null;
    loadedAt = 0;
    inflight = null;
  }

  return { circuits, state, load, reset };
});

/**
 * Étape RÉGLÉE d'un circuit (`application:circuit`, étape), ou null si rien n'a été réglé : circuit non chargé, route indisponible,
 * circuit inconnu ou étape jamais modifiée. Dans tous ces cas, l'écran garde ses droits historiques.
 */
export function useWorkflow(): {
  load: (force?: boolean) => Promise<void>;
  tuned: (circuit: string, step: string) => EffectiveStep | null;
  canAct: (circuit: string, step: string, legacy: readonly string[]) => boolean;
  stepEnabled: (circuit: string, step: string) => boolean;
} {
  const store = useWorkflowStore();
  const ctx = useContextStore();
  void store.load();

  function tuned(circuit: string, step: string): EffectiveStep | null {
    if (store.state !== 'ready') return null;
    const found = store.circuits[circuit]?.steps.find((s) => s.key === step);
    return found && found.source !== 'default' ? found : null;
  }

  function holdsRole(role: string): boolean {
    return ctx.activeRole === role || ctx.roles.some((r) => r.code === role);
  }

  /** Vrai si la personne tient la fonction désignée par le circuit à cette étape ; sinon (ou sans réglage) ses droits historiques. */
  function canAct(circuit: string, step: string, legacy: readonly string[]): boolean {
    const s = tuned(circuit, step);
    if (!s) return ctx.can(...legacy);
    if (!s.enabled) return false;
    return ctx.can(s.actor.permission) && (s.actor.role === null || holdsRole(s.actor.role));
  }

  /** Faux seulement si l'administrateur a désactivé cette étape facultative. */
  function stepEnabled(circuit: string, step: string): boolean {
    return tuned(circuit, step)?.enabled ?? true;
  }

  return { load: store.load, tuned, canAct, stepEnabled };
}

/** Identifiants des circuits branchés sur les écrans. */
export const WF = {
  grades: 'higher:grades_lifecycle',
  correction: 'higher:grades_correction',
  scholarship: 'higher:billing_scholarship',
  admission: 'higher:admissions_decision',
  reversal: 'billing:billing_reversal',
} as const;
