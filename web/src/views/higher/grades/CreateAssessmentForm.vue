<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { curricula, type Curriculum } from '@/api/higher';
import { teaching, type Assignment } from '@/api/higher-teaching';
import { ASSESSMENT_KINDS, DEFAULT_WEIGHTS, grades, type Assessment, type AssessmentKind } from '@/api/higher-grades';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { gk, useGradesI18n } from './i18n';

// Création d'une évaluation (enseignant affecté). Maquettes publiées lues si le rôle y a accès ; à défaut (ou si la maquette ne
// peut pas être lue), maquettes et EC tirés des affectations d'enseignement visibles (C110-I2) ; sinon identifiants saisis.
interface ElementOption { id: string; code: string; title: string }
interface CurriculumOption { id: string; label: string; level_id: string }
const emit = defineEmits<{ (e: 'created', a: Assessment): void }>();
const { tg } = useGradesI18n();
const higher = useHigherStore();
const act = useHigherAction();

const list = ref<Curriculum[] | null>(null);
const mine = ref<Assignment[]>([]);
const elements = ref<ElementOption[]>([]);
const touched = ref(false);
const form = reactive({ curriculum_id: '', element_id: '', group_id: '', kind: 'cc' as AssessmentKind, label: '', held_on: '', entry_due_on: '', max_score: 20, weight_percent: DEFAULT_WEIGHTS.cc, anonymous: false });

const options = computed<CurriculumOption[] | null>(() => {
  if (list.value && list.value.length) {
    return list.value.map((c) => ({ id: c.id, label: `${c.title || higher.programName(c.program_id)} · ${higher.levelName(c.level_id)} · v${c.version}`, level_id: c.level_id }));
  }
  const seen = new Map<string, CurriculumOption>();
  for (const a of mine.value) {
    if (!seen.has(a.curriculum_id)) seen.set(a.curriculum_id, { id: a.curriculum_id, label: `${higher.programName(a.program_id)} · ${higher.levelName(a.level_id)}`, level_id: a.level_id });
  }
  return seen.size ? [...seen.values()] : list.value ? [] : null;
});
const curriculum = computed(() => options.value?.find((c) => c.id === form.curriculum_id) ?? null);
const groups = computed(() => higher.groups.filter((g) => !curriculum.value || g.level_id === curriculum.value.level_id));
const rangeBad = computed(() => touched.value && (!(form.max_score >= 1 && form.max_score <= 100) || !(form.weight_percent >= 0 && form.weight_percent <= 100)));

async function pickCurriculum(): Promise<void> {
  form.element_id = '';
  elements.value = [];
  const id = form.curriculum_id;
  if (!id || !options.value) return;
  let found: ElementOption[] = [];
  if (list.value?.some((c) => c.id === id)) {
    try {
      const tree = await curricula.show(id);
      found = tree.semesters.flatMap((s) => s.units.flatMap((u) => u.elements.map((e) => ({ id: e.id, code: e.code, title: e.title }))));
    } catch {
      found = [];
    }
  }
  if (!found.length) found = assignedElements(id);
  if (form.curriculum_id === id) elements.value = found;
}

/** EC des affectations visibles pour une maquette (sans doublon). */
function assignedElements(curriculumId: string): ElementOption[] {
  const seen = new Map<string, ElementOption>();
  for (const a of mine.value) {
    if (a.curriculum_id === curriculumId && !seen.has(a.element_id)) seen.set(a.element_id, { id: a.element_id, code: a.element_code, title: a.element_title });
  }
  return [...seen.values()];
}

function pickKind(): void {
  form.weight_percent = DEFAULT_WEIGHTS[form.kind];
  form.anonymous = form.kind === 'exam' || form.kind === 'retake';
}

async function submit(): Promise<void> {
  touched.value = true;
  if (!form.curriculum_id.trim() || !form.element_id.trim() || rangeBad.value) return;
  const res = await act.run(() =>
    grades.create({
      curriculum_id: form.curriculum_id.trim(),
      element_id: form.element_id.trim(),
      group_id: form.group_id || null,
      kind: form.kind,
      ...(form.label.trim() ? { label: form.label.trim() } : {}),
      held_on: form.held_on || null,
      entry_due_on: form.entry_due_on || null,
      max_score: Number(form.max_score),
      weight_percent: Number(form.weight_percent),
      anonymous: form.anonymous,
    }),
  );
  if (!res) return;
  touched.value = false;
  Object.assign(form, { label: '', held_on: '', entry_due_on: '' });
  emit('created', res);
}

