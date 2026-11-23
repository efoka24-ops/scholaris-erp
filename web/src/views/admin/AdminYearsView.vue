<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { useConfirmation } from '@/views/account/useConfirmation';
import { useAction } from './useAction';
import { periodKindsFor } from './catalog';
import {
  approveClosure,
  closureCheck,
  createPeriod,
  createYear,
  deletePeriod,
  listPeriods,
  listYears,
  rejectClosure,
  requestClosure,
  updatePeriod,
  updateYear,
  type AcademicYear,
  type ClosureReport,
  type Period,
} from '@/api/admin';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';
import { formatDate, formatDateTime } from '@/lib/format';

// Années académiques, périodes et clôture (demande puis approbation par une autre personne) — design : Fondations.html, Frais.html (tableaux).
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const years = ref<AcademicYear[]>([]);
const loaded = ref(false);
const selectedId = ref<string | null>(null);
const periods = ref<Period[]>([]);
const report = ref<ClosureReport | null>(null);
const nextYearId = ref('');

const list = useAction();
const yearAction = useAction();
const periodAction = useAction();
const closureAction = useAction();
const router = useRouter();
const route = useRoute();
const confirmation = useConfirmation();
/** Approbation refusée faute de confirmation récente (code de double authentification, ou mot de passe sans 2FA). */
const needsConfirmation = computed(() => {
  const e = closureAction.error.value;
  return e instanceof ApiError && e.status === 403 && /double authentification/i.test(e.problem?.detail ?? '');
});
async function goConfirm(): Promise<void> {
  await router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}
const detailAction = useAction();

const yearForm = reactive({ label: '', starts_on: '', ends_on: '' });
const yearTouched = ref(false);
const periodForm = reactive({ kind: '', ordinal: 1, starts_on: '', ends_on: '', parent_id: '' });
const periodTouched = ref(false);

const canManage = computed(() => ctx.can('organisation.manage'));
const kinds = computed(() => periodKindsFor(ctx.establishmentType ?? 'secondary'));
const selected = computed(() => years.value.find((y) => y.id === selectedId.value) ?? null);
const closure = computed(() => report.value?.closure ?? null);
const mine = computed(() => !!closure.value && closure.value.requested_by === auth.me?.id);
const candidates = computed(() => years.value.filter((y) => y.status === 'open' && selected.value && y.starts_on > selected.value.starts_on));

const yearErrors = computed(() => ({
  label: yearTouched.value && yearForm.label.trim() === '',
  starts_on: yearTouched.value && yearForm.starts_on === '',
  ends_on: yearTouched.value && (yearForm.ends_on === '' || (yearForm.starts_on !== '' && yearForm.ends_on <= yearForm.starts_on)),
}));
// Rattachement (C-117) : une séquence appartient à un trimestre (obligatoire côté serveur), un trimestre peut l'être à l'année.
const PARENT_KIND: Record<string, string> = { sequence: 'trimester' };
const parentChoices = computed(() => periods.value.filter((p) => p.kind === PARENT_KIND[periodForm.kind]));
const periodEndBad = computed(() => periodTouched.value && (periodForm.ends_on === '' || (periodForm.starts_on !== '' && periodForm.ends_on < periodForm.starts_on)));

const statusKey = (s: string | null | undefined): AdminKey => `admin.closure.status.${s ?? 'none'}` as AdminKey;
const kindLabel = (k: string): string => t(`admin.period.${k}` as AdminKey);
const stepLabel = (k: string): string => t(`admin.closure.step.${k}` as AdminKey);
const stepStatusLabel = (k: string): string => t(`admin.closure.stepStatus.${k}` as AdminKey);
const who = (id: string | null): string => (id === auth.me?.id ? t('admin.closure.you') : t('admin.closure.someone'));
const yearTone = (y: AcademicYear): 'success' | 'neutral' => (y.status === 'open' ? 'success' : 'neutral');
const checkTone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'ok' ? 'success' : s === 'blocking' ? 'warning' : 'neutral');

