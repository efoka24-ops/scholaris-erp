import { ref } from 'vue';
import { fetchMyRegularisation, type MyRegularisation } from '@/api/platform';

/**
 * Mode régularisation de paiement côté établissement. Quand le client est au niveau « bloqué » (`locked`), le garde de
 * routes envoie toute navigation vers l'écran de régularisation (seuls la déconnexion et le paiement y restent possibles).
 * La situation est lue une fois par compte et par session ; une erreur réseau ne bloque jamais la navigation (le serveur
 * refuse de toute façon l'accès aux applications bloquées). Aucune donnée n'est supprimée par le blocage.
 */
const state = ref<MyRegularisation | null>(null);
const checkedFor = ref<string | null>(null);
/** Un blocage connu est relu au plus toutes les 60 secondes : un paiement ou un déblocage prend effet sans recharger la page. */
let lockedCheckedAt = 0;
const LOCKED_TTL_MS = 60_000;

/** Vrai si le compte appartient à un client dont l'accès est bloqué pour impayé. */
export async function isLockedForRegularisation(userId: string): Promise<boolean> {
  if (checkedFor.value !== userId || (state.value?.locked === true && Date.now() - lockedCheckedAt > LOCKED_TTL_MS)) {
    checkedFor.value = userId;
    state.value = null;
    await refreshRegularisation(userId);
  }
  return state.value?.locked === true;
}

/** Relit la situation de paiement (après un paiement, par exemple). */
export async function refreshRegularisation(userId?: string): Promise<MyRegularisation | null> {
  try {
    state.value = await fetchMyRegularisation();
    lockedCheckedAt = Date.now();
    if (userId) checkedFor.value = userId;
  } catch {
    // Hors ligne ou compte sans client : pas de blocage décidé côté navigateur.
    state.value = null;
  }
  return state.value;
}

export const regularisationState = state;

export function clearRegularisation(): void {
  state.value = null;
  checkedFor.value = null;
}
