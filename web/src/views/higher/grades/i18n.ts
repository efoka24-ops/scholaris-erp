// Textes des écrans « Notes » IPES : dictionnaires higher-grades-{fr,en}.ts, lus ici tant qu'ils ne sont pas fusionnés dans
// fr.ts / en.ts (MessageKey). Même substitution {param} que translate().
import { useI18n } from '@/i18n';
import { higherGradesFr, type HigherGradesKey } from '@/i18n/higher-grades-fr';
import { higherGradesEn } from '@/i18n/higher-grades-en';

export type { HigherGradesKey };
const dictionaries = { fr: higherGradesFr as Record<HigherGradesKey, string>, en: higherGradesEn };

export function gradesText(key: HigherGradesKey, params: Record<string, string | number> = {}, lang: 'fr' | 'en' = 'fr'): string {
  const template = dictionaries[lang][key] ?? dictionaries.fr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** Clé dynamique (préfixe + valeur de l'API) ; la valeur brute s'affiche si la clé manque. */
export const gk = (prefix: string, value: string | null | undefined): HigherGradesKey => `${prefix}${value ?? ''}` as HigherGradesKey;

export function useGradesI18n() {
  const base = useI18n();
  const tg = (key: HigherGradesKey, params?: Record<string, string | number>): string => {
    const out = gradesText(key, params, base.locale.value);
    return out === key ? key.substring(key.lastIndexOf('.') + 1) : out;
  };
  return { ...base, tg };
}
