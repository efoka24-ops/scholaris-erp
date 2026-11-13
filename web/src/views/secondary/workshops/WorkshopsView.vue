<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import { workshopsApi, type MaterialItem, type PracticalSheet, type SessionLearner, type Workshop, type WorkshopSession, type Workstation } from './workshops';
import { secondaryTimetable, type TeacherOption } from '@/api/secondaryTimetable';
import { classesOf, filterStudents, useStudentDirectory } from '../studentDirectory';
import { workshopMessages } from './workshopMessages';
import { workshopMessagesEn } from './workshopMessagesEn';

const { t: translate, locale } = useI18n();
function t(key: string): string {
  if (key in workshopMessages) {
    const localKey = key as keyof typeof workshopMessages;
    return locale.value === 'en' ? workshopMessagesEn[localKey] : workshopMessages[localKey];
  }
  return translate(key as MessageKey);
}
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canManage = computed(() => can('secondary.workshops.manage'));
const canSession = computed(() => can('secondary.workshops.session', 'secondary.workshops.manage'));
const canStock = computed(() => can('secondary.stock.manage'));
const workshops = ref<Workshop[]>([]);
const selected = ref('');
const stations = ref<Workstation[]>([]);
const sheets = ref<PracticalSheet[]>([]);
const sessions = ref<WorkshopSession[]>([]);
const items = ref<MaterialItem[]>([]);
const label = ref('');
const station = reactive({ label: '', machine: true, ppe: '' });
const sheet = reactive({ title: '', objective: '', base: '20', cap: '', criteria: '[{"code":"work","label":"Travail pratique","points":12}]' });
const material = reactive({ label: '', unit: '', cost: '', quantity: '' });
const session = reactive({ structure: '', teacher: '', date: new Date().toISOString().slice(0, 10) });
// Séance pratique (FIX-SEC) : classe, enseignant et élèves choisis par leur nom ; poste et EPI par élève présent.
const directory = useStudentDirectory();
const teachers = ref<TeacherOption[]>([]);
const sessionClasses = computed(() => classesOf(directory.students.value));
const classStudents = computed(() => (session.structure === '' ? [] : filterStudents(directory.students.value, '', session.structure)));
const present = reactive<Record<string, { here: boolean; station: string; ppe: string }>>({});
watch(classStudents, (list) => {
  for (const s of list) present[s.person_id] ??= { here: true, station: '', ppe: '' };
}, { immediate: true });
const line = (personId: string): { here: boolean; station: string; ppe: string } => present[personId] ?? { here: false, station: '', ppe: '' };
const classLabel = (structureId: string): string => sessionClasses.value.find((c) => c.structure_id === structureId)?.label ?? '—';
const done = ref('');

async function reload(): Promise<void> {
  workshops.value = (await run(() => workshopsApi.workshops())) ?? [];
  const firstWorkshop = workshops.value[0];
  if (selected.value === '' && firstWorkshop) selected.value = firstWorkshop.id;
  await loadWorkshop();
}
async function loadWorkshop(): Promise<void> {
  if (!selected.value) return;
  stations.value = (await run(() => workshopsApi.stations(selected.value))) ?? [];
  sheets.value = (await run(() => workshopsApi.sheets(selected.value))) ?? [];
  sessions.value = (await run(() => workshopsApi.sessions(selected.value))) ?? [];
  items.value = (await run(() => workshopsApi.materials(selected.value))) ?? [];
}
async function createWorkshop(): Promise<void> {
  const created = await run(() => workshopsApi.createWorkshop({ label: label.value.trim() }));
  if (created) { label.value = ''; workshops.value.push(created); selected.value = created.id; done.value = t('secw.saved'); }
}
async function createStation(): Promise<void> {
  const created = await run(() => workshopsApi.createStation({ workshop_id: selected.value, label: station.label.trim(), machine: station.machine, ppe_required: station.ppe.split(',').map((x) => x.trim()).filter(Boolean) }));
  if (created) { stations.value.push(created); station.label = ''; done.value = t('secw.saved'); }
}
async function createSheet(): Promise<void> {
  let criteria: unknown;
  try { criteria = JSON.parse(sheet.criteria); } catch { return; }
  const created = await run(() => workshopsApi.createSheet({ workshop_id: selected.value, title: sheet.title.trim(), objective: sheet.objective.trim(), materials: [], tools: [], safety_rules: [], criteria, base_score: Number(sheet.base), safety_cap: sheet.cap === '' ? null : Number(sheet.cap) }));
  if (created) { sheets.value.unshift(created); done.value = t('secw.saved'); }
}
async function createItem(): Promise<void> {
  const created = await run(() => workshopsApi.createMaterial({ workshop_id: selected.value, label: material.label.trim(), unit_label: material.unit.trim(), unit_cost: Number(material.cost), stock_qty: Number(material.quantity) }));
  if (created) { items.value.push(created); material.label = ''; done.value = t('secw.saved'); }
}
async function issue(item: MaterialItem): Promise<void> {
  const quantity = window.prompt(t('secw.quantity'), '1');
  if (quantity === null || !Number.isFinite(Number(quantity)) || Number(quantity) <= 0) return;
  const result = await run(() => workshopsApi.moveMaterial(item.id, { move_kind: 'out', quantity: Number(quantity) }));
  if (result) { await loadWorkshop(); done.value = `${t('secw.saved')} ${result.total_cost_xaf} XAF`; }
}
async function createSession(): Promise<void> {
  const learners: SessionLearner[] = classStudents.value.filter((s) => line(s.person_id).here).map((s) => {
    const l = line(s.person_id);
    const items = l.ppe.split(',').map((x) => x.trim()).filter(Boolean);
    return { person_id: s.person_id, ...(l.station ? { workstation_id: l.station } : {}), ...(items.length ? { ppe_items: items } : {}) };
  });
  const sheetId = sheets.value[0]?.id;
  if (!sheetId || !session.structure.trim()) return;
  const result = await run(() => workshopsApi.createSession(selected.value, { workshop_id: selected.value, structure_id: session.structure.trim(), sheet_id: sheetId, session_date: session.date, teacher_person_id: session.teacher.trim(), client_uuid: crypto.randomUUID(), learners }));
  if (result) { sessions.value.unshift(result.session); done.value = t('secw.saved'); }
}
watch(selected, loadWorkshop);

