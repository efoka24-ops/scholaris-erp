<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { TimetableWeek } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { useVocabulary } from '@/composables/useVocabulary';
import { formatDate, formatTime, todayIso } from '@/lib/format';

// Emploi du temps de la semaine, pour une classe ou pour l'enseignant connecté. Modifications ponctuelles signalées.
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const { term } = useVocabulary();

const week = ref(todayIso());
const mode = ref<'teacher' | 'structure'>(ctx.can('scheduling.read') ? 'structure' : 'teacher');
const structureId = ref('');
const structures = ref<Array<{ id: string; name?: string; label?: string; code?: string }>>([]);
const data = ref<TimetableWeek | null>(null);
const error = ref<unknown>(null);
const loading = ref(false);

const dayNames = computed(() => {
  const fmt = new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : 'fr-FR', { weekday: 'long' });
  return (date: string): string => fmt.format(new Date(`${date}T00:00:00`));
});

function shift(days: number): void {
  const d = new Date(`${week.value}T00:00:00`);
  d.setDate(d.getDate() + days);
  week.value = todayIso(d);
}

async function load(): Promise<void> {
  error.value = null;
  const query: Record<string, string> = { week: week.value };
  if (mode.value === 'teacher') {
    if (!auth.me?.person_id) return;
    query.teacher_person_id = auth.me.person_id;
  } else {
    if (!structureId.value) return;
    query.structure_id = structureId.value;
  }
  loading.value = true;
  try {
    data.value = (await http.get<{ data: TimetableWeek }>('/timetable', query)).data;
  } catch (e) {
    error.value = e;
    data.value = null;
  } finally {
    loading.value = false;
  }
}

const statusTone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'cancelled' ? 'warning' : s === 'planned' || s === 'normal' ? 'neutral' : 'warning');

watch([week, mode, structureId], load);
onMounted(async () => {
  if (ctx.can('scheduling.read')) {
    try {
      structures.value = await http.all('/structures', {}, {}, 5);
    } catch (e) {
      error.value = e;
    }
  }
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('timetable.eyebrow') }}</p>
      <h1>{{ t('timetable.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <ExportButton
      v-if="ctx.can('scheduling.read')"
      path="/timetable-slots/export"
      :query="mode === 'teacher' ? { 'filter[teacher_person_id]': auth.me?.person_id } : { 'filter[structure_id]': structureId }"
      name="emploi-du-temps"
    />

    <div class="row">
      <div v-if="ctx.can('scheduling.read')" class="field">
        <label for="tt-mode">{{ t('timetable.view') }}</label>
        <select id="tt-mode" v-model="mode">
          <option value="structure">{{ term('grouping') }}</option>
          <option value="teacher">{{ t('timetable.mine') }}</option>
        </select>
      </div>
      <div v-if="mode === 'structure'" class="field">
        <label for="tt-struct">{{ term('grouping') }}</label>
        <select id="tt-struct" v-model="structureId">
          <option value="" disabled>{{ t('grades.choose') }}</option>
          <option v-for="s in structures" :key="s.id" :value="s.id">{{ s.name ?? s.label ?? s.code ?? s.id }}</option>
        </select>
      </div>
      <div class="row nav">
        <button type="button" class="btn" @click="shift(-7)">← {{ t('timetable.prev') }}</button>
        <button type="button" class="btn" @click="week = todayIso()">{{ t('timetable.today') }}</button>
        <button type="button" class="btn" @click="shift(7)">{{ t('timetable.next') }} →</button>
      </div>
    </div>

    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
    <template v-if="data">
      <p class="muted">{{ t('timetable.week', { from: formatDate(data.week_start, locale), to: formatDate(data.week_end, locale) }) }}</p>
      <div class="days">
        <section v-for="d in data.days" :key="d.date" class="card" :aria-label="dayNames(d.date)">
          <h2 class="day">{{ dayNames(d.date) }} <small class="muted">{{ formatDate(d.date, locale) }}</small></h2>
          <p v-if="d.entries.length === 0" class="muted">{{ t('timetable.empty') }}</p>
          <ul v-else>
            <li v-for="e in d.entries" :key="e.slot_id + e.start_time">
              <strong>{{ formatTime(e.start_time) }} – {{ formatTime(e.end_time) }}</strong>
              {{ e.subject_label }}
              <StatusTag v-if="e.status && e.status !== 'planned' && e.status !== 'normal'" :tone="statusTone(e.status)">{{ e.status }}</StatusTag>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.days {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.75rem;
}
.days .card + .card {
  margin-top: 0;
}
.day {
  text-transform: capitalize;
  font-size: 16px;
}
.days ul {
  list-style: none;
  padding: 0;
  margin: 0;
}
.days li {
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border);
}
.nav {
  align-self: flex-end;
  margin-bottom: 0.9rem;
}
</style>
