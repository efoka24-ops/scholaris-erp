// Textes des écrans « Stages et soutenances » IPES : dictionnaires higher-internships-{fr,en}.ts, lus ici tant qu'ils ne sont pas
// fusionnés dans fr.ts / en.ts (MessageKey). Même substitution {param} que translate().
import { useI18n } from '@/i18n';
import { higherInternshipsFr, type HigherInternshipsKey } from '@/i18n/higher-internships-fr';
import { higherInternshipsEn } from '@/i18n/higher-internships-en';

export type { HigherInternshipsKey };
const dictionaries = { fr: higherInternshipsFr as Record<HigherInternshipsKey, string>, en: higherInternshipsEn };

export function internshipsText(key: HigherInternshipsKey, params: Record<string, string | number> = {}, lang: 'fr' | 'en' = 'fr'): string {
  const template = dictionaries[lang][key] ?? dictionaries.fr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** Clé dynamique (préfixe + valeur de l'API). */
export const ik = (prefix: string, value: string | null | undefined): HigherInternshipsKey => `${prefix}${value ?? ''}` as HigherInternshipsKey;

export function useInternshipsI18n() {
  const base = useI18n();
  const ts = (key: HigherInternshipsKey, params?: Record<string, string | number>): string => {
    const out = internshipsText(key, params, base.locale.value);
    return out === key ? key.substring(key.lastIndexOf('.') + 1) : out;
  };
  return { ...base, ts };
}
