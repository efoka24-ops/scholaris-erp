<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';
import { saveBlob } from '@/api/exports';
import { ApiError, http } from '@/api/http';
import type { GridColumn } from '@/api/assessment';
import { useI18n } from '@/i18n';

// Import CSV des notes d'une épreuve : modèle à télécharger, fichier à choisir, résultat ligne par ligne.
// Le serveur valide tout le fichier avant d'enregistrer : une ligne en erreur et rien n'est enregistré.
// Réutilisé par les applications (C-117, feuille de compétences du primaire) : importPath, templatePath et query remplacent
// alors l'épreuve à choisir (même envoi en fichier joint, même lecture des erreurs ligne par ligne).
const props = withDefaults(
  defineProps<{ columns?: GridColumn[]; importPath?: string; templatePath?: string; query?: Record<string, string>; templateName?: string; title?: string; intro?: string }>(),
  { columns: () => [], importPath: '', templatePath: '', query: () => ({}), templateName: 'modele-notes.csv', title: '', intro: '' },
);
const emit = defineEmits<{ imported: [] }>();
const { t } = useI18n();

const assessmentId = ref('');
const fixed = computed(() => props.importPath !== '');
const ready = computed(() => (fixed.value ? Object.values(props.query).every((v) => v !== '') : assessmentId.value !== ''));
const target = computed(() =>
  fixed.value
    ? { importPath: props.importPath, templatePath: props.templatePath, query: props.query }
    : { importPath: '/grades/import', templatePath: '/grades/import-template', query: { assessment_id: assessmentId.value } },
);
const busy = ref(false);
const error = ref<unknown>(null);
const lineErrors = ref<string[]>([]);
const done = ref<{ created: number; updated: number; unchanged: number } | null>(null);
const fileName = ref('');
let content = '';

async function downloadTemplate(): Promise<void> {
  if (!ready.value) return;
  error.value = null;
  try {
    const csv = await http.get<string>(target.value.templatePath, target.value.query, { expect: 'text', headers: { Accept: 'text/csv' } });
    saveBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), props.templateName);
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
  if (!ready.value || content.trim() === '') return;
  busy.value = true;
  error.value = null;
  lineErrors.value = [];
  done.value = null;
  try {
    // Envoi en fichier joint (multipart) : l'hébergement refuse un corps brut text/csv.
    const form = new FormData();
    form.append('file', new Blob([content], { type: 'text/csv' }), fileName.value || 'notes.csv');
    const res = await http.post<{ data: { created: number; updated: number; unchanged: number } }>(target.value.importPath, undefined, {
      query: target.value.query,
      form,
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
    <h2 id="imp-title">{{ title || t('grades.importTitle') }}</h2>
    <p class="muted">{{ intro || t('grades.importIntro') }}</p>
    <div v-if="!fixed" class="field">
      <label for="imp-assessment">{{ t('grades.importAssessment') }}</label>
      <select id="imp-assessment" v-model="assessmentId">
        <option value="" disabled>{{ t('grades.choose') }}</option>
        <option v-for="c in columns" :key="c.id" :value="c.id">{{ c.title }}</option>
      </select>
    </div>
    <div class="row">
      <button type="button" class="btn" :disabled="!ready" @click="downloadTemplate">{{ t('grades.importTemplate') }}</button>
      <label class="btn btn--quiet filebtn" :class="{ 'filebtn--off': !ready }">
        {{ fileName || t('grades.importChoose') }}
        <input type="file" accept=".csv,text/csv" :disabled="!ready" class="sr-only" @change="onFile" />
      </label>
      <button type="button" class="btn btn--primary" :disabled="busy || !ready || fileName === ''" @click="send">{{ busy ? t('common.loading') : t('grades.importSend') }}</button>
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
