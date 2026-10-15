<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';
import { saveBlob } from '@/api/exports';
import { ApiError, http } from '@/api/http';
import type { GridColumn } from '@/api/assessment';
import { useI18n } from '@/i18n';

// Import CSV des notes d'une épreuve : modèle à télécharger, fichier à choisir, résultat ligne par ligne.
// Le serveur valide tout le fichier avant d'enregistrer : une ligne en erreur et rien n'est enregistré.
defineProps<{ columns: GridColumn[] }>();
const emit = defineEmits<{ imported: [] }>();
const { t } = useI18n();

const assessmentId = ref('');
const busy = ref(false);
const error = ref<unknown>(null);
const lineErrors = ref<string[]>([]);
const done = ref<{ created: number; updated: number; unchanged: number } | null>(null);
const fileName = ref('');
let content = '';

async function downloadTemplate(): Promise<void> {
  if (!assessmentId.value) return;
  error.value = null;
  try {
    const csv = await http.get<string>('/grades/import-template', { assessment_id: assessmentId.value }, { expect: 'text', headers: { Accept: 'text/csv' } });
    saveBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'modele-notes.csv');
  } catch (e) {
    error.value = e;
  }
}

async function onFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  done.value = null;
  lineErrors.value = [];
  if (!file) {
    content = '';
    fileName.value = '';
    return;
  }
  fileName.value = file.name;
  content = await file.text();
}

async function send(): Promise<void> {
  if (!assessmentId.value || content.trim() === '') return;
  busy.value = true;
  error.value = null;
  lineErrors.value = [];
  done.value = null;
  try {
    const res = await http.post<{ data: { created: number; updated: number; unchanged: number } }>('/grades/import', undefined, {
      query: { assessment_id: assessmentId.value },
      rawBody: { contentType: 'text/csv', data: content },
    });
    done.value = res.data;
    emit('imported');
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.fieldErrors.entries) lineErrors.value = e.fieldErrors.entries;
    else if (e instanceof ApiError && e.status === 422 && e.fieldErrors.file) lineErrors.value = e.fieldErrors.file;
    else error.value = e;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="import card" aria-labelledby="imp-title">
    <h2 id="imp-title">{{ t('grades.importTitle') }}</h2>
    <p class="muted">{{ t('grades.importIntro') }}</p>
    <div class="field">
      <label for="imp-assessment">{{ t('grades.importAssessment') }}</label>
      <select id="imp-assessment" v-model="assessmentId">
        <option value="" disabled>{{ t('grades.choose') }}</option>
        <option v-for="c in columns" :key="c.id" :value="c.id">{{ c.title }}</option>
      </select>
    </div>
    <div class="row">
      <button type="button" class="btn" :disabled="!assessmentId" @click="downloadTemplate">{{ t('grades.importTemplate') }}</button>
      <label class="btn btn--quiet filebtn" :class="{ 'filebtn--off': !assessmentId }">
        {{ fileName || t('grades.importChoose') }}
        <input type="file" accept=".csv,text/csv" :disabled="!assessmentId" class="sr-only" @change="onFile" />
      </label>
      <button type="button" class="btn btn--primary" :disabled="busy || !assessmentId || fileName === ''" @click="send">{{ busy ? t('common.loading') : t('grades.importSend') }}</button>
    </div>
    <ErrorNotice :error="error" />
    <div v-if="lineErrors.length" class="notice notice--warning" role="alert">
      <p>
        <b>{{ t('grades.importRefused') }}</b>
      </p>
      <ul>
        <li v-for="(m, i) in lineErrors" :key="i">{{ m }}</li>
      </ul>
    </div>
    <p v-if="done" class="ok" role="status">{{ t('grades.importDone', { created: done.created, updated: done.updated, unchanged: done.unchanged }) }}</p>
  </section>
</template>

<style scoped>
.import {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
}
.import h2 {
  margin: 0;
  font-size: 18px;
}
.filebtn {
  cursor: pointer;
}
.filebtn--off {
  opacity: 0.5;
  cursor: not-allowed;
}
.ok {
  margin: 0;
  font-weight: 700;
  color: var(--success, #0a7a3e);
}
</style>
