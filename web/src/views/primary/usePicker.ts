// Sélection commune aux écrans du conseil, des bulletins et de la remédiation : classes de l'établissement, année en cours et
// trimestres (les séquences d'un trimestre sont celles dont les dates sont incluses dans les siennes).
import { computed, ref } from 'vue';
import { listPeriods, listStructures, type AcademicYear, type Period } from '@/api/admin';
import { todayIso } from '@/lib/format';
import { currentYear } from './usePri';

export function sequencesOf(trimester: Period, periods: Period[]): Period[] {
  return periods
    .filter((p) => p.kind === 'sequence' && p.starts_on >= trimester.starts_on && p.ends_on <= trimester.ends_on)
    .sort((a, b) => a.ordinal - b.ordinal);
}

/** Trimestre en cours (dates couvrant aujourd'hui), sinon le dernier commencé, sinon le premier. */
export function pickTrimester(trimesters: Period[], today = todayIso()): Period | null {
  const sorted = [...trimesters].sort((a, b) => a.ordinal - b.ordinal);
  return sorted.find((p) => p.starts_on <= today && today <= p.ends_on) ?? [...sorted].reverse().find((p) => p.starts_on <= today) ?? sorted[0] ?? null;
}

export function usePicker() {
  const classes = ref<{ id: string; name: string; parent_id?: string | null }[]>([]);
  const levels = ref<{ id: string; name: string }[]>([]);
  const year = ref<AcademicYear | null>(null);
  const periods = ref<Period[]>([]);
  const classId = ref('');
  const periodId = ref('');
  const trimesters = computed(() => periods.value.filter((p) => p.kind === 'trimester').sort((a, b) => a.ordinal - b.ordinal));
  const period = computed(() => periods.value.find((p) => p.id === periodId.value) ?? null);
  const className = (id: string): string => classes.value.find((c) => c.id === id)?.name ?? '';
  const levelName = (id: string): string => levels.value.find((c) => c.id === id)?.name ?? '';

  async function load(): Promise<void> {
    const structures = await listStructures();
    classes.value = structures.filter((s) => s.kind === 'class').map((s) => ({ id: s.id, name: s.name }));
    levels.value = structures.filter((s) => s.kind === 'level').map((s) => ({ id: s.id, name: s.name }));
    classId.value = classId.value || (classes.value[0]?.id ?? '');
    year.value = await currentYear();
    periods.value = year.value ? await listPeriods(year.value.id) : [];
    periodId.value = periodId.value || (pickTrimester(trimesters.value)?.id ?? '');
  }

  return { classes, levels, year, periods, classId, periodId, trimesters, period, className, levelName, load };
}

/** Nombre au format de la langue (virgule décimale en français), deux décimales ; tiret si absent. */
export function formatAverage(value: number | null | undefined, locale: string): string {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat(locale === 'en' ? 'en-GB' : 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}
