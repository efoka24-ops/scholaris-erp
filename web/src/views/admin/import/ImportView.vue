<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import AdminSubnav from '../AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import ImportCredentials from './ImportCredentials.vue';
import { ApiError } from '@/api/http';
import { saveBlob } from '@/api/exports';
import { importsApi, type ImportBatch, type ImportCredentials as CredentialMode, type ImportLine, type ImportLineStatus, type ProvisionalCredential } from '@/api/imports';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useAction } from '../useAction';
import { ROLE_CATALOG, roleKey } from '../catalog';
import { checkFile } from './helpers';

// Importer des personnes : fichier -> vérification (sans rien créer) -> choix des identifiants -> bilan et remise unique des mots de passe.
const { t } = useI18n();
const ctx = useContextStore();
const route = useRoute();
const router = useRouter();

const PAGE = 50;
type Step = 1 | 2 | 3 | 4;
type Filter = 'all' | 'valid' | 'error' | 'duplicate';

const step = ref<Step>(1);
const batch = ref<ImportBatch | null>(null);
const unknownColumns = ref<string[]>([]);
const file = ref<File | null>(null);
const fileProblem = ref<string>('');
const filter = ref<Filter>('all');
const shown = ref(PAGE);
const mode = ref<CredentialMode>('provisional_password');
const sendCodes = ref(false);
const confirming = ref(false);
const replay = ref(false);
const credentials = ref<ProvisionalCredential[] | null>(null);
const lost = ref(false);
const resuming = ref(false);
const heading = ref<HTMLElement | null>(null);
const credsBox = ref<InstanceType<typeof ImportCredentials> | null>(null);

const previewAction = useAction();
const commitAction = useAction();
const downloadAction = useAction();
const resumeAction = useAction();

const allowed = computed(() => ctx.can('imports.people.manage'));
const summary = computed(() => batch.value?.summary ?? { total: 0, valid: 0, error: 0, duplicate: 0, applied: 0, partial: 0, failed: 0 });
const lines = computed<ImportLine[]>(() => {
  const all = batch.value?.lines ?? [];
  return filter.value === 'all' ? all : all.filter((l) => l.status === filter.value);
});
const visibleLines = computed(() => lines.value.slice(0, shown.value));
const failedCount = computed(() => summary.value.failed + summary.value.partial);
const steps: Array<{ n: Step; label: MessageKey }> = [
  { n: 1, label: 'imports.step1' },
  { n: 2, label: 'imports.step2' },
  { n: 3, label: 'imports.step3' },
  { n: 4, label: 'imports.step4' },
];

const roleLabel = (code: string | null): string => (!code ? '' : ROLE_CATALOG.some((r) => r.code === code) ? t(roleKey(code)) : code);
const scopeLabel = (l: ImportLine): string => {
  if (!l.scope_type) return '';
  const known = ['establishment', 'org_unit', 'structure'].includes(l.scope_type);
  const word = known ? t(`imports.scope.${l.scope_type}` as MessageKey) : l.scope_type;
  return l.scope_code ? `${word} · ${l.scope_code}` : word;
};
const personLabel = (l: ImportLine): string => `${(l.last_name ?? '').toUpperCase()} ${l.first_name ?? ''}`.trim() || '—';
const contactLabel = (l: ImportLine): string => [l.phone, l.email].filter(Boolean).join(' · ');
const tone = (s: ImportLineStatus): 'success' | 'warning' | 'ink' | 'neutral' => (s === 'valid' || s === 'applied' ? 'success' : s === 'duplicate' ? 'neutral' : 'warning');
const statusText = (s: ImportLineStatus): string => t(`imports.status.${s}` as MessageKey);
const filterLabel = (f: Filter): string => {
  const count = f === 'all' ? summary.value.total : summary.value[f];
  return t(`imports.s2.${f}` as MessageKey, { count });
};

async function goTo(next: Step): Promise<void> {
  step.value = next;
  await nextTick();
  heading.value?.focus();
}

function setLot(id: string | null): void {
  const query = { ...route.query };
  if (id) query.lot = id;
  else delete query.lot;
  void router.replace({ query });
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement;
  file.value = input.files?.[0] ?? null;
  fileProblem.value = '';
  if (file.value) {
    const problem = checkFile(file.value);
    if (problem) fileProblem.value = t(problem === 'type' ? 'imports.s1.errType' : problem === 'size' ? 'imports.s1.errSize' : 'imports.s1.errEmpty');
  }
}

async function downloadTemplate(format: 'csv' | 'xlsx'): Promise<void> {
  const blob = await downloadAction.run(() => importsApi.template(format));
  if (blob) saveBlob(blob, `modele_import_acteurs.${format}`);
}