async function loadYears(): Promise<void> {
  const res = await list.run(listYears);
  if (res) years.value = res;
  loaded.value = true;
}

async function submitYear(): Promise<void> {
  yearTouched.value = true;
  if (yearErrors.value.label || yearErrors.value.starts_on || yearErrors.value.ends_on) return;
  const res = await yearAction.run(() => createYear({ label: yearForm.label.trim(), starts_on: yearForm.starts_on, ends_on: yearForm.ends_on }));
  if (!res) return;
  Object.assign(yearForm, { label: '', starts_on: '', ends_on: '' });
  yearTouched.value = false;
  await loadYears();
  await select(res.id);
}

async function select(id: string): Promise<void> {
  selectedId.value = id;
  periods.value = [];
  report.value = null;
  nextYearId.value = '';
  editingPeriod.value = null;
  periodForm.kind = kinds.value[0] ?? '';
  periodForm.ordinal = 1;
  const res = await detailAction.run(async () => ({ periods: await listPeriods(id), report: await closureReport(id) }));
  if (!res) return;
  periods.value = res.periods;
  report.value = res.report;
  periodForm.ordinal = Math.min(12, res.periods.length + 1);
}

async function closureReport(id: string): Promise<ClosureReport | null> {
  // Le contrôle de clôture n'est ouvert qu'à la gestion (organisation.manage) : en lecture seule, on s'en passe.
  return canManage.value ? closureCheck(id) : null;
}

async function submitPeriod(): Promise<void> {
  periodTouched.value = true;
  if (!selectedId.value || periodForm.starts_on === '' || periodEndBad.value) return;
  const id = selectedId.value;
  const parentId = parentChoices.value.some((p) => p.id === periodForm.parent_id) ? periodForm.parent_id : '';
  const res = await periodAction.run(() =>
    createPeriod({
      academic_year_id: id,
      kind: periodForm.kind,
      ordinal: Number(periodForm.ordinal),
      starts_on: periodForm.starts_on,
      ends_on: periodForm.ends_on,
      ...(parentId !== '' ? { parent_id: parentId } : {}),
    }),
  );
  if (!res) return;
  periods.value = [...periods.value, res].sort((a, b) => a.starts_on.localeCompare(b.starts_on));
  Object.assign(periodForm, { ordinal: Math.min(12, periods.value.length + 1), starts_on: '', ends_on: '' });
  periodTouched.value = false;
}

async function refreshReport(): Promise<void> {
  if (!selectedId.value) return;
  const id = selectedId.value;
  const r = await closureAction.run(() => closureCheck(id));
  if (r) report.value = r;
}

async function askClosure(): Promise<void> {
  if (!selectedId.value) return;
  const id = selectedId.value;
  const res = await closureAction.run(() => requestClosure(id, nextYearId.value || null));
  if (res) await refreshReport();
}

async function decide(kind: 'approve' | 'reject'): Promise<void> {
  if (!selectedId.value) return;
  const id = selectedId.value;
  const res = await closureAction.run(() => (kind === 'approve' ? approveClosure(id) : rejectClosure(id)));
  if (res) {
    await refreshReport();
    await loadYears();
  }
}

// Modification d'une année et d'une période, suppression d'une période libre (organisation.manage, année ouverte).
// Les erreurs de validation du serveur (422) s'affichent sous les champs ; les refus (409) dans l'avis d'erreur.
const notice = ref('');
const warnings = ref<string[]>([]);
const yearEditAction = useAction();
const periodEditAction = useAction();
const periodDeleteAction = useAction();
const editingYear = ref<AcademicYear | null>(null);
const yearEdit = reactive({ label: '', starts_on: '', ends_on: '' });
const editingPeriod = ref<Period | null>(null);
const periodEdit = reactive({ kind: '', ordinal: 1, starts_on: '', ends_on: '', parent_id: '' });

