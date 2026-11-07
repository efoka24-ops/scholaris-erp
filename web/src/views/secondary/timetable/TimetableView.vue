<script setup lang="ts">
// Emploi du temps du secondaire (T860, T861, T978) : vue par classe, enseignant, salle ou atelier et impression DOC-07 ; affectations
// (atelier seulement avec le module workshops) ; plages horaires et disponibilités de l'établissement ; génération simulée puis
// appliquée ; remplacements et permutations notifiés (NOT-14). Mobile d'abord ; le serveur reste juge (409 et 422 affichés).
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { AcademicYear } from '@/api/admin';
import { secondarySetup, type SchoolClass, type Subject } from '@/api/secondarySetup';
import {
  byDay, GROUPS, ROOM_KINDS, TEACHER_KINDS, WEEKDAYS, secondaryTimetable,
  type Assignment, type Band, type Change, type ChangeKind, type Conflict, type DocView, type Entry, type Generation, type GroupCode,
  type RoomKind, type TeacherKind, type TimetableDoc, type Window,
} from '@/api/secondaryTimetable';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

type Tab = 'grid' | 'assignments' | 'bands' | 'generate' | 'changes';
const MANAGE = ['secondary.timetable.manage'] as const;
const READ = ['secondary.timetable.read', 'secondary.timetable.manage', 'secondary.timetable.validate'] as const;

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canManage = computed(() => can(...MANAGE));
const canRead = computed(() => can(...READ));
const tabs = computed<Tab[]>(() => (canManage.value ? ['grid', 'assignments', 'bands', 'generate', 'changes'] : canRead.value ? ['grid', 'changes'] : ['grid']));
const tab = ref<Tab>('grid');
const done = ref<string | null>(null);
const today = new Date().toISOString().slice(0, 10);

const years = ref<AcademicYear[]>([]);
const year = ref('');
const classes = ref<SchoolClass[]>([]);
const subjects = ref<Subject[]>([]);
const assignments = ref<Assignment[]>([]);
const entries = ref<Entry[]>([]);
const conflicts = ref<{ count: number; conflicts: Conflict[] } | null>(null);
const doc = ref<TimetableDoc | null>(null);
const bands = ref<Band[]>([]);
const windows = ref<Window[]>([]);
const generation = ref<Generation | null>(null);
const changes = ref<Change[]>([]);
const checked = ref<Conflict[] | null>(null);

const filter = reactive({ view: 'class' as DocView, id: '', on: today });
const assignForm = reactive({ class_id: '', subject_ref: '', teacher_person_id: '', teacher_kind: 'permanent' as TeacherKind, room_kind: 'classroom' as RoomKind, group_code: '' as GroupCode | '', room_id: '' });
const poseForm = reactive({ assignment_id: '', weekday: 1, start_time: '07:30', end_time: '08:30', valid_from: today, valid_to: '' });
const bandForm = reactive({ weekday: 1, start_time: '07:30', end_time: '08:30' });
const availForm = reactive({ teacher: '', weekday: 1, start_time: '07:30', end_time: '12:30', availability_kind: 'unavailable' as Window['availability_kind'] });
const roomForm = reactive({ class_id: '', room_id: '' });
const genForm = reactive({ valid_from: today, valid_to: '', reason: '', class_ids: [] as string[] });
const changeForm = reactive({ change_kind: 'replacement' as ChangeKind, entry_id: '', other_entry_id: '', replacement_person_id: '', from_date: today, to_date: '', reason: '' });

const className = (id: string): string => classes.value.find((c) => c.structure_id === id)?.label ?? id;
const dayName = (d: number): string => t(`sectt.day.${d}` as MessageKey);
const grouped = computed(() => byDay(entries.value));
const assignmentLabel = (a: Assignment): string => `${className(a.class_id)}${a.group_code ? ` (${a.group_code})` : ''} · ${a.subject_label} · ${a.teacher_person_id}`;
const entryLabel = (e: Entry): string => `${dayName(e.weekday)} ${e.start_time}-${e.end_time} · ${e.class_label ?? className(e.class_id)} · ${e.subject_label}`;

