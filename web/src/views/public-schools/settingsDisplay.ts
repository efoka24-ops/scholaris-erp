// Paramètres en vigueur de la Primaire publique (RG-TER-6) lus par un humain : libellé traduit pour chaque clé et valeur mise en
// forme (oui ou non, durées, montants, listes et barèmes selon la langue). Une clé ou un champ inconnu (paramètre ajouté plus
// tard côté serveur) reste lisible : on retombe sur la clé et sur une valeur simplement énumérée, jamais sur du JSON brut.
import { translate, type Locale, type MessageKey } from '@/i18n';
import { formatXaf } from '@/lib/format';

const PREFIX = 'public_primary.';
/** Valeurs entières suivies d'une unité. */
const UNITS: Record<string, MessageKey> = {
  'remediation.minutes_per_week': 'pp.set.unit.minutes',
  'promotion.appeal_days': 'pp.set.unit.days',
  'evaluation.evaluation_week_days': 'pp.set.unit.days',
  'evaluation.units_per_year': 'pp.set.unit.units',
  'evaluation.occasions': 'pp.set.unit.occasions',
  'evaluation.mastery_min_success': 'pp.set.unit.occasions',
  'evaluation.numeric_scale_max': 'pp.set.unit.points',
};
/** Champs d'objet exprimés en francs CFA. */
const MONEY_FIELDS = new Set(['half_day', 'copy', 'director_per_candidate']);

function has(key: string): key is MessageKey {
  return translate(key as MessageKey, {}, 'fr') !== key;
}

function label(key: string, locale: Locale): string | null {
  return has(key) ? translate(key as MessageKey, {}, locale) : null;
}

/** Libellé lisible d'un paramètre ; la clé technique seulement s'il n'est pas encore traduit. */
export function settingLabel(key: string, locale: Locale): string {
  const short = key.startsWith(PREFIX) ? key.slice(PREFIX.length) : key;
  return label(`pp.set.key.${short}`, locale) ?? key;
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

/** Libellé d'un élément porteur de label_fr / label_en (mention, examen, fonction). */
function localized(v: Record<string, unknown>, locale: Locale): string | null {
  const text = v[`label_${locale}`] ?? v.label_fr ?? v.label_en;
  return typeof text === 'string' && text !== '' ? text : null;
}

function scalar(v: unknown, locale: Locale, field?: string): string {
  if (v === null || v === undefined || v === '') return translate('pp.set.none', {}, locale);
  if (typeof v === 'boolean') return translate(v ? 'pp.set.yes' : 'pp.set.no', {}, locale);
  if (typeof v === 'number') return field !== undefined && MONEY_FIELDS.has(field) ? formatXaf(v, locale) : String(v);
  if (typeof v === 'string') return label(`pp.set.enum.${v}`, locale) ?? v;
  return lines(v, locale).join(' · ');
}

function lines(v: unknown, locale: Locale): string[] {
  if (Array.isArray(v)) {
    if (v.length === 0) return [translate('pp.set.none', {}, locale)];
    return v.map((item) => {
      if (!isRecord(item)) return scalar(item, locale);
      const text = localized(item, locale);
      const code = typeof item.code === 'string' ? item.code : null;
      return text !== null ? (code !== null ? `${code} : ${text}` : text) : lines(item, locale).join(' · ');
    });
  }
  if (isRecord(v)) {
    const entries = Object.entries(v);
    if (entries.length === 0) return [translate('pp.set.none', {}, locale)];
    return entries.map(([field, item]) => {
      if (isRecord(item) && localized(item, locale) !== null) return localized(item, locale) as string;
      const name = label(`pp.set.field.${field}`, locale) ?? field;
      return `${name} : ${scalar(item, locale, field)}`;
    });
  }
  return [scalar(v, locale)];
}

/** Valeur d'un paramètre en une ou plusieurs lignes lisibles. */
export function settingValue(key: string, value: unknown, locale: Locale): string[] {
  const short = key.startsWith(PREFIX) ? key.slice(PREFIX.length) : key;
  if (short === 'exams.birth_act_alert_month' && typeof value === 'number' && value >= 1 && value <= 12) {
    const month = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { month: 'long' }).format(new Date(2026, value - 1, 1));
    return [month];
  }
  const unit = UNITS[short];
  if (unit !== undefined && typeof value === 'number') return [translate(unit, { n: value }, locale)];
  if (short === 'documents.letterhead' && isRecord(value)) {
    // Seuls les champs renseignés : un en-tête vide n'a rien d'utile à montrer.
    const filled = Object.fromEntries(Object.entries(value).filter(([, v]) => v !== '' && v !== null));
    return lines(filled, locale);
  }
  return lines(value, locale);
}
