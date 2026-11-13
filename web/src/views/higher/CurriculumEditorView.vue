<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import { curricula, UNIT_TYPES, type CurriculumElement, type CurriculumTree, type CurriculumUnit, type UnitType } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { liveCheck, type LiveIssue } from './coherence';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Éditeur de maquette par semestre, UE et EC (EX-MAQ-02 à 06) : crédits, coefficients, volumes CM/TD/TP/travail personnel,
// contrôle de cohérence en direct (RG-MAQ-2), publication, dépublication, nouvelle version, copie, export CSV/Excel.
const props = defineProps<{ id: string }>();
const { t } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const load = useHigherAction();
const act = useHigherAction();

const tree = ref<CurriculumTree | null>(null);
const copyYear = ref('');
const unitForms = reactive<Record<number, { code: string; title: string; unit_type: UnitType; credits: number; coefficient: number; choice_group: string; choice_count: string | number; national_exam_paper: string }>>({});
const elementForms = reactive<Record<string, { code: string; title: string; credits: number; coefficient: number; cm_hours: number; td_hours: number; tp_hours: number; personal_hours: number }>>({});

const canManage = computed(() => ctx.can(...P.curriculumManage) && !higher.readOnly);
const canApprove = computed(() => ctx.can(...P.curriculumApprove) && !higher.readOnly);
const editable = computed(() => tree.value?.status === 'draft' && canManage.value);
const nationalExam = computed(() => tree.value?.regime === 'bts' || tree.value?.regime === 'hnd');
const live = computed(() => (tree.value ? liveCheck(tree.value.regime, tree.value.semesters) : { issues: [], semesters: [] }));
const publishable = computed(() => live.value.issues.length === 0 && (tree.value?.check.errors.length ?? 1) === 0);
const title = computed(() =>
  tree.value ? t('hi.cur.editorTitle', { program: higher.programName(tree.value.program_id), level: higher.levelName(tree.value.level_id), year: higher.yearName(tree.value.academic_year_id) }) : t('hi.cur.title'),
);

function issueText(i: LiveIssue): string {
  switch (i.kind) {
    case 'emptySemester':
      return t('hi.cur.live.emptySemester', { label: i.label });
    case 'creditsSemester':
      return t('hi.cur.live.creditsSemester', { label: i.label, credits: i.credits, target: i.target });
    case 'unitNoElement':
      return t('hi.cur.live.unitNoElement', { code: i.code });
    case 'unitCredits':
      return t('hi.cur.live.unitCredits', { code: i.code, declared: i.declared, sum: i.sum });
    case 'noVolume':
      return t('hi.cur.live.noVolume', { code: i.code });
    case 'choiceCredits':
      return t('hi.cur.live.choiceCredits', { label: i.label, group: i.group });
  }
  return '';
}

function unitForm(ordinal: number) {
  unitForms[ordinal] ??= { code: '', title: '', unit_type: 'fundamental', credits: 0, coefficient: 1, choice_group: '', choice_count: '', national_exam_paper: '' };
  return unitForms[ordinal];
}
function elementForm(unitId: string) {
  elementForms[unitId] ??= { code: '', title: '', credits: 0, coefficient: 1, cm_hours: 0, td_hours: 0, tp_hours: 0, personal_hours: 0 };
  return elementForms[unitId];
}

async function fetchTree(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const res = await load.run(() => curricula.show(props.id));
  if (res) tree.value = res;
}

async function reload(): Promise<void> {
  const res = await act.run(() => curricula.show(props.id));
  if (res) tree.value = res;
}

async function addUnit(ordinal: number): Promise<void> {
  const f = unitForm(ordinal);
  if (!f.code.trim() || !f.title.trim()) return;
  const res = await act.run(() =>
    curricula.addUnit(props.id, {
      semester_ordinal: ordinal,
      code: f.code.trim(),
      title: f.title.trim(),
      unit_type: f.unit_type,
      credits: Number(f.credits),
      coefficient: Number(f.coefficient),
      ...(f.choice_group.trim() ? { choice_group: f.choice_group.trim(), choice_count: f.choice_count === '' ? null : Number(f.choice_count) } : {}),
      ...(nationalExam.value && f.national_exam_paper.trim() ? { national_exam_paper: f.national_exam_paper.trim() } : {}),
    }),
  );
  if (!res) return;
  delete unitForms[ordinal];
  await reload();
}