onMounted(async () => {
  await reload();
  if (canSession.value) {
    await directory.load();
    teachers.value = await secondaryTimetable.teachers().catch(() => []);
  }
});
</script>

<template>
  <div class="page">
    <header class="page-head"><p class="eyebrow">{{ t('sec.home.eyebrow') }}</p><h1>{{ t('secw.title') }}</h1><p class="lede">{{ t('secw.intro') }}</p></header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <section class="card">
      <h2>{{ t('secw.title') }}</h2>
      <div v-if="workshops.length" class="field"><label for="workshop-select">{{ t('secw.selectWorkshop') }}</label><select id="workshop-select" v-model="selected"><option v-for="entry in workshops" :key="entry.id" :value="entry.id">{{ entry.label }}</option></select></div>
      <form v-if="canManage" class="grid" @submit.prevent="createWorkshop"><div class="field"><label for="workshop-label">{{ t('secw.label') }}</label><input id="workshop-label" v-model="label" required maxlength="160" /></div><button class="btn btn--primary" :disabled="busy">{{ t('secw.create') }}</button></form>
      <div v-if="!workshops.length" class="empty-state">
        <p><strong>{{ t('secw.emptyTitle') }}</strong></p>
        <p class="muted">{{ canManage ? t('secw.emptyManage') : t('secw.emptyRead') }}</p>
        <ol v-if="canManage" class="muted"><li>{{ t('secw.step1') }}</li><li>{{ t('secw.step2') }}</li><li>{{ t('secw.step3') }}</li><li>{{ t('secw.step4') }}</li></ol>
      </div>
    </section>
    <template v-if="selected">
      <section v-if="canManage" class="card"><h2>{{ t('secw.station') }}</h2><ul class="rows"><li v-for="entry in stations" :key="entry.id">{{ entry.label }} · {{ entry.machine ? t('secw.machine') : t('secw.station') }} · EPI: {{ entry.ppe_required }}</li></ul><form class="grid" @submit.prevent="createStation"><div class="field"><label for="station-label">{{ t('secw.stationLabel') }}</label><input id="station-label" v-model="station.label" required /></div><div class="field"><label for="station-ppe">{{ t('secw.ppe') }}</label><input id="station-ppe" v-model="station.ppe" /></div><label class="field"><input v-model="station.machine" type="checkbox" /> {{ t('secw.machine') }}</label><button class="btn" :disabled="busy">{{ t('secw.addStation') }}</button></form></section>
      <section v-if="canManage" class="card"><h2>{{ t('secw.sheets') }}</h2><ul class="rows"><li v-for="entry in sheets" :key="entry.id">{{ entry.title }} · v{{ entry.version_no }} · {{ entry.base_score }} / {{ entry.safety_cap ?? '—' }}</li></ul><form class="grid" @submit.prevent="createSheet"><div class="field"><label for="sheet-title">{{ t('secw.sheetTitle') }}</label><input id="sheet-title" v-model="sheet.title" required /></div><div class="field"><label for="sheet-objective">{{ t('secw.objective') }}</label><input id="sheet-objective" v-model="sheet.objective" required /></div><div class="field"><label for="sheet-criteria">{{ t('secw.criteria') }}</label><textarea id="sheet-criteria" v-model="sheet.criteria" required rows="3" /></div><div class="field"><label for="sheet-base">{{ t('secw.base') }}</label><input id="sheet-base" v-model="sheet.base" type="number" min="0.01" step="0.01" /></div><div class="field"><label for="sheet-cap">{{ t('secw.cap') }}</label><input id="sheet-cap" v-model="sheet.cap" type="number" min="0" step="0.01" /></div><button class="btn" :disabled="busy">{{ t('secw.addSheet') }}</button></form></section>
      <section v-if="canSession" class="card">
        <h2>{{ t('secw.attendanceTitle') }}</h2>
        <p class="notice notice--warning">{{ t('secw.noPpe') }}</p>
        <ul class="rows"><li v-for="entry in sessions" :key="entry.id">{{ entry.session_date }} · {{ classLabel(entry.structure_id) }} · {{ entry.attendance.length }} · {{ entry.attendance.filter((learner) => learner.mode === 'observation').length }} {{ t('secw.observation') }}</li></ul>
        <p v-if="!sheets.length" class="muted">{{ t('secw.sessionNeedsSheet') }}</p>
        <form class="stack" @submit.prevent="createSession">
          <div class="field"><label for="session-class">{{ t('secw.class') }}</label><select id="session-class" v-model="session.structure" required><option v-for="c in sessionClasses" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select></div>
          <div class="field"><label for="session-teacher">{{ t('secw.teacher') }}</label><select id="session-teacher" v-model="session.teacher" required><option v-for="p in teachers" :key="p.person_id" :value="p.person_id">{{ p.name }}</option></select></div>
          <div class="field"><label for="session-date">{{ t('secw.date') }}</label><input id="session-date" v-model="session.date" type="date" required /></div>
          <fieldset v-if="classStudents.length" class="stack">
            <legend>{{ t('secw.learners') }}</legend>
            <div v-for="s in classStudents" :key="s.person_id" class="learner">
              <label class="check"><input v-model="line(s.person_id).here" type="checkbox" /> {{ s.full_name }}</label>
              <template v-if="line(s.person_id).here">
                <label class="sr-only" :for="`session-station-${s.person_id}`">{{ t('secw.workstation') }}</label>
                <select :id="`session-station-${s.person_id}`" v-model="line(s.person_id).station"><option value="">{{ t('secw.noStation') }}</option><option v-for="w in stations" :key="w.id" :value="w.id">{{ w.label }}</option></select>
                <label class="sr-only" :for="`session-ppe-${s.person_id}`">{{ t('secw.ppeWorn') }}</label>
                <input :id="`session-ppe-${s.person_id}`" v-model="line(s.person_id).ppe" :placeholder="t('secw.ppeWorn')" />
              </template>
            </div>
          </fieldset>
          <p v-else-if="session.structure" class="muted">{{ t('sec.student.none') }}</p>
          <div><button class="btn btn--primary" :disabled="busy || !sheets.length || !session.structure || !session.teacher">{{ t('secw.createSession') }}</button></div>
        </form>
      </section>
      <section v-if="canStock" class="card"><h2>{{ t('secw.materials') }}</h2><ul class="rows"><li v-for="entry in items" :key="entry.id">{{ entry.label }} · {{ entry.stock_qty }} {{ entry.unit_label }} · {{ entry.unit_cost }} XAF <button class="btn" type="button" @click="issue(entry)">{{ t('secw.move') }}</button></li></ul><form class="grid" @submit.prevent="createItem"><div class="field"><label for="material-name">{{ t('secw.item') }}</label><input id="material-name" v-model="material.label" required /></div><div class="field"><label for="material-unit">{{ t('secw.unit') }}</label><input id="material-unit" v-model="material.unit" required /></div><div class="field"><label for="material-cost">{{ t('secw.unitCost') }}</label><input id="material-cost" v-model="material.cost" type="number" min="0" required /></div><div class="field"><label for="material-quantity">{{ t('secw.quantity') }}</label><input id="material-quantity" v-model="material.quantity" type="number" min="0" step="0.01" required /></div><button class="btn" :disabled="busy">{{ t('secw.addItem') }}</button></form></section>
    </template>
  </div>
</template>
