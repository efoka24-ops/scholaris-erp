<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { billing, parseXaf, type FeeGrid, type FeeKind, type GridTerm } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Grilles de frais par école, filière, niveau et mode (EX-FIN-01, 02) — design : Frais.html et InscriptionPieces.html (détail des frais).
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const grids = ref<FeeGrid[]>([]);
const kinds = ref<FeeKind[]>([]);
const yearFilter = ref('');
const touched = ref(false);
const customTerms = ref(false);
const form = reactive({ academic_year_id: '', school_id: '', program_id: '', level_id: '', study_mode: '' as '' | 'day' | 'evening', label: '' });
const fees = ref<Array<{ fee_code: string; amount: string; due_on: string }>>([{ fee_code: 'hi.inscription', amount: '', due_on: '' }, { fee_code: 'hi.pension', amount: '', due_on: '' }]);
const terms = ref<Array<{ due_on: string; weight: number }>>([{ due_on: '', weight: 40 }, { due_on: '', weight: 30 }, { due_on: '', weight: 30 }]);

const canManage = computed(() => ctx.can(...P.billingManage) && !higher.readOnly);
const formLevels = computed(() => higher.levels.filter((l) => l.program_id === form.program_id));
const feesBad = computed(() => touched.value && (fees.value.length === 0 || fees.value.some((f) => !f.fee_code || parseXaf(f.amount) === null)));
const termsBad = computed(() => touched.value && customTerms.value && terms.value.some((x) => !x.due_on || !(Number(x.weight) > 0)));
const kindLabel = (code: string): string => {
  const kd = kinds.value.find((x) => x.code === code);
  return kd ? (locale.value === 'en' ? kd.label_en : kd.label_fr) : code;
};

async function load(): Promise<void> {
  await higher.loadRefs('schools', 'programs', 'levels', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (yearFilter.value) q.academic_year_id = yearFilter.value;
  const res = await list.run(async () => ({ g: await billing.grids(q), k: kinds.value.length ? kinds.value : await billing.feeKinds() }));
  if (!res) return;
  grids.value = res.g;
  kinds.value = res.k;
  if (!form.school_id) form.school_id = higher.scopedSchoolId ?? higher.schools[0]?.id ?? '';
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.academic_year_id || !form.school_id || !form.label.trim() || feesBad.value || termsBad.value) return;
  const res = await act.run(() =>
    billing.createGrid({
      academic_year_id: form.academic_year_id,
      school_id: form.school_id,
      program_id: form.program_id || null,
      level_id: form.level_id || null,
      study_mode: form.study_mode || null,
      label: form.label.trim(),
      fees: fees.value.map((f) => ({ fee_code: f.fee_code, amount_xaf: parseXaf(f.amount) ?? 0, due_on: f.due_on || null })),
      terms: customTerms.value ? terms.value.map((x): GridTerm => ({ due_on: x.due_on, weight: Number(x.weight) })) : null,
    }),
  );
  if (!res) return;
  touched.value = false;
  form.label = '';
  await load();
}

async function archive(g: FeeGrid): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.fin.archiveConfirm'))) return;
  const res = await act.run(() => billing.archiveGrid(g.id));
  if (res) await load();
}

