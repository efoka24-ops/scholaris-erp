// Textes des écrans « Enseignement et présences » : clés ht.* lues dans higher-teaching-{fr,en}.ts, sinon dictionnaire général
// (clés hi.*, nav.*). Une fois les clés fusionnées dans fr.ts/en.ts, ce module reste valable sans changement.
import { useI18n, type MessageKey } from '@/i18n';
import { higherTeachingFr, type HigherTeachingKey } from '@/i18n/higher-teaching-fr';
import { higherTeachingEn } from '@/i18n/higher-teaching-en';

export type HtKey = HigherTeachingKey | MessageKey;
const DICTS: Record<'fr' | 'en', Record<string, string>> = { fr: higherTeachingFr, en: higherTeachingEn };

/** Clé de traduction d'une valeur de l'API (`prefix` + valeur). */
export const hk = (prefix: string, value: string | number | null | undefined): HtKey => `${prefix}${value ?? ''}` as HtKey;

export function useHt() {
  const base = useI18n();
  function t(key: HtKey, params: Record<string, string | number> = {}): string {
    const own = DICTS[base.locale.value][key] ?? DICTS.fr[key];
    if (own === undefined) return base.t(key as MessageKey, params);
    return own.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  return { t, locale: base.locale };
}