onMounted(async () => {
  await Promise.all([
    higher.loadRefs('groups', 'programs', 'levels'),
    curricula.list(higher.scope()).then(
      (all) => (list.value = all.filter((c) => c.status === 'published')),
      () => (list.value = null),
    ),
    teaching.assignments().then(
      (all) => (mine.value = all.filter((a) => a.status === 'active')),
      () => (mine.value = []),
    ),
  ]);
  // Une seule maquette proposée : choisie d'office, ses EC sont chargés.
  if (options.value?.length === 1 && !form.curriculum_id) {
    form.curriculum_id = options.value[0]!.id;
    await pickCurriculum();
  }
});
</script>

<template>
  <form class="card hi-section hi-form" novalidate data-test="create-assessment" @submit.prevent="submit">
    <h2 class="hi-wide">{{ tg('hg.new.title') }}</h2>
    <p class="muted hi-small hi-wide">{{ tg('hg.new.hint') }}</p>
    <div class="field">
      <label for="na-cur">{{ tg('hg.new.curriculum') }}</label>
      <select v-if="options" id="na-cur" v-model="form.curriculum_id" :aria-invalid="touched && !form.curriculum_id" @change="pickCurriculum">
        <option value="" disabled>—</option>
        <option v-for="c in options" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
      <input v-else id="na-cur" v-model="form.curriculum_id" type="text" :aria-invalid="touched && !form.curriculum_id" />
      <span v-if="touched && !form.curriculum_id" class="error">{{ tg('hg.required') }}</span>
    </div>
    <div class="field">
      <label for="na-el">{{ tg('hg.new.element') }}</label>
      <select v-if="options && elements.length" id="na-el" v-model="form.element_id" :aria-invalid="touched && !form.element_id">
        <option value="" disabled>—</option>
        <option v-for="e in elements" :key="e.id" :value="e.id">{{ e.code }} · {{ e.title }}</option>
      </select>
      <input v-else id="na-el" v-model="form.element_id" type="text" :aria-invalid="touched && !form.element_id" />
      <span v-if="touched && !form.element_id" class="error">{{ tg('hg.required') }}</span>
    </div>
    <div class="field">
      <label for="na-grp">{{ tg('hg.new.group') }}</label>
      <select id="na-grp" v-model="form.group_id"><option value="">{{ tg('hg.allGroups') }}</option><option v-for="g in groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
    </div>
    <div class="field">
      <label for="na-kind">{{ tg('hg.kind') }}</label>
      <select id="na-kind" v-model="form.kind" @change="pickKind"><option v-for="k in ASSESSMENT_KINDS" :key="k" :value="k">{{ tg(gk('hg.kind.', k)) }}</option></select>
    </div>
    <div class="field"><label for="na-label">{{ tg('hg.label') }}</label><input id="na-label" v-model="form.label" type="text" maxlength="120" /></div>
    <div class="field"><label for="na-held">{{ tg('hg.heldOn') }}</label><input id="na-held" v-model="form.held_on" type="date" /></div>
    <div class="field"><label for="na-due">{{ tg('hg.dueOn') }}</label><input id="na-due" v-model="form.entry_due_on" type="date" /></div>
    <div class="field"><label for="na-max">{{ tg('hg.max') }}</label><input id="na-max" v-model.number="form.max_score" type="number" min="1" max="100" step="0.25" inputmode="decimal" :aria-invalid="rangeBad" /></div>
    <div class="field">
      <label for="na-w">{{ tg('hg.weight') }}</label><input id="na-w" v-model.number="form.weight_percent" type="number" min="0" max="100" inputmode="numeric" :aria-invalid="rangeBad" />
      <span v-if="rangeBad" class="error">{{ tg('hg.rangeBad') }}</span>
    </div>
    <div class="field"><label class="row"><input v-model="form.anonymous" type="checkbox" /> {{ tg('hg.anonymous') }}</label></div>
    <ErrorNotice class="hi-wide" :error="act.error.value" />
    <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value || higher.readOnly">{{ tg('hg.new.create') }}</button></div>
  </form>
</template>
