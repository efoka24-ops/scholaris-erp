<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, isUnsignedMinutes, type YearDecision } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import YearField from '../YearField.vue';
import { splitCodes } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import UnsignedNotice from './UnsignedNotice.vue';
import { DP } from './access';
import { dt, num } from './i18n';

// Décisions de fin d'année (EX-DEL-06) : aperçu (passage, crédits cumulés), dérogations cochées, puis enregistrement.
const ctx = useContextStore();
const higher = useHigherStore();
const act = useHigherAction();
const hist = useHigherAction();
const form = reactive({ structure_id: '', academic_year_id: '', periods: 'S1, S2' });
const rows = ref<YearDecision[]>([]);
const derogations = ref<string[]>([]);
const preview = ref(false);
const recorded = ref<number | null>(null);
const unsigned = ref(false);
const history = ref<YearDecision[]>([]);
const canWrite = computed(() => ctx.can(...DP.prepare) && !higher.readOnly);

const body = () => ({ structure_id: form.structure_id, academic_year_id: form.academic_year_id, period_ids: splitCodes(form.periods), derogations: derogations.value });

async function run(record: boolean): Promise<void> {
  if (!form.structure_id || !form.academic_year_id || splitCodes(form.periods).length === 0) return;
  unsigned.value = false;
  recorded.value = null;
  const res = await act.run(() => (record ? deliberation.recordYear(body()) : deliberation.previewYear(body())));
  if (!res) {
    if (isUnsignedMinutes(act.error.value)) {
      unsigned.value = true;
      act.error.value = null;
    }
    return;
  }
  rows.value = res;
  preview.value = !record;
  if (record) {
    recorded.value = res.length;
    await loadHistory();
  }
}

async function loadHistory(): Promise<void> {
  if (!form.structure_id) return;
  const res = await hist.run(() => deliberation.yearDecisions({ 'filter[structure_id]': form.structure_id, ...(form.academic_year_id ? { 'filter[academic_year_id]': form.academic_year_id } : {}) }));
  if (res) history.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'years');
});
</script>

<template>
  <DelFrame :title="dt('hdl.year.title')" :lede="dt('hdl.year.lede')">
    <form class="hi-form" novalidate @submit.prevent="run(false)">
      <div class="field">
        <label for="yd-group">{{ dt('hdl.group') }}</label>
        <select id="yd-group" v-model="form.structure_id" @change="loadHistory"><option value="" disabled>{{ dt('hdl.groupPick') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
      </div>
      <YearField id="yd-year" v-model="form.academic_year_id" :invalid="false" />
      <div class="field"><label for="yd-periods">{{ dt('hdl.year.periods') }}</label><input id="yd-periods" v-model="form.periods" type="text" aria-describedby="yd-periods-hint" /><span id="yd-periods-hint" class="hint">{{ dt('hdl.year.periodsHint') }}</span></div>
      <div class="field"><button type="submit" class="btn" :disabled="act.busy.value" data-test="year-preview">{{ dt('hdl.year.preview') }}</button></div>
    </form>
    <UnsignedNotice v-if="unsigned" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="preview" class="muted">{{ dt('hdl.year.previewNote') }}</p>
    <p v-if="recorded !== null" class="notice notice--success" role="status">{{ dt('hdl.year.recorded', { count: recorded }) }}</p>
    <div v-if="rows.length" class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ dt('hdl.student') }}</th><th scope="col" class="num">{{ dt('hdl.prep.average') }}</th><th scope="col" class="num">{{ dt('hdl.prep.credits') }}</th><th scope="col" class="num">{{ dt('hdl.year.cumulative') }}</th><th scope="col">{{ dt('hdl.year.decision') }}</th><th scope="col">{{ dt('hdl.year.missing') }}</th><th scope="col">{{ dt('hdl.year.derogation') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.person_id">
            <th scope="row">{{ r.matricule ?? r.person_id }}</th>
            <td class="num">{{ num(r.average) }}</td><td class="num">{{ r.credits_earned }} / {{ r.credits_total }}</td><td class="num">{{ r.cumulative_credits }}</td>
            <td><span class="tag tag--neutral">{{ r.decision_label }}</span><span v-if="r.diploma_file" class="hdl-sub">{{ dt('hdl.year.diploma') }}</span></td>
            <td>{{ r.missing_units.join(', ') || '—' }}</td>
            <td><label class="check"><input v-model="derogations" type="checkbox" :value="r.person_id" :disabled="!canWrite" /> {{ dt('hdl.year.derogation') }}</label></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="canWrite && preview" class="hi-actions hi-section">
      <button type="button" class="btn" :disabled="act.busy.value" @click="run(false)">{{ dt('hdl.year.preview') }}</button>
      <button type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="year-record" @click="run(true)">{{ dt('hdl.year.record') }}</button>
    </div>

    <section v-if="history.length" class="hi-section">
      <h2>{{ dt('hdl.year.history') }}</h2>
      <ErrorNotice :error="hist.error.value" />
      <ul class="hi-list"><li v-for="h in history" :key="h.id ?? h.person_id">{{ h.person_id }} · {{ h.decision_label }} · {{ num(h.average) }} · {{ h.cumulative_credits }}</li></ul>
    </section>
  </DelFrame>
</template>
