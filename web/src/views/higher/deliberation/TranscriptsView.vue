<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { deliberation, isUnsignedMinutes, SEMESTERS, type AnnualBatchResult, type IssuedJuryDoc } from '@/api/higher-deliberation';
import type { IssuedDoc } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { locale } from '@/i18n';
import PersonPicker from '../PersonPicker.vue';
import YearField from '../YearField.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import UnsignedNotice from './UnsignedNotice.vue';
import { DP } from './access';
import { dt } from './i18n';

// Relevés semestriels (DOC-09) : émission après signature du PV (RG-DOC-3) et liste des relevés émis (registre des documents).
const ctx = useContextStore();
const higher = useHigherStore();
const act = useHigherAction();
const list = useHigherAction();
const form = reactive({ person: null as string | null, period_id: 'S1', locale: 'fr' as 'fr' | 'en' });
const tried = ref(false);
const issued = ref<IssuedJuryDoc | null>(null);
const unsigned = ref(false);
const items = ref<IssuedDoc[]>([]);
const canIssue = computed(() => ctx.can(...DP.prepare) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => deliberation.issuedTranscripts({ ...(higher.scope() as Record<string, string>) }));
  if (res) items.value = res;
}

async function issue(): Promise<void> {
  tried.value = true;
  if (!form.person) return;
  const person = form.person;
  issued.value = null;
  unsigned.value = false;
  const res = await act.run(() => deliberation.issueTranscript({ person_id: person, period_id: form.period_id, locale: form.locale }));
  if (res) {
    issued.value = res;
    await load();
  } else if (isUnsignedMinutes(act.error.value)) {
    unsigned.value = true;
    act.error.value = null;
  }
}

// Relevé annuel DOC-10 : un étudiant ou toute une promotion (groupe et année) ; le lot saute, avec sa raison, ceux qui n'ont pas de décision annuelle.
const annualAct = useHigherAction();
const annualList = useHigherAction();
const annualForm = reactive({ mode: 'one' as 'one' | 'batch', person: null as string | null, structure_id: '', academic_year_id: '', locale: 'fr' as 'fr' | 'en' });
const annualTried = ref(false);
const annualIssued = ref<IssuedJuryDoc | null>(null);
const annualBatch = ref<AnnualBatchResult | null>(null);
const annualUnsigned = ref(false);
const annualNoDecision = ref(false);
const isNoYearDecision = (e: unknown): boolean => e instanceof ApiError && e.status === 409 && /fin d'année/.test(e.problem?.detail ?? '');
const annualItems = ref<IssuedDoc[]>([]);

async function loadAnnual(): Promise<void> {
  const res = await annualList.run(() => deliberation.issuedAnnualTranscripts({ ...(higher.scope() as Record<string, string>) }));
  if (res) annualItems.value = res;
}

async function reload(): Promise<void> {
  await Promise.all([load(), loadAnnual()]);
}

async function issueAnnual(): Promise<void> {
  annualTried.value = true;
  annualIssued.value = null;
  annualBatch.value = null;
  annualUnsigned.value = false;
  annualNoDecision.value = false;
  const f = annualForm;
  if (!f.academic_year_id || (f.mode === 'one' ? !f.person : !f.structure_id)) return;
  if (f.mode === 'one') {
    const person = f.person as string;
    const res = await annualAct.run(() => deliberation.issueAnnualTranscript({ person_id: person, academic_year_id: f.academic_year_id, locale: f.locale }));
    if (res) {
      annualIssued.value = res;
      await loadAnnual();
    } else if (isUnsignedMinutes(annualAct.error.value)) {
      annualUnsigned.value = true;
      annualAct.error.value = null;
    } else if (isNoYearDecision(annualAct.error.value)) {
      annualNoDecision.value = true;
      annualAct.error.value = null;
    }
    return;
  }
  const res = await annualAct.run(() => deliberation.issueAnnualBatch({ structure_id: f.structure_id, academic_year_id: f.academic_year_id, locale: f.locale }));
  if (res) {
    annualBatch.value = res;
    await loadAnnual();
  }
}

const skipReason = (reason: string): string => (reason === 'already_issued' ? dt('hdl.an.skipped.already_issued') : reason);

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'years');
  await Promise.all([load(), loadAnnual()]);
});
</script>

