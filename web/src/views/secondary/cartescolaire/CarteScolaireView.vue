<script setup lang="ts">
// Carte scolaire (T828, T829, T978) : cartescolaire.cm n'a aucune interface publique ; l'établissement extrait ses fichiers sur le
// site puis les importe ici (CSV UTF-8). Matricules : saisie, import avec rapprochement nom + date de naissance, écarts à corriger,
// liste à régulariser des niveaux d'entrée. Paiements : relevé de l'économe, lignes inconnues. Aucun appel au site.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  matchesPattern, secondaryCarteScolaire,
  type CsDiscrepancy, type CsImport, type CsPayment, type CsSettings, type CsStudent, type NationalIdStatus,
} from '@/api/secondaryCarteScolaire';
import { useI18n } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const settings = ref<CsSettings | null>(null);
const students = ref<CsStudent[]>([]);
const discrepancies = ref<CsDiscrepancy[]>([]);
const unknownPayments = ref<CsPayment[]>([]);
const lastImport = ref<CsImport | null>(null);
const filter = ref<'' | NationalIdStatus>('pending');
const entryOnly = ref(false);
const drafts = ref<Record<string, string>>({});

const canReadStudents = computed(() => can('secondary.enrol.read', 'secondary.enrol.manage'));
const canWriteStudents = computed(() => can('secondary.enrol.manage'));
const canPayments = computed(() => can('secondary.billing.manage'));
const canReadPayments = computed(() => can('secondary.fees.read', 'secondary.finance.read', 'secondary.billing.manage', 'secondary.enrol.manage'));
const money = (n: number): string => `${new Intl.NumberFormat('fr-FR').format(n)} FCFA`;
const columns = (kind: 'national_ids' | 'payments'): string => Object.values(settings.value?.columns[kind] ?? {}).join(settings.value?.csv_delimiter ?? ';');

function validDraft(personId: string): boolean {
  return matchesPattern(drafts.value[personId] ?? '', settings.value?.national_id_pattern ?? '.+');
}

async function loadStudents(): Promise<void> {
  if (!canReadStudents.value) return;
  students.value = (await run(() => secondaryCarteScolaire.students(filter.value === '' ? undefined : filter.value, entryOnly.value))) ?? [];
  discrepancies.value = (await run(() => secondaryCarteScolaire.discrepancies(true))) ?? [];
}
async function loadPayments(): Promise<void> {
  if (canReadPayments.value) unknownPayments.value = (await run(() => secondaryCarteScolaire.payments('unknown'))) ?? [];
}

async function record(s: CsStudent): Promise<void> {
  if (!validDraft(s.person_id)) return;
  if (await run(() => secondaryCarteScolaire.recordNationalId(s.person_id, drafts.value[s.person_id] ?? ''))) {
    drafts.value[s.person_id] = '';
    await loadStudents();
    await loadPayments();
  }
}

async function readFile(event: Event): Promise<{ name: string; content: string } | null> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  if (!file) return null;
  if (settings.value && file.size > settings.value.max_bytes) {
    error.value = new Error(t('seccs.tooBig'));
    return null;
  }
  return { name: file.name, content: await file.text() };
}
async function importIds(event: Event): Promise<void> {
  const file = await readFile(event);
  if (!file) return;
  const result = await run(() => secondaryCarteScolaire.importNationalIds(file.name, file.content));
  if (result) {
    lastImport.value = result;
    await loadStudents();
  }
}
async function importPayments(event: Event): Promise<void> {
  const file = await readFile(event);
  if (!file) return;
  const result = await run(() => secondaryCarteScolaire.importPayments(file.name, file.content));
  if (result) {
    lastImport.value = result;
    await loadPayments();
  }
}
async function resolve(d: CsDiscrepancy, apply: boolean): Promise<void> {
  const note = (window.prompt(t('seccs.notePrompt')) ?? '').trim();
  if (note !== '' && (await run(() => secondaryCarteScolaire.resolve(d.id, note, apply)))) await loadStudents();
}

