// Traduction des écrans IPES complémentaires : clés hx.* lues dans higher-extra-{fr,en}.ts, sinon dictionnaire général (hi.*, ht.*,
// nav.*). Une fois les clés fusionnées dans fr.ts/en.ts, ce module reste valable sans changement.
import { useI18n, type MessageKey } from '@/i18n';
import { higherExtraFr, type HigherExtraKey } from '@/i18n/higher-extra-fr';
import { higherExtraEn } from '@/i18n/higher-extra-en';

export type XKey = HigherExtraKey | MessageKey;
const DICTS: Record<'fr' | 'en', Record<string, string>> = { fr: higherExtraFr, en: higherExtraEn };

/** Clé d'une valeur de l'API (`prefix` + valeur). */
export const xk = (prefix: string, value: string | number | null | undefined): XKey => `${prefix}${value ?? ''}` as XKey;

export function useXt() {
  const base = useI18n();
  function t(key: XKey, params: Record<string, string | number> = {}): string {
    const own = DICTS[base.locale.value][key] ?? DICTS.fr[key];
    if (own === undefined) return base.t(key as MessageKey, params);
    return own.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  /** Libellé d'une valeur : la valeur brute si la clé n'existe ni ici ni dans le dictionnaire général. */
  function tv(prefix: string, value: string | null | undefined): string {
    if (!value) return '—';
    const out = t(xk(prefix, value));
    return out === `${prefix}${value}` ? value : out;
  }
  return { t, tv, locale: base.locale };
}
