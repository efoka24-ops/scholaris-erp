// Traduction des écrans de délibération : dictionnaires propres (higher-deliberation-fr/en) tant qu'ils ne sont pas fusionnés
// dans fr.ts/en.ts ; même langue courante que le reste de l'interface.
import { computed } from 'vue';
import { locale } from '@/i18n';
import { higherDeliberationFr, type HigherDeliberationKey } from '@/i18n/higher-deliberation-fr';
import { higherDeliberationEn } from '@/i18n/higher-deliberation-en';

export type DelKey = HigherDeliberationKey;

export function dt(key: DelKey, params: Record<string, string | number> = {}): string {
  const dict: Record<DelKey, string> = locale.value === 'en' ? higherDeliberationEn : higherDeliberationFr;
  const template = dict[key] ?? higherDeliberationFr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** Clé dynamique (préfixe + valeur) ; retourne la valeur brute si la clé n'existe pas. */
export function dk(prefix: string, value: string | null | undefined): string {
  const key = `${prefix}${value ?? ''}`;
  return key in higherDeliberationFr ? dt(key as DelKey) : (value ?? '—');
}

/** Nombre à la française (11,55) ou à l'anglaise ; « — » si absent. */
export function num(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

export function useDelI18n() {
  return { dt, dk, num, lang: computed(() => locale.value) };
}