const total = (g: FeeGrid): number => g.fees.reduce((a, f) => a + f.amount_xaf, 0);

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.fin.gridsTitle')" :lede="t('hi.fin.gridsLede')" section="finance" @school-change="load">
    <div class="hi-form"><YearField id="fg-year" v-model="yearFilter" @update:model-value="load" /></div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <ul class="hi-list">
      <li v-for="g in grids" :key="g.id" class="card hi-grid">
        <div class="hi-grow">
          <div class="row"><strong>{{ g.label }}</strong><span class="tag" :class="`tag--${toneOf(g.status) ?? 'neutral'}`">{{ t(k('hi.fin.state.', g.status)) }}</span></div>
          <p class="muted hi-small">{{ higher.schoolName(g.school_id) }} · {{ g.program_id ? higher.programName(g.program_id) : t('hi.fin.allPrograms') }} · {{ g.level_id ? higher.levelName(g.level_id) : t('hi.fin.allLevels') }} · {{ g.study_mode ? t(k('hi.mode.', g.study_mode)) : t('hi.fin.allModes') }} · {{ higher.yearName(g.academic_year_id) }}</p>
          <table>
            <caption class="sr-only">{{ t('hi.fin.fees') }} · {{ g.label }}</caption>
            <tbody>
              <tr v-for="f in g.fees" :key="f.fee_code"><th scope="row">{{ kindLabel(f.fee_code) }}</th><td class="num">{{ formatXaf(f.amount_xaf, locale) }}</td></tr>
              <tr><th scope="row">{{ t('hi.total') }}</th><td class="num"><strong>{{ formatXaf(total(g), locale) }}</strong></td></tr>
            </tbody>
          </table>
          <p v-if="g.terms?.length" class="hi-small">{{ t('hi.fin.terms') }} : <span v-for="(x, n) in g.terms" :key="n">{{ formatDate(x.due_on, locale) }} ({{ x.weight }})<template v-if="n < g.terms.length - 1"> · </template></span></p>
        </div>
        <button v-if="canManage && g.status === 'active'" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="archive(g)">{{ t('hi.fin.archive') }}</button>
      </li>
      <li v-if="grids.length === 0" class="muted">{{ t('hi.none') }}</li>
    </ul>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.fin.gridNew') }}</h2>
      <YearField id="gn-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="gn-school">{{ t('hi.school.label') }}</label><select id="gn-school" v-model="form.school_id"><option value="" disabled>{{ t('hi.schoolPick') }}</option><option v-for="s in higher.schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select></div>
      <div class="field"><label for="gn-prog">{{ t('hi.program') }}</label><select id="gn-prog" v-model="form.program_id" @change="form.level_id = ''"><option value="">{{ t('hi.fin.allPrograms') }}</option><option v-for="p in higher.programs.filter((x) => !form.school_id || x.school_id === form.school_id)" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select></div>
      <div class="field"><label for="gn-level">{{ t('hi.level') }}</label><select id="gn-level" v-model="form.level_id"><option value="">{{ t('hi.fin.allLevels') }}</option><option v-for="l in formLevels" :key="l.id" :value="l.id">{{ l.code }}</option></select></div>
      <div class="field"><label for="gn-mode">{{ t('hi.ins.mode') }}</label><select id="gn-mode" v-model="form.study_mode"><option value="">{{ t('hi.fin.allModes') }}</option><option value="day">{{ t('hi.mode.day') }}</option><option value="evening">{{ t('hi.mode.evening') }}</option></select></div>
      <div class="field"><label for="gn-label">{{ t('hi.fin.gridLabel') }}</label><input id="gn-label" v-model="form.label" type="text" :aria-invalid="touched && !form.label.trim()" /></div>

      <fieldset class="field hi-wide">
        <legend class="label">{{ t('hi.fin.fees') }}</legend>
        <div v-for="(f, n) in fees" :key="n" class="row hi-feerow">
          <label :for="`fee-kind-${n}`" class="sr-only">{{ t('hi.fin.feeKind') }}</label>
          <select :id="`fee-kind-${n}`" v-model="f.fee_code"><option v-for="kd in kinds" :key="kd.code" :value="kd.code">{{ locale === 'en' ? kd.label_en : kd.label_fr }}</option></select>
          <label :for="`fee-amt-${n}`" class="sr-only">{{ t('hi.amount') }}</label>
          <input :id="`fee-amt-${n}`" v-model="f.amount" type="text" inputmode="numeric" :placeholder="t('hi.amount')" :aria-invalid="touched && parseXaf(f.amount) === null" />
          <button type="button" class="hi-linkbtn" @click="fees.splice(n, 1)">{{ t('hi.fin.removeFee') }}</button>
        </div>
        <button type="button" class="btn btn--quiet" @click="fees.push({ fee_code: kinds[0]?.code ?? 'hi.pension', amount: '', due_on: '' })">{{ t('hi.fin.addFee') }}</button>
        <span v-if="feesBad" class="error">{{ t('hi.amountBad') }}</span>
      </fieldset>

      <fieldset class="field hi-wide">
        <legend class="label">{{ t('hi.fin.terms') }}</legend>
        <label class="check"><input v-model="customTerms" type="checkbox" /> {{ customTerms ? t('hi.fin.terms') : t('hi.fin.termsDefault') }}</label>
        <template v-if="customTerms">
          <div v-for="(x, n) in terms" :key="n" class="row hi-feerow">
            <label :for="`term-date-${n}`" class="sr-only">{{ t('hi.date') }} {{ n + 1 }}</label><input :id="`term-date-${n}`" v-model="x.due_on" type="date" />
            <label :for="`term-w-${n}`" class="sr-only">{{ t('hi.fin.weight') }} {{ n + 1 }}</label><input :id="`term-w-${n}`" v-model.number="x.weight" type="number" min="1" :placeholder="t('hi.fin.weight')" />
            <button type="button" class="hi-linkbtn" @click="terms.splice(n, 1)">{{ t('hi.fin.removeFee') }}</button>
          </div>
          <button type="button" class="btn btn--quiet" @click="terms.push({ due_on: '', weight: 10 })">{{ t('hi.fin.addTerm') }}</button>
          <span v-if="termsBad" class="error">{{ t('hi.required') }}</span>
        </template>
      </fieldset>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>

<style scoped>
.hi-grid {
  align-items: flex-start;
}
.hi-feerow {
  flex-wrap: nowrap;
  margin-bottom: 8px;
}
.hi-feerow > select,
.hi-feerow > input {
  flex: 1;
  min-width: 0;
}
</style>
