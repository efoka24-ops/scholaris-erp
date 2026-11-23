<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';
import { http } from '@/api/http';
import { formatDateTime } from '@/lib/format';
import { useI18n, type MessageKey } from '@/i18n';

// Historique d'une note : chaque saisie ou correction avec son auteur, son motif et l'approbation éventuelle (jamais effacé).
interface GradeEvent {
  id: string;
  event_kind: string;
  old_score: number | null;
  new_score: number | null;
  old_absence: string | null;
  new_absence: string | null;
  author: string;
  reason: string | null;
  approved_by: string | null;
  happened_at: string;
}
const props = defineProps<{ gradeId: string; title?: string }>();
const emit = defineEmits<{ close: [] }>();
const { t, locale } = useI18n();

const events = ref<GradeEvent[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);

function shown(score: number | null, absence: string | null): string {
  if (absence) return t(absence === 'justified' ? 'grades.absentJustified' : 'grades.absentUnjustified');
  return score === null ? '—' : String(score);
}

const KINDS: Record<string, MessageKey> = { entry: 'grades.event.entry', import: 'grades.event.import', sync: 'grades.event.sync', correction: 'grades.event.correction' };
function kindLabel(kind: string): string {
  const key = KINDS[kind];
  return key ? t(key) : kind;
}

onMounted(async () => {
  try {
    events.value = (await http.get<{ data: GradeEvent[] }>(`/grades/${encodeURIComponent(props.gradeId)}/events`)).data;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="history card" role="dialog" aria-modal="false" :aria-label="t('grades.historyTitle')">
    <header class="row row--between">
      <h2>{{ t('grades.historyTitle') }}<template v-if="title"> · {{ title }}</template></h2>
      <button type="button" class="btn btn--quiet" @click="emit('close')">{{ t('common.close') }}</button>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
    <p v-else-if="events.length === 0 && !error" class="muted">{{ t('grades.historyEmpty') }}</p>
    <ol v-else class="timeline">
      <li v-for="e in events" :key="e.id">
        <div class="when">{{ formatDateTime(e.happened_at, locale) }}</div>
        <div>
          <b>{{ kindLabel(e.event_kind) }}</b> : {{ shown(e.old_score, e.old_absence) }} → <b>{{ shown(e.new_score, e.new_absence) }}</b>
          <div class="muted small">{{ t('grades.historyBy', { author: e.author }) }}<template v-if="e.approved_by"> · {{ t('grades.historyApproved', { approver: e.approved_by }) }}</template></div>
          <div v-if="e.reason" class="small">{{ t('grades.historyReason', { reason: e.reason }) }}</div>
        </div>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.history {
  margin: 12px 0;
}
.history h2 {
  margin: 0;
  font-size: 18px;
}
.timeline {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.timeline li {
  display: grid;
  grid-template-columns: 150px 1fr;
  gap: 12px;
  border-top: 1px solid var(--border, #dcdcd8);
  padding-top: 10px;
}
.when {
  font-size: 13px;
  color: #595959;
}
.small {
  font-size: 13px;
}
@media (max-width: 520px) {
  .timeline li {
    grid-template-columns: 1fr;
  }
}
</style>
