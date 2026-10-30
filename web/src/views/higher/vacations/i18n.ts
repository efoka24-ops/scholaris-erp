// Textes des écrans « Vacations » : clés hv.* lues dans higher-vacations-{fr,en}.ts, sinon dictionnaire général (hi.*, nav.*).
// Une fois les clés fusionnées dans fr.ts/en.ts, ce module reste valable sans changement.
import { useI18n, type MessageKey } from '@/i18n';
import { higherVacationsFr, type HigherVacationsKey } from '@/i18n/higher-vacations-fr';
import { higherVacationsEn } from '@/i18n/higher-vacations-en';

export type HvKey = HigherVacationsKey | MessageKey;
const DICTS: Record<'fr' | 'en', Record<string, string>> = { fr: higherVacationsFr, en: higherVacationsEn };

/** Clé de traduction d'une valeur de l'API (`prefix` + valeur). */
export const vk = (prefix: string, value: string | null | undefined): HvKey => `${prefix}${value ?? ''}` as HvKey;

export function useHv() {
  const base = useI18n();
  function t(key: HvKey, params: Record<string, string | number> = {}): string {
    const own = DICTS[base.locale.value][key] ?? DICTS.fr[key];
    if (own === undefined) return base.t(key as MessageKey, params);
    return own.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  return { t, locale: base.locale };
}
