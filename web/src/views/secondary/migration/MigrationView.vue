<script setup lang="ts">
// Reprise de données d'un ancien logiciel (T977, lot E4-C) : jeu et année, fichier joint (multipart, CSV ou Excel), simulation avec
// rapport ligne par ligne, application seulement s'il ne reste aucune erreur (confirmation), historique des lots. Mobile d'abord.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listYears, type AcademicYear } from '@/api/admin';
import { saveBlob } from '@/api/exports';
import {
  applicable, MIGRATION_KINDS, secondaryMigration, type MigrationBatch, type MigrationKind, type MigrationKindInfo, type MigrationLine,
} from '@/api/secondaryMigration';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction } from '../useSec';

const { t } = useI18n();
const { error, busy, run } = useAction();

const kinds = ref<MigrationKindInfo[]>([]);
const years = ref<AcademicYear[]>([]);
const form = reactive({ kind: 'classes' as MigrationKind, year: '', entryDate: '', file: null as File | null });
const tried = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const report = ref<MigrationBatch | null>(null);
const onlyIssues = ref(true);
const confirming = ref(false);
const message = ref('');
const batches = ref<MigrationBatch[]>([]);

const current = computed(() => kinds.value.find((k) => k.kind === form.kind) ?? null);
const allowed = computed(() => current.value?.allowed ?? false);
const needsEntry = computed(() => form.kind === 'students');
const lines = computed<MigrationLine[]>(() => (report.value?.lines ?? []).filter((l) => !onlyIssues.value || l.status === 'error' || l.status === 'duplicate'));
const canApply = computed(() => report.value !== null && applicable(report.value) && (kinds.value.find((k) => k.kind === report.value?.kind)?.allowed ?? false));
const kindLabel = (k: string): string => t(`secmg.kind.${k}` as MessageKey);
const statusLabel = (s: string): string => t(`secmg.status.${s}` as MessageKey);

async function loadHistory(): Promise<void> {
  const res = await run(() => secondaryMigration.batches());
  if (res) batches.value = res;
}