onMounted(async () => {
  settings.value = (await run(() => secondaryCarteScolaire.settings())) ?? null;
  await loadStudents();
  await loadPayments();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('seccs.title') }}</h1>
      <p class="lede">{{ t('seccs.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="settings" class="card" aria-labelledby="cs-files">
      <h2 id="cs-files">{{ t('seccs.filesTitle') }}</h2>
      <p class="muted">{{ t('seccs.noNetwork') }}</p>
      <p class="muted">{{ t('seccs.toConfirm') }}</p>
      <div v-if="canWriteStudents" class="field">
        <label for="cs-ids">{{ t('seccs.importIds') }}</label>
        <input id="cs-ids" type="file" accept=".csv,text/csv" :disabled="busy" @change="importIds" />
        <p class="muted">{{ t('seccs.expectedColumns', { columns: columns('national_ids') }) }}</p>
      </div>
      <div v-if="canPayments" class="field">
        <label for="cs-pay">{{ t('seccs.importPayments') }}</label>
        <input id="cs-pay" type="file" accept=".csv,text/csv" :disabled="busy" @change="importPayments" />
        <p class="muted">{{ t('seccs.expectedColumns', { columns: columns('payments') }) }}</p>
      </div>
      <div v-if="lastImport" class="notice notice--success" role="status">
        <p><strong>{{ lastImport.replayed ? t('seccs.replayed') : t('seccs.imported', { file: lastImport.file_name }) }}</strong></p>
        <p>{{ t('seccs.counts', { total: lastImport.rows_total, ok: lastImport.rows_ok, rejected: lastImport.rows_rejected, duplicate: lastImport.rows_duplicate, unknown: lastImport.rows_unknown }) }}</p>
        <ul v-if="lastImport.rejected_rows.length > 0" class="rows">
          <li v-for="r in lastImport.rejected_rows" :key="r.line">{{ t('seccs.line', { line: r.line }) }} : {{ r.reason }}</li>
        </ul>
      </div>
    </section>

    <section v-if="canReadStudents" class="card" aria-labelledby="cs-students">
      <h2 id="cs-students">{{ t('seccs.studentsTitle') }}</h2>
      <div class="row">
        <div class="field">
          <label for="cs-filter">{{ t('seccs.status') }}</label>
          <select id="cs-filter" v-model="filter" @change="loadStudents">
            <option value="">{{ t('seccs.all') }}</option>
            <option value="pending">{{ t('seccs.st.pending') }}</option>
            <option value="recorded">{{ t('seccs.st.recorded') }}</option>
            <option value="mismatch">{{ t('seccs.st.mismatch') }}</option>
          </select>
        </div>
        <label class="check"><input v-model="entryOnly" type="checkbox" @change="loadStudents" /> {{ t('seccs.entryOnly') }}</label>
      </div>
      <p v-if="students.length === 0" class="muted">{{ t('seccs.noStudents') }}</p>
      <ul v-else class="rows">
        <li v-for="s in students" :key="s.person_id">
          <strong>{{ s.last_name }} {{ s.first_name }}</strong>
          <span class="muted"> · {{ s.birth_date ?? '—' }}<template v-if="s.level"> · {{ s.level }}</template></span>
          <span class="tag" :class="{ 'tag--warning': s.national_id_status !== 'recorded' }">{{ t(`seccs.st.${s.national_id_status}`) }}</span>
          <div v-if="s.national_id">{{ s.national_id }}</div>
          <form v-if="canWriteStudents" class="row" @submit.prevent="record(s)">
            <label :for="`cs-id-${s.person_id}`" class="sr-only">{{ t('seccs.nationalId') }}</label>
            <input :id="`cs-id-${s.person_id}`" v-model="drafts[s.person_id]" type="text" maxlength="40" autocomplete="off" :placeholder="t('seccs.nationalId')" />
            <button type="submit" class="btn" :disabled="busy || !validDraft(s.person_id)">{{ t('seccs.record') }}</button>
          </form>
        </li>
      </ul>
    </section>

    <section v-if="canReadStudents" class="card" aria-labelledby="cs-gaps">
      <h2 id="cs-gaps">{{ t('seccs.gapsTitle') }}</h2>
      <p class="muted">{{ t('seccs.gapsHelp') }}</p>
      <p v-if="discrepancies.length === 0" class="muted">{{ t('seccs.noGaps') }}</p>
      <ul v-else class="rows">
        <li v-for="d in discrepancies" :key="d.id">
          <span class="tag tag--warning">{{ t(`seccs.kind.${d.discrepancy_kind}`) }}</span>
          {{ d.detail }}
          <div v-if="d.remote_label" class="muted">{{ t('seccs.remote', { label: d.remote_label }) }}<template v-if="d.national_id"> · {{ d.national_id }}</template></div>
          <div v-if="canWriteStudents" class="actions">
            <button v-if="d.person_id && d.national_id" type="button" class="btn btn--primary" :disabled="busy" @click="resolve(d, true)">{{ t('seccs.applyRemote') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="resolve(d, false)">{{ t('seccs.markResolved') }}</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="canReadPayments" class="card" aria-labelledby="cs-payments">
      <h2 id="cs-payments">{{ t('seccs.unknownTitle') }}</h2>
      <p class="muted">{{ t('seccs.unknownHelp') }}</p>
      <p v-if="unknownPayments.length === 0" class="muted">{{ t('seccs.noUnknown') }}</p>
      <ul v-else class="rows">
        <li v-for="p in unknownPayments" :key="p.id">
          <strong>{{ p.external_ref }}</strong> · {{ p.national_id }} · {{ t(`seccs.pay.${p.payment_kind}`) }} · {{ money(p.amount) }} · {{ p.paid_on }}
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.row input { flex: 1 1 160px; min-width: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.check { display: flex; gap: 8px; align-items: center; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
