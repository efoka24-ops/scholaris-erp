<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { teaching, TEACHER_STATUSES, type Teacher, type TeacherStatus } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import PersonPicker from '../PersonPicker.vue';
import { splitCodes } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Dossiers enseignants (EX-ENS-01) : statut, grade, diplômes, autorisation de l'employeur pour un vacataire fonctionnaire.
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Teacher[]>([]);
const statusFilter = ref('');
const editing = ref<string | null>(null);
const touched = ref(false);
const blank = () => ({ person_id: null as string | null, teacher_status: 'permanent' as TeacherStatus, department_id: '', grade: '', diplomas: '', origin_establishment: '', public_servant: false, employer_authorization_ref: '', employer_authorization_until: '' });
const form = reactive(blank());

const canManage = computed(() => ctx.can(...TP.teacherManage) && !higher.readOnly);
const authMissing = (x: Teacher): boolean => x.teacher_status === 'vacataire' && x.public_servant && !x.employer_authorization_ref;

async function load(): Promise<void> {
  await higher.loadRefs('departments');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (statusFilter.value) q['filter[teacher_status]'] = statusFilter.value;
  const res = await list.run(() => teaching.teachers(q));
  if (res) items.value = res;
}

function edit(x: Teacher): void {
  editing.value = x.person_id;
  Object.assign(form, {
    person_id: x.person_id, teacher_status: x.teacher_status, department_id: x.department_id ?? '', grade: x.grade ?? '', diplomas: x.diplomas.join(', '),
    origin_establishment: x.origin_establishment ?? '', public_servant: x.public_servant, employer_authorization_ref: x.employer_authorization_ref ?? '',
    employer_authorization_until: x.employer_authorization_until ?? '',
  });
}

function reset(): void {
  editing.value = null;
  touched.value = false;
  Object.assign(form, blank());
}

async function save(): Promise<void> {
  touched.value = true;
  if (!form.person_id) return;
  const opt = (v: string): string | null => (v.trim() ? v.trim() : null);
  const body = {
    teacher_status: form.teacher_status, department_id: opt(form.department_id), grade: opt(form.grade), diplomas: splitCodes(form.diplomas),
    origin_establishment: opt(form.origin_establishment), public_servant: form.public_servant, employer_authorization_ref: opt(form.employer_authorization_ref),
    employer_authorization_until: opt(form.employer_authorization_until),
  };
  const person = form.person_id;
  const res = await act.run(() => (editing.value ? teaching.updateTeacher(person, body) : teaching.createTeacher({ person_id: person, ...body })));
  if (!res) return;
  reset();
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.tea.title')" :lede="t('ht.tea.lede')" @school-change="load">
    <div class="hi-form">
      <div class="field">
        <label for="te-status">{{ t('ht.tea.status') }}</label>
        <select id="te-status" v-model="statusFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in TEACHER_STATUSES" :key="s" :value="s">{{ t(hk('ht.tea.status.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ t('ht.teacher') }}</th><th scope="col">{{ t('ht.tea.status') }}</th><th scope="col">{{ t('ht.tea.grade') }}</th><th scope="col">{{ t('hi.department') }}</th><th scope="col">{{ t('ht.tea.diplomas') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="x in items" :key="x.id">
            <th scope="row" class="hi-mono">{{ x.person_id }}</th>
            <td>
              <span class="tag tag--neutral">{{ t(hk('ht.tea.status.', x.teacher_status)) }}</span>
              <span v-if="authMissing(x)" class="tag tag--warning" data-test="auth-missing">{{ t('ht.tea.authMissing') }}</span>
              <span v-else-if="x.employer_authorization_until" class="muted hi-small"> · {{ formatDate(x.employer_authorization_until, locale) }}</span>
            </td>
            <td>{{ x.grade ?? '—' }}</td>
            <td>{{ higher.departmentName(x.department_id) }}</td>
            <td>{{ x.diplomas.join(', ') || '—' }}</td>
            <td class="hi-actions"><button v-if="canManage" type="button" class="hi-linkbtn" @click="edit(x)">{{ t('hi.edit') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="save">
      <h2 class="hi-wide">{{ editing ? t('ht.tea.edit') : t('ht.tea.new') }}</h2>
      <div v-if="!editing" class="hi-wide"><PersonPicker id="te-person" v-model="form.person_id" :label="t('ht.teacher')" /><span v-if="touched && !form.person_id" class="error">{{ t('hi.required') }}</span></div>
      <p v-else class="hi-wide hi-mono">{{ editing }}</p>
      <div class="field"><label for="te-st">{{ t('ht.tea.status') }}</label><select id="te-st" v-model="form.teacher_status"><option v-for="s in TEACHER_STATUSES" :key="s" :value="s">{{ t(hk('ht.tea.status.', s)) }}</option></select></div>
      <div class="field">
        <label for="te-dep">{{ t('hi.department') }}</label>
        <select id="te-dep" v-model="form.department_id"><option value="">{{ t('hi.departmentPick') }}</option><option v-for="d in higher.departments" :key="d.id" :value="d.id">{{ d.code }} · {{ d.name }}</option></select>
      </div>
      <div class="field"><label for="te-grade">{{ t('ht.tea.grade') }}</label><input id="te-grade" v-model="form.grade" type="text" /></div>
      <div class="field"><label for="te-dipl">{{ t('ht.tea.diplomas') }}</label><input id="te-dipl" v-model="form.diplomas" type="text" aria-describedby="te-dipl-hint" /><span id="te-dipl-hint" class="hint">{{ t('ht.tea.diplomasHint') }}</span></div>
      <div class="field"><label for="te-origin">{{ t('ht.tea.origin') }}</label><input id="te-origin" v-model="form.origin_establishment" type="text" /></div>
      <label class="check"><input v-model="form.public_servant" type="checkbox" /> {{ t('ht.tea.publicServant') }}</label>
      <template v-if="form.public_servant">
        <div class="field"><label for="te-auth">{{ t('ht.tea.authRef') }}</label><input id="te-auth" v-model="form.employer_authorization_ref" type="text" /></div>
        <div class="field"><label for="te-until">{{ t('ht.tea.authUntil') }}</label><input id="te-until" v-model="form.employer_authorization_until" type="date" /></div>
      </template>
      <div class="field hi-actions">
        <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ editing ? t('hi.save') : t('hi.create') }}</button>
        <button v-if="editing" type="button" class="btn btn--quiet" @click="reset">{{ t('hi.cancel') }}</button>
      </div>
    </form>
  </TeachingFrame>
</template>
