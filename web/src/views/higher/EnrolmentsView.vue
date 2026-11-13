<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { admissions, enrolments, ENROLMENT_STATUSES, STUDY_MODES, type Application, type Enrolment, type StudyMode } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import HigherFrame from './HigherFrame.vue';
import PersonPicker from './PersonPicker.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Inscriptions (EX-INS-01, 02) : liste filtrée, création depuis une candidature admise, réinscription — design : Inscription.html (étapes).
const { t } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Enrolment[]>([]);
const admitted = ref<Application[]>([]);
const filters = reactive({ status: '', academic_year_id: '', program_id: '' });
const mode = ref<'application' | 'reenrol'>('application');
const touched = ref(false);
const form = reactive({ application_id: '', person_id: null as string | null, level_id: '', academic_year_id: '', group_id: '', study_mode: '' as StudyMode | '' });

const canEnrol = computed(() => ctx.can(...P.enrol) && !higher.readOnly);
const levelGroups = computed(() => higher.groups.filter((g) => g.level_id === form.level_id));

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'groups', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  for (const [key, v] of Object.entries(filters)) if (v) q[`filter[${key}]`] = v;
  const res = await list.run(async () => ({
    e: await enrolments.list(q),
    a: canEnrol.value ? [...(await admissions.applications({ ...higher.scope(), 'filter[status]': 'admitted' })), ...(await admissions.applications({ ...higher.scope(), 'filter[status]': 'conditional' }))] : [],
  }));
  if (!res) return;
  items.value = res.e;
  admitted.value = res.a.filter((a) => !a.enrolment_id);
}

async function create(): Promise<void> {
  touched.value = true;
  let body: Parameters<typeof enrolments.create>[0];
  if (mode.value === 'application') {
    if (!form.application_id) return;
    body = { application_id: form.application_id };
  } else {
    if (!form.person_id || !form.level_id || !form.academic_year_id) return;
    body = { person_id: form.person_id, level_id: form.level_id, academic_year_id: form.academic_year_id };
  }
  if (form.group_id) body.group_id = form.group_id;
  if (form.study_mode) body.study_mode = form.study_mode;
  const res = await act.run(() => enrolments.create(body));
  if (res) await router.push({ name: 'higher-enrolment', params: { id: res.id } });
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.ins.title')" :lede="t('hi.ins.lede')" section="enrolment" @school-change="load">
    <form class="hi-form" :aria-label="t('hi.filters')" @submit.prevent="load">
      <div class="field">
<label for="ef-status">{{ t('hi.status') }}</label>
        <select id="ef-status" v-model="filters.status" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in ENROLMENT_STATUSES" :key="s" :value="s">{{ t(k('hi.ins.st.', s)) }}</option></select>
      </div>
      <div class="field">
<label for="ef-prog">{{ t('hi.program') }}</label>
        <select id="ef-prog" v-model="filters.program_id" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="p in higher.programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="ef-year" v-model="filters.academic_year_id" @update:model-value="load" />
    </form>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.ins.matricule') }}</th><th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.level') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('hi.ins.mode') }}</th><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="e in items" :key="e.id">
            <th scope="row"><router-link :to="{ name: 'higher-enrolment', params: { id: e.id } }">{{ e.matricule ?? t('hi.ins.matriculePending') }}</router-link></th>
            <td>{{ higher.programName(e.program_id) }}</td><td>{{ higher.levelName(e.level_id) }}</td><td>{{ e.group_id ? higher.groupName(e.group_id) : '—' }}</td>
            <td>{{ t(k('hi.mode.', e.study_mode)) }}</td><td>{{ higher.yearName(e.academic_year_id) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(e.status) ?? 'neutral'}`">{{ t(k('hi.ins.st.', e.status)) }}</span></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="act.error.value" />
    <form v-if="canEnrol" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <div class="hi-tabs hi-wide" role="tablist">
        <button type="button" role="tab" :aria-selected="mode === 'application'" @click="mode = 'application'">{{ t('hi.ins.fromApplication') }}</button>
        <button type="button" role="tab" :aria-selected="mode === 'reenrol'" @click="mode = 'reenrol'">{{ t('hi.ins.reenrol') }}</button>
      </div>
      <template v-if="mode === 'application'">
        <div class="field hi-wide">
<label for="en-app">{{ t('hi.ins.applicationPick') }}</label>
          <select id="en-app" v-model="form.application_id" :aria-invalid="touched && !form.application_id"><option value="" disabled>—</option><option v-for="a in admitted" :key="a.id" :value="a.id">{{ a.reference }} · {{ higher.programName(a.program_id) }} · {{ higher.levelName(a.level_id) }} · {{ t(k('hi.app.st.', a.status)) }}</option></select>
          <span v-if="admitted.length === 0" class="hint">{{ t('hi.ins.applicationNone') }}</span>
        </div>
      </template>
      <template v-else>
        <PersonPicker id="en-person" v-model="form.person_id" :label="t('hi.ins.student')" class="hi-wide" />
        <div class="field">
<label for="en-level">{{ t('hi.level') }}</label>
          <select id="en-level" v-model="form.level_id" :aria-invalid="touched && !form.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in higher.levels" :key="l.id" :value="l.id">{{ l.code }} · {{ higher.programName(l.program_id) }}</option></select>
        </div>
        <YearField id="en-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      </template>
      <div v-if="mode === 'reenrol'" class="field"><label for="en-group">{{ t('hi.group') }}</label><select id="en-group" v-model="form.group_id"><option value="">{{ t('hi.groupNone') }}</option><option v-for="g in levelGroups" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
      <div class="field"><label for="en-mode">{{ t('hi.ins.mode') }}</label><select id="en-mode" v-model="form.study_mode"><option value="">{{ t('hi.ins.unchanged') }}</option><option v-for="m in STUDY_MODES" :key="m" :value="m">{{ t(k('hi.mode.', m)) }}</option></select></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.ins.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
