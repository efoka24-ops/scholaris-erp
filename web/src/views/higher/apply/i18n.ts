// Textes de la candidature en ligne : dictionnaires higher-apply-{fr,en}.ts, propres au module (fr.ts et en.ts partagés intacts).
import { useI18n } from '@/i18n';
import { higherApplyFr, type HigherApplyKey } from '@/i18n/higher-apply-fr';
import { higherApplyEn } from '@/i18n/higher-apply-en';

const dictionaries: Record<'fr' | 'en', Record<string, string>> = { fr: higherApplyFr, en: higherApplyEn };

/** Clé dynamique (préfixe + valeur de l'API). */
export const hk = (prefix: string, value: string | null | undefined): HigherApplyKey => `${prefix}${value ?? ''}` as HigherApplyKey;

export function useApplyI18n() {
  const base = useI18n();
  function ta(key: HigherApplyKey, params: Record<string, string | number> = {}): string {
    const template = dictionaries[base.locale.value][key] ?? dictionaries.fr[key];
    if (template === undefined) return key.substring(key.lastIndexOf('.') + 1);
    return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  return { ta, locale: base.locale, t: base.t };
}