async function saveUnit(u: CurriculumUnit): Promise<void> {
  const res = await act.run(() =>
    curricula.updateUnit(u.id, {
      code: u.code,
      title: u.title,
      unit_type: u.unit_type,
      credits: Number(u.credits),
      coefficient: Number(u.coefficient),
      choice_group: u.choice_group || undefined,
      choice_count: u.choice_group ? (u.choice_count === null ? null : Number(u.choice_count)) : null,
      ...(nationalExam.value ? { national_exam_paper: u.national_exam_paper || null } : {}),
    }),
  );
  if (res) await reload();
}

async function removeUnit(u: CurriculumUnit): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.cur.removeUnitConfirm', { code: u.code }))) return;
  const res = await act.run(() => curricula.removeUnit(u.id).then(() => true));
  if (res) await reload();
}

async function addElement(u: CurriculumUnit): Promise<void> {
  const f = elementForm(u.id);
  if (!f.code.trim() || !f.title.trim()) return;
  const res = await act.run(() =>
    curricula.addElement(u.id, { ...f, code: f.code.trim(), title: f.title.trim(), credits: Number(f.credits), coefficient: Number(f.coefficient), cm_hours: Number(f.cm_hours), td_hours: Number(f.td_hours), tp_hours: Number(f.tp_hours), personal_hours: Number(f.personal_hours) }),
  );
  if (!res) return;
  delete elementForms[u.id];
  await reload();
}

async function saveElement(e: CurriculumElement): Promise<void> {
  const res = await act.run(() =>
    curricula.updateElement(e.id, { code: e.code, title: e.title, credits: Number(e.credits), coefficient: Number(e.coefficient), cm_hours: Number(e.cm_hours), td_hours: Number(e.td_hours), tp_hours: Number(e.tp_hours), personal_hours: Number(e.personal_hours) }),
  );
  if (res) await reload();
}

async function removeElement(e: CurriculumElement): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.cur.removeElementConfirm', { code: e.code }))) return;
  const res = await act.run(() => curricula.removeElement(e.id).then(() => true));
  if (res) await reload();
}

async function publish(): Promise<void> {
  if (!publishable.value) return;
  const res = await act.run(() => curricula.publish(props.id));
  if (res) await reload();
}
async function unpublish(): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.cur.unpublishConfirm'))) return;
  const res = await act.run(() => curricula.unpublish(props.id));
  if (res) await reload();
}
async function newVersion(): Promise<void> {
  const res = await act.run(() => curricula.newVersion(props.id));
  if (res) await router.push({ name: 'higher-curriculum', params: { id: res.id } });
}
async function copy(): Promise<void> {
  if (!copyYear.value) return;
  const res = await act.run(() => curricula.copy(props.id, copyYear.value));
  if (res) await router.push({ name: 'higher-curriculum', params: { id: res.id } });
}

onMounted(async () => {
  await higher.ensure();
  await fetchTree();
});
</script>

