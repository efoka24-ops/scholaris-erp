<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { migration, IMPORT_KINDS, type Batch, type BatchLine, type ImportKind, type KindInfo } from '@/api/higher-migration';
import { needsSecondFactor } from '@/api/higher-deliberation';
import { saveBlob } from '@/api/exports';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import YearField from '../YearField.vue';
import TwoFactorNotice from '../deliberation/TwoFactorNotice.vue';
import { P } from '../access';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useMigrationI18n } from './i18n';

// Reprise des données d'un ancien système (lot I4) : jeu et année, fichier joint (multipart), simulation avec rapport d'écarts ligne par
// ligne, application seulement s'il ne reste aucune erreur (double authentification récente), annulation motivée, historique des lots.
const { tm, tv, locale } = useMigrationI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const simulation = useHigherAction();
const loading = useHigherAction();
const applying = useHigherAction();
const reverting = useHigherAction();
const history = useHigherAction();

const kinds = ref<KindInfo[]>([]);
const form = reactive({ kind: 'students' as ImportKind, year: '', file: null as File | null });
const tried = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const report = ref<Batch | null>(null);
const filter = ref<'' | 'error' | 'warning' | 'duplicate' | 'ok'>('');
const batches = ref<Batch[]>([]);
const confirmApply = ref(false);
const revertOpen = ref(false);
const revertReason = ref('');
const needsTwoFactor = ref(false);
const message = ref('');

const canManage = computed(() => ctx.can(...P.migration) && !higher.readOnly);
const current = computed(() => kinds.value.find((k) => k.kind === form.kind) ?? null);
const reasonOk = computed(() => revertReason.value.trim().length >= 5);
const issues = computed<BatchLine[]>(() => report.value?.lines ?? []);

async function loadHistory(): Promise<void> {
  const res = await history.run(() => migration.batches());
  if (res) batches.value = res;
}

