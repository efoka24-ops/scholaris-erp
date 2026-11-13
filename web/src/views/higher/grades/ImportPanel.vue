<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { grades, type EnterResult } from '@/api/higher-grades';
import { useHigherAction } from '../useHigherAction';
import { downloadText, fileToCsv } from './spreadsheet';
import { useGradesI18n } from './i18n';

// Import CSV (modèle « ; ») ou XLSX converti dans le navigateur ; lot refusé en entier à la moindre erreur (422 par ligne).
const props = defineProps<{ assessmentId: string }>();
const emit = defineEmits<{ (e: 'imported', r: EnterResult): void }>();
const { tg } = useGradesI18n();
const act = useHigherAction();
const file = ref<File | null>(null);
const unreadable = ref(false);
const done = ref<EnterResult | null>(null);

const lineErrors = computed(() => {
  const e = act.error.value;
  if (!(e instanceof ApiError) || e.status !== 422) return [];
  return Object.entries(e.fieldErrors).map(([field, msgs]) => `${field.replace(/^lines\./, '#')} : ${msgs.join(' ')}`);
});

async function template(): Promise<void> {
  const csv = await act.run(() => grades.template(props.assessmentId));
  if (csv !== undefined) downloadText(csv, `notes_${props.assessmentId}.csv`);
}

function pick(event: Event): void {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
  unreadable.value = false;
  done.value = null;
}

async function send(): Promise<void> {
  const f = file.value;
  if (!f) return;
  let content: string;
  try {
    content = await fileToCsv(f);
  } catch {
    unreadable.value = true;
    return;
  }
  const res = await act.run(() => grades.importCsv(props.assessmentId, content));
  if (!res) return;
  done.value = res;
  emit('imported', res);
}
</script>

<template>
  <section class="card hi-section" data-test="import-panel">
    <h2>{{ tg('hg.import.title') }}</h2>
    <p class="muted hi-small">{{ tg('hg.import.hint') }}</p>
    <div class="hi-actions">
      <button type="button" class="btn btn--quiet" :disabled="act.busy.value" data-test="template" @click="template">{{ tg('hg.import.template') }}</button>
      <label class="field">
        <span class="label">{{ tg('hg.import.file') }}</span>
        <input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-test="import-file" @change="pick" />
      </label>
      <button type="button" class="btn btn--primary" :disabled="!file || act.busy.value" data-test="import-send" @click="send">{{ tg('hg.import.send') }}</button>
    </div>
    <p v-if="unreadable" class="error" role="alert">{{ tg('hg.import.unreadable') }}</p>
    <p v-if="done" class="notice" role="status">{{ tg('hg.import.done', { lines: done.lines ?? 0, written: done.written }) }}</p>
    <ErrorNotice :error="act.error.value" />
    <div v-if="lineErrors.length">
      <p><strong>{{ tg('hg.import.errors') }}</strong></p>
      <ul class="hi-errors"><li v-for="l in lineErrors" :key="l">{{ l }}</li></ul>
    </div>
  </section>
</template>
