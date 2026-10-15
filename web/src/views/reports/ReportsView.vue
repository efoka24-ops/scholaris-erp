<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { http } from '@/api/http';
import type { Person } from '@/api/types';
import {
  createSchedule, deleteSchedule, listRuns, listSchedules, runSchedule, updateSchedule,
  type Frequency, type ReportRun, type ReportSchedule, type ReportType, type RunResult,
} from '@/api/reports';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';

// Rapports planifiés : créer, modifier, mettre en pause, supprimer, exécuter maintenant, historique — design : Dashboard.html, SecDashboard.html (cartes, tableaux).
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const rows = ref<ReportSchedule[]>([]);
const loaded = ref(false);
const list = useAction();
const save = useAction();
const act = useAction();
const search = useAction();
const editingId = ref<string | null>(null);
const touched = ref(false);
const form = reactive({ name: '', report_type: 'direction' as ReportType, frequency: 'weekly' as Frequency, run_day: 1, recipients: [] as Array<{ id: string; label: string }> });
const personQuery = ref('');
const found = ref<Person[]>([]);
const runResult = ref<{ id: string; result: RunResult } | null>(null);
const runsOf = ref<{ id: string; items: ReportRun[] } | null>(null);
const confirmDelete = ref<string | null>(null);

const types = computed<ReportType[]>(() => (ctx.can('reporting.group.read') ? ['direction', 'censeur', 'groupe', 'regulatory'] : ['direction', 'censeur', 'regulatory']));
const dayMax = computed(() => (form.frequency === 'weekly' ? 7 : 28));
const errors = computed(() => ({
  name: touched.value && (form.name.trim() === '' || form.name.trim().length > 120),
  recipients: touched.value && (form.recipients.length === 0 || form.recipients.length > 20),
  day: touched.value && form.frequency !== 'daily' && !(form.run_day >= 1 && form.run_day <= dayMax.value),
}));

const typeLabel = (k: string): string => t(`reports.type.${k}` as MessageKey);
const freqLabel = (k: string): string => t(`reports.freq.${k}` as MessageKey);
const tone = (s: string | null): 'success' | 'warning' | 'neutral' => (s === 'done' || s === 'active' ? 'success' : s === 'failed' ? 'warning' : 'neutral');
const runStatus = (s: string): string => {
  const key = `reports.run.${s}` as MessageKey;
  const label = t(key);
  return label === key ? s : label;
};

async function load(): Promise<void> {
  const res = await list.run(listSchedules);
  if (res) rows.value = res;
  loaded.value = true;
}

function reset(): void {
  editingId.value = null;
  touched.value = false;
  Object.assign(form, { name: '', report_type: 'direction', frequency: 'weekly', run_day: 1 });
  form.recipients = auth.me?.person_id ? [{ id: auth.me.person_id, label: t('reports.me') }] : [];
}

function edit(r: ReportSchedule): void {
  editingId.value = r.id;
  touched.value = false;
  Object.assign(form, { name: r.name, report_type: r.report_type, frequency: r.frequency, run_day: r.run_day ?? 1 });
  form.recipients = r.recipient_ids.map((id) => ({ id, label: id === auth.me?.person_id ? t('reports.me') : id }));
}

async function searchPeople(): Promise<void> {
  const q = personQuery.value.trim();
  if (q === '') return;
  const res = await search.run(() => http.page<Person>('/people', { 'filter[q]': q }));
  if (res) found.value = res.items;
}
function addRecipient(p: Person): void {
  if (!form.recipients.some((r) => r.id === p.id)) form.recipients.push({ id: p.id, label: `${p.first_name} ${p.last_name}` });
}
function removeRecipient(id: string): void {
  form.recipients = form.recipients.filter((r) => r.id !== id);
}

async function submit(): Promise<void> {
  touched.value = true;
  if (Object.values(errors.value).some(Boolean)) return;
  const body: Record<string, unknown> = { name: form.name.trim(), frequency: form.frequency, recipient_ids: form.recipients.map((r) => r.id) };
  if (form.frequency !== 'daily') body.run_day = Number(form.run_day);
  else if (editingId.value) body.run_day = null;
  const id = editingId.value;
  if (!id) body.report_type = form.report_type;
  const res = await save.run(() => (id ? updateSchedule(id, body) : createSchedule(body)));
  if (!res) return;
  reset();
  await load();
}

