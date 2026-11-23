<script setup lang="ts">
// Cahier de textes du secondaire (T862, T863, T978) : séance signée par l'enseignant (API-06), gardée sur l'appareil sans réseau et
// renvoyée avec le même client_uuid (idempotent) ; couverture des programmes et rapport DOC-26 ; progressions (animateur) ; cahier de
// la classe DOC-25 et visas (censeur, animateur) ; rappels NOT-12. Mobile d'abord ; le serveur reste juge des droits.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import type { AcademicYear } from '@/api/admin';
import { secondarySetup, type SchoolClass, type Subject } from '@/api/secondarySetup';
import {
  formatRate, newClientUuid, secondarySyllabus, secondaryTimetable,
  type Assignment, type ClassCoverage, type CoverageReport, type LessonDraft, type LessonLogDraft, type Logbook, type Progression, type Reminders,
} from '@/api/secondaryTimetable';
import { useI18n } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

type Tab = 'log' | 'coverage' | 'progressions' | 'logbook';
const QUEUE_KEY = 'scholaris.secondary.lessonQueue';
const READ = ['secondary.syllabus.read', 'secondary.syllabus.manage', 'secondary.syllabus.visa', 'secondary.inspection.read'] as const;

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canLog = computed(() => can('secondary.lesson.log'));
const canRead = computed(() => can(...READ));
const canManage = computed(() => can('secondary.syllabus.manage'));
const canVisa = computed(() => can('secondary.syllabus.visa', 'secondary.syllabus.manage'));
const canRemind = computed(() => can('secondary.syllabus.visa', 'secondary.syllabus.manage', 'secondary.timetable.manage'));
const tabs = computed<Tab[]>(() => [
  ...(canLog.value ? (['log'] as Tab[]) : []),
  ...(canRead.value || canLog.value ? (['coverage', 'logbook'] as Tab[]) : []),
  ...(canRead.value ? (['progressions'] as Tab[]) : []),
]);
const tab = ref<Tab>('log');
const done = ref<string | null>(null);
const today = new Date().toISOString().slice(0, 10);

const years = ref<AcademicYear[]>([]);
const year = ref('');
const classes = ref<SchoolClass[]>([]);
const subjects = ref<Subject[]>([]);
const assignments = ref<Assignment[]>([]);
const progressions = ref<Progression[]>([]);
const coverage = ref<ClassCoverage | null>(null);
const report = ref<CoverageReport | null>(null);
const logbook = ref<Logbook | null>(null);
const reminders = ref<Reminders | null>(null);
const queue = ref<LessonLogDraft[]>(readQueue());

const logForm = reactive({ assignment_id: '', session_date: today, hours: 1, content: '', homework: '', lesson_id: '' });
const coverageForm = reactive({ class_id: '', year_start: '' });
const progressionForm = reactive({ subject_ref: '', level_code: '', lessons: [] as LessonDraft[] });
const lessonDraft = reactive({ title: '', planned_hours: 1, planned_week: 1 });
const visaForm = reactive({ subject_ref: '', remark: '' });

const className = (id: string): string => classes.value.find((c) => c.structure_id === id)?.label ?? id;
const selected = computed(() => assignments.value.find((a) => a.id === logForm.assignment_id) ?? null);
const classIds = computed(() => [...new Set([...classes.value.map((c) => c.structure_id), ...assignments.value.map((a) => a.class_id)])]);

function readQueue(): LessonLogDraft[] {
  try {
    const raw = window.localStorage.getItem(QUEUE_KEY);
    const parsed: unknown = raw === null ? [] : JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as LessonLogDraft[]) : [];
  } catch {
    return [];
  }
}
function saveQueue(): void {
  try {
    window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue.value));
  } catch {
    // stockage indisponible : la file reste en mémoire pour cette session
  }
}
async function quiet<T>(action: () => Promise<T>): Promise<T | undefined> {
  try {
    return await action();
  } catch {
    return undefined;
  }
}

async function load(): Promise<void> {
  years.value = (await quiet(() => secondarySetup.years())) ?? [];
  if (year.value === '' && years.value.length > 0) year.value = years.value.find((y) => y.status === 'open')?.id ?? years.value[0]?.id ?? '';
  subjects.value = (await quiet(() => secondarySetup.subjects())) ?? [];
  const first = tabs.value[0];
  if (first !== undefined) tab.value = first;
  if (queue.value.length > 0) await flush();
}
async function loadYear(): Promise<void> {
  if (year.value === '') return;
  classes.value = (await quiet(() => secondarySetup.classes(year.value))) ?? [];
  assignments.value = (await quiet(() => secondaryTimetable.assignments(year.value))) ?? [];
  if (canRead.value) progressions.value = (await run(() => secondarySyllabus.progressions(year.value))) ?? [];
}