const canEditYear = (y: AcademicYear): boolean => canManage.value && y.status === 'open';
const canEditPeriods = computed(() => canManage.value && selected.value?.status === 'open');
const periodName = (p: Pick<Period, 'kind' | 'ordinal'>): string => (p.kind === 'year' ? kindLabel(p.kind) : `${kindLabel(p.kind)} ${p.ordinal}`);
const editKinds = computed(() => {
  const k = editingPeriod.value?.kind;
  return k && !(kinds.value as string[]).includes(k) ? [k, ...kinds.value] : [...kinds.value];
});
const editParentChoices = computed(() => periods.value.filter((p) => p.kind === PARENT_KIND[periodEdit.kind] && p.id !== editingPeriod.value?.id));

function fieldError(error: unknown, field: string): string | undefined {
  return error instanceof ApiError && error.status === 422 ? error.fieldErrors[field]?.[0] : undefined;
}
const yearEditError = (field: string): string | undefined => fieldError(yearEditAction.error.value, field);
const periodEditError = (field: string): string | undefined => fieldError(periodEditAction.error.value, field);
/** Erreur générale (hors 422 par champ) : refus 409, droits, réseau. */
const generalError = (error: unknown): unknown => (error instanceof ApiError && error.status === 422 && Object.keys(error.fieldErrors).length > 0 ? null : error);

function startYearEdit(y: AcademicYear): void {
  editingYear.value = y;
  Object.assign(yearEdit, { label: y.label, starts_on: y.starts_on, ends_on: y.ends_on });
  yearEditAction.error.value = null;
  notice.value = '';
}

async function saveYear(): Promise<void> {
  const y = editingYear.value;
  if (!y) return;
  const res = await yearEditAction.run(() => updateYear(y.id, { label: yearEdit.label.trim(), starts_on: yearEdit.starts_on, ends_on: yearEdit.ends_on }));
  if (!res) return;
  years.value = years.value.map((x) => (x.id === res.id ? { ...x, ...res } : x));
  editingYear.value = null;
  notice.value = t('admin.years.saved', { name: res.label });
}

function startPeriodEdit(p: Period): void {
  editingPeriod.value = p;
  Object.assign(periodEdit, { kind: p.kind, ordinal: p.ordinal, starts_on: p.starts_on, ends_on: p.ends_on, parent_id: p.parent_id ?? '' });
  periodEditAction.error.value = null;
  notice.value = '';
  warnings.value = [];
}

async function savePeriod(): Promise<void> {
  const p = editingPeriod.value;
  if (!p) return;
  const parentId = PARENT_KIND[periodEdit.kind] && editParentChoices.value.some((c) => c.id === periodEdit.parent_id) ? periodEdit.parent_id : null;
  const res = await periodEditAction.run(() =>
    updatePeriod(p.id, { kind: periodEdit.kind, ordinal: Number(periodEdit.ordinal), starts_on: periodEdit.starts_on, ends_on: periodEdit.ends_on, parent_id: parentId }),
  );
  if (!res) return;
  periods.value = periods.value.map((x) => (x.id === p.id ? { ...x, ...res.period } : x)).sort((a, b) => a.starts_on.localeCompare(b.starts_on));
  editingPeriod.value = null;
  warnings.value = res.warnings;
  notice.value = t('admin.periods.saved', { name: periodName(res.period) });
}

async function removePeriod(p: Period): Promise<void> {
  const name = periodName(p);
  if (typeof window !== 'undefined' && !window.confirm(t('admin.periods.deleteConfirm', { name }))) return;
  notice.value = '';
  warnings.value = [];
  const done = await periodDeleteAction.run(async () => {
    await deletePeriod(p.id);
    return true;
  });
  if (!done) return;
  periods.value = periods.value.filter((x) => x.id !== p.id);
  if (editingPeriod.value?.id === p.id) editingPeriod.value = null;
  notice.value = t('admin.periods.deleted', { name });
}