async function togglePause(r: ReportSchedule): Promise<void> {
  const res = await act.run(() => updateSchedule(r.id, { status: r.status === 'active' ? 'paused' : 'active' }));
  if (res) rows.value = rows.value.map((x) => (x.id === r.id ? res : x));
}

async function runNow(r: ReportSchedule): Promise<void> {
  const res = await act.run(() => runSchedule(r.id));
  if (!res) return;
  runResult.value = { id: r.id, result: res };
  await load();
  if (runsOf.value?.id === r.id) await showRuns(r, true);
}

async function showRuns(r: ReportSchedule, force = false): Promise<void> {
  if (!force && runsOf.value?.id === r.id) {
    runsOf.value = null;
    return;
  }
  const res = await act.run(() => listRuns(r.id));
  if (res) runsOf.value = { id: r.id, items: res };
}

async function remove(r: ReportSchedule): Promise<void> {
  const ok = await act.run(async () => {
    await deleteSchedule(r.id);
    return true;
  });
  confirmDelete.value = null;
  if (ok) rows.value = rows.value.filter((x) => x.id !== r.id);
}

onMounted(() => {
  reset();
  void load();
});
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('reports.eyebrow') }}</p>
      <h1>{{ t('reports.title') }}</h1>
      <p class="lede">{{ t('reports.lede') }}</p>
    </header>

    <div class="card">
      <ErrorNotice v-if="list.error.value || act.error.value" :error="list.error.value ?? act.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0 && !list.error.value" class="muted">{{ t('reports.empty') }}</p>
      <div v-else-if="rows.length > 0" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('reports.col.name') }}</th><th>{{ t('reports.col.type') }}</th><th>{{ t('reports.col.frequency') }}</th>
              <th>{{ t('reports.col.next') }}</th><th>{{ t('reports.col.last') }}</th><th>{{ t('staff.col.status') }}</th><th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in rows" :key="r.id">
              <tr>
                <th scope="row">{{ r.name }}</th>
                <td>{{ typeLabel(r.report_type) }}</td>
                <td>{{ freqLabel(r.frequency) }}<template v-if="r.run_day"> · {{ r.run_day }}</template></td>
                <td>{{ formatDate(r.next_run_on, locale) || '—' }}</td>
                <td>
                  <template v-if="r.last_run_at">{{ formatDateTime(r.last_run_at, locale) }} <StatusTag :tone="tone(r.last_run_status)">{{ runStatus(r.last_run_status ?? '') }}</StatusTag></template>
                  <template v-else>—</template>
                </td>
                <td><StatusTag :tone="tone(r.status)">{{ t(`reports.status.${r.status}` as MessageKey) }}</StatusTag></td>
                <td class="row">
                  <button type="button" class="btn btn--quiet" data-action="run" :disabled="act.busy.value" @click="runNow(r)">{{ t('reports.action.run') }}</button>
                  <button type="button" class="btn btn--quiet" data-action="runs" @click="showRuns(r)">{{ t('reports.action.history') }}</button>
                  <button type="button" class="btn btn--quiet" data-action="edit" @click="edit(r)">{{ t('hr.edit') }}</button>
                  <button type="button" class="btn btn--quiet" data-action="pause" :disabled="act.busy.value" @click="togglePause(r)">{{ r.status === 'active' ? t('reports.action.pause') : t('reports.action.resume') }}</button>
                  <button v-if="confirmDelete !== r.id" type="button" class="btn btn--quiet" data-action="delete" @click="confirmDelete = r.id">{{ t('hr.delete') }}</button>
                  <button v-else type="button" class="btn btn--ink" data-action="confirm-delete" @click="remove(r)">{{ t('reports.action.confirmDelete') }}</button>
                </td>
              </tr>
              <tr v-if="runResult?.id === r.id">
                <td colspan="7">
                  <div class="notice notice--success" role="status">
                    {{ t('reports.run.result', { status: runStatus(runResult.result.status), sent: runResult.result.sent_count, failed: runResult.result.failed_count }) }}
                  </div>
                </td>
              </tr>
              <tr v-if="runsOf?.id === r.id" data-test="runs">
                <td colspan="7">
                  <p v-if="runsOf.items.length === 0" class="muted">{{ t('reports.runs.empty') }}</p>
                  <div v-else class="table-wrap">
                    <table>
                      <thead><tr><th>{{ t('reports.runs.date') }}</th><th>{{ t('reports.runs.trigger') }}</th><th>{{ t('staff.col.status') }}</th><th class="num">{{ t('reports.runs.sent') }}</th><th class="num">{{ t('reports.runs.failed') }}</th><th>{{ t('reports.runs.summary') }}</th></tr></thead>
                      <tbody>
                        <tr v-for="run in runsOf.items" :key="run.id">
                          <td>{{ formatDateTime(run.created_at, locale) }}</td>
                          <td>{{ run.trigger_kind === 'scheduled' ? t('reports.runs.scheduled') : t('reports.runs.manual') }}</td>
                          <td><StatusTag :tone="tone(run.status)">{{ runStatus(run.status) }}</StatusTag></td>
                          <td class="num">{{ run.sent_count }}</td><td class="num">{{ run.failed_count }}</td><td>{{ run.summary ?? '—' }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <form class="card" data-test="schedule-form" novalidate @submit.prevent="submit">
      <h2>{{ editingId ? t('reports.edit') : t('reports.create') }}</h2>
      <ErrorNotice v-if="save.error.value || search.error.value" :error="save.error.value ?? search.error.value" />
      <div class="grid grid--kpi">
        <label class="field">
          <span class="label">{{ t('reports.col.name') }}</span>
          <input v-model="form.name" type="text" maxlength="120" :aria-invalid="errors.name" />
          <span v-if="errors.name" class="error" role="alert">{{ t('reports.nameInvalid') }}</span>
        </label>
        <label class="field">
          <span class="label">{{ t('reports.col.type') }}</span>
          <select v-model="form.report_type" :disabled="editingId !== null"><option v-for="k in types" :key="k" :value="k">{{ typeLabel(k) }}</option></select>
        </label>
        <label class="field">
          <span class="label">{{ t('reports.col.frequency') }}</span>
          <select v-model="form.frequency"><option v-for="k in ['daily', 'weekly', 'monthly']" :key="k" :value="k">{{ freqLabel(k) }}</option></select>
        </label>
        <label v-if="form.frequency !== 'daily'" class="field">
          <span class="label">{{ form.frequency === 'weekly' ? t('reports.runDay.weekly') : t('reports.runDay.monthly') }}</span>
          <input v-model.number="form.run_day" type="number" min="1" :max="dayMax" :aria-invalid="errors.day" />
          <span v-if="errors.day" class="error" role="alert">{{ t('reports.dayInvalid', { max: dayMax }) }}</span>
        </label>
      </div>

      <fieldset class="field">
        <legend class="label">{{ t('reports.recipients') }}</legend>
        <ul class="row" style="list-style: none; padding: 0; margin: 0 0 8px">
          <li v-for="r in form.recipients" :key="r.id" class="tag">
            {{ r.label }} <button type="button" class="btn btn--quiet" style="min-height: 24px; min-width: 24px; padding: 0 6px" :aria-label="t('reports.recipient.remove', { name: r.label })" @click="removeRecipient(r.id)">×</button>
          </li>
        </ul>
        <span v-if="errors.recipients" class="error" role="alert">{{ t('reports.recipientsInvalid') }}</span>
        <div class="row">
          <input v-model="personQuery" type="search" class="spacer" :placeholder="t('reports.recipient.search')" @keydown.enter.prevent="searchPeople" />
          <button type="button" class="btn" :disabled="search.busy.value" @click="searchPeople">{{ t('staff.create.search') }}</button>
        </div>
        <ul v-if="found.length > 0" class="row" style="list-style: none; padding: 0">
          <li v-for="p in found" :key="p.id"><button type="button" class="btn btn--quiet" @click="addRecipient(p)">+ {{ p.first_name }} {{ p.last_name }}</button></li>
        </ul>
      </fieldset>

      <div class="row">
        <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ editingId ? t('hr.save') : t('reports.create.submit') }}</button>
        <button v-if="editingId" type="button" class="btn btn--quiet" @click="reset">{{ t('hr.cancel') }}</button>
      </div>
    </form>
  </section>
</template>
