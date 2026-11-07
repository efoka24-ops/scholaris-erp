import { computed, ref } from 'vue';
import { fr } from './fr';
import { en } from './en';

export type MessageKey = keyof typeof fr;
export type Locale = 'fr' | 'en';

const STORAGE_KEY = 'scholaris.locale';
const dictionaries: Record<Locale, Record<MessageKey, string>> = { fr, en };

function initialLocale(): Locale {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'fr' || saved === 'en') return saved;
  } catch {
    /* stockage indisponible : français par défaut */
  }
  return 'fr';
}

export const locale = ref<Locale>(initialLocale());

export function setLocale(next: Locale): void {
  locale.value = next;
  try {
    localStorage.setItem(STORAGE_KEY, next);
  } catch {
    /* sans conséquence */
  }
  if (typeof document !== 'undefined') document.documentElement.lang = next;
}

export function translate(key: MessageKey, params: Record<string, string | number> = {}, lang: Locale = locale.value): string {
  const template = dictionaries[lang][key] ?? dictionaries.fr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
}

/** Pluriel simple : la clé « .one » est utilisée pour 0 ou 1 (usage français), « .other » sinon. */
export function translatePlural(base: string, count: number, params: Record<string, string | number> = {}): string {
  const suffix = count > 1 ? 'other' : 'one';
  return translate(`${base}.${suffix}` as MessageKey, { count, ...params });
}

export function useI18n() {
  const t = (key: MessageKey, params?: Record<string, string | number>): string => translate(key, params, locale.value);
  const tp = (base: string, count: number, params?: Record<string, string | number>): string => {
    const suffix = count > 1 ? 'other' : 'one';
    return translate(`${base}.${suffix}` as MessageKey, { count, ...params }, locale.value);
  };
  return { t, tp, locale: computed(() => locale.value), setLocale };
}

if (typeof document !== 'undefined') document.documentElement.lang = locale.value;
