<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, SEMESTERS, type ExamSession, type Jury } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { locale } from '@/i18n';
import PersonPicker from '../PersonPicker.vue';
import YearField from '../YearField.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import { DP } from './access';
import { dk, dt } from './i18n';

// Jurys de délibération (EX-DEL-02) : liste du périmètre et constitution (groupe, semestre, session, président, membres, quorum).
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Jury[]>([]);
const sessionFilter = ref('');
const touched = ref(false);
const memberPick = ref<string | null>(null);
const form = reactive({ structure_id: '', academic_year_id: '', period_id: 'S1', exam_session: 'normal' as ExamSession, president: null as string | null, members: [] as string[], quorum: '' as string | number, agenda: '' });

const canCreate = computed(() => ctx.can(...DP.prepare) && !higher.readOnly);
const invalid = computed(() => !form.structure_id || !form.academic_year_id || !form.period_id || !form.president || form.members.length < 1);

async function load(): Promise<void> {
  await higher.loadRefs('groups', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (sessionFilter.value) q['filter[exam_session]'] = sessionFilter.value;
  const res = await list.run(() => deliberation.juries(q));
  if (res) items.value = res;
}

function addMember(id: string | null): void {
  if (id && id !== form.president && !form.members.includes(id)) form.members.push(id);
  memberPick.value = null;
}

async function create(): Promise<void> {
  touched.value = true;
  const president = form.president;
  if (invalid.value || !president) return;
  const res = await act.run(() =>
    deliberation.createJury({
      structure_id: form.structure_id, academic_year_id: form.academic_year_id, period_id: form.period_id, period_label: form.period_id,
      exam_session: form.exam_session, president_person_id: president, members: form.members,
      ...(form.quorum !== '' ? { quorum_min: Number(form.quorum) } : {}), ...(form.agenda.trim() ? { agenda: form.agenda.trim() } : {}),
    }),
  );
  if (!res) return;
  touched.value = false;
  Object.assign(form, { president: null, members: [], quorum: '', agenda: '' });
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <DelFrame :title="dt('hdl.jury.title')" :lede="dt('hdl.jury.lede')" @school-change="load">
    <div class="hi-form">
      <div class="field">
        <label for="jf-session">{{ dt('hdl.session') }}</label>
        <select id="jf-session" v-model="sessionFilter" @change="load"><option value="">—</option><option value="normal">{{ dt('hdl.session.normal') }}</option><option value="resit">{{ dt('hdl.session.resit') }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ dt('hdl.group') }}</th><th scope="col">{{ dt('hdl.period') }}</th><th scope="col">{{ dt('hdl.session') }}</th><th scope="col">{{ dt('hdl.year') }}</th><th scope="col">{{ dt('hdl.status') }}</th><th scope="col">{{ dt('hdl.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="j in items" :key="j.id">
            <th scope="row">{{ higher.groupName(j.structure_id) }}</th>
            <td>{{ j.period_label || j.period_id }}</td>
            <td>{{ dk('hdl.session.', j.exam_session) }}</td>
            <td>{{ higher.yearName(j.academic_year_id) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(j.status) ?? 'neutral'}`">{{ dk('hdl.state.', j.status) }}</span><span v-if="j.published_at" class="hdl-sub">{{ formatDate(j.published_at, locale) }}</span></td>
            <td><router-link :to="{ name: 'higher-del-jury', params: { id: j.id } }">{{ dt('hdl.jury.open') }}</router-link></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ dt('hdl.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <form v-if="canCreate" class="card hi-section hi-form" novalidate data-test="jury-form" @submit.prevent="create">
      <h2 class="hi-wide">{{ dt('hdl.jury.new') }}</h2>
      <div class="field">
        <label for="jn-group">{{ dt('hdl.group') }}</label>
        <select id="jn-group" v-model="form.structure_id" :aria-invalid="touched && !form.structure_id"><option value="" disabled>{{ dt('hdl.groupPick') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
        <span v-if="touched && !form.structure_id" class="error">{{ dt('hdl.required') }}</span>
      </div>
      <YearField id="jn-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="jn-period">{{ dt('hdl.period') }}</label><select id="jn-period" v-model="form.period_id"><option v-for="s in SEMESTERS" :key="s" :value="s">{{ s }}</option></select></div>
      <div class="field"><label for="jn-session">{{ dt('hdl.session') }}</label><select id="jn-session" v-model="form.exam_session"><option value="normal">{{ dt('hdl.session.normal') }}</option><option value="resit">{{ dt('hdl.session.resit') }}</option></select></div>
      <div class="hi-wide"><PersonPicker id="jn-president" v-model="form.president" :label="dt('hdl.jury.president')" /><span v-if="touched && !form.president" class="error">{{ dt('hdl.required') }}</span></div>
      <div class="hi-wide">
        <PersonPicker id="jn-member" :model-value="memberPick" :label="dt('hdl.jury.members')" @update:model-value="addMember" />
        <p class="hint">{{ dt('hdl.jury.membersHint') }}</p>
        <ul class="hi-list"><li v-for="m in form.members" :key="m">{{ m }} <button type="button" class="hi-linkbtn" @click="form.members = form.members.filter((x) => x !== m)">×</button></li></ul>
        <span v-if="touched && form.members.length < 1" class="error">{{ dt('hdl.required') }}</span>
      </div>
      <div class="field"><label for="jn-quorum">{{ dt('hdl.jury.quorum') }}</label><input id="jn-quorum" v-model="form.quorum" type="number" min="1" :max="form.members.length + 1" /></div>
      <div class="field hi-wide"><label for="jn-agenda">{{ dt('hdl.jury.agenda') }}</label><textarea id="jn-agenda" v-model="form.agenda" maxlength="2000" /></div>
      <ErrorNotice :error="act.error.value" class="hi-wide" />
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ dt('hdl.jury.create') }}</button></div>
    </form>
  </DelFrame>
</template>
