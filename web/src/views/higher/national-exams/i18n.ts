// Textes des écrans « Examens nationaux » et « Tutelle » IPES : dictionnaires higher-nationalexams-{fr,en}.ts, lus ici tant qu'ils
// ne sont pas fusionnés dans fr.ts / en.ts (MessageKey). Même substitution {param} que translate().
import { useI18n } from '@/i18n';
import { higherNationalExamsFr, type HigherNationalExamsKey } from '@/i18n/higher-nationalexams-fr';
import { higherNationalExamsEn } from '@/i18n/higher-nationalexams-en';

export type { HigherNationalExamsKey };
const dictionaries = { fr: higherNationalExamsFr as Record<HigherNationalExamsKey, string>, en: higherNationalExamsEn };

export function nationalText(key: HigherNationalExamsKey, params: Record<string, string | number> = {}, lang: 'fr' | 'en' = 'fr'): string {
  const template = dictionaries[lang][key] ?? dictionaries.fr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** Clé dynamique (préfixe + valeur de l'API). */
export const xk = (prefix: string, value: string | null | undefined): HigherNationalExamsKey => `${prefix}${value ?? ''}` as HigherNationalExamsKey;

export function useNationalI18n() {
  const base = useI18n();
  const tx = (key: HigherNationalExamsKey, params?: Record<string, string | number>): string => {
    const out = nationalText(key, params, base.locale.value);
    return out === key ? key.substring(key.lastIndexOf('.') + 1) : out;
  };
  return { ...base, tx };
}