function pickFile(event: Event): void {
  form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function downloadTemplate(): Promise<void> {
  const blob = await run(() => secondaryMigration.template(form.kind));
  if (blob) saveBlob(blob, `modele_reprise_${form.kind}.csv`);
}

async function simulate(): Promise<void> {
  tried.value = true;
  message.value = '';
  if (!form.year || !form.file || (needsEntry.value && !form.entryDate)) return;
  const file = form.file;
  const res = await run(() => secondaryMigration.simulate({ kind: form.kind, academic_year_id: form.year, entry_date: needsEntry.value ? form.entryDate : undefined, file }));
  if (res) {
    report.value = res;
    confirming.value = false;
    form.file = null;
    tried.value = false;
    if (fileInput.value) fileInput.value.value = '';
    await loadHistory();
  }
}

async function open(id: string): Promise<void> {
  const res = await run(() => secondaryMigration.batch(id));
  if (res) {
    report.value = res;
    confirming.value = false;
    message.value = '';
  }
}

async function apply(): Promise<void> {
  if (!report.value) return;
  const id = report.value.id;
  const res = await run(() => secondaryMigration.apply(id));
  confirming.value = false;
  if (res) {
    report.value = res;
    message.value = t('secmg.applied', { n: res.summary.applied });
    await loadHistory();
  } else if (report.value) {
    await open(id);
  }
}

onMounted(async () => {
  const res = await run(() => secondaryMigration.kinds());
  if (res) {
    kinds.value = res;
    form.kind = res.find((k) => k.allowed)?.kind ?? 'classes';
  }
  const y = await listYears().catch(() => [] as AcademicYear[]);
  years.value = y;
  form.year = y.find((x) => x.status === 'open')?.id ?? y[0]?.id ?? '';
  await loadHistory();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secmg.title') }}</h1>
      <p class="lede">{{ t('secmg.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="message" class="notice notice--success" role="status" data-test="mg-message">{{ message }}</p>

    <form class="card stack" novalidate data-test="mg-upload" @submit.prevent="simulate">
      <h2>{{ t('secmg.step1') }}</h2>
      <div class="field">
        <label for="mg-kind">{{ t('secmg.kind') }}</label>
        <select id="mg-kind" v-model="form.kind">
          <option v-for="k in MIGRATION_KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
        </select>
      </div>
      <p v-if="current" class="muted" data-test="mg-columns">{{ t('secmg.columns', { list: current.required.join(', ') }) }} {{ t('secmg.allColumns', { list: current.columns.join(', ') }) }}</p>
      <p v-if="current && !allowed" class="notice" role="status">{{ t('secmg.notAllowed') }}</p>
      <div class="field">
        <label for="mg-year">{{ t('secmg.year') }}</label>
        <select v-if="years.length" id="mg-year" v-model="form.year" :aria-invalid="tried && !form.year">
          <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
        </select>
        <input v-else id="mg-year" v-model.trim="form.year" type="text" maxlength="40" autocomplete="off" :aria-invalid="tried && !form.year" />
        <p v-if="tried && !form.year" class="error">{{ t('secmg.yearRequired') }}</p>
      </div>
      <div v-if="needsEntry" class="field">
        <label for="mg-entry">{{ t('secmg.entryDate') }}</label>
        <input id="mg-entry" v-model="form.entryDate" type="date" :aria-invalid="tried && !form.entryDate" />
        <p v-if="tried && !form.entryDate" class="error">{{ t('secmg.entryRequired') }}</p>
      </div>
      <div class="field">
        <label for="mg-file">{{ t('secmg.file') }}</label>
        <input id="mg-file" ref="fileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="pickFile" />
        <p v-if="tried && !form.file" class="error">{{ t('secmg.fileRequired') }}</p>
      </div>
      <div class="row">
        <button type="button" class="btn" :disabled="busy" @click="downloadTemplate">{{ t('secmg.template') }}</button>
        <button type="submit" class="btn btn--primary" :disabled="busy || !allowed" data-test="mg-simulate">{{ t('secmg.simulate') }}</button>
      </div>
    </form>

    <section v-if="report" class="card stack" aria-labelledby="mg-report" data-test="mg-report">
      <h2 id="mg-report">{{ t('secmg.step2') }} · {{ kindLabel(report.kind) }} · {{ statusLabel(report.status) }}</h2>
      <p v-if="report.file_name" class="muted">{{ report.file_name }} · {{ report.academic_year_id }}</p>
      <p data-test="mg-summary">{{ t('secmg.summary', { ...report.summary }) }}</p>
      <p v-if="report.unknown_columns.length" class="muted">{{ t('secmg.unknownColumns', { list: report.unknown_columns.join(', ') }) }}</p>
      <p v-if="report.status !== 'applied' && report.summary.error > 0" class="notice notice--warning" role="status" data-test="mg-blocked">{{ t('secmg.blocked') }}</p>
      <p v-else-if="report.status !== 'applied' && report.summary.valid === 0" class="muted">{{ t('secmg.nothing') }}</p>
      <div v-if="canApply" class="row">
        <button v-if="!confirming" type="button" class="btn btn--primary" :disabled="busy" data-test="mg-apply" @click="confirming = true">{{ t('secmg.apply') }}</button>
        <template v-else>
          <button type="button" class="btn btn--primary" :disabled="busy" data-test="mg-confirm" @click="apply">{{ t('secmg.confirm', { n: report.summary.valid }) }}</button>
          <button type="button" class="btn" @click="confirming = false">{{ t('secmg.cancel') }}</button>
        </template>
      </div>
      <label class="check"><input v-model="onlyIssues" type="checkbox" /> {{ t('secmg.onlyIssues') }}</label>
      <ul class="rows" data-test="mg-lines">
        <li v-for="l in lines" :key="l.line" :data-status="l.status">
          <strong>{{ t('secmg.line', { n: l.line }) }}</strong> · {{ statusLabel(l.status) }}<span v-if="l.label"> · {{ l.label }}</span>
          <div v-for="(m, i) in l.messages" :key="i" class="muted">{{ m }}</div>
        </li>
      </ul>
    </section>

    <section class="card" aria-labelledby="mg-history">
      <h2 id="mg-history">{{ t('secmg.history') }}</h2>
      <p v-if="batches.length === 0" class="muted">{{ t('secmg.noHistory') }}</p>
      <ul v-else class="rows">
        <li v-for="b in batches" :key="b.id">
          <strong>{{ kindLabel(b.kind) }}</strong> · {{ statusLabel(b.status) }} · {{ b.created_at.slice(0, 16).replace('T', ' ') }}
          <div class="muted">{{ b.file_name ?? '—' }} · {{ t('secmg.summary', { ...b.summary }) }}</div>
          <button type="button" class="btn btn--quiet" @click="open(b.id)">{{ t('secmg.open') }}</button>
        </li>
      </ul>
    </section>
  </div>
</template>
