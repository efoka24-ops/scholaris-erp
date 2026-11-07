<script setup lang="ts">
import { reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { createLiaisonSheet, loadLiaisonSheet, signLiaisonSheet, type PublicNurseryLiaisonSheet } from '@/api/publicNursery';
import { useI18n } from '@/i18n';

const { t } = useI18n();
const error = ref<unknown>(null);
const loading = ref(false);
const sheet = ref<PublicNurseryLiaisonSheet | null>(null);
const form = reactive({ learner_id: '', school_year: '2026-2027' });

async function refresh(): Promise<void> {
  if (!form.learner_id.trim()) return;
  loading.value = true;
  error.value = null;
  try {
    sheet.value = (await loadLiaisonSheet(form.learner_id.trim(), form.school_year.trim())).data;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function generate(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    sheet.value = (await createLiaisonSheet({ learner_id: form.learner_id.trim(), school_year: form.school_year.trim() })).data;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function sign(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    sheet.value = (await signLiaisonSheet({ learner_id: form.learner_id.trim(), school_year: form.school_year.trim() })).data;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

function lines(value: string | null): string[] {
  return (value ?? '').split(/\r?\n/u).map((line) => line.trim()).filter(Boolean);
}
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.liaison.eyebrow') }}</p>
      <h1>{{ t('pn.liaison.title') }}</h1>
      <p class="lead">{{ t('pn.liaison.intro') }}</p>
    </header>

    <ErrorNotice :error="error" />

    <section class="card panel">
      <div class="grid2">
        <div class="field">
          <label for="ls-learner">{{ t('nursery.child') }}</label>
          <input id="ls-learner" v-model="form.learner_id" type="text" placeholder="learner-1" />
        </div>
        <div class="field">
          <label for="ls-year">{{ t('pn.transition.schoolYear') }}</label>
          <input id="ls-year" v-model="form.school_year" type="text" placeholder="2026-2027" />
        </div>
      </div>
      <div class="row">
        <button type="button" class="btn btn--primary" :disabled="loading || form.learner_id.trim() === ''" @click="generate">{{ t('pn.liaison.generate') }}</button>
        <button type="button" class="btn" :disabled="loading || form.learner_id.trim() === ''" @click="sign">{{ t('pn.liaison.sign') }}</button>
        <button type="button" class="btn" :disabled="loading || form.learner_id.trim() === ''" @click="refresh">{{ t('pn.transition.refresh') }}</button>
      </div>
    </section>

    <section class="card panel preview">
      <h2>{{ t('pn.liaison.preview') }}</h2>
      <p v-if="sheet === null" class="muted">{{ t('pn.liaison.empty') }}</p>
      <template v-else>
        <p>{{ sheet.path_summary }}</p>
        <div class="grid2">
          <div>
            <h3>{{ t('pn.liaison.strengths') }}</h3>
            <ul>
              <li v-for="line in lines(sheet.strengths)" :key="line">{{ line }}</li>
            </ul>
          </div>
          <div>
            <h3>{{ t('pn.liaison.needs') }}</h3>
            <ul>
              <li v-for="line in lines(sheet.needs)" :key="line">{{ line }}</li>
            </ul>
          </div>
        </div>
        <!-- Aperçu DOC-20 isolé : iframe sans script ni origine (aucune injection de HTML dans la page). -->
        <iframe class="preview-html" sandbox="" referrerpolicy="no-referrer" :title="t('pn.liaison.title')" :srcdoc="sheet.html"></iframe>
      </template>
    </section>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.lead { max-width: 760px; color: var(--muted, #5f5b52); }
.panel { display: flex; flex-direction: column; gap: 12px; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 12px; }
.preview-html { width: 100%; min-height: 480px; border: 1px solid var(--border, #dcdcd8); border-radius: 12px; background: #fff; }
@media (min-width: 901px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>