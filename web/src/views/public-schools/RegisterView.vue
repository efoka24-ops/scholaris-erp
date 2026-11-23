<script setup lang="ts">
// Cahier d'appel mensuel d'une classe (DOC-06) : une colonne par demi-journée dont l'appel a été fait, une ligne par élève. Vue
// imprimable en A4 paysage. Données nominatives, lisibles dans l'école seulement : le serveur répond 403 ailleurs.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicSchools, publicSchoolsP2, type AttendanceRegister, type DayPart } from '@/api/publicSchools';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { todayIso } from '@/lib/format';
import { useAction } from './usePs';

const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const { error, busy, run } = useAction();

const month = ref(todayIso().slice(0, 7));
const register = ref<AttendanceRegister | null>(null);

/** Colonnes affichées : seules les demi-journées dont l'appel existe (un jour sans appel n'a aucune colonne). */
const columns = computed(() =>
  (register.value?.days ?? []).flatMap((d) =>
    (['morning', 'afternoon'] as DayPart[]).filter((p) => d[p]).map((part) => ({ date: d.date, part, key: `${d.date}:${part}` })),
  ),
);
const dayHeads = computed(() =>
  (register.value?.days ?? [])
    .map((d) => ({ date: d.date, span: Number(d.morning) + Number(d.afternoon) }))
    .filter((d) => d.span > 0),
);
const monthLabel = computed(() => {
  const [y, m] = month.value.split('-').map(Number);
  return new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(new Date(y ?? 1970, (m ?? 1) - 1, 1));
});
const isCurrentMonth = computed(() => month.value >= todayIso().slice(0, 7));

const dayNumber = (iso: string): string => String(Number(iso.slice(8, 10)));
const partShort = (p: DayPart): string => t(p === 'morning' ? 'ps.register.morningShort' : 'ps.register.afternoonShort');
const partLong = (p: DayPart): string => t(p === 'morning' ? 'ps.call.morning' : 'ps.call.afternoon');
const markOf = (pupil: AttendanceRegister['pupils'][number], date: string, part: DayPart): 'A' | 'P' | null => pupil.marks[date]?.[part] ?? null;

function shift(delta: number): void {
  const [y, m] = month.value.split('-').map(Number);
  const d = new Date(y ?? 1970, (m ?? 1) - 1 + delta, 1);
  month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

async function load(): Promise<void> {
  const res = await run(() => publicSchools.attendanceRegister(props.id, month.value));
  register.value = res ?? null;
}

const print = (): void => window.print();
/** DOC-06 officiel (numéroté) : émis par le directeur, puis ouvert depuis le module Documents. */
const canIssue = computed(() => useContextStore().can('documents.read') && useContextStore().can('public_schools.attendance.write'));
async function issuePdf(): Promise<void> {
  await run(async () => openDocument((await publicSchoolsP2.registerDocument(props.id, month.value, locale.value === 'en' ? 'en' : 'fr')).id));
}

// A4 paysage et mise en page d'impression : posés seulement tant que le cahier est affiché (une règle @page est globale).
const PRINT_CSS = `@page { size: A4 landscape; margin: 10mm; }
@media print {
  body * { visibility: hidden; }
  .register-print, .register-print * { visibility: visible; }
  .register-print { position: absolute; left: 0; top: 0; width: 100%; }
  .register-print .table-wrap { overflow: visible; }
  .no-print { display: none !important; }
}`;
let printStyle: HTMLStyleElement | null = null;

watch(month, () => void load());
onMounted(() => {
  printStyle = document.createElement('style');
  printStyle.textContent = PRINT_CSS;
  document.head.appendChild(printStyle);
  void load();
});
onBeforeUnmount(() => printStyle?.remove());
</script>

<template>
  <div class="page register-page">
    <header class="page-head no-print">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.register.title') }}</h1>
      <p><router-link :to="{ name: 'ps-classes' }">{{ t('ps.register.back') }}</router-link></p>
    </header>
    <ErrorNotice :error="error" class="no-print" />

    <div class="row controls no-print" role="group" :aria-label="t('ps.register.month')">
      <button type="button" class="btn" :disabled="busy" @click="shift(-1)">{{ t('ps.register.previous') }}</button>
      <strong class="month" aria-live="polite">{{ monthLabel }}</strong>
      <button type="button" class="btn" :disabled="busy || isCurrentMonth" @click="shift(1)">{{ t('ps.register.next') }}</button>
      <button type="button" class="btn btn--primary" :disabled="!register || columns.length === 0" @click="print">{{ t('ps.register.print') }}</button>
      <button v-if="canIssue" type="button" class="btn" :disabled="busy || !register || columns.length === 0" @click="issuePdf">{{ t('pp.doc.registerPdf') }}</button>
    </div>

    <section v-if="register" class="register-print" :aria-label="t('ps.register.title')">
      <h2 class="print-title">{{ t('ps.register.heading', { class: register.class.label, month: monthLabel }) }}</h2>
      <p v-if="columns.length === 0" class="muted">{{ t('ps.register.none') }}</p>
      <p v-else-if="register.pupils.length === 0" class="muted">{{ t('ps.register.noPupils') }}</p>
      <div v-else class="table-wrap">
        <table class="grid">
          <caption class="sr-only">{{ t('ps.register.heading', { class: register.class.label, month: monthLabel }) }}</caption>
          <thead>
            <tr>
              <th scope="col" rowspan="2" class="name">{{ t('ps.pupils.name') }}</th>
              <th v-for="d in dayHeads" :key="d.date" scope="colgroup" :colspan="d.span">{{ dayNumber(d.date) }}</th>
              <th scope="col" rowspan="2" class="total">{{ t('ps.register.total') }}</th>
            </tr>
            <tr>
              <th v-for="c in columns" :key="c.key" scope="col"><abbr :title="partLong(c.part)">{{ partShort(c.part) }}</abbr></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in register.pupils" :key="p.person_id">
              <th scope="row" class="name">{{ p.name }}</th>
              <td v-for="c in columns" :key="c.key" :class="{ absent: markOf(p, c.date, c.part) === 'A' }">
                <template v-if="markOf(p, c.date, c.part) === 'A'">A<span class="sr-only"> {{ t('ps.call.absent') }}</span></template>
                <template v-else>·<span class="sr-only"> {{ t('ps.call.present') }}</span></template>
              </td>
              <td class="total">{{ p.absent_half_days }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted legend">{{ t('ps.register.legend') }}</p>
    </section>
  </div>
</template>

<style scoped>
.controls { align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.month { min-width: 10rem; text-align: center; text-transform: capitalize; }
.grid { border-collapse: collapse; font-size: 13px; }
.grid th, .grid td { border: 1px solid var(--border, #bdbdb8); padding: 3px 5px; text-align: center; min-width: 1.7rem; }
.grid .name { text-align: left; min-width: 11rem; white-space: nowrap; }
.grid .absent { font-weight: 700; background: var(--surface-2, #f3f3f0); }
.grid .total { font-weight: 700; }
.print-title { font-size: 1.1rem; text-transform: capitalize; }
.legend { margin-top: 8px; }
</style>
