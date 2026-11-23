<script setup lang="ts">
// Questionnaire statistique annuel (STA, DOC-23) : pré-rempli depuis les données de l'école, modifiable tant qu'il n'est pas transmis,
// contrôles de cohérence (bloquants ou à justifier), circuit inspection > délégation départementale > délégation régionale (gel),
// consolidation par la planification ; correction après gel = nouvelle version motivée. Aucune donnée nominative.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { openDocument, publicSchoolsP2, type CensusForm } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const forms = ref<CensusForm[]>([]);
const pending = ref<CensusForm[]>([]);
const current = ref<CensusForm | null>(null);
const yearLabel = ref('');
const campaign = ref<'start_of_year' | 'end_of_year'>('start_of_year');
const edits = ref<Record<string, number | null>>({});
const anomalies = ref<string[]>([]);
const justifications = ref<Record<string, string>>({});
const done = ref('');

const canWrite = computed(() => ctx.can('public_schools.census.write'));
const canValidate = computed(() => ctx.can('public_schools.census.validate'));
const editable = computed(() => current.value !== null && ['draft', 'returned'].includes(current.value.state) && canWrite.value);
const sections = computed(() => Object.entries(current.value?.payload.sections ?? {}));
const isBlocking = (code: string): boolean => /mismatch|negative|missing/.test(code);
const stateLabel = (s: CensusForm['state']): string =>
  t(s === 'draft' ? 'pp.census.draft' : s === 'returned' ? 'pp.census.returned' : s === 'in_review' ? 'pp.census.inReview' : s === 'frozen' ? 'pp.census.frozen' : 'pp.census.consolidated');

onMounted(async () => {
  await run(async () => {
    yearLabel.value = (await currentYear())?.label ?? '';
    await reload();
  });
});

async function reload(): Promise<void> {
  forms.value = canWrite.value || canValidate.value ? await publicSchoolsP2.census() : [];
  pending.value = canValidate.value ? await publicSchoolsP2.censusPending() : [];
  if (current.value) current.value = forms.value.find((f) => f.id === current.value?.id) ?? current.value;
}

function select(form: CensusForm): void {
  current.value = form;
  edits.value = {};
  anomalies.value = form.checks?.anomalies ?? [];
}

async function open(): Promise<void> {
  await run(async () => {
    select(await publicSchoolsP2.openCensus(yearLabel.value, campaign.value));
    await reload();
  });
}

async function save(): Promise<void> {
  if (!current.value || Object.keys(edits.value).length === 0) return;
  await run(async () => {
    select(await publicSchoolsP2.editCensus((current.value as CensusForm).id, edits.value));
    done.value = t('pp.census.saved');
  });
}

async function submit(): Promise<void> {
  if (!current.value) return;
  error.value = null;
  busy.value = true;
  try {
    select(await publicSchoolsP2.submitCensus(current.value.id, justifications.value));
    done.value = t('pp.census.submitted');
    await reload();
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && e.fieldErrors.checks) anomalies.value = e.fieldErrors.checks;
    error.value = e;
  } finally {
    busy.value = false;
  }
}

async function sendBack(): Promise<void> {
  const reason = window.prompt(t('pp.census.returnReason')) ?? '';
  if (!current.value || reason.trim() === '') return;
  await run(async () => {
    select(await publicSchoolsP2.returnCensus((current.value as CensusForm).id, reason.trim()));
    await reload();
  });
}

async function correct(): Promise<void> {
  const reason = window.prompt(t('pp.census.correctionReason')) ?? '';
  if (!current.value || reason.trim() === '') return;
  await run(async () => {
    select(await publicSchoolsP2.correctCensus((current.value as CensusForm).id, reason.trim()));
    await reload();
  });
}

async function printDoc(): Promise<void> {
  if (!current.value) return;
  await run(async () => openDocument((await publicSchoolsP2.censusDocument((current.value as CensusForm).id)).document.id));
}

