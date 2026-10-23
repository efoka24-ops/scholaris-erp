// Actions de séance passant par le moteur du socle : signature du PV (API-14) et publication (API-15).
// 403 « double authentification récente » : on affiche le renvoi vers l'écran 2FA au lieu de l'erreur brute.
import { ref } from 'vue';
import { deliberation, isUnsignedMinutes, needsSecondFactor, type Jury } from '@/api/higher-deliberation';
import { useHigherAction } from '../useHigherAction';

export function useJurySession() {
  const act = useHigherAction();
  const needsTfa = ref(false);
  const unsigned = ref(false);
  const done = ref<'signed' | 'published' | null>(null);

  async function guarded(fn: () => Promise<unknown>, outcome: 'signed' | 'published'): Promise<boolean> {
    needsTfa.value = false;
    unsigned.value = false;
    done.value = null;
    const res = await act.run(async () => (await fn()) ?? true);
    if (res !== undefined) {
      done.value = outcome;
      return true;
    }
    if (needsSecondFactor(act.error.value)) {
      needsTfa.value = true;
      act.error.value = null;
    } else if (isUnsignedMinutes(act.error.value)) {
      unsigned.value = true;
      act.error.value = null;
    }
    return false;
  }

  const sign = (j: Jury): Promise<boolean> => (j.deliberation_ref ? guarded(() => deliberation.sign(j.deliberation_ref as string), 'signed') : Promise.resolve(false));
  const publish = (j: Jury): Promise<boolean> => guarded(() => deliberation.publish(j), 'published');

  return { ...act, needsTfa, unsigned, done, sign, publish, guarded };
}
