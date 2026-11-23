import { computed } from 'vue';
import { translate, locale, type MessageKey } from '@/i18n';
import { asEstablishmentType } from '@/lib/landing';
import { useContextStore } from '@/stores/context';

export type VocabTerm = 'learner' | 'grouping' | 'period' | 'document';

/** Termes de l'interface adaptés au type de l'établissement actif (enfant / élève / étudiant, classe / filière…). */
export function useVocabulary() {
  const ctx = useContextStore();
  const type = computed(() => asEstablishmentType(ctx.establishmentType));
  function term(name: VocabTerm): string {
    return translate(`vocab.${name}.${type.value}` as MessageKey, {}, locale.value);
  }
  return { type, term };
}