function flash(text: string): void {
  done.value = text;
}

async function loadYear(): Promise<void> {
  if (year.value === '') return;
  if (canManage.value || canRead.value) {
    classes.value = (await quiet(() => secondarySetup.classes(year.value))) ?? [];
  }
  if (canManage.value) {
    assignments.value = (await run(() => secondaryTimetable.assignments(year.value))) ?? [];
    bands.value = (await run(() => secondaryTimetable.bands(year.value)))?.bands ?? [];
  }
  if (canRead.value) changes.value = (await run(() => secondaryTimetable.changes(year.value))) ?? [];
  await show();
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
  if (canManage.value) subjects.value = (await quiet(() => secondarySetup.subjects())) ?? [];
  if (years.value.length === 0) await show();
}

async function show(): Promise<void> {
  doc.value = null;
  const query: Record<string, string> = { on: filter.on };
  if (year.value !== '') query.academic_year_id = year.value;
  if (filter.id !== '') query[filter.view === 'class' ? 'class_id' : filter.view === 'teacher' ? 'teacher_person_id' : 'room_id'] = filter.id;
  entries.value = (await run(() => secondaryTimetable.view(query)))?.entries ?? [];
}
async function print(): Promise<void> {
  if (filter.id === '') return;
  const loaded = await run(() => secondaryTimetable.document(filter.view, filter.id, filter.on));
  if (loaded) {
    doc.value = loaded;
    setTimeout(() => window.print(), 50);
  }
}
async function remove(entry: Entry): Promise<void> {
  if (!window.confirm(t('sectt.removeConfirm'))) return;
  await run(() => secondaryTimetable.removeEntry(entry.id));
  await show();
}
async function checkAll(): Promise<void> {
  conflicts.value = (await run(() => secondaryTimetable.conflicts(year.value))) ?? null;
}

async function assign(): Promise<void> {
  const created = await run(() => secondaryTimetable.createAssignment({
    academic_year_id: year.value, class_id: assignForm.class_id, subject_ref: assignForm.subject_ref, teacher_person_id: assignForm.teacher_person_id.trim(),
    teacher_kind: assignForm.teacher_kind, room_kind: assignForm.room_kind, room_id: assignForm.room_id.trim() === '' ? null : assignForm.room_id.trim(),
    ...(assignForm.group_code === '' ? {} : { group_code: assignForm.group_code }),
  }));
  if (created) {
    assignments.value = [...assignments.value, created];
    Object.assign(assignForm, { teacher_person_id: '', room_id: '', group_code: '' });
    flash(t('sectt.saved'));
  }
}
async function endAssignment(a: Assignment): Promise<void> {
  if (await run(() => secondaryTimetable.endAssignment(a.id))) assignments.value = assignments.value.filter((x) => x.id !== a.id);
}
function poseBody() {
  return { assignment_id: poseForm.assignment_id, weekday: Number(poseForm.weekday), start_time: poseForm.start_time, end_time: poseForm.end_time, valid_from: poseForm.valid_from };
}
async function checkPose(): Promise<void> {
  checked.value = (await run(() => secondaryTimetable.checkEntry(poseBody())))?.conflicts ?? null;
}
async function pose(): Promise<void> {
  checked.value = null;
  if (await run(() => secondaryTimetable.createEntry({ ...poseBody(), valid_to: poseForm.valid_to === '' ? null : poseForm.valid_to }))) {
    flash(t('sectt.saved'));
    await show();
  }
}

