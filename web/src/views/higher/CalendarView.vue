<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { academic, CALENDAR_KINDS, type CalendarEntry, type CalendarKind } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Calendrier académique (EX-ACA-06) — design : Fondations.html (tableau) ; dates au format local.
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<CalendarEntry[]>([]);
const touched = ref(false);
const form = reactive({ school_id: '', academic_year_id: '', entry_kind: 'semester' as CalendarKind, label: '', starts_on: '', ends_on: '', semester_ordinal: '' as string | number });

const canManage = computed(() => ctx.can(...P.calendarManage) && !higher.readOnly);
const datesBad = computed(() => touched.value && (!form.starts_on || !form.ends_on || form.ends_on < form.starts_on));
const semesterBad = computed(() => touched.value && form.entry_kind === 'semester' && !(Number(form.semester_ordinal) >= 1));

async function load(): Promise<void> {
  await higher.loadRefs('schools', 'years');
  const res = await list.run(() => academic.calendar({ ...higher.scope(), sort: 'starts_on' }));
  if (res) items.value = res;
  if (!form.school_id) form.school_id = higher.scopedSchoolId ?? higher.schools[0]?.id ?? '';
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.school_id || !form.academic_year_id || !form.label.trim() || datesBad.value || semesterBad.value) return;
  const res = await save.run(() =>
    academic.createCalendarEntry({
      school_id: form.school_id,
      academic_year_id: form.academic_year_id,
      entry_kind: form.entry_kind,
      label: form.label.trim(),
      starts_on: form.starts_on,
      ends_on: form.ends_on,
      semester_ordinal: form.entry_kind === 'semester' ? Number(form.semester_ordinal) : null,
    }),
  );
  if (!res) return;
  Object.assign(form, { label: '', starts_on: '', ends_on: '', semester_ordinal: '' });
  touched.value = false;
  await load();
}

async function remove(e: CalendarEntry): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.cal.deleteConfirm'))) return;
  const res = await save.run(() => academic.deleteCalendarEntry(e.id).then(() => true));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.cal.title')" :lede="t('hi.cal.lede')" section="academic" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ t('hi.cal.kind') }}</th><th scope="col">{{ t('hi.label') }}</th><th scope="col">{{ t('hi.startsOn') }}</th><th scope="col">{{ t('hi.endsOn') }}</th><th scope="col">{{ t('hi.school.label') }}</th><th scope="col">{{ t('hi.year') }}</th><th v-if="canManage" scope="col">{{ t('hi.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="e in items" :key="e.id">
            <th scope="row">{{ t(k('hi.cal.kind.', e.entry_kind)) }}<template v-if="e.semester_ordinal"> {{ e.semester_ordinal }}</template></th>
            <td>{{ e.label }}</td><td>{{ formatDate(e.starts_on, locale) }}</td><td>{{ formatDate(e.ends_on, locale) }}</td>
            <td>{{ higher.schoolName(e.school_id) }}</td><td>{{ higher.yearName(e.academic_year_id) }}</td>
            <td v-if="canManage"><button type="button" class="hi-linkbtn" @click="remove(e)">{{ t('hi.delete') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="save.error.value" />
    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.cal.new') }}</h2>
      <div class="field">
<label for="c-school">{{ t('hi.school.label') }}</label>
        <select id="c-school" v-model="form.school_id" :aria-invalid="touched && !form.school_id"><option value="" disabled>{{ t('hi.schoolPick') }}</option><option v-for="s in higher.schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select>
      </div>
      <YearField id="c-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="c-kind">{{ t('hi.cal.kind') }}</label><select id="c-kind" v-model="form.entry_kind"><option v-for="x in CALENDAR_KINDS" :key="x" :value="x">{{ t(k('hi.cal.kind.', x)) }}</option></select></div>
      <div v-if="form.entry_kind === 'semester'" class="field"><label for="c-sem">{{ t('hi.cal.semester') }}</label><input id="c-sem" v-model="form.semester_ordinal" type="number" min="1" max="12" :aria-invalid="semesterBad" /><span v-if="semesterBad" class="error">{{ t('hi.cal.semesterRequired') }}</span></div>
      <div class="field"><label for="c-label">{{ t('hi.label') }}</label><input id="c-label" v-model="form.label" type="text" :aria-invalid="touched && !form.label.trim()" /></div>
      <div class="field"><label for="c-from">{{ t('hi.startsOn') }}</label><input id="c-from" v-model="form.starts_on" type="date" :aria-invalid="datesBad" /></div>
      <div class="field"><label for="c-to">{{ t('hi.endsOn') }}</label><input id="c-to" v-model="form.ends_on" type="date" :aria-invalid="datesBad" /><span v-if="datesBad" class="error">{{ t('hi.datesBad') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.save') }}</button></div>
    </form>
  </HigherFrame>
</template>
