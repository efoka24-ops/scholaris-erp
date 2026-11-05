<script setup lang="ts">
// Notes du secondaire (lot E2-C, US6) : grille de l'enseignant par classe, séquence et matière (coefficient de Setup), saisie en ligne
// ou hors ligne si l'établissement l'active, import par fichier joint, cycle soumise → validée → verrouillée (deuxième
// authentification) → publiée, corrections en deux validations, relevé DOC-08, épreuves communes, saisines pour fraude, paramètres.
// Avant toute saisie, le serveur contrôle l'affectation (403 journalisé). Les notes elles-mêmes vivent dans le socle Assessment.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { saveBlob } from '@/api/exports';
import type { AcademicYear } from '@/api/admin';
import type { GradeGrid, GridColumn, GridRow } from '@/api/assessment';
import { secondarySetup, type SchoolClass } from '@/api/secondarySetup';
import {
  letterFor, nextStage, onStep, secondaryGrades,
  type CommonTest, type Correction, type FraudReport, type GradeSheet, type GradesSettings, type Period, type Statement,
} from '@/api/secondaryGrades';
import { queueGradeEdit } from '@/offline/grades';
import { useOfflineStore } from '@/stores/offline';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

type Tab = 'grid' | 'import' | 'statement' | 'corrections' | 'common' | 'fraud' | 'settings';
const VALIDATE = ['secondary.grades.validate', 'secondary.grades.lock'] as const;

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const ctx = useContextStore();
const offline = useOfflineStore();

const canEnter = computed(() => can('secondary.grades.enter', ...VALIDATE));
const canValidate = computed(() => can(...VALIDATE));
const canFraud = computed(() => can('secondary.discipline.manage', 'secondary.discipline.validate', ...VALIDATE));
const canSettings = computed(() => can('secondary.settings.manage'));
const tabs = computed<Tab[]>(() => {
  const list: Tab[] = ['grid'];
  if (canEnter.value) list.push('import');
  list.push('statement');
  if (canEnter.value) list.push('corrections');
  list.push('common');
  if (canFraud.value) list.push('fraud');
  list.push('settings');
  return list;
});

const tab = ref<Tab>('grid');
const notice = ref<string | null>(null);
const years = ref<AcademicYear[]>([]);
const year = ref('');
const classes = ref<SchoolClass[]>([]);
const classId = ref('');
const periods = ref<Period[]>([]);
const periodId = ref('');
const sheet = ref<GradeSheet | null>(null);
const grid = ref<GradeGrid | null>(null);
const subjectId = ref('');
const allowed = ref(false);
const cellErrors = ref<Record<string, string>>({});
const settings = ref<GradesSettings | null>(null);
const statement = ref<Statement | null>(null);
const corrections = ref<Correction[]>([]);
const commonTests = ref<CommonTest[]>([]);
const frauds = ref<FraudReport[]>([]);
const outcomes = reactive<Record<string, string>>({});
const correctionFor = ref<{ gradeId: string; label: string } | null>(null);
const correctionForm = reactive({ value: '', reason: '' });
const newAssessment = reactive({ title: '', kind: 'devoir', weight: '1', max: '20' });
const importForm = reactive({ assessmentId: '', file: null as File | null });
const commonForm = reactive({ title: '', held_on: '' });
const lateForm = reactive({ due_on: new Date().toISOString().slice(0, 10) });

const sequences = computed(() => periods.value.filter((p) => p.kind === undefined || p.kind === 'sequence'));
const subject = computed(() => sheet.value?.subjects.find((s) => s.subject_id === subjectId.value) ?? null);
const columns = computed<GridColumn[]>(() => (grid.value?.columns ?? []).filter((c) => c.subject_id === subjectId.value));
const step = computed(() => sheet.value?.settings.step ?? null);
const offlineEntry = computed(() => sheet.value?.settings.offline_entry === true);
const absenceCodes = computed(() => [
  { code: 'justified', label: t('secg.justified') },
  { code: 'unjustified', label: t('secg.unjustified') },
  ...(sheet.value?.settings.absence_codes ?? []).map((c) => ({ code: c.code, label: c.label })),
]);
const est = computed(() => ctx.establishmentId ?? '');
const periodLabel = (p: Period): string => p.label ?? p.name ?? p.id;
const stageKey = (s: string): MessageKey => `secg.stage.${s}` as MessageKey;
const editable = (status: string): boolean => ['draft', 'submitted', 'validated'].includes(status);