function addBand(): void {
  bands.value = [...bands.value, { weekday: Number(bandForm.weekday), start_time: bandForm.start_time, end_time: bandForm.end_time }]
    .sort((a, b) => a.weekday - b.weekday || a.start_time.localeCompare(b.start_time));
}
async function saveBands(): Promise<void> {
  const saved = await run(() => secondaryTimetable.setBands(year.value, bands.value.map(({ weekday, start_time, end_time }) => ({ weekday, start_time, end_time }))));
  if (saved) {
    bands.value = saved.bands;
    flash(t('sectt.saved'));
  }
}
async function loadWindows(): Promise<void> {
  if (availForm.teacher.trim() === '') return;
  windows.value = (await run(() => secondaryTimetable.availabilities(year.value, availForm.teacher.trim())))?.windows ?? [];
}
function addWindow(): void {
  windows.value = [...windows.value, { weekday: Number(availForm.weekday), start_time: availForm.start_time, end_time: availForm.end_time, availability_kind: availForm.availability_kind }];
}
async function saveWindows(): Promise<void> {
  const saved = await run(() => secondaryTimetable.setAvailabilities(year.value, availForm.teacher.trim(), windows.value));
  if (saved) {
    windows.value = saved.windows;
    flash(t('sectt.saved'));
  }
}
async function saveClassRoom(): Promise<void> {
  if (await run(() => secondaryTimetable.setClassRoom(year.value, roomForm.class_id, roomForm.room_id.trim()))) flash(t('sectt.saved'));
}

async function generate(apply: boolean): Promise<void> {
  const result = await run(() => secondaryTimetable.generate({
    academic_year_id: year.value, valid_from: genForm.valid_from, valid_to: genForm.valid_to === '' ? null : genForm.valid_to, apply,
    ...(genForm.class_ids.length > 0 ? { class_ids: genForm.class_ids } : {}), ...(genForm.reason.trim() === '' ? {} : { reason: genForm.reason.trim() }),
  }));
  if (result) {
    generation.value = result;
    if (apply) {
      flash(t('sectt.applied', { n: result.placed.length }));
      await show();
    }
  }
}