/** Envoie la file : retirée si le serveur répond (succès ou refus affiché), gardée si le réseau manque. */
async function flush(): Promise<void> {
  error.value = null;
  const pending = [...queue.value];
  for (const draft of pending) {
    try {
      await secondarySyllabus.log(draft);
      queue.value = queue.value.filter((d) => d.client_uuid !== draft.client_uuid);
      done.value = t('secsy.sent');
    } catch (e) {
      if (e instanceof ApiError && e.network) break;
      queue.value = queue.value.filter((d) => d.client_uuid !== draft.client_uuid);
      error.value = e;
    }
  }
  saveQueue();
}
async function sign(): Promise<void> {
  const a = selected.value;
  if (a === null) return;
  queue.value = [...queue.value, {
    client_uuid: newClientUuid(), class_id: a.class_id, subject_ref: a.subject_ref, session_date: logForm.session_date, hours: Number(logForm.hours),
    content: logForm.content.trim(), homework: logForm.homework.trim() === '' ? null : logForm.homework.trim(), lesson_id: logForm.lesson_id === '' ? null : logForm.lesson_id,
  }];
  saveQueue();
  busy.value = true;
  await flush();
  busy.value = false;
  if (queue.value.length === 0 && error.value === null) Object.assign(logForm, { content: '', homework: '', lesson_id: '' });
}

async function showCoverage(): Promise<void> {
  if (coverageForm.class_id === '') return;
  report.value = null;
  coverage.value = (await run(() => secondarySyllabus.coverage(year.value, coverageForm.class_id, coverageForm.year_start))) ?? null;
}
async function showReport(): Promise<void> {
  const loaded = await run(() => secondarySyllabus.report(year.value, coverageForm.year_start));
  if (loaded) {
    report.value = loaded;
    setTimeout(() => window.print(), 50);
  }
}
async function remind(): Promise<void> {
  reminders.value = (await run(() => secondarySyllabus.remind(year.value))) ?? null;
}

function addLesson(): void {
  if (lessonDraft.title.trim() === '') return;
  progressionForm.lessons = [...progressionForm.lessons, { title: lessonDraft.title.trim(), planned_hours: Number(lessonDraft.planned_hours), planned_week: Number(lessonDraft.planned_week) }];
  lessonDraft.title = '';
}
async function createProgression(): Promise<void> {
  const created = await run(() => secondarySyllabus.createProgression({ academic_year_id: year.value, subject_ref: progressionForm.subject_ref, level_code: progressionForm.level_code.trim(), lessons: progressionForm.lessons }));
  if (created) {
    progressions.value = [...progressions.value, created];
    Object.assign(progressionForm, { lessons: [], level_code: '' });
    done.value = t('sectt.saved');
  }
}
async function approve(p: Progression): Promise<void> {
  const updated = await run(() => secondarySyllabus.approve(p.id));
  if (updated) progressions.value = progressions.value.map((x) => (x.id === updated.id ? updated : x));
}

async function showLogbook(): Promise<void> {
  if (coverageForm.class_id === '') return;
  logbook.value = (await run(() => secondarySyllabus.logbook(year.value, coverageForm.class_id))) ?? null;
}
async function visa(): Promise<void> {
  if (await run(() => secondarySyllabus.visa({ academic_year_id: year.value, class_id: coverageForm.class_id, subject_ref: visaForm.subject_ref, ...(visaForm.remark.trim() === '' ? {} : { remark: visaForm.remark.trim() }) }))) {
    visaForm.remark = '';
    done.value = t('secsy.visaDone');
    await showLogbook();
  }
}
function printPage(): void {
  window.print();
}

