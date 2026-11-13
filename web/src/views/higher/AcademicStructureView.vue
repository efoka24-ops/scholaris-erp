<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { academic, LANGUAGES, REGIMES, STUDY_MODES, type Department, type Group, type Level, type Program, type Regime, type School, type StudyMode, type TeachingLanguage } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { k } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Écoles, départements, filières, niveaux, groupes (EX-ACA-01, 02) — design : Fondations.html (tableaux) et Inscription.html (onglets).
type Tab = 'schools' | 'departments' | 'programs' | 'levels' | 'groups';
const TABS: Tab[] = ['schools', 'departments', 'programs', 'levels', 'groups'];

const { t } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const save = useHigherAction();

const tab = ref<Tab>('schools');
const schools = ref<School[]>([]);
const departments = ref<Department[]>([]);
const programs = ref<Program[]>([]);
const levels = ref<Level[]>([]);
const groups = ref<Group[]>([]);
const touched = ref(false);
const editing = ref<Program | null>(null);

const canManage = computed(() => ctx.can(...P.settingsManage) && !higher.readOnly);

const fSchool = reactive({ code: '', name: '' });
const fDept = reactive({ school_id: '', code: '', name: '' });
const fProg = reactive({ department_id: '', code: '', name: '', regime: 'lmd' as Regime, teaching_language: 'fr' as TeachingLanguage, study_mode: 'day' as StudyMode, duration_years: 3 });
const fLevel = reactive({ program_id: '', ordinal: 1, code: '', name: '' });
const fGroup = reactive({ level_id: '', code: '', name: '', capacity: '' as string | number });
const fEdit = reactive({ code: '', name: '', teaching_language: 'fr' as TeachingLanguage, study_mode: 'day' as StudyMode, duration_years: 3 });

const levelProgram = computed(() => programs.value.find((p) => p.id === fLevel.program_id) ?? null);
const ordinalBad = computed(() => touched.value && (!Number.isInteger(Number(fLevel.ordinal)) || Number(fLevel.ordinal) < 1 || (levelProgram.value !== null && Number(fLevel.ordinal) > levelProgram.value.duration_years)));
const durationBad = (n: number): boolean => !Number.isInteger(Number(n)) || Number(n) < 1 || Number(n) > 8;
const empty = (v: string): boolean => touched.value && v.trim() === '';

async function load(): Promise<void> {
  const q = higher.scope();
  const res = await list.run(async () => {
    const [s, d, p, l, g] = await Promise.all([academic.schools(q), academic.departments(q), academic.programs(q), academic.levels(q), academic.groups(q)]);
    return { s, d, p, l, g };
  });
  if (!res) return;
  schools.value = res.s;
  departments.value = res.d;
  programs.value = res.p;
  levels.value = res.l;
  groups.value = res.g;
}

async function done(promise: () => Promise<unknown>, reset: () => void): Promise<void> {
  const res = await save.run(promise);
  if (res === undefined) return;
  reset();
  touched.value = false;
  higher.invalidate('schools', 'departments', 'programs', 'levels', 'groups');
  await load();
}

async function createSchool(): Promise<void> {
  touched.value = true;
  if (!fSchool.code.trim() || !fSchool.name.trim()) return;
  await done(() => academic.createSchool({ code: fSchool.code.trim(), name: fSchool.name.trim() }), () => Object.assign(fSchool, { code: '', name: '' }));
}
async function createDept(): Promise<void> {
  touched.value = true;
  if (!fDept.school_id || !fDept.code.trim() || !fDept.name.trim()) return;
  await done(() => academic.createDepartment({ school_id: fDept.school_id, code: fDept.code.trim(), name: fDept.name.trim() }), () => Object.assign(fDept, { code: '', name: '' }));
}
async function createProg(): Promise<void> {
  touched.value = true;
  if (!fProg.department_id || !fProg.code.trim() || !fProg.name.trim() || durationBad(fProg.duration_years)) return;
  await done(
    () => academic.createProgram({ ...fProg, code: fProg.code.trim(), name: fProg.name.trim(), duration_years: Number(fProg.duration_years) }),
    () => Object.assign(fProg, { code: '', name: '' }),
  );
}
async function createLevel(): Promise<void> {
  touched.value = true;
  if (!fLevel.program_id || !fLevel.code.trim() || !fLevel.name.trim() || ordinalBad.value) return;
  await done(() => academic.createLevel({ program_id: fLevel.program_id, ordinal: Number(fLevel.ordinal), code: fLevel.code.trim(), name: fLevel.name.trim() }), () => Object.assign(fLevel, { code: '', name: '' }));
}
async function createGroup(): Promise<void> {
  touched.value = true;
  if (!fGroup.level_id || !fGroup.code.trim() || !fGroup.name.trim()) return;
  const capacity = fGroup.capacity === '' ? null : Number(fGroup.capacity);
  await done(() => academic.createGroup({ level_id: fGroup.level_id, code: fGroup.code.trim(), name: fGroup.name.trim(), capacity }), () => Object.assign(fGroup, { code: '', name: '', capacity: '' }));
}

