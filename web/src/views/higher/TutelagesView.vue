<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { academic, type Tutelage } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { expiryThreshold } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Registre des tutelles par année (EX-ACA-04, RG-ORG-8) — design : DocRegistre.html (registre et formulaire latéral).
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<Tutelage[]>([]);
const yearFilter = ref('');
const touched = ref(false);
const form = reactive({ program_id: '', academic_year_id: '', university_name: '', convention_reference: '', convention_ends_on: '', approved_programme_ref: '', contact: '' });

const canManage = computed(() => ctx.can(...P.tutelageManage) && !higher.readOnly);
const lmdPrograms = computed(() => higher.programs.filter((p) => p.regime === 'lmd'));
const daysUntil = (iso: string | null): number | null => {
  if (!iso) return null;
  return Math.round((new Date(`${iso}T00:00:00`).getTime() - new Date(`${todayIso()}T00:00:00`).getTime()) / 86400000);
};

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'years');
  const q = { ...higher.scope(), ...(yearFilter.value ? { 'filter[academic_year_id]': yearFilter.value } : {}) };
  const res = await list.run(() => academic.tutelages(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.program_id || !form.academic_year_id || !form.university_name.trim()) return;
  const [name, role, phone] = form.contact.split(',').map((s) => s.trim());
  const res = await save.run(() =>
    academic.createTutelage({
      program_id: form.program_id,
      academic_year_id: form.academic_year_id,
      university_name: form.university_name.trim(),
      convention_reference: form.convention_reference.trim() || null,
      convention_document_id: null,
      convention_starts_on: null,
      convention_ends_on: form.convention_ends_on || null,
      approved_programme_ref: form.approved_programme_ref.trim() || null,
      contacts: name ? [{ name, ...(role ? { role } : {}), ...(phone ? { phone } : {}) }] : [],
    }),
  );
  if (!res) return;
  Object.assign(form, { university_name: '', convention_reference: '', convention_ends_on: '', approved_programme_ref: '', contact: '' });
  touched.value = false;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.tut.title')" :lede="t('hi.tut.lede')" section="academic" @school-change="load">
    <div class="hi-form">
      <YearField id="tut-year-filter" v-model="yearFilter" :label="t('hi.filters') + ' · ' + t('hi.year')" @update:model-value="load" />
    </div>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.tut.university') }}</th>
            <th scope="col">{{ t('hi.tut.convention') }}</th><th scope="col">{{ t('hi.tut.conventionEnds') }}</th><th scope="col">{{ t('hi.tut.programmeRef') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="x in items" :key="x.id">
            <th scope="row">{{ higher.programName(x.program_id) }}</th>
            <td>{{ higher.yearName(x.academic_year_id) }}</td>
            <td>{{ x.university_name }}</td>
            <td>{{ x.convention_reference ?? '—' }}</td>
            <td>
              {{ formatDate(x.convention_ends_on, locale) || '—' }}
              <span v-if="expiryThreshold(daysUntil(x.convention_ends_on))" class="tag tag--warning">{{ t('hi.tut.expiring', { days: daysUntil(x.convention_ends_on) ?? 0 }) }}</span>
            </td>
            <td>{{ x.approved_programme_ref ?? '—' }}</td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="save.error.value" />
    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.tut.new') }}</h2>
      <p class="muted hi-wide hi-small">{{ t('hi.tut.lmdOnly') }}</p>
      <div class="field">
<label for="t-prog">{{ t('hi.program') }}</label>
        <select id="t-prog" v-model="form.program_id" :aria-invalid="touched && !form.program_id"><option value="" disabled>{{ t('hi.programPick') }}</option><option v-for="p in lmdPrograms" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="t-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="t-uni">{{ t('hi.tut.university') }}</label><input id="t-uni" v-model="form.university_name" type="text" :aria-invalid="touched && !form.university_name.trim()" /></div>
      <div class="field"><label for="t-conv">{{ t('hi.tut.convention') }}</label><input id="t-conv" v-model="form.convention_reference" type="text" /></div>
      <div class="field"><label for="t-end">{{ t('hi.tut.conventionEnds') }}</label><input id="t-end" v-model="form.convention_ends_on" type="date" /></div>
      <div class="field"><label for="t-ref">{{ t('hi.tut.programmeRef') }}</label><input id="t-ref" v-model="form.approved_programme_ref" type="text" /></div>
      <div class="field hi-wide"><label for="t-contact">{{ t('hi.tut.contact') }}</label><input id="t-contact" v-model="form.contact" type="text" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.save') }}</button></div>
    </form>
  </HigherFrame>
</template>
