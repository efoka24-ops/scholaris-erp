<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { addDays, mondayOf, teaching, WEEKDAYS, type Room, type TimetableChange, type TimetableEntry } from '@/api/higher-teaching';
import { formatDate } from '@/lib/format';
import PersonPicker from '../PersonPicker.vue';
import GroupField from '../teaching/GroupField.vue';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { useXt, xk } from '../xi18n';

// Emploi du temps imprimable (EX-EDT) : une semaine d'un groupe ou d'un enseignant, une colonne par jour ; à l'impression, seule
// la feuille est imprimée (A4 paysage). Les modifications de la semaine (report, annulation, salle) sont signalées.
type Mode = 'group' | 'teacher';
const { t, locale } = useXt();
const higher = useHigherStore();
const list = useHigherAction();

const mode = ref<Mode>('group');
const groupId = ref('');
const personId = ref<string | null>(null);
const week = ref(mondayOf(new Date().toISOString().slice(0, 10)));
const entries = ref<TimetableEntry[]>([]);
const changes = ref<TimetableChange[]>([]);
const rooms = ref<Room[]>([]);
const loaded = ref(false);
const { name } = useHigherNames(() => [personId.value, ...entries.value.map((e) => e.person_id)]);

const days = computed(() =>
  WEEKDAYS.map((d) => ({ d, date: addDays(week.value, d - 1), items: entries.value.filter((e) => e.weekday === d).sort((a, b) => a.start_time.localeCompare(b.start_time)) }))
    .filter((x) => x.d <= 6 || x.items.length > 0),
);
const subject = computed(() => (mode.value === 'group' ? higher.groupName(groupId.value) : name(personId.value)));
const roomCode = (id: string | null): string => rooms.value.find((r) => r.id === id)?.code ?? '—';
const changeOf = (e: TimetableEntry, date: string): TimetableChange | undefined => changes.value.find((c) => c.entry_id === e.id && c.occurs_on === date);

async function load(): Promise<void> {
  loaded.value = false;
  const who = mode.value === 'group' ? groupId.value : personId.value;
  if (!who) return;
  const q = { ...higher.scope(), week: week.value, ...(mode.value === 'group' ? { group_id: who } : { person_id: who }) };
  const res = await list.run(() => teaching.timetable(q));
  if (!res) return;
  entries.value = res.entries;
  changes.value = res.changes;
  loaded.value = true;
}

function setMode(m: Mode): void {
  mode.value = m;
  entries.value = [];
  void load();
}

function print(): void {
  window.print();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  const r = await list.run(() => teaching.rooms(higher.scope()));
  if (r) rooms.value = r;
});
</script>

<template>
  <HigherFrame :title="t('hx.print.title')" :lede="t('hx.print.lede')" section="home" @school-change="load">
    <div class="tp-controls">
      <div class="hi-tabs" role="tablist">
        <button type="button" role="tab" :aria-selected="mode === 'group'" @click="setMode('group')">{{ t('ht.tt.byGroup') }}</button>
        <button type="button" role="tab" :aria-selected="mode === 'teacher'" @click="setMode('teacher')">{{ t('ht.tt.byTeacher') }}</button>
      </div>
      <div class="hi-form">
        <GroupField v-if="mode === 'group'" id="tp-group" :model-value="groupId" @update:model-value="(v: string) => { groupId = v; load(); }" />
        <div v-else class="hi-wide"><PersonPicker id="tp-person" :model-value="personId" :label="t('hx.print.teacher')" @update:model-value="(v: string | null) => { personId = v; load(); }" /></div>
        <div class="field"><label for="tp-week">{{ t('ht.tt.week') }}</label><input id="tp-week" :value="week" type="date" @change="week = mondayOf(($event.target as HTMLInputElement).value || week); load()" /></div>
      </div>
      <button type="button" class="btn btn--primary" :disabled="!loaded" data-test="print" @click="print">{{ t('hx.print.print') }}</button>
      <ErrorNotice :error="list.error.value" />
      <p v-if="!loaded && !list.busy.value" class="muted">{{ t('hx.print.choose') }}</p>
    </div>

    <section v-if="loaded" class="tp-sheet" data-test="sheet">
      <header class="tp-head">
        <h2>{{ subject }}</h2>
        <p>{{ t('hx.print.weekOf', { from: formatDate(week, locale), to: formatDate(addDays(week, 6), locale) }) }}</p>
      </header>
      <p v-if="entries.length === 0" class="muted">{{ t('hx.print.empty') }}</p>
      <div v-else class="tp-grid">
        <div v-for="day in days" :key="day.d" class="tp-day">
          <h3>{{ t(xk('ht.tt.day.', day.d)) }} {{ day.date.slice(8, 10) }}/{{ day.date.slice(5, 7) }}</h3>
          <div v-for="e in day.items" :key="e.id" class="tp-slot" :class="{ 'tp-slot--changed': changeOf(e, day.date) }" data-test="slot">
            <p class="tp-time">{{ e.start_time }}–{{ e.end_time }} · {{ t(xk('ht.kind.', e.session_kind)) }}</p>
            <p class="tp-title">{{ e.element_title }}</p>
            <p>{{ mode === 'group' ? name(e.person_id) : higher.groupName(e.group_id) }} · {{ t('ht.room') }} {{ roomCode(e.room_id) }}</p>
            <p v-if="changeOf(e, day.date)" class="tp-change">{{ t(xk('ht.tt.change.', changeOf(e, day.date)!.change_kind)) }}</p>
          </div>
        </div>
      </div>
      <footer class="tp-foot">{{ t('hx.print.generated', { date: formatDate(new Date().toISOString(), locale) }) }}</footer>
    </section>
  </HigherFrame>
</template>

<style scoped>
.tp-controls {
  margin-bottom: 16px;
}
.tp-sheet {
  background: var(--surface);
  border: 1px solid var(--border);
  padding: 16px;
}
.tp-head h2 {
  margin: 0;
}
.tp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 8px;
}
.tp-day h3 {
  font-size: 14px;
  margin: 0 0 6px;
  padding-bottom: 4px;
  border-bottom: 2px solid var(--ink);
}
.tp-slot {
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  padding: 6px 8px;
  margin-bottom: 6px;
  break-inside: avoid;
}
.tp-slot--changed {
  border-left-color: var(--warning);
}
.tp-slot p {
  margin: 0 0 2px;
  font-size: 13px;
}
.tp-time,
.tp-title,
.tp-change {
  font-weight: 700;
}
.tp-foot {
  margin-top: 12px;
  font-size: 12px;
  color: var(--muted, inherit);
}
</style>

<style>
/* Impression : seule la feuille de l'emploi du temps est imprimée, en A4 paysage, en noir sur blanc. */
@media print {
  @page {
    size: A4 landscape;
    margin: 10mm;
  }
  body * {
    visibility: hidden;
  }
  .tp-sheet,
  .tp-sheet * {
    visibility: visible;
    color: #000 !important;
    background: #fff !important;
  }
  .tp-sheet {
    position: absolute;
    inset: 0 auto auto 0;
    width: 100%;
    border: 0;
    padding: 0;
  }
  .tp-sheet .tp-grid {
    grid-template-columns: repeat(6, 1fr);
  }
}
</style>