function startEdit(p: Program): void {
  editing.value = p;
  Object.assign(fEdit, { code: p.code, name: p.name, teaching_language: p.teaching_language, study_mode: p.study_mode, duration_years: p.duration_years });
}
async function saveEdit(): Promise<void> {
  const p = editing.value;
  if (!p || durationBad(fEdit.duration_years) || !fEdit.name.trim()) return;
  // Le régime n'est jamais envoyé (RG-ORG-7).
  await done(() => academic.updateProgram(p.id, { code: fEdit.code.trim(), name: fEdit.name.trim(), teaching_language: fEdit.teaching_language, study_mode: fEdit.study_mode, duration_years: Number(fEdit.duration_years) }), () => (editing.value = null));
}

const schoolLabel = (id: string): string => {
  const s = schools.value.find((x) => x.id === id);
  return s ? `${s.code} · ${s.name}` : id;
};
const deptLabel = (id: string): string => departments.value.find((x) => x.id === id)?.name ?? id;
const progLabel = (id: string): string => {
  const p = programs.value.find((x) => x.id === id);
  return p ? `${p.code} · ${p.name}` : id;
};
const levelLabel = (id: string): string => levels.value.find((x) => x.id === id)?.code ?? id;

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.aca.title')" :lede="t('hi.aca.lede')" section="academic" @school-change="load">
    <div class="hi-tabs" role="tablist" :aria-label="t('hi.aca.tabs')">
      <button v-for="x in TABS" :key="x" type="button" role="tab" :aria-selected="tab === x" :data-tab="x" @click="tab = x; touched = false">{{ t(k('hi.aca.tab.', x)) }}</button>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="save.error.value" />
    <p v-if="list.busy.value" class="muted">{{ t('hi.loading') }}</p>

    <section v-if="tab === 'schools'" role="tabpanel">
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in schools" :key="s.id"><th scope="row">{{ s.code }}</th><td>{{ s.name }}</td></tr>
            <tr v-if="schools.length === 0"><td colspan="2" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
      <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="createSchool">
        <h2 class="hi-wide">{{ t('hi.aca.newSchool') }}</h2>
        <div class="field"><label for="s-code">{{ t('hi.code') }}</label><input id="s-code" v-model="fSchool.code" type="text" maxlength="12" :aria-invalid="empty(fSchool.code)" aria-describedby="s-code-hint" /><span id="s-code-hint" class="hint">{{ t('hi.aca.schoolCodeHint') }}</span></div>
        <div class="field"><label for="s-name">{{ t('hi.name') }}</label><input id="s-name" v-model="fSchool.name" type="text" maxlength="160" :aria-invalid="empty(fSchool.name)" /><span v-if="empty(fSchool.name)" class="error">{{ t('hi.required') }}</span></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
      </form>
    </section>

    <section v-else-if="tab === 'departments'" role="tabpanel">
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th><th scope="col">{{ t('hi.school.label') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in departments" :key="d.id"><th scope="row">{{ d.code }}</th><td>{{ d.name }}</td><td>{{ schoolLabel(d.school_id) }}</td></tr>
            <tr v-if="departments.length === 0"><td colspan="3" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
      <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="createDept">
        <h2 class="hi-wide">{{ t('hi.aca.newDepartment') }}</h2>
        <div class="field">
<label for="d-school">{{ t('hi.school.label') }}</label>
          <select id="d-school" v-model="fDept.school_id" :aria-invalid="touched && !fDept.school_id"><option value="" disabled>{{ t('hi.schoolPick') }}</option><option v-for="s in schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select>
        </div>
        <div class="field"><label for="d-code">{{ t('hi.code') }}</label><input id="d-code" v-model="fDept.code" type="text" maxlength="12" :aria-invalid="empty(fDept.code)" /></div>
        <div class="field"><label for="d-name">{{ t('hi.name') }}</label><input id="d-name" v-model="fDept.name" type="text" :aria-invalid="empty(fDept.name)" /></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
      </form>
    </section>

    <section v-else-if="tab === 'programs'" role="tabpanel">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th><th scope="col">{{ t('hi.aca.regime') }}</th><th scope="col">{{ t('hi.aca.mode') }}</th>
              <th scope="col">{{ t('hi.aca.language') }}</th><th scope="col" class="num">{{ t('hi.aca.duration') }}</th><th scope="col">{{ t('hi.department') }}</th><th v-if="canManage" scope="col">{{ t('hi.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in programs" :key="p.id">
              <th scope="row">{{ p.code }}</th><td>{{ p.name }}</td>
              <td><span class="tag tag--ink">{{ t(k('hi.regime.', p.regime)) }}</span> <span v-if="p.requires_tutelage" class="tag tag--neutral">{{ t('hi.aca.tutelageRequired') }}</span></td>
              <td>{{ t(k('hi.mode.', p.study_mode)) }}</td><td>{{ t(k('hi.lang.', p.teaching_language)) }}</td><td class="num">{{ p.duration_years }}</td><td>{{ deptLabel(p.department_id) }}</td>
              <td v-if="canManage"><button type="button" class="hi-linkbtn" :aria-label="t('hi.aca.editProgram', { name: p.name })" @click="startEdit(p)">{{ t('hi.edit') }}</button></td>
            </tr>
            <tr v-if="programs.length === 0"><td colspan="8" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>

      <form v-if="editing && canManage" class="card hi-section hi-form" data-test="program-edit" novalidate @submit.prevent="saveEdit">
        <h2 class="hi-wide">{{ t('hi.aca.editProgram', { name: editing.name }) }}</h2>
        <p class="notice hi-wide" data-test="regime-locked"><strong>{{ t('hi.aca.regime') }} : {{ t(k('hi.regime.', editing.regime)) }}.</strong> {{ t('hi.aca.regimeLocked') }}</p>
        <div class="field"><label for="e-code">{{ t('hi.code') }}</label><input id="e-code" v-model="fEdit.code" type="text" maxlength="12" /></div>
        <div class="field"><label for="e-name">{{ t('hi.name') }}</label><input id="e-name" v-model="fEdit.name" type="text" /></div>
        <div class="field"><label for="e-mode">{{ t('hi.aca.mode') }}</label><select id="e-mode" v-model="fEdit.study_mode"><option v-for="m in STUDY_MODES" :key="m" :value="m">{{ t(k('hi.mode.', m)) }}</option></select></div>
        <div class="field"><label for="e-lang">{{ t('hi.aca.language') }}</label><select id="e-lang" v-model="fEdit.teaching_language"><option v-for="l in LANGUAGES" :key="l" :value="l">{{ t(k('hi.lang.', l)) }}</option></select></div>
        <div class="field"><label for="e-dur">{{ t('hi.aca.duration') }}</label><input id="e-dur" v-model.number="fEdit.duration_years" type="number" min="1" max="8" :aria-invalid="durationBad(fEdit.duration_years)" /><span v-if="durationBad(fEdit.duration_years)" class="error">{{ t('hi.aca.durationBad') }}</span></div>
        <div class="field hi-actions"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.save') }}</button><button type="button" class="btn btn--quiet" @click="editing = null">{{ t('hi.cancel') }}</button></div>
      </form>

      <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="createProg">
        <h2 class="hi-wide">{{ t('hi.aca.newProgram') }}</h2>
        <div class="field">
<label for="p-dept">{{ t('hi.department') }}</label>
          <select id="p-dept" v-model="fProg.department_id" :aria-invalid="touched && !fProg.department_id"><option value="" disabled>{{ t('hi.departmentPick') }}</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code }} · {{ d.name }}</option></select>
        </div>
        <div class="field"><label for="p-code">{{ t('hi.code') }}</label><input id="p-code" v-model="fProg.code" type="text" maxlength="12" :aria-invalid="empty(fProg.code)" /></div>
        <div class="field"><label for="p-name">{{ t('hi.name') }}</label><input id="p-name" v-model="fProg.name" type="text" :aria-invalid="empty(fProg.name)" /></div>
        <div class="field"><label for="p-regime">{{ t('hi.aca.regime') }}</label><select id="p-regime" v-model="fProg.regime" aria-describedby="p-regime-hint"><option v-for="r in REGIMES" :key="r" :value="r">{{ t(k('hi.regime.', r)) }}</option></select><span id="p-regime-hint" class="hint">{{ t('hi.aca.regimeHint') }}</span></div>
        <div class="field"><label for="p-mode">{{ t('hi.aca.mode') }}</label><select id="p-mode" v-model="fProg.study_mode"><option v-for="m in STUDY_MODES" :key="m" :value="m">{{ t(k('hi.mode.', m)) }}</option></select></div>
        <div class="field"><label for="p-lang">{{ t('hi.aca.language') }}</label><select id="p-lang" v-model="fProg.teaching_language"><option v-for="l in LANGUAGES" :key="l" :value="l">{{ t(k('hi.lang.', l)) }}</option></select></div>
        <div class="field"><label for="p-dur">{{ t('hi.aca.duration') }}</label><input id="p-dur" v-model.number="fProg.duration_years" type="number" min="1" max="8" :aria-invalid="durationBad(fProg.duration_years)" /><span v-if="durationBad(fProg.duration_years)" class="error">{{ t('hi.aca.durationBad') }}</span></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
      </form>
    </section>

    <section v-else-if="tab === 'levels'" role="tabpanel">
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th><th scope="col" class="num">{{ t('hi.aca.ordinal') }}</th><th scope="col">{{ t('hi.program') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in levels" :key="l.id"><th scope="row">{{ l.code }}</th><td>{{ l.name }}</td><td class="num">{{ l.ordinal }}</td><td>{{ progLabel(l.program_id) }}</td></tr>
            <tr v-if="levels.length === 0"><td colspan="4" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
      <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="createLevel">
        <h2 class="hi-wide">{{ t('hi.aca.newLevel') }}</h2>
        <div class="field">
<label for="l-prog">{{ t('hi.program') }}</label>
          <select id="l-prog" v-model="fLevel.program_id" :aria-invalid="touched && !fLevel.program_id"><option value="" disabled>{{ t('hi.programPick') }}</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
        </div>
        <div class="field"><label for="l-ord">{{ t('hi.aca.ordinal') }}</label><input id="l-ord" v-model.number="fLevel.ordinal" type="number" min="1" max="8" :aria-invalid="ordinalBad" /><span v-if="ordinalBad" class="error">{{ t('hi.aca.ordinalBad') }}</span></div>
        <div class="field"><label for="l-code">{{ t('hi.code') }}</label><input id="l-code" v-model="fLevel.code" type="text" placeholder="L1" :aria-invalid="empty(fLevel.code)" /></div>
        <div class="field"><label for="l-name">{{ t('hi.name') }}</label><input id="l-name" v-model="fLevel.name" type="text" :aria-invalid="empty(fLevel.name)" /></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
      </form>
    </section>

    <section v-else role="tabpanel">
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th><th scope="col">{{ t('hi.level') }}</th><th scope="col" class="num">{{ t('hi.aca.capacity') }}</th></tr></thead>
          <tbody>
            <tr v-for="g in groups" :key="g.id"><th scope="row">{{ g.code }}</th><td>{{ g.name }}</td><td>{{ levelLabel(g.level_id) }} · {{ progLabel(g.program_id) }}</td><td class="num">{{ g.capacity ?? '—' }}</td></tr>
            <tr v-if="groups.length === 0"><td colspan="4" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
      <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="createGroup">
        <h2 class="hi-wide">{{ t('hi.aca.newGroup') }}</h2>
        <div class="field">
<label for="g-level">{{ t('hi.level') }}</label>
          <select id="g-level" v-model="fGroup.level_id" :aria-invalid="touched && !fGroup.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in levels" :key="l.id" :value="l.id">{{ l.code }} · {{ progLabel(l.program_id) }}</option></select>
        </div>
        <div class="field"><label for="g-code">{{ t('hi.code') }}</label><input id="g-code" v-model="fGroup.code" type="text" maxlength="20" :aria-invalid="empty(fGroup.code)" /></div>
        <div class="field"><label for="g-name">{{ t('hi.name') }}</label><input id="g-name" v-model="fGroup.name" type="text" :aria-invalid="empty(fGroup.name)" /></div>
        <div class="field"><label for="g-cap">{{ t('hi.aca.capacity') }}</label><input id="g-cap" v-model="fGroup.capacity" type="number" min="1" /></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
      </form>
    </section>
  </HigherFrame>
</template>