async function loadYears(): Promise<void> {
  await run(async () => {
    years.value = await secondarySetup.years();
    year.value = years.value[0]?.id ?? '';
    periods.value = await secondaryGrades.periods();
  });
}

async function loadSheet(): Promise<void> {
  sheet.value = null;
  grid.value = null;
  allowed.value = false;
  if (!classId.value || !periodId.value) return;
  await run(async () => {
    sheet.value = await secondaryGrades.sheet(classId.value, periodId.value);
    grid.value = await secondaryGrades.grid(classId.value, periodId.value);
    if (!sheet.value.subjects.some((s) => s.subject_id === subjectId.value)) subjectId.value = sheet.value.subjects.find((s) => s.can_enter)?.subject_id ?? '';
  });
}

async function checkEntry(): Promise<void> {
  allowed.value = false;
  if (!classId.value || !subject.value?.can_enter) return;
  // Contrôle d'affectation : refus 403 journalisé par le serveur, jamais contourné par l'écran.
  const res = await run(() => secondaryGrades.entryCheck(classId.value, subjectId.value));
  allowed.value = res?.allowed === true;
}

function cellValue(row: GridRow, col: GridColumn): string {
  const cell = row.cells[col.id];
  return cell?.value === null || cell?.value === undefined ? '' : String(cell.value);
}

async function enter(row: GridRow, col: GridColumn, raw: string, absence: string | null): Promise<void> {
  const key = `${col.id}|${row.person_id}`;
  const text = raw.trim().replace(',', '.');
  const value = text === '' ? null : Number(text);
  const { [key]: _gone, ...rest } = cellErrors.value;
  cellErrors.value = rest;
  if (value !== null && (Number.isNaN(value) || !/^\d+(\.\d+)?$/.test(text))) {
    cellErrors.value = { ...cellErrors.value, [key]: t('secg.invalid') };
    return;
  }
  if (value !== null && value > col.max_score) {
    cellErrors.value = { ...cellErrors.value, [key]: t('secg.overMax', { value: text, max: col.max_score }) };
    return;
  }
  if (value !== null && !onStep(value, step.value)) {
    cellErrors.value = { ...cellErrors.value, [key]: t('secg.offStep', { value: text, step: step.value ?? 0.01 }) };
    return;
  }
  const version = row.cells[col.id]?.version ?? 0;
  const ok = await run(async () => {
    if (offlineEntry.value) {
      await queueGradeEdit(est.value, { assessment_id: col.id, person_id: row.person_id, value: absence ? null : value, absence: absence as 'justified' | null, base_version: version }, `${row.name} · ${col.title}`);
      await offline.refresh();
      if (offline.online) await offline.sync();
      notice.value = offline.online ? t('secg.saved') : t('secg.queued');
    } else {
      await secondaryGrades.enter({ assessment_id: col.id, person_id: row.person_id, value: absence ? null : value, absence, expected_version: version });
      notice.value = t('secg.saved');
    }
    if (absence === 'fraud') {
      try {
        await secondaryGrades.reportFraud({ class_id: classId.value, subject_id: subjectId.value, assessment_id: col.id, person_id: row.person_id, period_id: periodId.value });
        notice.value = t('secg.fraudReported');
      } catch (e) {
        if (!(e instanceof ApiError && e.status === 409)) throw e;
      }
    }
    return true;
  });
  if (ok && !offlineEntry.value) grid.value = await secondaryGrades.grid(classId.value, periodId.value);
}

function absenceOf(row: GridRow, col: GridColumn): string {
  return row.cells[col.id]?.absence ?? '';
}