async function analyse(): Promise<void> {
  if (!file.value) {
    fileProblem.value = t('imports.s1.errNone');
    return;
  }
  if (fileProblem.value) return;
  const res = await previewAction.run(() => importsApi.preview(file.value as File));
  if (!res) return;
  batch.value = res.batch;
  unknownColumns.value = res.unknownColumns;
  filter.value = 'all';
  shown.value = PAGE;
  lost.value = false;
  setLot(res.batch.id);
  await goTo(2);
}

async function downloadErrors(): Promise<void> {
  if (!batch.value) return;
  const id = batch.value.id;
  const blob = await downloadAction.run(() => importsApi.errorsCsv(id));
  if (blob) saveBlob(blob, `erreurs_import_${id}.csv`);
}

function restart(): void {
  batch.value = null;
  file.value = null;
  fileProblem.value = '';
  credentials.value = null;
  replay.value = false;
  confirming.value = false;
  previewAction.error.value = null;
  commitAction.error.value = null;
  setLot(null);
  void goTo(1);
}

async function commit(): Promise<void> {
  if (!batch.value) return;
  const id = batch.value.id;
  const res = await commitAction.run(() => importsApi.commit(id, mode.value, mode.value === 'activation_code' && sendCodes.value));
  confirming.value = false;
  if (!res) return;
  credentials.value = res.batch.provisional_credentials?.length ? res.batch.provisional_credentials : null;
  replay.value = res.replay;
  // Les secrets ne vivent que dans `credentials` : on les retire du lot gardé en mémoire.
  batch.value = { ...res.batch, provisional_credentials: undefined };
  await goTo(4);
}

/** Termine l'import : les mots de passe sont jetés de la mémoire de la page. */
function finish(): void {
  credentials.value = null;
}

// Reprise : le lot reste consultable par son identifiant (?lot=…), un rechargement ne perd rien — sauf les mots de passe déjà remis.
async function resume(id: string): Promise<void> {
  resuming.value = true;
  const res = await resumeAction.run(() => importsApi.show(id));
  resuming.value = false;
  if (!res) {
    const gone = resumeAction.error.value instanceof ApiError && resumeAction.error.value.status === 404;
    if (gone) {
      resumeAction.error.value = null;
      lost.value = true;
    }
    setLot(null);
    return;
  }
  batch.value = res;
  if (res.committed_at) {
    replay.value = true;
    await goTo(4);
  } else {
    await goTo(2);
  }
}

onMounted(() => {
  const id = typeof route.query.lot === 'string' ? route.query.lot : '';
  if (id && allowed.value && ctx.establishmentId) void resume(id);
});