function pickFile(event: Event): void {
  form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function downloadTemplate(): Promise<void> {
  const blob = await loading.run(() => migration.template(form.kind));
  if (blob) saveBlob(blob, `modele_reprise_${form.kind}.csv`);
}

async function simulate(): Promise<void> {
  tried.value = true;
  message.value = '';
  needsTwoFactor.value = false;
  if (!form.year || !form.file) return;
  const file = form.file;
  const res = await simulation.run(() => migration.simulate({ kind: form.kind, academic_year_id: form.year, file }));
  if (res) {
    report.value = res;
    filter.value = '';
    confirmApply.value = false;
    revertOpen.value = false;
    form.file = null;
    tried.value = false;
    if (fileInput.value) fileInput.value.value = '';
    await loadHistory();
  }
}

async function openBatch(id: string, status = ''): Promise<void> {
  const res = await loading.run(() => migration.batch(id, status || undefined));
  if (res) {
    report.value = res;
    confirmApply.value = false;
    revertOpen.value = false;
    message.value = '';
    needsTwoFactor.value = false;
  }
}

async function changeFilter(): Promise<void> {
  if (report.value) await openBatch(report.value.id, filter.value);
}

async function apply(): Promise<void> {
  if (!report.value) return;
  const id = report.value.id;
  needsTwoFactor.value = false;
  const res = await applying.run(() => migration.apply(id));
  if (res) {
    report.value = res;
    confirmApply.value = false;
    message.value = tm('hmg.applied', { n: res.counts.applied });
    await loadHistory();
  } else if (needsSecondFactor(applying.error.value)) {
    needsTwoFactor.value = true;
    applying.error.value = null;
    confirmApply.value = false;
  }
}

async function revert(): Promise<void> {
  if (!report.value || !reasonOk.value) return;
  const id = report.value.id;
  const reason = revertReason.value.trim();
  needsTwoFactor.value = false;
  const res = await reverting.run(() => migration.revert(id, reason));
  if (res) {
    report.value = res;
    revertOpen.value = false;
    revertReason.value = '';
    message.value = tm('hmg.reverted');
    await loadHistory();
  } else if (needsSecondFactor(reverting.error.value)) {
    needsTwoFactor.value = true;
    reverting.error.value = null;
  }
}

onMounted(async () => {
  await higher.ensure();
  const res = await loading.run(() => migration.kinds());
  if (res) kinds.value = res;
  await loadHistory();
});
</script>

<template>
  <HigherFrame :title="tm('hmg.title')" :lede="tm('hmg.lede')" section="home" :selector="false">
    <p v-if="higher.readOnly" class="notice" role="status" data-test="read-only">{{ tm('hmg.readOnly') }}</p>

    <form v-if="canManage" class="card hi-form" novalidate data-test="upload" @submit.prevent="simulate">
      <h2 class="hi-wide">{{ tm('hmg.step1') }}</h2>
      <div class="field">
        <label for="mg-kind">{{ tm('hmg.kind') }}</label>
        <select id="mg-kind" v-model="form.kind">
          <option v-for="k in IMPORT_KINDS" :key="k" :value="k">{{ tv('hmg.kind.', k) }}</option>
        </select>
      </div>
      <YearField id="mg-year" v-model="form.year" :label="tm('hmg.year')" :invalid="tried && !form.year" />
      <p v-if="current" class="muted hi-wide" data-test="columns">{{ tm('hmg.columns', { list: current.required.join(', ') }) }}</p>
      <div class="hi-wide hi-actions">
        <button type="button" class="btn btn--quiet" :disabled="loading.busy.value" data-test="template" @click="downloadTemplate">{{ tm('hmg.template') }}</button>
        <span class="muted">{{ tm('hmg.templateHint') }}</span>
      </div>
      <h2 class="hi-wide">{{ tm('hmg.step2') }}</h2>
      <div class="field hi-wide">
        <label for="mg-file">{{ tm('hmg.file') }}</label>
        <input id="mg-file" ref="fileInput" type="file" accept=".csv,.xlsx,text/csv" :aria-invalid="tried && !form.file" @change="pickFile" />
        <span v-if="tried && !form.file" class="error">{{ tm('hmg.fileRequired') }}</span>
      </div>
      <div class="hi-wide hi-actions">
        <button type="submit" class="btn btn--primary" :disabled="simulation.busy.value" data-test="simulate">{{ simulation.busy.value ? tm('hmg.simulating') : tm('hmg.simulate') }}</button>
        <span class="muted">{{ tm('hmg.safe') }}</span>
      </div>
    </form>
    <ErrorNotice :error="simulation.error.value" />
    <ErrorNotice :error="loading.error.value" />

    <section v-if="report" class="hi-section" data-test="report" aria-live="polite">
      <h2>{{ tm('hmg.step3') }}</h2>
      <p>
        <strong>{{ tm('hmg.batchOf', { kind: tv('hmg.kind.', report.kind), file: report.file_name ?? '—' }) }}</strong>
        <span class="tag" :class="report.status === 'applied' ? 'tag--success' : 'tag--neutral'">{{ tv('hmg.batch.', report.status) }}</span>
      </p>
      <ul class="hmg-counts" data-test="counts">
        <li v-for="key in (['total', 'ok', 'warning', 'duplicate', 'error', 'applied'] as const)" :key="key" :class="{ 'hmg-bad': key === 'error' && report.counts.error > 0 }">
          <span class="muted">{{ tv('hmg.count.', key) }}</span>
          <strong :data-test="`count-${key}`">{{ report.counts[key] }}</strong>
        </li>
      </ul>
      <p v-if="report.unknown_columns?.length" class="notice" role="status">{{ tm('hmg.unknown', { list: report.unknown_columns.join(', ') }) }}</p>
      <p v-if="message" class="notice notice--success" role="status" data-test="done">{{ message }}</p>

      <div class="field hmg-filter">
        <label for="mg-filter">{{ tm('hmg.status') }}</label>
        <select id="mg-filter" v-model="filter" @change="changeFilter">
          <option value="">{{ tm('hmg.filter.all') }}</option>
          <option value="error">{{ tm('hmg.filter.error') }}</option>
          <option value="warning">{{ tm('hmg.filter.warning') }}</option>
          <option value="duplicate">{{ tm('hmg.filter.duplicate') }}</option>
          <option value="ok">{{ tm('hmg.filter.ok') }}</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ tm('hmg.line') }}</th><th scope="col">{{ tm('hmg.key') }}</th><th scope="col">{{ tm('hmg.status') }}</th><th scope="col">{{ tm('hmg.messages') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in issues" :key="l.line" :data-test="`line-${l.line}`">
              <th scope="row">{{ l.line }}</th>
              <td>{{ l.key }}</td>
              <td><span class="tag" :class="l.status === 'error' ? 'tag--warning' : l.status === 'ok' || l.status === 'applied' ? 'tag--success' : 'tag--neutral'">{{ tv('hmg.line.', l.status) }}</span></td>
              <td>
                <ul class="hmg-msgs">
                  <li v-for="(m, i) in l.errors" :key="`e${i}`" class="error">{{ m }}</li>
                  <li v-for="(m, i) in l.warnings" :key="`w${i}`">{{ m }}</li>
                </ul>
              </td>
            </tr>
            <tr v-if="issues.length === 0"><td colspan="4" class="muted">{{ tm('hmg.noIssue') }}</td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="report.lines_truncated" class="notice" role="status">{{ tm('hmg.truncated') }}</p>

      <template v-if="canManage">
        <p v-if="report.status === 'simulated' && !report.applicable" class="notice notice--warning" role="status" data-test="blocked">{{ tm('hmg.blocked', { n: report.counts.error }) }}</p>
        <TwoFactorNotice v-if="needsTwoFactor" />
        <ErrorNotice :error="applying.error.value" />
        <ErrorNotice :error="reverting.error.value" />
        <div v-if="report.status === 'simulated'" class="hi-actions">
          <button v-if="!confirmApply" type="button" class="btn btn--primary" :disabled="!report.applicable" data-test="apply" @click="confirmApply = true">{{ tm('hmg.apply') }}</button>
          <template v-else>
            <p class="notice" role="status">{{ tm('hmg.applyConfirm') }}</p>
            <button type="button" class="btn btn--primary" :disabled="applying.busy.value" data-test="apply-yes" @click="apply">{{ applying.busy.value ? tm('hmg.applying') : tm('hmg.applyYes') }}</button>
            <button type="button" class="btn btn--quiet" @click="confirmApply = false">{{ tm('hmg.applyNo') }}</button>
          </template>
        </div>
        <div v-if="report.status === 'applied'" class="hi-actions">
          <button v-if="!revertOpen" type="button" class="btn btn--quiet" data-test="revert" @click="revertOpen = true">{{ tm('hmg.revert') }}</button>
          <form v-else class="hi-form" novalidate @submit.prevent="revert">
            <div class="field hi-wide">
              <label for="mg-reason">{{ tm('hmg.revertReason') }}</label>
              <input id="mg-reason" v-model="revertReason" type="text" maxlength="500" />
            </div>
            <div class="hi-wide"><button type="submit" class="btn btn--ink" :disabled="!reasonOk || reverting.busy.value" data-test="revert-yes">{{ tm('hmg.revertYes') }}</button></div>
          </form>
        </div>
      </template>
    </section>

    <section class="hi-section" data-test="history">
      <h2>{{ tm('hmg.history') }}</h2>
      <ErrorNotice :error="history.error.value" />
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ tm('hmg.date') }}</th><th scope="col">{{ tm('hmg.kind') }}</th><th scope="col">{{ tm('hmg.file.col') }}</th><th scope="col">{{ tm('hmg.status') }}</th><th scope="col"><span class="sr-only">{{ tm('hmg.open') }}</span></th></tr></thead>
          <tbody>
            <tr v-for="b in batches" :key="b.id" :data-test="`batch-${b.id}`">
              <td>{{ formatDate(b.created_at, locale) }}</td>
              <td>{{ tv('hmg.kind.', b.kind) }}</td>
              <td>{{ b.file_name ?? '—' }}</td>
              <td><span class="tag" :class="b.status === 'applied' ? 'tag--success' : 'tag--neutral'">{{ tv('hmg.batch.', b.status) }}</span> <span class="muted">{{ b.counts.ok + b.counts.warning }}/{{ b.counts.total }}</span></td>
              <td><button type="button" class="btn btn--quiet" :data-test="`open-${b.id}`" @click="openBatch(b.id)">{{ tm('hmg.open') }}</button></td>
            </tr>
            <tr v-if="batches.length === 0"><td colspan="5" class="muted">{{ tm('hmg.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </HigherFrame>
</template>

<style scoped>
.hmg-counts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  list-style: none;
  margin: 12px 0;
  padding: 0;
}
.hmg-counts li {
  display: flex;
  flex-direction: column;
  min-width: 96px;
  padding: 8px 12px;
  border: 1px solid var(--border);
}
.hmg-counts strong {
  font-size: 22px;
}
.hmg-bad {
  border-color: var(--danger, #b00020);
}
.hmg-filter {
  max-width: 320px;
}
.hmg-msgs {
  margin: 0;
  padding-left: 16px;
}
</style>
