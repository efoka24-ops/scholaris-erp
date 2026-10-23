// Formats d'affichage : montants entiers en XAF, dates et heures en français ou en anglais.
export function formatXaf(amount: number, locale = 'fr'): string {
  const n = new Intl.NumberFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 0 }).format(amount);
  return `${n.replace(/[\u202f\u00a0]/g, ' ')} FCFA`;
}

export function formatDate(iso: string | null | undefined, locale = 'fr'): string {
  if (!iso) return '';
  const d = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
}

export function formatTime(hhmm: string | null | undefined): string {
  return hhmm ? hhmm.slice(0, 5) : '';
}

export function formatDateTime(iso: string | null | undefined, locale = 'fr'): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(d);
}

/** Date locale au format AAAA-MM-JJ (jamais via toISOString, qui décale selon le fuseau). */
export function todayIso(now: Date = new Date()): string {
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${m}-${d}`;
}

export function nowHhmm(now: Date = new Date()): string {
  return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}

/** Rapport de contraste d'un texte noir sur une couleur d'accent (l'API refuse moins de 4,5:1 ; garde-fou côté client). */
export function contrastWithBlack(hex: string): number {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex);
  if (!m) return 0;
  const v = parseInt(m[1] as string, 16);
  const lin = (c: number): number => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  const l = 0.2126 * lin((v >> 16) & 255) + 0.7152 * lin((v >> 8) & 255) + 0.0722 * lin(v & 255);
  return (l + 0.05) / 0.05;
}
