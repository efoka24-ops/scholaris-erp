// Textes des écrans « Tableaux de bord » : dictionnaires higher-dashboards-{fr,en}.ts (déjà fusionnés dans fr.ts / en.ts).
import { useI18n } from '@/i18n';
import { higherDashboardsFr, type HigherDashboardsKey } from '@/i18n/higher-dashboards-fr';
import { higherDashboardsEn } from '@/i18n/higher-dashboards-en';

const dictionaries: Record<'fr' | 'en', Record<string, string>> = { fr: higherDashboardsFr, en: higherDashboardsEn };

/** Clé dynamique (préfixe + valeur de l'API). */
export const dk = (prefix: string, value: string | null | undefined): HigherDashboardsKey => `${prefix}${value ?? ''}` as HigherDashboardsKey;

export function useDashboardsI18n() {
  const base = useI18n();
  /** Traduit ; `fallback` (sinon la valeur brute) s'affiche si la clé manque. */
  function td(key: HigherDashboardsKey, params: Record<string, string | number> = {}, fallback?: string): string {
    const template = dictionaries[base.locale.value][key] ?? dictionaries.fr[key];
    if (template === undefined) return fallback ?? key.substring(key.lastIndexOf('.') + 1);
    return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  return { td, locale: base.locale };
}