async function createAssessment(): Promise<void> {
  if (!sheet.value || !subjectId.value || newAssessment.title.trim() === '') return;
  const created = await run(() => secondaryGrades.createAssessment({
    subject_id: subjectId.value, structure_id: classId.value, period_id: periodId.value, academic_year_id: sheet.value?.class.academic_year_id ?? year.value,
    kind: newAssessment.kind, title: newAssessment.title.trim(), weight: Number(newAssessment.weight), max_score: Number(newAssessment.max),
  }));
  if (created) {
    newAssessment.title = '';
    grid.value = await secondaryGrades.grid(classId.value, periodId.value);
  }
}

async function move(col: GridColumn, to: string): Promise<void> {
  try {
    error.value = null;
    await secondaryGrades.transition(col.id, to);
    if (to === 'published' && subject.value) {
      const res = await secondaryGrades.published({ class_id: classId.value, assessment_id: col.id, subject_label: subject.value.label, label: col.title });
      notice.value = t('secg.notified', { count: res.notified });
    }
    grid.value = await secondaryGrades.grid(classId.value, periodId.value);
  } catch (e) {
    if (e instanceof ApiError && e.status === 403 && /authentification/i.test(e.message)) notice.value = t('secg.secondFactor');
    else error.value = e;
  }
}

async function sendCorrection(): Promise<void> {
  if (!correctionFor.value || correctionForm.reason.trim() === '') return;
  const target = correctionFor.value;
  const value = correctionForm.value.trim() === '' ? null : Number(correctionForm.value.replace(',', '.'));
  const ok = await run(() => secondaryGrades.requestCorrection(target.gradeId, value, correctionForm.reason.trim()));
  if (ok !== undefined) {
    notice.value = t('secg.correctionSent');
    correctionFor.value = null;
    correctionForm.value = '';
    correctionForm.reason = '';
  }
}

async function loadCorrections(): Promise<void> {
  await run(async () => (corrections.value = await secondaryGrades.corrections('pending')));
}

async function decideCorrection(id: string, approve: boolean): Promise<void> {
  await run(() => (approve ? secondaryGrades.approveCorrection(id) : secondaryGrades.rejectCorrection(id)));
  await loadCorrections();
}

async function downloadTemplate(): Promise<void> {
  if (!importForm.assessmentId) return;
  const csv = await run(() => secondaryGrades.template(importForm.assessmentId));
  if (csv !== undefined) saveBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'modele-notes.csv');
}