async function exportCsv(): Promise<void> {
  if (!current.value) return;
  await run(async () => {
    const url = URL.createObjectURL(await publicSchoolsP2.censusExport((current.value as CensusForm).id));
    const a = document.createElement('a');
    a.href = url;
    a.download = `questionnaire-${(current.value as CensusForm).year_label}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  });
}

function valueOf(section: string, field: string, value: number | null): number | null {
  const key = `${section}.${field}`;
  return key in edits.value ? (edits.value[key] ?? null) : value;
}
function setValue(section: string, field: string, event: Event): void {
  const raw = (event.target as HTMLInputElement).value;
  edits.value = { ...edits.value, [`${section}.${field}`]: raw === '' ? null : Number(raw) };
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.census.title') }}</h1>
      <p class="lede">{{ t('pp.census.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section v-if="canWrite" class="card controls">
      <div class="field"><label for="cs-year">{{ t('pp.apee.yearLabel') }}</label><input id="cs-year" v-model="yearLabel" type="text" pattern="\d{4}-\d{4}" /></div>
      <div class="field">
        <label for="cs-camp">{{ t('pp.census.campaign') }}</label>
        <select id="cs-camp" v-model="campaign"><option value="start_of_year">{{ t('pp.census.start') }}</option><option value="end_of_year">{{ t('pp.census.end') }}</option></select>
      </div>
      <button type="button" class="btn btn--primary" :disabled="busy || !yearLabel" @click="open">{{ t('pp.census.open') }}</button>
    </section>

    <section v-if="pending.length > 0" class="card" aria-labelledby="cs-pending">
      <h2 id="cs-pending">{{ t('pp.census.pending') }}</h2>
      <ul><li v-for="f in pending" :key="f.id"><button type="button" class="btn btn--small" @click="select(f)">{{ f.year_label }} · v{{ f.version }} · {{ f.current_step }}</button></li></ul>
    </section>

    <section v-if="forms.length > 0" class="card" aria-labelledby="cs-list">
      <h2 id="cs-list">{{ t('pp.census.forms') }}</h2>
      <ul><li v-for="f in forms" :key="f.id"><button type="button" class="btn btn--small" @click="select(f)">{{ f.year_label }} · {{ f.campaign === 'start_of_year' ? t('pp.census.start') : t('pp.census.end') }} · v{{ f.version }} · {{ stateLabel(f.state) }}</button></li></ul>
    </section>

    <section v-if="current" class="card" aria-labelledby="cs-form">
      <h2 id="cs-form">{{ current.year_label }} · v{{ current.version }} · <span class="tag">{{ stateLabel(current.state) }}</span></h2>
      <p v-if="current.entered_by_proxy" class="muted">{{ t('pp.census.proxy') }}</p>
      <p v-if="current.return_reason" class="notice notice--warning">{{ t('pp.census.returnedBecause', { reason: current.return_reason }) }}</p>
      <p v-if="current.correction_reason" class="muted">{{ t('pp.census.correction', { reason: current.correction_reason }) }}</p>
      <div v-for="[key, section] in sections" :key="key" class="section">
        <h3>{{ locale === 'en' ? section.title_en : section.title_fr }}</h3>
        <div class="grid-fields">
          <div v-for="(value, field) in section.values" :key="field" class="field">
            <label :for="`cv-${key}-${field}`">{{ field }}</label>
            <input :id="`cv-${key}-${field}`" type="number" :value="valueOf(key, String(field), value)" :readonly="!editable" @change="setValue(key, String(field), $event)" />
          </div>
        </div>
      </div>
      <div v-if="anomalies.length > 0" class="notice notice--warning" role="alert">
        <p>{{ t('pp.census.checks') }}</p>
        <ul>
          <li v-for="a in anomalies" :key="a">
            {{ a }} <span class="tag">{{ isBlocking(a) ? t('pp.census.blocking') : t('pp.census.toJustify') }}</span>
            <input v-if="!isBlocking(a) && editable" v-model="justifications[a]" type="text" maxlength="500" :aria-label="t('pp.census.justification', { code: a })" />
          </li>
        </ul>
      </div>
      <div class="actions">
        <button v-if="editable" type="button" class="btn" :disabled="busy" @click="save">{{ t('pp.common.save') }}</button>
        <button v-if="editable || (canValidate && ['in_review', 'frozen'].includes(current.state))" type="button" class="btn btn--primary" :disabled="busy" @click="submit">{{ t('pp.census.submit') }}</button>
        <button v-if="canValidate && current.state === 'in_review'" type="button" class="btn" :disabled="busy" @click="sendBack">{{ t('pp.census.sendBack') }}</button>
        <button v-if="canWrite && ['frozen', 'consolidated'].includes(current.state)" type="button" class="btn" :disabled="busy" @click="correct">{{ t('pp.census.correct') }}</button>
        <button type="button" class="btn" :disabled="busy" @click="printDoc">{{ t('pp.common.pdf') }}</button>
        <button type="button" class="btn" :disabled="busy" @click="exportCsv">{{ t('pp.census.export') }}</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.grid-fields { display: grid; gap: 8px; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
</style>