<template>
  <DelFrame :title="dt('hdl.tr.title')" :lede="dt('hdl.tr.lede')" @school-change="reload">
    <form v-if="canIssue" class="card hi-form" novalidate @submit.prevent="issue">
      <div class="hi-wide"><PersonPicker id="tr-person" v-model="form.person" :label="dt('hdl.student')" /><span v-if="tried && !form.person" class="error">{{ dt('hdl.required') }}</span></div>
      <div class="field"><label for="tr-period">{{ dt('hdl.period') }}</label><select id="tr-period" v-model="form.period_id"><option v-for="s in SEMESTERS" :key="s" :value="s">{{ s }}</option></select></div>
      <div class="field"><label for="tr-locale">{{ dt('hdl.tr.locale') }}</label><select id="tr-locale" v-model="form.locale"><option value="fr">Français</option><option value="en">English</option></select></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ dt('hdl.tr.issue') }}</button></div>
    </form>
    <UnsignedNotice v-if="unsigned" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="issued" class="notice notice--success" role="status">{{ dt('hdl.tr.issued', { number: String(issued.number ?? '') }) }}</p>

    <section class="hi-section">
      <h2>{{ dt('hdl.tr.list') }}</h2>
      <ErrorNotice :error="list.error.value" />
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ dt('hdl.tr.number') }}</th><th scope="col">{{ dt('hdl.student') }}</th><th scope="col">{{ dt('hdl.tr.issuedAt') }}</th><th scope="col">{{ dt('hdl.status') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in items" :key="d.id"><th scope="row">{{ d.number }}</th><td>{{ d.person_id }}</td><td>{{ formatDate(d.issued_at, locale) }}</td><td><span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ d.status }}</span></td></tr>
            <tr v-if="items.length === 0"><td colspan="4" class="muted">{{ dt('hdl.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="hi-section" data-test="annual">
      <h2>{{ dt('hdl.an.title') }}</h2>
      <p class="muted">{{ dt('hdl.an.lede') }}</p>
      <form v-if="canIssue" class="card hi-form" novalidate @submit.prevent="issueAnnual">
        <div class="field">
          <label for="an-mode">{{ dt('hdl.an.mode') }}</label>
          <select id="an-mode" v-model="annualForm.mode"><option value="one">{{ dt('hdl.an.modeOne') }}</option><option value="batch">{{ dt('hdl.an.modeBatch') }}</option></select>
        </div>
        <YearField id="an-year" v-model="annualForm.academic_year_id" :label="dt('hdl.an.year')" :invalid="annualTried && !annualForm.academic_year_id" />
        <div v-if="annualForm.mode === 'one'" class="hi-wide"><PersonPicker id="an-person" v-model="annualForm.person" :label="dt('hdl.student')" /><span v-if="annualTried && !annualForm.person" class="error">{{ dt('hdl.required') }}</span></div>
        <div v-else class="field">
          <label for="an-group">{{ dt('hdl.group') }}</label>
          <select id="an-group" v-model="annualForm.structure_id" :aria-invalid="annualTried && !annualForm.structure_id"><option value="" disabled>{{ dt('hdl.groupPick') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
          <span v-if="annualTried && !annualForm.structure_id" class="error">{{ dt('hdl.required') }}</span>
        </div>
        <div class="field"><label for="an-locale">{{ dt('hdl.tr.locale') }}</label><select id="an-locale" v-model="annualForm.locale"><option value="fr">Français</option><option value="en">English</option></select></div>
        <div class="field"><button type="submit" class="btn btn--primary" data-test="an-issue" :disabled="annualAct.busy.value">{{ annualForm.mode === 'one' ? dt('hdl.an.issue') : dt('hdl.an.issueBatch') }}</button></div>
      </form>
      <UnsignedNotice v-if="annualUnsigned" />
      <p v-if="annualNoDecision" class="notice notice--warning" role="alert" data-test="an-no-decision">{{ dt('hdl.an.noDecision') }}</p>
      <ErrorNotice :error="annualAct.error.value" />
      <p v-if="annualIssued" class="notice notice--success" role="status" data-test="an-issued">{{ dt('hdl.an.issued', { number: String(annualIssued.number ?? '') }) }}</p>
      <div v-if="annualBatch" data-test="an-batch">
        <p class="notice notice--success" role="status">{{ dt('hdl.an.batchDone', { issued: annualBatch.issued.length, skipped: annualBatch.skipped.length, total: annualBatch.total }) }}</p>
        <template v-if="annualBatch.skipped.length > 0">
          <h3>{{ dt('hdl.an.skippedTitle') }}</h3>
          <div class="table-wrap">
            <table>
              <thead><tr><th scope="col">{{ dt('hdl.student') }}</th><th scope="col">{{ dt('hdl.status') }}</th></tr></thead>
              <tbody><tr v-for="s in annualBatch.skipped" :key="s.person_id" :data-test="`skip-${s.person_id}`"><th scope="row">{{ s.matricule ?? s.person_id }}</th><td>{{ skipReason(s.reason) }}</td></tr></tbody>
            </table>
          </div>
        </template>
      </div>
      <h3>{{ dt('hdl.an.list') }}</h3>
      <ErrorNotice :error="annualList.error.value" />
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ dt('hdl.tr.number') }}</th><th scope="col">{{ dt('hdl.student') }}</th><th scope="col">{{ dt('hdl.tr.issuedAt') }}</th><th scope="col">{{ dt('hdl.status') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in annualItems" :key="d.id"><th scope="row">{{ d.number }}</th><td>{{ d.person_id }}</td><td>{{ formatDate(d.issued_at, locale) }}</td><td><span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ d.status }}</span></td></tr>
            <tr v-if="annualItems.length === 0"><td colspan="4" class="muted">{{ dt('hdl.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </DelFrame>
</template>