function onFile(event: Event): void {
  importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function sendImport(): Promise<void> {
  const file = importForm.file;
  if (!file || !importForm.assessmentId) return;
  await checkEntry();
  if (!allowed.value) return;
  // Fichier joint (multipart, champ « file ») : l'hébergeur refuse un corps brut text/csv.
  const res = await run(() => secondaryGrades.importFile(importForm.assessmentId, file, file.name));
  if (res) {
    notice.value = t('secg.imported', res);
    grid.value = await secondaryGrades.grid(classId.value, periodId.value);
  }
}

async function loadStatement(): Promise<void> {
  if (!classId.value || !periodId.value) return;
  await run(async () => (statement.value = await secondaryGrades.statement(classId.value, periodId.value)));
}

async function loadCommon(): Promise<void> {
  await run(async () => (commonTests.value = await secondaryGrades.commonTests({ 'filter[academic_year_id]': year.value || undefined })));
}

async function createCommon(): Promise<void> {
  if (!sheet.value || !subjectId.value || commonForm.title.trim() === '') return;
  const created = await run(() => secondaryGrades.createCommonTest({
    academic_year_id: sheet.value?.class.academic_year_id ?? year.value, level_code: sheet.value?.class.level_code ?? '', subject_id: subjectId.value,
    period_id: periodId.value, title: commonForm.title.trim(), held_on: commonForm.held_on || null,
  }));
  if (created) {
    commonForm.title = '';
    await loadCommon();
  }
}

async function setCommonStatus(test: CommonTest, status: CommonTest['status']): Promise<void> {
  await run(() => secondaryGrades.updateCommonTest(test.id, { status, assessment_ids: test.assessment_ids }));
  await loadCommon();
}

async function loadFrauds(): Promise<void> {
  await run(async () => (frauds.value = await secondaryGrades.fraudReports('open')));
}

async function handleFraud(id: string): Promise<void> {
  const outcome = (outcomes[id] ?? '').trim();
  if (outcome === '') return;
  await run(() => secondaryGrades.handleFraud(id, outcome));
  await loadFrauds();
}

async function loadSettings(): Promise<void> {
  await run(async () => (settings.value = await secondaryGrades.settings()));
}

async function preset(): Promise<void> {
  const res = await run(() => secondaryGrades.preset());
  if (res) {
    settings.value = res;
    notice.value = t('secg.presetDone', { applied: (res.applied ?? []).join(', ') || '—', kept: (res.kept ?? []).join(', ') || '—' });
  }
}

async function remindLate(): Promise<void> {
  if (!classId.value || !periodId.value || !sheet.value) return;
  const late = sheet.value.subjects.filter((s) => !(grid.value?.columns ?? []).some((c) => c.subject_id === s.subject_id)).map((s) => s.subject_id);
  if (late.length === 0) return;
  const res = await run(() => secondaryGrades.lateReminders({ class_id: classId.value, period_id: periodId.value, due_on: lateForm.due_on, subject_ids: late }));
  if (res) notice.value = `${t('secg.notified', { count: res.notified })} ${t('secg.withoutTeacher', { count: res.without_teacher.length })}`;
}

function print(): void {
  window.print();
}

watch(year, async (y) => {
  classes.value = y ? ((await run(() => secondarySetup.classes(y))) ?? []) : [];
});
watch([classId, periodId], () => void loadSheet());
watch([subjectId, classId], () => void checkEntry());
watch(tab, (next) => {
  notice.value = null;
  if (next === 'statement') void loadStatement();
  if (next === 'corrections') void loadCorrections();
  if (next === 'common') void loadCommon();
  if (next === 'fraud') void loadFrauds();
  if (next === 'settings') void loadSettings();
});
onMounted(loadYears);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secg.title') }}</h1>
      <p class="lede">{{ t('secg.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>

    <div class="filters">
      <div class="field">
        <label for="secg-year">{{ t('secg.year') }}</label>
        <select id="secg-year" v-model="year">
          <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="secg-class">{{ t('secg.class') }}</label>
        <select id="secg-class" v-model="classId">
          <option value="">{{ t('secg.choose') }}</option>
          <option v-for="c in classes" :key="c.id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="secg-period">{{ t('secg.period') }}</label>
        <select id="secg-period" v-model="periodId">
          <option value="">{{ t('secg.choose') }}</option>
          <option v-for="p in sequences" :key="p.id" :value="p.id">{{ periodLabel(p) }}</option>
        </select>
      </div>
      <div v-if="sheet" class="field">
        <label for="secg-subject">{{ t('secg.subject') }}</label>
        <select id="secg-subject" v-model="subjectId">
          <option v-for="s in sheet.subjects" :key="s.subject_id" :value="s.subject_id">
            {{ s.label }} ({{ t('secg.coef', { coef: s.coefficient }) }}){{ s.can_enter ? '' : ` · ${t('secg.notAssigned')}` }}
          </option>
        </select>
      </div>
    </div>

    <nav class="tabs" :aria-label="t('secg.title')">
      <button v-for="item in tabs" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`secg.tab.${item}`) }}</button>
    </nav>

    <section v-if="tab === 'grid'" class="card" aria-labelledby="secg-grid">
      <h2 id="secg-grid">{{ t('secg.tab.grid') }}</h2>
      <template v-if="sheet">
        <p class="muted">{{ offlineEntry ? t('secg.offlineOn') : t('secg.offlineOff') }} <span v-if="step">{{ t('secg.step', { step }) }}</span></p>
        <p v-if="columns.length === 0" class="muted">{{ t('secg.noAssessment') }}</p>
        <article v-for="col in columns" :key="col.id" class="column">
          <header class="column-head">
            <h3>{{ col.title }} <span class="muted small">/ {{ col.max_score }} · {{ t('secg.status', { status: t(stageKey(col.status)) }) }}</span></h3>
            <div class="actions">
              <button v-if="col.status === 'submitted' && canValidate" type="button" class="btn" :disabled="busy" @click="move(col, 'draft')">{{ t('secg.move.draft') }}</button>
              <button v-if="nextStage(col.status) && (nextStage(col.status) === 'submitted' ? allowed : canValidate)" type="button" class="btn btn--primary" :disabled="busy" @click="move(col, nextStage(col.status) ?? '')">
                {{ t(`secg.move.${nextStage(col.status)}` as MessageKey) }}
              </button>
            </div>
          </header>
          <ul class="rows">
            <li v-for="row in grid?.rows ?? []" :key="row.person_id" class="cell-row">
              <span class="name">{{ row.name }}</span>
              <template v-if="editable(col.status) && allowed">
                <label class="sr-only" :for="`v-${col.id}-${row.person_id}`">{{ row.name }}</label>
                <input :id="`v-${col.id}-${row.person_id}`" class="score" inputmode="decimal" :value="cellValue(row, col)" :disabled="busy || absenceOf(row, col) !== ''" @change="enter(row, col, ($event.target as HTMLInputElement).value, null)" />
                <label class="sr-only" :for="`a-${col.id}-${row.person_id}`">{{ t('secg.absence') }}</label>
                <select :id="`a-${col.id}-${row.person_id}`" :value="absenceOf(row, col)" :disabled="busy" @change="enter(row, col, '', ($event.target as HTMLSelectElement).value || null)">
                  <option value="">{{ t('secg.none') }}</option>
                  <option v-for="c in absenceCodes" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>
              </template>
              <template v-else>
                <strong>{{ cellValue(row, col) || absenceOf(row, col) || t('secg.none') }}</strong>
                <span v-if="letterFor(row.cells[col.id]?.value ?? null, sheet.settings.letter_scale)" class="tag">{{ letterFor(row.cells[col.id]?.value ?? null, sheet.settings.letter_scale) }}</span>
                <button v-if="!editable(col.status) && row.cells[col.id]?.grade_id && canEnter" type="button" class="btn small" @click="correctionFor = { gradeId: row.cells[col.id]?.grade_id ?? '', label: `${row.name} · ${col.title}` }">{{ t('secg.askCorrection') }}</button>
              </template>
              <p v-if="cellErrors[`${col.id}|${row.person_id}`]" class="error small" role="alert">{{ cellErrors[`${col.id}|${row.person_id}`] }}</p>
            </li>
          </ul>
        </article>
        <form v-if="correctionFor" class="grid card" @submit.prevent="sendCorrection">
          <h3>{{ t('secg.askCorrection') }} · {{ correctionFor.label }}</h3>
          <p class="muted">{{ t('secg.locked') }}</p>
          <div class="field"><label for="cor-value">{{ t('secg.newValue') }}</label><input id="cor-value" v-model="correctionForm.value" inputmode="decimal" /></div>
          <div class="field"><label for="cor-reason">{{ t('secg.reason') }}</label><input id="cor-reason" v-model="correctionForm.reason" maxlength="255" required /></div>
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secg.send') }}</button>
        </form>
        <form v-if="allowed" class="grid" @submit.prevent="createAssessment">
          <h3>{{ t('secg.newAssessment') }}</h3>
          <div class="field"><label for="na-title">{{ t('secg.assessmentTitle') }}</label><input id="na-title" v-model="newAssessment.title" maxlength="160" required /></div>
          <div class="field">
<label for="na-kind">{{ t('secg.kind') }}</label>
            <select id="na-kind" v-model="newAssessment.kind"><option value="devoir">devoir</option><option value="interrogation">interrogation</option><option value="tp">tp</option><option value="composition">composition</option></select>
          </div>
          <div class="field"><label for="na-weight">{{ t('secg.weight') }}</label><input id="na-weight" v-model="newAssessment.weight" inputmode="decimal" /></div>
          <div class="field"><label for="na-max">{{ t('secg.max') }}</label><input id="na-max" v-model="newAssessment.max" inputmode="decimal" /></div>
          <button type="submit" class="btn" :disabled="busy">{{ t('secg.create') }}</button>
        </form>
      </template>
    </section>

    <section v-if="tab === 'import'" class="card" aria-labelledby="secg-import">
      <h2 id="secg-import">{{ t('secg.tab.import') }}</h2>
      <p class="muted">{{ t('secg.importIntro') }}</p>
      <form class="grid" @submit.prevent="sendImport">
        <div class="field">
          <label for="imp-a">{{ t('secg.importAssessment') }}</label>
          <select id="imp-a" v-model="importForm.assessmentId">
            <option value="">{{ t('secg.choose') }}</option>
            <option v-for="c in columns.filter((c) => editable(c.status))" :key="c.id" :value="c.id">{{ c.title }}</option>
          </select>
        </div>
        <button type="button" class="btn" :disabled="busy || !importForm.assessmentId" @click="downloadTemplate">{{ t('secg.template') }}</button>
        <div class="field"><label for="imp-file">{{ t('secg.file') }}</label><input id="imp-file" type="file" accept=".csv,text/csv" @change="onFile" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy || !importForm.file || !importForm.assessmentId">{{ t('secg.import') }}</button>
      </form>
    </section>

    <section v-if="tab === 'statement'" class="card" aria-labelledby="secg-statement">
      <h2 id="secg-statement">{{ t('secg.tab.statement') }}</h2>
      <p class="muted">{{ t('secg.statementIntro') }}</p>
      <button type="button" class="btn" @click="print">{{ t('secg.print') }}</button>
      <div v-if="statement" class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('secg.learner') }}</th><th v-for="s in sheet?.subjects ?? []" :key="s.subject_id" scope="col">{{ s.code }}</th><th scope="col">{{ t('secg.average') }}</th><th scope="col">{{ t('secg.rank') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in statement.rows" :key="r.person_id">
              <th scope="row">{{ r.name }}</th>
              <td v-for="s in sheet?.subjects ?? []" :key="s.subject_id">
                {{ r.subjects.find((m) => m.subject_id === s.subject_id)?.average ?? '—' }}
                <span v-if="statement.letters_enabled" class="small">{{ r.subjects.find((m) => m.subject_id === s.subject_id)?.letter ?? '' }}</span>
              </td>
              <td>{{ r.average ?? '—' }}</td>
              <td>{{ r.rank ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
        <ul class="rows small">
          <li v-for="s in statement.subjects" :key="s.subject_id">{{ sheet?.subjects.find((x) => x.subject_id === s.subject_id)?.label ?? s.subject_id }} : {{ t('secg.classAverage', { avg: s.class_avg, min: s.min, max: s.max }) }}</li>
        </ul>
      </div>
    </section>

    <section v-if="tab === 'corrections'" class="card" aria-labelledby="secg-cor">
      <h2 id="secg-cor">{{ t('secg.tab.corrections') }}</h2>
      <p v-if="corrections.length === 0" class="muted">{{ t('secg.noCorrection') }}</p>
      <ul class="rows">
        <li v-for="c in corrections" :key="c.id">
          <strong>{{ c.new_score ?? c.new_absence ?? '—' }}</strong> · {{ c.reason }}
          <span class="muted small">{{ t('secg.step.label', { current: c.approval_step + 1, total: c.steps_total }) }}{{ c.current_step ? ` · ${c.current_step}` : '' }}</span>
          <div v-if="canValidate || can('secondary.reportcard.validate')" class="actions">
            <button type="button" class="btn btn--primary" :disabled="busy" @click="decideCorrection(c.id, true)">{{ t('secg.approve') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="decideCorrection(c.id, false)">{{ t('secg.reject') }}</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="tab === 'common'" class="card" aria-labelledby="secg-common">
      <h2 id="secg-common">{{ t('secg.tab.common') }}</h2>
      <p v-if="commonTests.length === 0" class="muted">{{ t('secg.noCommon') }}</p>
      <ul class="rows">
        <li v-for="c in commonTests" :key="c.id">
          <strong>{{ c.title }}</strong> <span class="muted small">· {{ c.level_code }} · {{ c.held_on ?? '' }} · {{ t(`secg.common.${c.status}` as MessageKey) }}</span>
          <div v-if="canValidate && c.status !== 'closed'" class="actions">
            <button v-if="c.status === 'planned'" type="button" class="btn" :disabled="busy" @click="setCommonStatus(c, 'held')">{{ t('secg.markHeld') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="setCommonStatus(c, 'closed')">{{ t('secg.close') }}</button>
          </div>
        </li>
      </ul>
      <form v-if="canValidate && sheet" class="grid" @submit.prevent="createCommon">
        <div class="field"><label for="cm-title">{{ t('secg.commonTitle') }}</label><input id="cm-title" v-model="commonForm.title" maxlength="160" required /></div>
        <div class="field"><label for="cm-date">{{ t('secg.heldOn') }}</label><input id="cm-date" v-model="commonForm.held_on" type="date" /></div>
        <p class="muted small">{{ t('secg.level') }} : {{ sheet.class.level_code }} · {{ subject?.label ?? '' }}</p>
        <button type="submit" class="btn" :disabled="busy || !subjectId">{{ t('secg.create') }}</button>
      </form>
    </section>

    <section v-if="tab === 'fraud'" class="card" aria-labelledby="secg-fraud">
      <h2 id="secg-fraud">{{ t('secg.tab.fraud') }}</h2>
      <p v-if="frauds.length === 0" class="muted">{{ t('secg.noFraud') }}</p>
      <ul class="rows">
        <li v-for="f in frauds" :key="f.id">
          <strong>{{ f.person_id }}</strong> <span class="muted small">· {{ f.created_at.slice(0, 10) }} · {{ f.note ?? '' }}</span>
          <form v-if="can('secondary.discipline.manage', 'secondary.discipline.validate')" class="actions" @submit.prevent="handleFraud(f.id)">
            <label class="sr-only" :for="`fo-${f.id}`">{{ t('secg.outcome') }}</label>
            <input :id="`fo-${f.id}`" v-model="outcomes[f.id]" :placeholder="t('secg.outcome')" maxlength="500" />
            <button type="submit" class="btn" :disabled="busy">{{ t('secg.handle') }}</button>
          </form>
        </li>
      </ul>
    </section>

    <section v-if="tab === 'settings'" class="card" aria-labelledby="secg-settings">
      <h2 id="secg-settings">{{ t('secg.tab.settings') }}</h2>
      <p class="muted">{{ t('secg.settingsIntro') }}</p>
      <ul v-if="settings" class="rows small">
        <li v-for="(set, key) in settings.configured" :key="key"><code>{{ key }}</code> : {{ set ? t('secg.configured') : t('secg.notConfigured') }}</li>
        <li>{{ t('secg.letters', { value: settings.letter_scale.length ? settings.letter_scale.map((b) => `${b.letter} ≥ ${b.min}`).join(', ') : t('secg.lettersOff') }) }}</li>
      </ul>
      <button v-if="canSettings" type="button" class="btn btn--primary" :disabled="busy" @click="preset">{{ t('secg.preset') }}</button>
      <form v-if="canValidate && sheet" class="grid" @submit.prevent="remindLate">
        <h3>{{ t('secg.lateTitle') }}</h3>
        <div class="field"><label for="late-due">{{ t('secg.dueOn') }}</label><input id="late-due" v-model="lateForm.due_on" type="date" required /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('secg.remind') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.filters { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
.tab { border: 1px solid var(--border, #ccc); background: transparent; border-radius: 999px; padding: 6px 12px; min-height: 44px; }
.tab[aria-pressed='true'] { background: var(--accent, #1d4ed8); color: #fff; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.grid { display: grid; gap: 8px; margin-top: 12px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.column { margin-top: 12px; }
.column-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
.cell-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.name { flex: 1 1 140px; }
.score { width: 5.5rem; min-height: 44px; }
.small { font-size: 0.875rem; }
.error { color: var(--danger, #b91c1c); flex-basis: 100%; }
.table-wrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th, td { border-bottom: 1px solid var(--border, #ddd); padding: 4px 6px; text-align: left; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
input, select { max-width: 100%; }
</style>
