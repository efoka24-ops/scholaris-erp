// Textes de l'écran de reprise de données : dictionnaires higher-migration-{fr,en}.ts, propres au module.
import { useI18n } from '@/i18n';
import { higherMigrationFr, type HigherMigrationKey } from '@/i18n/higher-migration-fr';
import { higherMigrationEn } from '@/i18n/higher-migration-en';

const dictionaries: Record<'fr' | 'en', Record<string, string>> = { fr: higherMigrationFr, en: higherMigrationEn };

/** Clé dynamique (préfixe + valeur de l'API). */
export const mk = (prefix: string, value: string | null | undefined): HigherMigrationKey => `${prefix}${value ?? ''}` as HigherMigrationKey;

export function useMigrationI18n() {
  const base = useI18n();
  function tm(key: HigherMigrationKey, params: Record<string, string | number> = {}): string {
    const template = dictionaries[base.locale.value][key] ?? dictionaries.fr[key];
    if (template === undefined) return key.substring(key.lastIndexOf('.') + 1);
    return template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`));
  }
  /** Libellé d'une valeur de l'API : la valeur brute si aucune clé ne correspond. */
  function tv(prefix: string, value: string | null | undefined): string {
    if (!value) return '—';
    const key = mk(prefix, value);
    return key in higherMigrationFr ? tm(key) : value;
  }
  return { tm, tv, locale: base.locale };
}
