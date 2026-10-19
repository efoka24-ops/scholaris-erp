<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { EXAM_TYPES, nationalExams, type ExamType, type NationalSession } from '@/api/higher-nationalexams';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { X } from './perms';
import { useNationalI18n, xk } from './i18n';

// Sessions d'examen national (EX-EXN-01) du périmètre ; création par la scolarité (filière BTS/HND, niveau de sortie déduit).
const { tx } = useNationalI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<NationalSession[]>([]);
const filters = reactive({ exam_type: '', academic_year_id: '' });
const form = reactive({ program_id: '', academic_year_id: '', exam_type: 'bts' as ExamType, attendance_from: '', attendance_to: '', fee_xaf: 0, pieces: '' });
const canManage = computed(() => ctx.can(...X.manage) && !higher.readOnly);
const examPrograms = computed(() => higher.programs.filter((p) => p.regime === 'bts' || p.regime === 'hnd'));

async function load(): Promise<void> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  for (const [key, value] of Object.entries(filters)) if (value) q[`filter[${key}]`] = value;
  const res = await list.run(() => nationalExams.sessions(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  const program = higher.programs.find((p) => p.id === form.program_id);
  const res = await save.run(() => nationalExams.create({
    program_id: form.program_id, academic_year_id: form.academic_year_id, exam_type: program?.regime === 'hnd' ? 'hnd' : form.exam_type,
    attendance_from: form.attendance_from, attendance_to: form.attendance_to, fee_xaf: Number(form.fee_xaf) || 0,
    required_pieces: form.pieces.split(',').map((p) => p.trim()).filter((p) => p !== ''),
  }));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  await load();
});
</script>

<template>
  <HigherFrame :title="tx('hx.title')" :lede="tx('hx.lede')" section="portal" @school-change="load">
    <div class="hi-form" role="search">
      <div class="field">
        <label for="xs-type">{{ tx('hx.examType') }}</label>
        <select id="xs-type" v-model="filters.exam_type" @change="load"><option value="">{{ tx('hx.all') }}</option><option v-for="t in EXAM_TYPES" :key="t" :value="t">{{ tx(xk('hx.type.', t)) }}</option></select>
      </div>
      <div class="field">
        <label for="xs-year">{{ tx('hx.year') }}</label>
        <select id="xs-year" v-model="filters.academic_year_id" @change="load"><option value="">{{ tx('hx.all') }}</option><option v-for="y in higher.years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ tx('hx.specialty') }}</th><th scope="col">{{ tx('hx.examType') }}</th><th scope="col">{{ tx('hx.year') }}</th><th scope="col" class="num">{{ tx('hx.exports') }}</th><th scope="col">{{ tx('hx.status') }}</th><th scope="col">{{ tx('hx.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in items" :key="s.id" data-test="session-row">
            <th scope="row">{{ s.specialty_label }}<br /><small class="muted">{{ higher.programName(s.program_id) }}</small></th>
            <td>{{ tx(xk('hx.type.', s.exam_type)) }}</td>
            <td>{{ higher.yearName(s.academic_year_id) }}</td>
            <td class="num">{{ s.export_count }}</td>
            <td><span class="tag tag--neutral">{{ tx(xk('hx.sessionState.', s.status)) }}</span></td>
            <td><router-link class="btn btn--quiet" :to="{ name: 'higher-national-exam', params: { id: s.id } }" data-test="open-session">{{ tx('hx.open') }} →</router-link></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ tx('hx.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <form v-if="canManage" class="card hi-section hi-form" data-test="create-session" @submit.prevent="create">
      <h2>{{ tx('hx.createTitle') }}</h2>
      <div class="field">
        <label for="xc-program">{{ tx('hx.program') }}</label>
        <select id="xc-program" v-model="form.program_id" required><option v-for="p in examPrograms" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <div class="field">
        <label for="xc-year">{{ tx('hx.year') }}</label>
        <select id="xc-year" v-model="form.academic_year_id" required><option v-for="y in higher.years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
      </div>
      <div class="field">
        <label for="xc-type">{{ tx('hx.examType') }}</label>
        <select id="xc-type" v-model="form.exam_type"><option v-for="t in EXAM_TYPES" :key="t" :value="t">{{ tx(xk('hx.type.', t)) }}</option></select>
      </div>
      <div class="field"><label for="xc-from">{{ tx('hx.attendanceFrom') }}</label><input id="xc-from" v-model="form.attendance_from" type="date" required /></div>
      <div class="field"><label for="xc-to">{{ tx('hx.attendanceTo') }}</label><input id="xc-to" v-model="form.attendance_to" type="date" required /></div>
      <div class="field"><label for="xc-fee">{{ tx('hx.fee') }}</label><input id="xc-fee" v-model.number="form.fee_xaf" type="number" min="0" /></div>
      <div class="field"><label for="xc-pieces">{{ tx('hx.pieces') }}</label><input id="xc-pieces" v-model="form.pieces" type="text" /></div>
      <ErrorNotice :error="save.error.value" />
      <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ tx('hx.create') }}</button>
    </form>
  </HigherFrame>
</template>