onBeforeRouteLeave(() => {
  if (credentials.value && !credsBox.value?.acknowledged) return window.confirm(t('imports.s4.leave'));
  return true;
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('imports.title') }}</h1>
      <p class="lede">{{ t('imports.lede') }}</p>
    </header>

    <AdminSubnav />

    <p v-if="!ctx.establishmentId" class="notice notice--warning">{{ t('imports.chooseEstablishment') }}</p>
    <template v-else>
      <ol class="steps" :aria-label="t('imports.steps')">
        <li v-for="s in steps" :key="s.n" :aria-current="step === s.n ? 'step' : undefined" :class="{ 'steps__on': step === s.n, 'steps__done': step > s.n }">{{ t(s.label) }}</li>
      </ol>

      <p v-if="lost" class="notice notice--warning" role="status" data-test="import-lost">{{ t('imports.s1.lost') }}</p>
      <p v-if="resuming" class="muted" role="status">{{ t('imports.s1.resume') }}</p>
      <ErrorNotice :error="resumeAction.error.value" />
      <ErrorNotice :error="downloadAction.error.value" />

      <!-- Étape 1 : modèle et fichier -->
      <section v-if="step === 1" class="card" aria-labelledby="imp-s1">
        <h2 id="imp-s1" ref="heading" tabindex="-1">{{ t('imports.s1.title') }}</h2>
        <p class="muted">{{ t('imports.s1.hint') }}</p>
        <div class="row">
          <button type="button" class="btn" data-test="import-template-csv" :disabled="downloadAction.busy.value" @click="downloadTemplate('csv')">{{ t('imports.s1.templateCsv') }}</button>
          <button type="button" class="btn" data-test="import-template-xlsx" :disabled="downloadAction.busy.value" @click="downloadTemplate('xlsx')">{{ t('imports.s1.templateXlsx') }}</button>
        </div>
        <form novalidate @submit.prevent="analyse">
          <div class="field">
            <label for="imp-file">{{ t('imports.s1.file') }}</label>
            <input id="imp-file" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :aria-invalid="fileProblem !== ''" aria-describedby="imp-file-msg" data-test="import-file" @change="onFile" />
            <span id="imp-file-msg" class="hint" :class="{ error: fileProblem !== '' }" :role="fileProblem ? 'alert' : undefined">
              {{ fileProblem || (file ? t('imports.s1.chosen', { name: file.name, size: Math.max(1, Math.round(file.size / 1024)) }) : '') }}
            </span>
          </div>
          <ErrorNotice :error="previewAction.error.value" />
          <button type="submit" class="btn btn--primary" data-test="import-analyse" :disabled="previewAction.busy.value || !allowed">
            {{ previewAction.busy.value ? t('imports.s1.analysing') : t('imports.s1.analyse') }}
          </button>
        </form>
      </section>

      <!-- Étape 2 : aperçu filtrable -->
      <section v-else-if="step === 2 && batch" class="card" aria-labelledby="imp-s2">
        <h2 id="imp-s2" ref="heading" tabindex="-1">{{ t('imports.s2.title') }}</h2>
        <p class="muted">{{ t('imports.s2.file', { name: batch.file_name ?? '' }) }}</p>
        <p role="status" data-test="import-summary">{{ t('imports.s2.summary', { total: summary.total, valid: summary.valid, error: summary.error, duplicate: summary.duplicate }) }}</p>
        <p v-if="unknownColumns.length > 0" class="notice notice--warning">{{ t('imports.s2.unknown', { columns: unknownColumns.join(', ') }) }}</p>
        <p v-if="summary.duplicate > 0" class="hint">{{ t('imports.s2.dupNote') }}</p>
        <p v-if="summary.valid === 0" class="notice notice--error" role="alert">{{ t('imports.s2.nothingValid') }}</p>

        <div class="row" role="group" :aria-label="t('imports.s2.filter')">
          <button v-for="f in (['all', 'valid', 'error', 'duplicate'] as Filter[])" :key="f" type="button" class="btn" :class="{ 'btn--ink': filter === f }" :aria-pressed="filter === f" :data-test="`import-filter-${f}`" @click="filter = f; shown = PAGE">
            {{ filterLabel(f) }}
          </button>
        </div>

        <div class="table-wrap">
          <table data-test="import-table">
            <caption class="sr-only">{{ t('imports.s2.table') }}</caption>
            <thead>
              <tr>
                <th scope="col">{{ t('imports.s2.line') }}</th>
                <th scope="col">{{ t('imports.s2.status') }}</th>
                <th scope="col">{{ t('imports.s2.person') }}</th>
                <th scope="col">{{ t('imports.s2.role') }}</th>
                <th scope="col">{{ t('imports.s2.scope') }}</th>
                <th scope="col">{{ t('imports.s2.contact') }}</th>
                <th scope="col">{{ t('imports.s2.messages') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in visibleLines" :key="l.line" :data-test="`import-row-${l.line}`">
                <td>{{ l.line }}</td>
                <td><StatusTag :tone="tone(l.status)">{{ statusText(l.status) }}</StatusTag></td>
                <td>{{ personLabel(l) }}</td>
                <td>{{ roleLabel(l.role) }}</td>
                <td>{{ scopeLabel(l) }}</td>
                <td>{{ contactLabel(l) }}</td>
                <td class="wrap">
                  <ul v-if="l.messages.length > 0" class="msgs">
                    <li v-for="(m, i) in l.messages" :key="i">{{ m }}</li>
                  </ul>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="lines.length === 0" class="muted">{{ t('imports.s2.empty') }}</p>
        <button v-if="lines.length > shown" type="button" class="btn btn--quiet" data-test="import-more" @click="shown += PAGE">{{ t('imports.s2.more', { count: lines.length - shown }) }}</button>

        <ErrorNotice :error="downloadAction.error.value" />
        <div class="row actions">
          <button type="button" class="btn btn--primary" data-test="import-next" :disabled="summary.valid === 0" @click="goTo(3)">{{ t('imports.step3') }}</button>
          <button v-if="summary.error + summary.duplicate > 0" type="button" class="btn" data-test="import-errors-csv" :disabled="downloadAction.busy.value" @click="downloadErrors">{{ t('imports.s2.errorsCsv') }}</button>
          <button type="button" class="btn btn--quiet" @click="restart">{{ t('imports.s2.restart') }}</button>
        </div>
      </section>

      <!-- Étape 3 : identifiants et validation -->
      <section v-else-if="step === 3 && batch" class="card" aria-labelledby="imp-s3">
        <h2 id="imp-s3" ref="heading" tabindex="-1">{{ t('imports.s3.title') }}</h2>
        <fieldset class="choices">
          <legend>{{ t('imports.s3.legend') }}</legend>
          <label class="choice">
            <input v-model="mode" type="radio" name="imp-mode" value="provisional_password" data-test="import-mode-password" @change="sendCodes = false" />
            <span><strong>{{ t('imports.s3.password') }}</strong><br /><span class="muted">{{ t('imports.s3.passwordHint') }}</span></span>
          </label>
          <label class="choice">
            <input v-model="mode" type="radio" name="imp-mode" value="activation_code" data-test="import-mode-code" />
            <span><strong>{{ t('imports.s3.code') }}</strong><br /><span class="muted">{{ t('imports.s3.codeHint') }}</span></span>
          </label>
        </fieldset>
        <label class="choice" :class="{ 'choice--off': mode !== 'activation_code' }">
          <input v-model="sendCodes" type="checkbox" :disabled="mode !== 'activation_code'" aria-describedby="imp-send-hint" data-test="import-send-codes" />
          <span><strong>{{ t('imports.s3.send') }}</strong><br /><span id="imp-send-hint" class="muted">{{ t('imports.s3.sendHint') }}</span></span>
        </label>

        <ErrorNotice :error="commitAction.error.value" />
        <div v-if="confirming" class="notice notice--warning" role="alertdialog" aria-labelledby="imp-confirm-title" aria-describedby="imp-confirm-text" data-test="import-confirm">
          <p id="imp-confirm-title"><strong>{{ t('imports.s3.confirmTitle') }}</strong></p>
          <p id="imp-confirm-text">{{ t('imports.s3.confirm', { count: summary.valid }) }}</p>
          <div class="row">
            <button type="button" class="btn btn--primary" data-test="import-confirm-yes" :disabled="commitAction.busy.value" @click="commit">
              {{ commitAction.busy.value ? t('imports.s3.committing') : t('imports.s3.confirmYes') }}
            </button>
            <button type="button" class="btn" :disabled="commitAction.busy.value" @click="confirming = false">{{ t('imports.s3.confirmNo') }}</button>
          </div>
        </div>
        <div v-else class="row actions">
          <button type="button" class="btn btn--primary" data-test="import-commit" :disabled="summary.valid === 0" @click="confirming = true">{{ t('imports.s3.commit') }}</button>
          <button type="button" class="btn btn--quiet" @click="goTo(2)">{{ t('imports.step2') }}</button>
        </div>
      </section>

      <!-- Étape 4 : bilan et remise unique -->
      <section v-else-if="step === 4 && batch" aria-labelledby="imp-s4">
        <div class="card">
          <h2 id="imp-s4" ref="heading" tabindex="-1">{{ t('imports.s4.title') }}</h2>
          <p role="status" data-test="import-result">{{ t('imports.s4.done', { applied: summary.applied + summary.partial, failed: failedCount, duplicate: summary.duplicate, error: summary.error }) }}</p>
          <p v-if="replay && !credentials" class="notice notice--warning" data-test="import-replay">{{ t('imports.s4.replay') }}</p>
          <p v-else-if="batch.credentials === 'activation_code'">{{ batch.send_codes ? t('imports.s4.codesSent') : t('imports.s4.codes') }}</p>
          <p v-if="failedCount > 0" class="notice notice--warning">{{ t('imports.s4.failedLines') }}</p>
          <ErrorNotice :error="downloadAction.error.value" />
          <div class="row actions">
            <button v-if="failedCount + summary.error + summary.duplicate > 0" type="button" class="btn" :disabled="downloadAction.busy.value" @click="downloadErrors">{{ t('imports.s2.errorsCsv') }}</button>
            <button v-if="!credentials" type="button" class="btn btn--primary" data-test="import-new" @click="restart">{{ t('imports.s4.new') }}</button>
          </div>
        </div>
        <ImportCredentials v-if="credentials" ref="credsBox" :credentials="credentials" :establishment="ctx.activeTile?.name ?? ''" :role-label="roleLabel" @close="finish" />
      </section>
    </template>
  </div>
</template>

<style scoped>
.steps {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  list-style: none;
  padding: 0;
  margin: 0 0 16px;
}
.steps li {
  padding: 6px 12px;
  border: 1px solid var(--border-strong);
  font-size: 13px;
  font-weight: 700;
  color: var(--muted);
}
.steps__on {
  background: var(--ink);
  color: #fff !important;
  border-color: var(--ink) !important;
}
.steps__done {
  color: var(--ink) !important;
}
h2:focus {
  outline: none;
}
.actions {
  margin-top: 16px;
}
.choices {
  border: 0;
  padding: 0;
  margin: 12px 0;
}
.choices legend {
  font-weight: 700;
  margin-bottom: 8px;
}
.choice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-height: var(--tap);
  padding: 10px 0;
}
.choice input {
  width: 22px;
  height: 22px;
  flex: none;
  margin-top: 2px;
}
.choice--off {
  opacity: 0.6;
}
.wrap {
  white-space: normal;
  min-width: 220px;
}
.msgs {
  margin: 0;
  padding-left: 16px;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