watch(year, loadYear);
watch(tab, () => {
  done.value = null;
});
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head no-print">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secsy.title') }}</h1>
      <p class="lede">{{ t('secsy.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success no-print" role="status">{{ done }}</p>
    <div v-if="queue.length > 0" class="notice notice--warning no-print" role="status">
      <p>{{ t('secsy.queued', { n: queue.length }) }}</p>
      <button type="button" class="btn" :disabled="busy" @click="flush">{{ t('secsy.flush') }}</button>
    </div>

    <div v-if="years.length > 0" class="field no-print">
      <label for="sy-year">{{ t('sectt.year') }}</label>
      <select id="sy-year" v-model="year"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
    </div>
    <nav v-if="tabs.length > 1" class="tabs no-print" :aria-label="t('secsy.title')">
      <button v-for="item in tabs" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`secsy.tab.${item}`) }}</button>
    </nav>

    <section v-if="tab === 'log' && canLog" class="card" aria-labelledby="sy-log">
      <h2 id="sy-log">{{ t('secsy.tab.log') }}</h2>
      <form class="grid" @submit.prevent="sign">
        <div class="field">
          <label for="lg-as">{{ t('secsy.class') }} · {{ t('secsy.subject') }}</label>
          <select id="lg-as" v-model="logForm.assignment_id" required>
            <option v-for="a in assignments" :key="a.id" :value="a.id">{{ className(a.class_id) }}<template v-if="a.group_code"> ({{ a.group_code }})</template> · {{ a.subject_label }}</option>
          </select>
        </div>
        <div class="field"><label for="lg-date">{{ t('secsy.date') }}</label><input id="lg-date" v-model="logForm.session_date" type="date" :max="today" required /></div>
        <div class="field"><label for="lg-hours">{{ t('secsy.hours') }}</label><input id="lg-hours" v-model.number="logForm.hours" type="number" min="0.25" max="12" step="0.25" inputmode="decimal" required /></div>
        <div class="field"><label for="lg-content">{{ t('secsy.content') }}</label><textarea id="lg-content" v-model="logForm.content" maxlength="5000" rows="4" required /></div>
        <div class="field"><label for="lg-home">{{ t('secsy.homework') }}</label><textarea id="lg-home" v-model="logForm.homework" maxlength="5000" rows="2" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy || selected === null">{{ t('secsy.signSend') }}</button>
      </form>
    </section>

    <section v-if="tab === 'coverage'" class="card" aria-labelledby="sy-cov">
      <h2 id="sy-cov" class="no-print">{{ t('secsy.tab.coverage') }}</h2>
      <form class="grid no-print" @submit.prevent="showCoverage">
        <div class="field"><label for="cv-class">{{ t('secsy.class') }}</label><select id="cv-class" v-model="coverageForm.class_id" required><option v-for="id in classIds" :key="id" :value="id">{{ className(id) }}</option></select></div>
        <div class="field"><label for="cv-start">{{ t('secsy.yearStart') }}</label><input id="cv-start" v-model="coverageForm.year_start" type="date" /></div>
        <div class="actions">
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsy.show') }}</button>
          <button v-if="canRead" type="button" class="btn" :disabled="busy || year === ''" @click="showReport">{{ t('secsy.report') }}</button>
          <button v-if="canRemind" type="button" class="btn" :disabled="busy || year === ''" @click="remind">{{ t('secsy.remind') }}</button>
        </div>
      </form>
      <div v-if="reminders" class="notice no-print">
        <p>{{ t('secsy.reminded', { n: reminders.notified, days: reminders.reminder_days }) }}</p>
        <ul><li v-for="l in reminders.late" :key="l.assignment_id">{{ t('secsy.late', { subject: l.subject_label, class: l.class_label, days: l.days }) }}</li></ul>
      </div>
      <template v-for="block in (report ? report.classes : coverage ? [coverage] : [])" :key="block.class_id">
        <h3>{{ block.class_label }}</h3>
        <p v-if="block.lines.length === 0" class="muted">{{ t('secsy.noCoverage') }}</p>
        <ul class="rows">
          <li v-for="l in block.lines" :key="l.assignment_id">
            <strong>{{ l.subject_label }}</strong><template v-if="l.group_code"> ({{ l.group_code }})</template> · {{ formatRate(l.rate) }}
            <span class="muted">· {{ t('secsy.done') }} {{ l.done_hours }} h · {{ t('secsy.planned') }} {{ l.planned_hours ?? '—' }} h<template v-if="l.expected_hours_to_date !== null"> · {{ t('secsy.expected') }} {{ l.expected_hours_to_date }} h</template> · {{ t('secsy.lessons') }} {{ l.lessons_done }}/{{ l.lessons_total }}</span>
          </li>
        </ul>
      </template>
      <p v-if="report">{{ t('secsy.overall', { done: report.done_hours, planned: report.planned_hours, rate: formatRate(report.rate) }) }}</p>
    </section>

    <section v-if="tab === 'progressions'" class="card" aria-labelledby="sy-prog">
      <h2 id="sy-prog">{{ t('secsy.tab.progressions') }}</h2>
      <p v-if="progressions.length === 0" class="muted">{{ t('secsy.noProgressions') }}</p>
      <ul class="rows">
        <li v-for="p in progressions" :key="p.id">
          <strong>{{ p.subject_label }}</strong> · {{ p.level_code }} <span class="muted">· {{ t('secsy.totalHours', { n: p.total_hours }) }} · {{ t(`secsy.status.${p.status}`) }}</span>
          <div v-if="canManage && p.status === 'draft'" class="actions"><button type="button" class="btn" :disabled="busy" @click="approve(p)">{{ t('secsy.approve') }}</button></div>
        </li>
      </ul>
      <form v-if="canManage" class="grid" @submit.prevent="createProgression">
        <h3>{{ t('secsy.createProgression') }}</h3>
        <div class="field"><label for="pg-subject">{{ t('secsy.subject') }}</label><select id="pg-subject" v-model="progressionForm.subject_ref" required><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.code }} · {{ s.label }}</option></select></div>
        <div class="field"><label for="pg-level">{{ t('secsy.level') }}</label><input id="pg-level" v-model="progressionForm.level_code" maxlength="20" required /></div>
        <ol class="small"><li v-for="(l, i) in progressionForm.lessons" :key="i">{{ l.title }} · {{ l.planned_hours }} h · S{{ l.planned_week }}</li></ol>
        <div class="field"><label for="pg-title">{{ t('secsy.lessonTitle') }}</label><input id="pg-title" v-model="lessonDraft.title" maxlength="200" /></div>
        <div class="field"><label for="pg-hours">{{ t('secsy.plannedHours') }}</label><input id="pg-hours" v-model.number="lessonDraft.planned_hours" type="number" min="0.25" step="0.25" inputmode="decimal" /></div>
        <div class="field"><label for="pg-week">{{ t('secsy.plannedWeek') }}</label><input id="pg-week" v-model.number="lessonDraft.planned_week" type="number" min="1" max="60" inputmode="numeric" /></div>
        <div class="actions">
          <button type="button" class="btn" @click="addLesson">{{ t('secsy.addLesson') }}</button>
          <button type="submit" class="btn btn--primary" :disabled="busy || progressionForm.lessons.length === 0">{{ t('secsy.createProgression') }}</button>
        </div>
      </form>
    </section>

    <section v-if="tab === 'logbook'" class="card" aria-labelledby="sy-book">
      <h2 id="sy-book" class="no-print">{{ t('secsy.logbookDoc') }}</h2>
      <form class="grid no-print" @submit.prevent="showLogbook">
        <div class="field"><label for="lb-class">{{ t('secsy.class') }}</label><select id="lb-class" v-model="coverageForm.class_id" required><option v-for="id in classIds" :key="id" :value="id">{{ className(id) }}</option></select></div>
        <div class="actions">
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsy.show') }}</button>
          <button type="button" class="btn" :disabled="logbook === null" @click="printPage">{{ t('secsy.print') }}</button>
        </div>
      </form>
      <template v-if="logbook">
        <h3>{{ logbook.title }} · {{ logbook.class_label }}</h3>
        <p v-if="logbook.entries.length === 0" class="muted">{{ t('secsy.noEntries') }}</p>
        <ul class="rows">
          <li v-for="e in logbook.entries" :key="e.id">
            <strong>{{ e.session_date }}</strong> · {{ e.subject_label }} · {{ e.hours }} h <span class="muted">· {{ e.teacher_name }}</span>
            <p>{{ e.content }}</p>
            <p v-if="e.homework" class="muted">{{ t('secsy.homework') }} : {{ e.homework }}</p>
          </li>
        </ul>
        <h3>{{ t('secsy.visas') }}</h3>
        <ul class="rows"><li v-for="v in logbook.visas" :key="v.id">{{ v.visa_on }} · {{ t(`secsy.visaRole.${v.visa_role}`) }}<template v-if="v.remark"> · {{ v.remark }}</template></li></ul>
        <form v-if="canVisa" class="grid no-print" @submit.prevent="visa">
          <div class="field"><label for="vs-subject">{{ t('secsy.subject') }}</label><select id="vs-subject" v-model="visaForm.subject_ref" required><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.code }} · {{ s.label }}</option></select></div>
          <div class="field"><label for="vs-remark">{{ t('secsy.remark') }}</label><input id="vs-remark" v-model="visaForm.remark" maxlength="500" /></div>
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsy.visa') }}</button>
        </form>
      </template>
    </section>
  </div>
</template>

<style scoped>
.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
.tab { border: 1px solid var(--border, #ccc); background: transparent; border-radius: 999px; padding: 6px 12px; min-height: 44px; }
.tab[aria-pressed='true'] { background: var(--accent, #1d4ed8); color: #fff; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.grid { display: grid; gap: 8px; margin-top: 12px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.small { font-size: 0.875rem; }
@media print {
  .no-print, .tabs { display: none !important; }
}
</style>
