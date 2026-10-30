// Aides d'affichage du personnel et des vacations.
import { formatXaf } from './format';

/** 150 minutes → « 2 h 30 ». */
export function formatMinutes(total: number): string {
  const m = Math.max(0, Math.round(total));
  const h = Math.floor(m / 60);
  const r = m % 60;
  return r === 0 ? `${h} h` : `${h} h ${String(r).padStart(2, '0')}`;
}

/** Heures d'équivalent TD, stockées en millièmes (1500 → « 1,5 »). */
export function formatHetd(milli: number, locale = 'fr'): string {
  return new Intl.NumberFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 3 }).format(milli / 1000);
}

export const money = formatXaf;

/** AAAA-MM-JJ + n jours (calcul en date locale). */
export function addDaysIso(iso: string, days: number): string {
  const d = new Date(`${iso}T00:00:00`);
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Mois courant au format AAAA-MM. */
export function currentMonth(now: Date = new Date()): string {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}
