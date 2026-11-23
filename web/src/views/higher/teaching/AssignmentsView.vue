<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatMinutes, SESSION_KINDS, teaching, type Assignment, type SessionKind } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import PersonPicker from '../PersonPicker.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import ElementField from './ElementField.vue';
import GroupField from './GroupField.vue';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Affectations des enseignants aux EC (EX-ENS-02) par le chef de département ; accord de dépassement (RG-ENS-4).
const { t } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Assignment[]>([]);
const { name: personName } = useHigherNames(() => items.value.map((x) => x.person_id));
const groupFilter = ref('');
const statusFilter = ref('active');
const touched = ref(false);
const form = reactive({ person_id: null as string | null, curriculum_id: '', element_id: '', group_id: '', session_kind: 'cm' as SessionKind, hours: 20 });
const overrunFor = ref<Assignment | null>(null);
const overrun = reactive({ hours: 2, document_ref: '', reason: '' });
const overrunTried = ref(false);

const canAssign = computed(() => ctx.can(...TP.assign) && !higher.readOnly);
const canOverrun = computed(() => ctx.can(...TP.overrunApprove) && !higher.readOnly);
const hoursBad = computed(() => touched.value && !(Number(form.hours) >= 0.5 && Number(form.hours) <= 1000));

async function load(): Promise<void> {
  await higher.loadRefs('groups', 'levels');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (groupFilter.value) q['filter[group_id]'] = groupFilter.value;
  if (statusFilter.value) q['filter[status]'] = statusFilter.value;
  const res = await list.run(() => teaching.assignments(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.person_id || !form.curriculum_id || !form.element_id || !form.group_id || hoursBad.value) return;
  const person = form.person_id;
  const res = await act.run(() =>
    teaching.assign({ person_id: person, curriculum_id: form.curriculum_id, element_id: form.element_id, group_id: form.group_id, session_kind: form.session_kind, planned_minutes: Math.round(Number(form.hours) * 60) }),
  );
  if (!res) return;
  touched.value = false;
  await load();
}

async function end(a: Assignment): Promise<void> {
  const res = await act.run(() => teaching.endAssignment(a.id));
  if (res) await load();
}

async function approveOverrun(): Promise<void> {
  overrunTried.value = true;
  const a = overrunFor.value;
  if (!a || !overrun.document_ref.trim() || !overrun.reason.trim() || !(Number(overrun.hours) >= 0.5)) return;
  const res = await act.run(() => teaching.approveOverrun(a.id, { extra_minutes: Math.round(Number(overrun.hours) * 60), document_ref: overrun.document_ref.trim(), reason: overrun.reason.trim() }));
  if (!res) return;
  overrunFor.value = null;
  overrunTried.value = false;
  Object.assign(overrun, { document_ref: '', reason: '' });
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.asg.title')" :lede="t('ht.asg.lede')" @school-change="load">
    <div class="hi-form">
      <div class="field">
        <label for="as-group">{{ t('hi.group') }}</label>
        <select id="as-group" v-model="groupFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ higher.levelName(g.level_id) }} · {{ g.name }}</option></select>
      </div>
      <div class="field">
        <label for="as-status">{{ t('hi.status') }}</label>
        <select id="as-status" v-model="statusFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option value="active">{{ t('ht.asg.state.active') }}</option><option value="ended">{{ t('ht.asg.state.ended') }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('ht.element') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('ht.kind') }}</th><th scope="col">{{ t('ht.teacher') }}</th><th scope="col" class="num">{{ t('ht.asg.planned') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="a in items" :key="a.id">
            <th scope="row">{{ a.element_code }} · {{ a.element_title }}</th>
            <td>{{ higher.groupName(a.group_id) }}</td>
            <td>{{ t(hk('ht.kind.', a.session_kind)) }}</td>
            <td>{{ personName(a.person_id) }}</td>
            <td class="num">{{ formatMinutes(a.planned_minutes) }}</td>
            <td><span class="tag" :class="a.status === 'active' ? 'tag--success' : 'tag--neutral'">{{ t(hk('ht.asg.state.', a.status)) }}</span></td>
            <td class="hi-actions">
              <button v-if="canAssign && a.status === 'active'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="end(a)">{{ t('ht.asg.end') }}</button>
              <button v-if="canOverrun && a.status === 'active'" type="button" class="hi-linkbtn" @click="overrunFor = a">{{ t('ht.asg.overrun') }}</button>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="overrunFor" class="card card--strong hi-section hi-form" role="dialog" aria-labelledby="ov-title" data-test="overrun">
      <h2 id="ov-title" class="hi-wide">{{ t('ht.asg.overrun') }} · {{ overrunFor.element_code }}</h2>
      <p class="hi-wide">{{ t('ht.asg.overrunText') }}</p>
      <div class="field"><label for="ov-hours">{{ t('ht.asg.overrunMinutes') }}</label><input id="ov-hours" v-model.number="overrun.hours" type="number" min="0.5" step="0.5" /></div>
      <div class="field"><label for="ov-doc">{{ t('ht.asg.overrunDoc') }}</label><input id="ov-doc" v-model="overrun.document_ref" type="text" :aria-invalid="overrunTried && !overrun.document_ref.trim()" /></div>
      <div class="field hi-wide"><label for="ov-reason">{{ t('hi.reason') }}</label><textarea id="ov-reason" v-model="overrun.reason" :aria-invalid="overrunTried && !overrun.reason.trim()" /><span v-if="overrunTried && !overrun.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
      <div class="hi-actions hi-wide"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="approveOverrun">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="overrunFor = null">{{ t('hi.back') }}</button></div>
    </section>

    <form v-if="canAssign" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('ht.asg.new') }}</h2>
      <div class="hi-wide"><PersonPicker id="as-person" v-model="form.person_id" :label="t('ht.teacher')" /><span v-if="touched && !form.person_id" class="error">{{ t('hi.required') }}</span></div>
      <ElementField id="as-el" v-model:curriculum-id="form.curriculum_id" v-model:element-id="form.element_id" :invalid="touched" />
      <GroupField id="as-grp" v-model="form.group_id" :invalid="touched" />
      <div class="field"><label for="as-kind">{{ t('ht.kind') }}</label><select id="as-kind" v-model="form.session_kind"><option v-for="s in SESSION_KINDS" :key="s" :value="s">{{ t(hk('ht.kind.', s)) }}</option></select></div>
      <div class="field"><label for="as-hours">{{ t('ht.asg.plannedHours') }}</label><input id="as-hours" v-model.number="form.hours" type="number" min="0.5" step="0.5" :aria-invalid="hoursBad" /><span v-if="hoursBad" class="error">{{ t('ht.asg.plannedBad') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </TeachingFrame>
</template>