async function change(): Promise<void> {
  const created = await run(() => secondaryTimetable.change({
    change_kind: changeForm.change_kind, entry_id: changeForm.entry_id, from_date: changeForm.from_date, to_date: changeForm.to_date === '' ? null : changeForm.to_date,
    reason: changeForm.reason.trim(),
    ...(changeForm.change_kind === 'replacement' ? { replacement_person_id: changeForm.replacement_person_id.trim() } : { other_entry_id: changeForm.other_entry_id }),
  }));
  if (created) {
    changes.value = [created, ...changes.value];
    flash(t('sectt.notified', { n: created.notified_count }));
    await show();
  }
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
      <h1>{{ t('sectt.title') }}</h1>
      <p class="lede">{{ t('sectt.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success no-print" role="status">{{ done }}</p>

    <div v-if="years.length > 0" class="field no-print">
      <label for="tt-year">{{ t('sectt.year') }}</label>
      <select id="tt-year" v-model="year"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
    </div>

    <nav v-if="tabs.length > 1" class="tabs no-print" :aria-label="t('sectt.title')">
      <button v-for="item in tabs" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`sectt.tab.${item}`) }}</button>
    </nav>

    <section v-if="tab === 'grid'" class="card" aria-labelledby="tt-grid">
      <h2 id="tt-grid" class="no-print">{{ t('sectt.tab.grid') }}</h2>
      <p v-if="!canRead" class="muted no-print">{{ t('sectt.own') }}</p>
      <form v-if="canRead" class="grid no-print" @submit.prevent="show">
        <div class="field">
          <label for="tt-view">{{ t('sectt.view') }}</label>
          <select id="tt-view" v-model="filter.view">
            <option value="class">{{ t('sectt.view.class') }}</option>
            <option value="teacher">{{ t('sectt.view.teacher') }}</option>
            <option value="room">{{ t('sectt.view.room') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="tt-id">{{ filter.view === 'class' ? t('sectt.class') : t('sectt.idLabel') }}</label>
          <select v-if="filter.view === 'class'" id="tt-id" v-model="filter.id"><option value="">—</option><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select>
          <input v-else id="tt-id" v-model="filter.id" maxlength="40" />
        </div>
        <div class="field"><label for="tt-on">{{ t('sectt.on') }}</label><input id="tt-on" v-model="filter.on" type="date" /></div>
        <div class="actions">
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sectt.show') }}</button>
          <button type="button" class="btn" :disabled="busy || filter.id === ''" @click="print">{{ t('sectt.print') }}</button>
          <button v-if="year !== ''" type="button" class="btn" :disabled="busy" @click="checkAll">{{ t('sectt.checkConflicts') }}</button>
        </div>
      </form>
      <template v-if="conflicts">
        <p v-if="conflicts.count === 0" class="notice notice--success">{{ t('sectt.conflictsNone') }}</p>
        <div v-else class="notice notice--warning">
          <p>{{ t('sectt.conflictsCount', { n: conflicts.count }) }}</p>
          <ul><li v-for="(c, i) in conflicts.conflicts" :key="i">{{ c.message }}</li></ul>
        </div>
      </template>

      <article v-if="doc" class="print-doc">
        <h2>{{ doc.title }} · {{ doc.holder_label }}</h2>
        <p class="muted">{{ doc.doc }} · {{ doc.on }}</p>
        <div v-for="d in doc.days" :key="d.weekday" class="day">
          <h3>{{ d.label }}</h3>
          <ul class="rows"><li v-for="e in d.entries" :key="e.id">{{ e.start_time }}-{{ e.end_time }} · {{ e.subject_label }} · {{ e.class_label }} · {{ e.teacher_name }}<template v-if="e.room_id"> · {{ e.room_id }}</template></li></ul>
        </div>
        <p class="muted small">{{ doc.notice }}</p>
      </article>

      <p v-if="!doc && entries.length === 0" class="muted">{{ t('sectt.empty') }}</p>
      <div v-for="d in (doc ? [] : grouped)" :key="d.weekday" class="day">
        <h3>{{ dayName(d.weekday) }}</h3>
        <ul class="rows">
          <li v-for="e in d.entries" :key="e.id">
            <strong>{{ e.start_time }}-{{ e.end_time }}</strong> {{ e.subject_label }}<template v-if="e.group_code"> ({{ e.group_code }})</template>
            <span class="muted">· {{ e.class_label ?? className(e.class_id) }} · {{ e.teacher_name || e.teacher_person_id }}<template v-if="e.room_id"> · {{ e.room_id }}</template></span>
            <div v-if="canManage" class="actions no-print"><button type="button" class="btn" :disabled="busy" @click="remove(e)">{{ t('sectt.remove') }}</button></div>
          </li>
        </ul>
      </div>
    </section>

    <section v-if="tab === 'assignments'" class="card" aria-labelledby="tt-assign">
      <h2 id="tt-assign">{{ t('sectt.tab.assignments') }}</h2>
      <p v-if="assignments.length === 0" class="muted">{{ t('sectt.noAssignments') }}</p>
      <ul class="rows">
        <li v-for="a in assignments" :key="a.id">
          {{ assignmentLabel(a) }} <span class="muted">· {{ t(`sectt.teacherKind.${a.teacher_kind}`) }} · {{ t(`sectt.roomKind.${a.room_kind}`) }}</span>
          <div class="actions"><button type="button" class="btn" :disabled="busy" @click="endAssignment(a)">{{ t('sectt.end') }}</button></div>
        </li>
      </ul>
      <form class="grid" @submit.prevent="assign">
        <div class="field"><label for="as-class">{{ t('sectt.class') }}</label><select id="as-class" v-model="assignForm.class_id" required><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select></div>
        <div class="field"><label for="as-subject">{{ t('sectt.subject') }}</label><select id="as-subject" v-model="assignForm.subject_ref" required><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.code }} · {{ s.label }}</option></select></div>
        <div class="field"><label for="as-teacher">{{ t('sectt.teacher') }}</label><input id="as-teacher" v-model="assignForm.teacher_person_id" maxlength="40" required /></div>
        <div class="field"><label for="as-kind">{{ t('sectt.teacherKind') }}</label><select id="as-kind" v-model="assignForm.teacher_kind"><option v-for="k in TEACHER_KINDS" :key="k" :value="k">{{ t(`sectt.teacherKind.${k}`) }}</option></select></div>
        <div class="field">
          <label for="as-room-kind">{{ t('sectt.roomKind') }}</label>
          <select id="as-room-kind" v-model="assignForm.room_kind"><option v-for="k in ROOM_KINDS" :key="k" :value="k">{{ t(`sectt.roomKind.${k}`) }}</option></select>
          <p v-if="assignForm.room_kind === 'workshop'" class="muted small">{{ t('sectt.workshopHelp') }}</p>
        </div>
        <div class="field"><label for="as-group">{{ t('sectt.group') }}</label><select id="as-group" v-model="assignForm.group_code"><option value="">{{ t('sectt.wholeClass') }}</option><option v-for="g in GROUPS" :key="g" :value="g">{{ g }}</option></select></div>
        <div class="field"><label for="as-room">{{ t('sectt.room') }}</label><input id="as-room" v-model="assignForm.room_id" maxlength="40" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sectt.assign') }}</button>
      </form>

      <form class="grid" @submit.prevent="pose">
        <h3>{{ t('sectt.pose') }}</h3>
        <div class="field"><label for="po-as">{{ t('sectt.tab.assignments') }}</label><select id="po-as" v-model="poseForm.assignment_id" required><option v-for="a in assignments" :key="a.id" :value="a.id">{{ assignmentLabel(a) }}</option></select></div>
        <div class="field"><label for="po-day">{{ t('sectt.weekday') }}</label><select id="po-day" v-model.number="poseForm.weekday"><option v-for="d in WEEKDAYS" :key="d" :value="d">{{ dayName(d) }}</option></select></div>
        <div class="field"><label for="po-start">{{ t('sectt.start') }}</label><input id="po-start" v-model="poseForm.start_time" type="time" required /></div>
        <div class="field"><label for="po-end">{{ t('sectt.endTime') }}</label><input id="po-end" v-model="poseForm.end_time" type="time" required /></div>
        <div class="field"><label for="po-from">{{ t('sectt.validFrom') }}</label><input id="po-from" v-model="poseForm.valid_from" type="date" required /></div>
        <div class="field"><label for="po-to">{{ t('sectt.validTo') }}</label><input id="po-to" v-model="poseForm.valid_to" type="date" /></div>
        <p v-if="checked && checked.length === 0" class="notice notice--success">{{ t('sectt.free') }}</p>
        <ul v-if="checked && checked.length > 0" class="notice notice--warning"><li v-for="(c, i) in checked" :key="i">{{ c.message }}</li></ul>
        <div class="actions">
          <button type="button" class="btn" :disabled="busy || poseForm.assignment_id === ''" @click="checkPose">{{ t('sectt.check') }}</button>
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sectt.save') }}</button>
        </div>
      </form>
    </section>

    <section v-if="tab === 'bands'" class="card" aria-labelledby="tt-bands">
      <h2 id="tt-bands">{{ t('sectt.tab.bands') }}</h2>
      <p class="muted">{{ t('sectt.bandsHelp') }}</p>
      <ul class="chips"><li v-for="(b, i) in bands" :key="i" class="tag">{{ dayName(b.weekday) }} {{ b.start_time }}-{{ b.end_time }} <button type="button" class="btn small" :aria-label="t('sectt.remove')" @click="bands = bands.filter((_, j) => j !== i)">×</button></li></ul>
      <form class="grid" @submit.prevent="addBand">
        <div class="field"><label for="bd-day">{{ t('sectt.weekday') }}</label><select id="bd-day" v-model.number="bandForm.weekday"><option v-for="d in WEEKDAYS" :key="d" :value="d">{{ dayName(d) }}</option></select></div>
        <div class="field"><label for="bd-start">{{ t('sectt.start') }}</label><input id="bd-start" v-model="bandForm.start_time" type="time" required /></div>
        <div class="field"><label for="bd-end">{{ t('sectt.endTime') }}</label><input id="bd-end" v-model="bandForm.end_time" type="time" required /></div>
        <div class="actions">
          <button type="submit" class="btn">{{ t('sectt.addBand') }}</button>
          <button type="button" class="btn btn--primary" :disabled="busy" @click="saveBands">{{ t('sectt.save') }}</button>
        </div>
      </form>

      <h3>{{ t('sectt.tab.bands') }} · {{ t('sectt.teacher') }}</h3>
      <p class="muted">{{ t('sectt.availHelp') }}</p>
      <form class="grid" @submit.prevent="addWindow">
        <div class="field"><label for="av-teacher">{{ t('sectt.teacher') }}</label><input id="av-teacher" v-model="availForm.teacher" maxlength="40" required @change="loadWindows" /></div>
        <ul class="chips"><li v-for="(w, i) in windows" :key="i" class="tag">{{ dayName(w.weekday) }} {{ w.start_time }}-{{ w.end_time }} · {{ t(`sectt.availability.${w.availability_kind}`) }} <button type="button" class="btn small" :aria-label="t('sectt.remove')" @click="windows = windows.filter((_, j) => j !== i)">×</button></li></ul>
        <div class="field"><label for="av-day">{{ t('sectt.weekday') }}</label><select id="av-day" v-model.number="availForm.weekday"><option v-for="d in WEEKDAYS" :key="d" :value="d">{{ dayName(d) }}</option></select></div>
        <div class="field"><label for="av-start">{{ t('sectt.start') }}</label><input id="av-start" v-model="availForm.start_time" type="time" /></div>
        <div class="field"><label for="av-end">{{ t('sectt.endTime') }}</label><input id="av-end" v-model="availForm.end_time" type="time" /></div>
        <div class="field"><label for="av-kind">{{ t('sectt.teacherKind') }}</label><select id="av-kind" v-model="availForm.availability_kind"><option value="unavailable">{{ t('sectt.availability.unavailable') }}</option><option value="available">{{ t('sectt.availability.available') }}</option></select></div>
        <div class="actions">
          <button type="button" class="btn" :disabled="busy" @click="loadWindows">{{ t('sectt.load') }}</button>
          <button type="submit" class="btn">{{ t('sectt.addWindow') }}</button>
          <button type="button" class="btn btn--primary" :disabled="busy || availForm.teacher === ''" @click="saveWindows">{{ t('sectt.save') }}</button>
        </div>
      </form>

      <form class="grid" @submit.prevent="saveClassRoom">
        <h3>{{ t('sectt.classRoom') }}</h3>
        <div class="field"><label for="cr-class">{{ t('sectt.class') }}</label><select id="cr-class" v-model="roomForm.class_id" required><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select></div>
        <div class="field"><label for="cr-room">{{ t('sectt.room') }}</label><input id="cr-room" v-model="roomForm.room_id" maxlength="40" required /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sectt.save') }}</button>
      </form>
    </section>

    <section v-if="tab === 'generate'" class="card" aria-labelledby="tt-gen">
      <h2 id="tt-gen">{{ t('sectt.tab.generate') }}</h2>
      <p class="muted">{{ t('sectt.generateHelp') }}</p>
      <form class="grid" @submit.prevent="generate(false)">
        <fieldset class="field">
          <legend>{{ t('sectt.class') }}</legend>
          <label v-for="c in classes" :key="c.structure_id" class="check"><input v-model="genForm.class_ids" type="checkbox" :value="c.structure_id" /> {{ c.label }}</label>
        </fieldset>
        <div class="field"><label for="gn-from">{{ t('sectt.validFrom') }}</label><input id="gn-from" v-model="genForm.valid_from" type="date" required /></div>
        <div class="field"><label for="gn-to">{{ t('sectt.validTo') }}</label><input id="gn-to" v-model="genForm.valid_to" type="date" /></div>
        <div class="field"><label for="gn-reason">{{ t('sectt.reason') }}</label><input id="gn-reason" v-model="genForm.reason" maxlength="255" /></div>
        <div class="actions">
          <button type="submit" class="btn" :disabled="busy">{{ t('sectt.simulate') }}</button>
          <button type="button" class="btn btn--primary" :disabled="busy || generation === null" @click="generate(true)">{{ t('sectt.apply') }}</button>
        </div>
      </form>
      <template v-if="generation">
        <p>{{ t('sectt.placed', { n: generation.placed.length }) }} <span class="muted">{{ t('sectt.perDay', { n: generation.max_same_subject_per_day }) }}</span></p>
        <ul class="rows">
          <li v-for="u in generation.unplaced" :key="u.assignment_id" class="notice notice--warning">
            {{ className(u.class_id) }} · {{ u.reason === 'no_weekly_hours' ? t('sectt.unplacedNoHours', { subject: u.subject_label }) : t('sectt.unplaced', { subject: u.subject_label, missing: u.missing_bands }) }}
          </li>
        </ul>
      </template>
    </section>

    <section v-if="tab === 'changes'" class="card" aria-labelledby="tt-changes">
      <h2 id="tt-changes">{{ t('sectt.tab.changes') }}</h2>
      <p class="muted">{{ t('sectt.changeHelp') }}</p>
      <form v-if="canManage" class="grid" @submit.prevent="change">
        <div class="field">
          <label for="ch-kind">{{ t('sectt.tab.changes') }}</label>
          <select id="ch-kind" v-model="changeForm.change_kind"><option value="replacement">{{ t('sectt.changeKind.replacement') }}</option><option value="permutation">{{ t('sectt.changeKind.permutation') }}</option></select>
        </div>
        <div class="field"><label for="ch-entry">{{ t('sectt.entry') }}</label><select id="ch-entry" v-model="changeForm.entry_id" required><option v-for="e in entries" :key="e.id" :value="e.id">{{ entryLabel(e) }}</option></select></div>
        <div v-if="changeForm.change_kind === 'permutation'" class="field"><label for="ch-other">{{ t('sectt.otherEntry') }}</label><select id="ch-other" v-model="changeForm.other_entry_id" required><option v-for="e in entries" :key="e.id" :value="e.id">{{ entryLabel(e) }}</option></select></div>
        <div v-else class="field"><label for="ch-rep">{{ t('sectt.replacement') }}</label><input id="ch-rep" v-model="changeForm.replacement_person_id" maxlength="40" required /></div>
        <div class="field"><label for="ch-from">{{ t('sectt.fromDate') }}</label><input id="ch-from" v-model="changeForm.from_date" type="date" required /></div>
        <div class="field"><label for="ch-to">{{ t('sectt.toDate') }}</label><input id="ch-to" v-model="changeForm.to_date" type="date" /></div>
        <div class="field"><label for="ch-reason">{{ t('sectt.reason') }}</label><input id="ch-reason" v-model="changeForm.reason" maxlength="255" required /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sectt.save') }}</button>
      </form>
      <p v-if="changes.length === 0" class="muted">{{ t('sectt.noChanges') }}</p>
      <ul class="rows">
        <li v-for="c in changes" :key="c.id">
          <strong>{{ t(`sectt.changeKind.${c.change_kind}`) }}</strong> · {{ className(c.class_id) }} · {{ c.from_date }}<template v-if="c.to_date"> → {{ c.to_date }}</template>
          <span class="muted">· {{ c.reason }} · {{ t('sectt.notified', { n: c.notified_count }) }}</span>
        </li>
      </ul>
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
.chips { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
.day { margin-top: 12px; }
.small { font-size: 0.875rem; }
@media print {
  .no-print, .tabs { display: none !important; }
}
</style>