<template>
  <HigherFrame :title="title" section="curriculum" :selector="false">
    <template #actions>
      <router-link :to="{ name: 'higher-curricula' }" class="btn btn--quiet">← {{ t('hi.backToList') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <p v-if="load.busy.value" class="muted">{{ t('hi.loading') }}</p>

    <template v-if="tree">
      <div class="row hi-head">
        <span class="tag" :class="`tag--${toneOf(tree.status) ?? 'neutral'}`">{{ t(k('hi.cur.state.', tree.status)) }}</span>
        <span class="tag tag--ink">{{ t(k('hi.regime.', tree.regime)) }}</span>
        <span>{{ t('hi.cur.version', { version: tree.version }) }}</span>
        <span v-if="tree.approved_programme_ref" class="muted">{{ t('hi.cur.programmeRef') }} : {{ tree.approved_programme_ref }}</span>
      </div>
      <p v-if="tree.status !== 'draft'" class="notice">{{ t('hi.cur.readonlyPublished') }}</p>

      <div class="hi-layout">
        <div class="hi-main">
          <section v-for="s in tree.semesters" :key="s.id" class="card hi-sem" :aria-labelledby="`sem-${s.ordinal}`">
            <div class="row row--between">
              <h2 :id="`sem-${s.ordinal}`">{{ s.label || t('hi.cur.semester', { n: s.ordinal }) }}</h2>
              <span class="tag" :class="tree.regime === 'lmd' && live.semesters.find((x) => x.ordinal === s.ordinal)?.credits !== 30 ? 'tag--warning' : 'tag--success'" :data-credits="s.ordinal">
                {{ tree.regime === 'lmd' ? t('hi.cur.semesterCredits', { credits: live.semesters.find((x) => x.ordinal === s.ordinal)?.credits ?? 0, target: 30 }) : t('hi.cur.semesterCreditsFree', { credits: live.semesters.find((x) => x.ordinal === s.ordinal)?.credits ?? 0 }) }}
              </span>
            </div>
            <p class="muted hi-small">{{ t('hi.cur.volume', { hours: live.semesters.find((x) => x.ordinal === s.ordinal)?.hours ?? 0 }) }}</p>

            <article v-for="u in s.units" :key="u.id" class="hi-unit" :data-unit="u.code">
              <div class="hi-form">
                <div class="field"><label :for="`u-code-${u.id}`">{{ t('hi.cur.unit') }} · {{ t('hi.code') }}</label><input :id="`u-code-${u.id}`" v-model="u.code" type="text" :disabled="!editable" /></div>
                <div class="field hi-span2"><label :for="`u-title-${u.id}`">{{ t('hi.label') }}</label><input :id="`u-title-${u.id}`" v-model="u.title" type="text" :disabled="!editable" /></div>
                <div class="field"><label :for="`u-type-${u.id}`">{{ t('hi.cur.unitType') }}</label><select :id="`u-type-${u.id}`" v-model="u.unit_type" :disabled="!editable"><option v-for="x in UNIT_TYPES" :key="x" :value="x">{{ t(k('hi.cur.unitType.', x)) }}</option></select></div>
                <div class="field"><label :for="`u-cr-${u.id}`">{{ t('hi.cur.credits') }}</label><input :id="`u-cr-${u.id}`" v-model.number="u.credits" type="number" min="0" max="60" :disabled="!editable" /></div>
                <div class="field"><label :for="`u-coef-${u.id}`">{{ t('hi.cur.coefficient') }}</label><input :id="`u-coef-${u.id}`" v-model.number="u.coefficient" type="number" min="0" step="0.01" :disabled="!editable" /></div>
                <div class="field"><label :for="`u-grp-${u.id}`">{{ t('hi.cur.choiceGroup') }}</label><input :id="`u-grp-${u.id}`" v-model="u.choice_group" type="text" :disabled="!editable" /></div>
                <div v-if="u.choice_group" class="field"><label :for="`u-cnt-${u.id}`">{{ t('hi.cur.choiceCount') }}</label><input :id="`u-cnt-${u.id}`" v-model.number="u.choice_count" type="number" min="1" max="10" :disabled="!editable" /></div>
                <div v-if="nationalExam" class="field"><label :for="`u-nat-${u.id}`">{{ t('hi.cur.nationalPaper') }}</label><input :id="`u-nat-${u.id}`" v-model="u.national_exam_paper" type="text" :disabled="!editable" /></div>
              </div>
              <div v-if="editable" class="hi-actions">
                <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="saveUnit(u)">{{ t('hi.save') }}</button>
                <button type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="removeUnit(u)">{{ t('hi.delete') }}</button>
              </div>

              <div class="table-wrap hi-ec">
                <table>
                  <caption class="sr-only">{{ t('hi.cur.element') }} · {{ u.code }}</caption>
                  <thead>
                    <tr>
                      <th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.label') }}</th><th scope="col" class="num">{{ t('hi.cur.credits') }}</th><th scope="col" class="num">{{ t('hi.cur.coefficient') }}</th>
                      <th scope="col" class="num">{{ t('hi.cur.cm') }}</th><th scope="col" class="num">{{ t('hi.cur.td') }}</th><th scope="col" class="num">{{ t('hi.cur.tp') }}</th><th scope="col" class="num">{{ t('hi.cur.personal') }}</th><th v-if="editable" scope="col">{{ t('hi.actions') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="e in u.elements" :key="e.id">
                      <template v-if="editable">
                        <td><input v-model="e.code" type="text" :aria-label="`${t('hi.cur.element')} ${t('hi.code')}`" class="hi-cell" /></td>
                        <td><input v-model="e.title" type="text" :aria-label="t('hi.label')" class="hi-cell hi-cell--wide" /></td>
                        <td><input v-model.number="e.credits" type="number" min="0" max="30" :aria-label="t('hi.cur.credits')" class="hi-cell" /></td>
                        <td><input v-model.number="e.coefficient" type="number" min="0" step="0.01" :aria-label="t('hi.cur.coefficient')" class="hi-cell" /></td>
                        <td><input v-model.number="e.cm_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.cm')" class="hi-cell" /></td>
                        <td><input v-model.number="e.td_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.td')" class="hi-cell" /></td>
                        <td><input v-model.number="e.tp_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.tp')" class="hi-cell" /></td>
                        <td><input v-model.number="e.personal_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.personal')" class="hi-cell" /></td>
                        <td class="hi-actions"><button type="button" class="hi-linkbtn" @click="saveElement(e)">{{ t('hi.save') }}</button><button type="button" class="hi-linkbtn" @click="removeElement(e)">{{ t('hi.delete') }}</button></td>
                      </template>
                      <template v-else>
                        <th scope="row">{{ e.code }}</th><td>{{ e.title }}</td><td class="num">{{ e.credits }}</td><td class="num">{{ e.coefficient }}</td>
                        <td class="num">{{ e.cm_hours }}</td><td class="num">{{ e.td_hours }}</td><td class="num">{{ e.tp_hours }}</td><td class="num">{{ e.personal_hours }}</td>
                      </template>
                    </tr>
                    <tr v-if="editable" class="hi-new">
                      <td><input v-model="elementForm(u.id).code" type="text" :aria-label="`${t('hi.cur.addElement')} · ${t('hi.code')}`" class="hi-cell" /></td>
                      <td><input v-model="elementForm(u.id).title" type="text" :aria-label="`${t('hi.cur.addElement')} · ${t('hi.label')}`" class="hi-cell hi-cell--wide" /></td>
                      <td><input v-model.number="elementForm(u.id).credits" type="number" min="0" :aria-label="t('hi.cur.credits')" class="hi-cell" /></td>
                      <td><input v-model.number="elementForm(u.id).coefficient" type="number" min="0" step="0.01" :aria-label="t('hi.cur.coefficient')" class="hi-cell" /></td>
                      <td><input v-model.number="elementForm(u.id).cm_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.cm')" class="hi-cell" /></td>
                      <td><input v-model.number="elementForm(u.id).td_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.td')" class="hi-cell" /></td>
                      <td><input v-model.number="elementForm(u.id).tp_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.tp')" class="hi-cell" /></td>
                      <td><input v-model.number="elementForm(u.id).personal_hours" type="number" min="0" step="0.25" :aria-label="t('hi.cur.personal')" class="hi-cell" /></td>
                      <td><button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="addElement(u)">{{ t('hi.cur.addElement') }}</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </article>

            <form v-if="editable" class="hi-form hi-addunit" novalidate @submit.prevent="addUnit(s.ordinal)">
              <h3 class="hi-wide">{{ t('hi.cur.addUnit') }}</h3>
              <div class="field"><label :for="`nu-code-${s.ordinal}`">{{ t('hi.code') }}</label><input :id="`nu-code-${s.ordinal}`" v-model="unitForm(s.ordinal).code" type="text" maxlength="20" /></div>
              <div class="field hi-span2"><label :for="`nu-title-${s.ordinal}`">{{ t('hi.label') }}</label><input :id="`nu-title-${s.ordinal}`" v-model="unitForm(s.ordinal).title" type="text" /></div>
              <div class="field"><label :for="`nu-type-${s.ordinal}`">{{ t('hi.cur.unitType') }}</label><select :id="`nu-type-${s.ordinal}`" v-model="unitForm(s.ordinal).unit_type"><option v-for="x in UNIT_TYPES" :key="x" :value="x">{{ t(k('hi.cur.unitType.', x)) }}</option></select></div>
              <div class="field"><label :for="`nu-cr-${s.ordinal}`">{{ t('hi.cur.credits') }}</label><input :id="`nu-cr-${s.ordinal}`" v-model.number="unitForm(s.ordinal).credits" type="number" min="0" max="60" /></div>
              <div class="field"><label :for="`nu-coef-${s.ordinal}`">{{ t('hi.cur.coefficient') }}</label><input :id="`nu-coef-${s.ordinal}`" v-model.number="unitForm(s.ordinal).coefficient" type="number" min="0" step="0.01" /></div>
              <div class="field"><label :for="`nu-grp-${s.ordinal}`">{{ t('hi.cur.choiceGroup') }}</label><input :id="`nu-grp-${s.ordinal}`" v-model="unitForm(s.ordinal).choice_group" type="text" :aria-describedby="`nu-grp-hint-${s.ordinal}`" /><span :id="`nu-grp-hint-${s.ordinal}`" class="hint">{{ t('hi.cur.choiceHint') }}</span></div>
              <div v-if="unitForm(s.ordinal).choice_group" class="field"><label :for="`nu-cnt-${s.ordinal}`">{{ t('hi.cur.choiceCount') }}</label><input :id="`nu-cnt-${s.ordinal}`" v-model="unitForm(s.ordinal).choice_count" type="number" min="1" max="10" /></div>
              <div v-if="nationalExam" class="field"><label :for="`nu-nat-${s.ordinal}`">{{ t('hi.cur.nationalPaper') }}</label><input :id="`nu-nat-${s.ordinal}`" v-model="unitForm(s.ordinal).national_exam_paper" type="text" /></div>
              <div class="field"><button type="submit" class="btn" :disabled="act.busy.value">{{ t('hi.cur.addUnit') }}</button></div>
            </form>
          </section>
        </div>

        <aside class="hi-side">
          <section class="card card--strong" aria-live="polite" data-test="coherence">
            <h2>{{ t('hi.cur.check') }}</h2>
            <p class="muted hi-small">{{ t('hi.cur.checkLive') }}</p>
            <p v-if="publishable" class="tag tag--success">{{ t('hi.cur.checkOk') }}</p>
            <template v-else>
              <template v-if="live.issues.length">
                <p><strong>{{ t('hi.cur.checkErrors', { count: live.issues.length }) }}</strong></p>
                <ul class="hi-errors">
                  <li v-for="(i, n) in live.issues" :key="`l${n}`" data-test="live-issue">{{ issueText(i) }}</li>
                </ul>
              </template>
              <template v-if="tree.check.errors.length">
                <h3 class="hi-section">{{ t('hi.cur.serverCheck') }}</h3>
                <ul class="hi-errors">
                  <li v-for="(e, n) in tree.check.errors" :key="`s${n}`" data-test="server-issue">{{ e }}</li>
                </ul>
              </template>
            </template>
            <template v-if="tree.check.warnings.length">
              <h3 class="hi-section">{{ t('hi.cur.checkWarnings') }}</h3>
              <ul class="hi-list hi-small"><li v-for="(w, n) in tree.check.warnings" :key="n">{{ w }}</li></ul>
            </template>
          </section>

          <section class="card hi-section">
            <div class="hi-actions">
              <button v-if="tree.status === 'draft' && canApprove" type="button" class="btn btn--primary" :disabled="act.busy.value || !publishable" :title="publishable ? '' : t('hi.cur.publishBlocked')" @click="publish">{{ t('hi.cur.publish') }}</button>
              <button v-if="tree.status === 'published' && canApprove" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="unpublish">{{ t('hi.cur.unpublish') }}</button>
              <button v-if="tree.status !== 'draft' && canManage" type="button" class="btn" :disabled="act.busy.value" @click="newVersion">{{ t('hi.cur.newVersion') }}</button>
            </div>
            <p v-if="tree.status === 'draft' && canApprove && !publishable" class="muted hi-small">{{ t('hi.cur.publishBlocked') }}</p>
            <form v-if="canManage" class="hi-section" @submit.prevent="copy">
              <YearField id="copy-year" v-model="copyYear" :label="t('hi.cur.copyTarget')" />
              <button type="submit" class="btn btn--quiet" :disabled="act.busy.value || !copyYear">{{ t('hi.cur.copy') }}</button>
            </form>
            <div class="hi-section">
              <ExportButton :path="curricula.exportPath(tree.id)" :name="`maquette_${tree.id}_v${tree.version}`" :caption="t('hi.cur.exportCaption')" />
            </div>
          </section>
          <ErrorNotice :error="act.error.value" />
        </aside>
      </div>
    </template>
  </HigherFrame>
</template>

<style scoped>
.hi-head {
  margin-bottom: 16px;
}
.hi-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
  align-items: start;
}
.hi-side {
  position: sticky;
  top: 16px;
}
.hi-sem + .hi-sem {
  margin-top: 16px;
}
.hi-unit {
  border-top: 2px solid var(--ink);
  padding-top: 12px;
  margin-top: 16px;
}
.hi-ec {
  margin-top: 12px;
}
.hi-cell {
  min-width: 64px;
  width: 80px;
}
.hi-cell--wide {
  width: 200px;
}
.hi-span2 {
  grid-column: span 2;
}
.hi-addunit {
  border-top: 1px dashed var(--border-strong);
  margin-top: 16px;
  padding-top: 12px;
}
@media (max-width: 1000px) {
  .hi-layout {
    grid-template-columns: 1fr;
  }
  .hi-side {
    position: static;
  }
  .hi-span2 {
    grid-column: auto;
  }
}
</style>