onMounted(loadYears);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.years.title') }}</h1>
      <p class="lede">{{ t('admin.years.lede', { name: ctx.activeTile?.name ?? '' }) }}</p>
    </header>

    <AdminSubnav />
    <ErrorNotice :error="list.error.value" />

    <form v-if="canManage" class="card" novalidate aria-labelledby="y-new" @submit.prevent="submitYear">
      <h2 id="y-new">{{ t('admin.years.new') }}</h2>
      <ErrorNotice :error="yearAction.error.value" />
      <div class="grid grid--kpi">
        <div class="field">
          <label for="y-label">{{ t('admin.years.label') }}</label>
          <input id="y-label" v-model="yearForm.label" type="text" maxlength="40" placeholder="2026-2027" :aria-invalid="yearErrors.label" aria-describedby="y-label-err" />
          <span v-if="yearErrors.label" id="y-label-err" class="error">{{ t('admin.years.labelRequired') }}</span>
        </div>
        <div class="field">
          <label for="y-start">{{ t('admin.years.start') }}</label>
          <input id="y-start" v-model="yearForm.starts_on" type="date" :aria-invalid="yearErrors.starts_on" />
          <span v-if="yearErrors.starts_on" class="error">{{ t('admin.years.dateRequired') }}</span>
        </div>
        <div class="field">
          <label for="y-end">{{ t('admin.years.end') }}</label>
          <input id="y-end" v-model="yearForm.ends_on" type="date" :aria-invalid="yearErrors.ends_on" aria-describedby="y-end-err" />
          <span v-if="yearErrors.ends_on" id="y-end-err" class="error">{{ t('admin.years.endAfter') }}</span>
        </div>
      </div>
      <p class="muted small">{{ t('admin.years.activationNote') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="yearAction.busy.value">{{ t('admin.years.create') }}</button>
    </form>

    <p v-if="loaded && years.length === 0 && !list.error.value" class="card muted">{{ t('admin.years.none') }}</p>
    <div v-else-if="years.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('admin.years.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('admin.years.label') }}</th>
            <th scope="col">{{ t('admin.years.start') }}</th>
            <th scope="col">{{ t('admin.years.end') }}</th>
            <th scope="col">{{ t('admin.years.state') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="y in years" :key="y.id" :class="{ current: y.id === selectedId }">
            <th scope="row">{{ y.label }}</th>
            <td>{{ formatDate(y.starts_on, locale) }}</td>
            <td>{{ formatDate(y.ends_on, locale) }}</td>
            <td><span class="tag" :class="`tag--${yearTone(y)}`">{{ y.status === 'open' ? t('admin.years.open') : t('admin.years.closed') }}</span></td>
            <td class="actions">
              <button type="button" class="btn" :aria-pressed="y.id === selectedId" :aria-label="t('admin.years.manageNamed', { name: y.label })" @click="select(y.id)">{{ t('admin.years.manage') }}</button>
              <button v-if="canEditYear(y)" type="button" class="btn" data-test="year-edit" :aria-label="t('admin.years.editNamed', { name: y.label })" @click="startYearEdit(y)">{{ t('admin.years.edit') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
    <ul v-if="warnings.length" class="notice notice--warning" data-test="period-warnings">
      <li v-for="w in warnings" :key="w">{{ w }}</li>
    </ul>

    <form v-if="editingYear && canEditYear(editingYear)" class="card" novalidate aria-labelledby="y-edit" @submit.prevent="saveYear">
      <h2 id="y-edit">{{ t('admin.years.editTitle', { name: editingYear.label }) }}</h2>
      <ErrorNotice :error="generalError(yearEditAction.error.value)" />
      <div class="grid grid--kpi">
        <div class="field">
          <label for="ye-label">{{ t('admin.years.label') }}</label>
          <input id="ye-label" v-model="yearEdit.label" type="text" maxlength="40" :aria-invalid="!!yearEditError('label')" aria-describedby="ye-label-err" />
          <span v-if="yearEditError('label')" id="ye-label-err" class="error">{{ yearEditError('label') }}</span>
        </div>
        <div class="field">
          <label for="ye-start">{{ t('admin.years.start') }}</label>
          <input id="ye-start" v-model="yearEdit.starts_on" type="date" :aria-invalid="!!yearEditError('starts_on')" aria-describedby="ye-start-err" />
          <span v-if="yearEditError('starts_on')" id="ye-start-err" class="error">{{ yearEditError('starts_on') }}</span>
        </div>
        <div class="field">
          <label for="ye-end">{{ t('admin.years.end') }}</label>
          <input id="ye-end" v-model="yearEdit.ends_on" type="date" :aria-invalid="!!yearEditError('ends_on')" aria-describedby="ye-end-err" />
          <span v-if="yearEditError('ends_on')" id="ye-end-err" class="error">{{ yearEditError('ends_on') }}</span>
        </div>
      </div>
      <p class="muted small">{{ t('admin.years.editHint') }}</p>
      <div class="row">
        <button type="submit" class="btn btn--primary" :disabled="yearEditAction.busy.value">{{ t('admin.common.save') }}</button>
        <button type="button" class="btn" @click="editingYear = null">{{ t('admin.common.cancel') }}</button>
      </div>
    </form>

    <template v-if="selected">
      <ErrorNotice :error="detailAction.error.value" />

      <section class="card" aria-labelledby="p-title">
        <h2 id="p-title">{{ t('admin.periods.title', { name: selected.label }) }}</h2>
        <ErrorNotice :error="periodDeleteAction.error.value" />
        <p v-if="periods.length === 0" class="muted">{{ t('admin.periods.none') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ t('admin.periods.kind') }}</th>
                <th scope="col" class="num">{{ t('admin.periods.ordinal') }}</th>
                <th scope="col">{{ t('admin.years.start') }}</th>
                <th scope="col">{{ t('admin.years.end') }}</th>
                <th v-if="canEditPeriods" scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in periods" :key="p.id">
                <th scope="row">{{ kindLabel(p.kind) }}</th>
                <td class="num">{{ p.ordinal }}</td>
                <td>{{ formatDate(p.starts_on, locale) }}</td>
                <td>{{ formatDate(p.ends_on, locale) }}</td>
                <td v-if="canEditPeriods" class="actions">
                  <button type="button" class="btn" data-test="period-edit" :aria-label="t('admin.periods.editNamed', { name: periodName(p) })" @click="startPeriodEdit(p)">{{ t('admin.periods.edit') }}</button>
                  <button type="button" class="btn" data-test="period-delete" :disabled="periodDeleteAction.busy.value" :aria-label="t('admin.periods.deleteNamed', { name: periodName(p) })" @click="removePeriod(p)">
                    {{ t('admin.periods.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="editingPeriod && canEditPeriods" class="subform" novalidate aria-labelledby="p-edit" @submit.prevent="savePeriod">
          <h3 id="p-edit">{{ t('admin.periods.editTitle', { name: periodName(editingPeriod) }) }}</h3>
          <ErrorNotice :error="generalError(periodEditAction.error.value)" />
          <div class="grid grid--kpi">
            <div class="field">
              <label for="pe-kind">{{ t('admin.periods.kind') }}</label>
              <select id="pe-kind" v-model="periodEdit.kind" :aria-invalid="!!periodEditError('kind')" aria-describedby="pe-kind-err">
                <option v-for="k in editKinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
              </select>
              <span v-if="periodEditError('kind')" id="pe-kind-err" class="error">{{ periodEditError('kind') }}</span>
            </div>
            <div v-if="PARENT_KIND[periodEdit.kind]" class="field">
              <label for="pe-parent">{{ t('admin.periods.parent') }}</label>
              <select id="pe-parent" v-model="periodEdit.parent_id" :aria-invalid="!!periodEditError('parent_id')" aria-describedby="pe-parent-err">
                <option value="">—</option>
                <option v-for="c in editParentChoices" :key="c.id" :value="c.id">{{ periodName(c) }}</option>
              </select>
              <span v-if="periodEditError('parent_id')" id="pe-parent-err" class="error">{{ periodEditError('parent_id') }}</span>
            </div>
            <div class="field">
              <label for="pe-ord">{{ t('admin.periods.ordinal') }}</label>
              <input id="pe-ord" v-model.number="periodEdit.ordinal" type="number" min="1" max="12" step="1" :aria-invalid="!!periodEditError('ordinal')" aria-describedby="pe-ord-err" />
              <span v-if="periodEditError('ordinal')" id="pe-ord-err" class="error">{{ periodEditError('ordinal') }}</span>
            </div>
            <div class="field">
              <label for="pe-start">{{ t('admin.years.start') }}</label>
              <input id="pe-start" v-model="periodEdit.starts_on" type="date" :aria-invalid="!!periodEditError('starts_on')" aria-describedby="pe-start-err" />
              <span v-if="periodEditError('starts_on')" id="pe-start-err" class="error">{{ periodEditError('starts_on') }}</span>
            </div>
            <div class="field">
              <label for="pe-end">{{ t('admin.years.end') }}</label>
              <input id="pe-end" v-model="periodEdit.ends_on" type="date" :aria-invalid="!!periodEditError('ends_on')" aria-describedby="pe-end-err" />
              <span v-if="periodEditError('ends_on')" id="pe-end-err" class="error">{{ periodEditError('ends_on') }}</span>
            </div>
          </div>
          <p class="muted small">{{ t('admin.periods.editHint') }}</p>
          <div class="row">
            <button type="submit" class="btn btn--primary" :disabled="periodEditAction.busy.value">{{ t('admin.common.save') }}</button>
            <button type="button" class="btn" @click="editingPeriod = null">{{ t('admin.common.cancel') }}</button>
          </div>
        </form>

        <form v-if="canManage && selected.status === 'open'" class="subform" novalidate aria-labelledby="p-new" @submit.prevent="submitPeriod">
          <h3 id="p-new">{{ t('admin.periods.new') }}</h3>
          <ErrorNotice :error="periodAction.error.value" />
          <div class="grid grid--kpi">
            <div class="field">
              <label for="p-kind">{{ t('admin.periods.kind') }}</label>
              <select id="p-kind" v-model="periodForm.kind">
                <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
              </select>
            </div>
            <div v-if="PARENT_KIND[periodForm.kind]" class="field">
              <label for="p-parent">{{ t('admin.periods.parent') }}</label>
              <select id="p-parent" v-model="periodForm.parent_id">
                <option value="">—</option>
                <option v-for="p in parentChoices" :key="p.id" :value="p.id">{{ kindLabel(p.kind) }} {{ p.ordinal }}</option>
              </select>
              <span class="hint">{{ t('admin.periods.parentHint') }}</span>
            </div>
            <div class="field">
              <label for="p-ord">{{ t('admin.periods.ordinal') }}</label>
              <input id="p-ord" v-model.number="periodForm.ordinal" type="number" min="1" max="12" step="1" />
            </div>
            <div class="field">
              <label for="p-start">{{ t('admin.years.start') }}</label>
              <input id="p-start" v-model="periodForm.starts_on" type="date" :aria-invalid="periodTouched && periodForm.starts_on === ''" />
            </div>
            <div class="field">
              <label for="p-end">{{ t('admin.years.end') }}</label>
              <input id="p-end" v-model="periodForm.ends_on" type="date" :aria-invalid="periodEndBad" aria-describedby="p-end-err" />
              <span v-if="periodEndBad" id="p-end-err" class="error">{{ t('admin.periods.endAfter') }}</span>
            </div>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="periodAction.busy.value">{{ t('admin.periods.create') }}</button>
        </form>
      </section>

      <section v-if="canManage" class="card" aria-labelledby="c-title">
        <div class="row row--between">
          <h2 id="c-title">{{ t('admin.closure.title', { name: selected.label }) }}</h2>
          <span class="tag" :class="closure?.status === 'closed' || selected.status === 'closed' ? 'tag--ink' : closure ? 'tag--warning' : 'tag--neutral'" data-test="closure-status">
            {{ selected.status === 'closed' && !closure ? t('admin.years.closed') : t(statusKey(closure?.status)) }}
          </span>
        </div>
        <p class="muted">{{ t('admin.closure.principle') }}</p>
        <ErrorNotice :error="closureAction.error.value" />
        <button v-if="needsConfirmation" type="button" class="btn btn--ink" data-test="closure-confirm" @click="goConfirm">{{ confirmation.go(t('twofa.title')) }}</button>

        <template v-if="report">
          <ul class="checks" :aria-label="t('admin.closure.checks')">
            <li v-for="c in report.checks" :key="c.key" class="check-line">
              <span class="tag" :class="`tag--${checkTone(c.status)}`">{{ t(`admin.closure.check.${c.status}` as AdminKey) }}</span>
              <span>{{ c.label }}<template v-if="c.blocking_count > 0"> ({{ c.blocking_count }})</template></span>
              <span v-if="c.detail" class="muted small">{{ c.detail }}</span>
            </li>
          </ul>
          <p v-if="!report.ready && selected.status === 'open'" class="notice notice--warning">{{ t('admin.closure.blocked', { count: report.blocking_count }) }}</p>
        </template>

        <div v-if="selected.status === 'closed'" class="notice notice--success" role="status">
          {{ t('admin.closure.doneAt', { date: formatDateTime(closure?.closed_at ?? selected.closed_at, locale) }) }}
        </div>

        <template v-else>
          <div v-if="closure && closure.status === 'pending_approval'" class="notice" role="status">
            <p>
              <strong>{{ t('admin.closure.pendingBy', { who: who(closure.requested_by), date: formatDateTime(closure.requested_at, locale) }) }}</strong>
            </p>
            <p v-if="mine">{{ t('admin.closure.needsAnother') }}</p>
            <p v-else>{{ t('admin.closure.needs2fa') }}</p>
            <div class="row">
              <button type="button" class="btn btn--primary" :disabled="mine || closureAction.busy.value" :aria-describedby="mine ? 'c-why' : undefined" @click="decide('approve')">{{ t('admin.closure.approve') }}</button>
              <button type="button" class="btn" :disabled="mine || closureAction.busy.value" @click="decide('reject')">{{ t('admin.closure.reject') }}</button>
            </div>
            <p v-if="mine" id="c-why" class="muted small">{{ t('admin.closure.whyDisabled') }}</p>
          </div>

          <div v-else-if="closure && closure.status === 'approved_incomplete'" class="notice notice--warning" role="status">
            <p>{{ t('admin.closure.incomplete') }}</p>
            <ul v-if="closure.steps" class="steps">
              <li v-for="(s, k) in closure.steps" :key="k">{{ stepLabel(String(k)) }} : {{ stepStatusLabel(s.status) }}<template v-if="s.summary"> — {{ s.summary }}</template></li>
            </ul>
            <button type="button" class="btn btn--primary" :disabled="closureAction.busy.value" @click="decide('approve')">{{ t('admin.closure.resume') }}</button>
          </div>

          <div v-else>
            <p v-if="closure && closure.status === 'rejected'" class="notice notice--warning">{{ t('admin.closure.rejectedNote') }}</p>
            <div class="field">
              <label for="c-next">{{ t('admin.closure.next') }}</label>
              <select id="c-next" v-model="nextYearId" aria-describedby="c-next-hint">
                <option value="">{{ t('admin.closure.nextNone') }}</option>
                <option v-for="y in candidates" :key="y.id" :value="y.id">{{ y.label }}</option>
              </select>
              <span id="c-next-hint" class="hint">{{ t('admin.closure.nextHint') }}</span>
            </div>
            <button type="button" class="btn btn--ink" :disabled="closureAction.busy.value" @click="askClosure">{{ t('admin.closure.request') }}</button>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>

<style scoped>
.card,
.table-wrap {
  margin-top: 16px;
}
.current th,
.current td {
  background: var(--panel);
}
.subform {
  margin-top: 24px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
}
.subform h3 {
  margin: 0 0 12px;
}
.checks {
  list-style: none;
  padding: 0;
  margin: 0 0 16px;
}
.check-line {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  padding: 10px 0;
  border-top: 1px solid var(--border);
}
.steps {
  margin: 8px 0 12px;
  padding-left: 20px;
}
.small {
  font-size: 13px;
}
.actions .btn + .btn {
  margin-left: 8px;
}
ul.notice {
  padding-left: 20px;
}
</style>
