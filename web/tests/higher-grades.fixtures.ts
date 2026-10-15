import type { AbsenceCode } from '@/api/higher-grades';

/** Codes d'absence IPES par défaut (IpesDefaults::ABSENCE_CODES). */
export const IpesDefaultsCodes: Record<string, AbsenceCode> = {
  justified: { effect: 'retake', label: 'Absence justifiée', aliases: ['ABJ', 'ABS'] },
  unjustified: { effect: 'zero', label: 'Absence injustifiée', aliases: ['ABI'] },
  na: { effect: 'zero', label: 'Non autorisé à composer', aliases: ['NA'] },
  fr: { effect: 'zero', label: 'Fraude', aliases: ['FR'], discipline: true },
};
