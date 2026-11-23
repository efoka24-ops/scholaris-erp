<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatMinutes, teaching, type HoursRow, type HoursValidation } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Validation mensuelle des heures (EX-ENS-06) par le chef de département : seules les séances contre-signées sont validées ;
// le reste est signalé (pending) pour régularisation.
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const month = ref(new Date().toISOString().slice(0, 7));
const rows = ref<HoursRow[]>([]);
const history = ref<HoursValidation[]>([]);
const { name: personName } = useHigherNames(() => [...rows.value.map((x) => x.person_id), ...history.value.map((x) => x.person_id)]);
const pending = ref<number | null>(null);
const canValidate = computed(() => ctx.can(...TP.hoursValidate) && !higher.readOnly);
const monthBad = computed(() => !/^\d{4}-(0[1-9]|1[0-2])$/.test(month.value));
const KINDS = ['cm', 'td', 'tp'] as const;
const hetd = (milli: number): string => (milli / 1000).toLocaleString(locale.value === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 2 });

async function load(): Promise<void> {
  if (monthBad.value) return;
  const scope = higher.scope() as Record<string, string>;
  const res = await list.run(() => teaching.hours({ ...scope, month: month.value }));
  if (res) rows.value = res;
  const h = await list.run(() => teaching.validations({ ...scope, 'filter[period_month]': month.value }));
  if (h) history.value = h;
}

async function validate(r: HoursRow): Promise<void> {
  const res = await act.run(() => teaching.validateMonth({ person_id: r.person_id, month: month.value }));
  if (!res) return;
  pending.value = res.pending.length;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.hrs.title')" :lede="t('ht.hrs.lede')" @school-change="load">
    <form class="hi-form" @submit.prevent="load">
      <div class="field"><label for="hr-month">{{ t('ht.hrs.month') }}</label><input id="hr-month" v-model="month" type="month" :aria-invalid="monthBad" /><span v-if="monthBad" class="error">{{ t('ht.hrs.monthBad') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="list.busy.value">{{ t('ht.load') }}</button></div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="pending" class="notice notice--warning" role="status" data-test="hours-pending">{{ t('ht.hrs.pending', { count: pending }) }}</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('ht.teacher') }}</th><th scope="col" class="num">{{ t('ht.hrs.logs') }}</th><th v-for="k in KINDS" :key="k" scope="col" class="num">{{ t(hk('ht.kind.', k)) }}</th><th scope="col" class="num">{{ t('ht.hrs.hetd') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.person_id">
            <th scope="row">{{ personName(r.person_id) }}</th>
            <td class="num">{{ r.logs }}</td>
            <td v-for="k in KINDS" :key="k" class="num">{{ formatMinutes(r.minutes[k]) }}</td>
            <td class="num">{{ hetd(r.hetd_milli) }}</td>
            <td><span v-for="(n, s) in r.by_status" :key="s" class="tag tag--neutral">{{ t(hk('ht.log.state.', String(s))) }} {{ n }}</span></td>
            <td class="hi-actions"><button v-if="canValidate" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="validate(r)">{{ t('ht.hrs.validate') }}</button></td>
          </tr>
          <tr v-if="rows.length === 0"><td colspan="8" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section class="hi-section">
      <h2>{{ t('ht.hrs.history') }}</h2>
      <ul v-if="history.length" class="hi-list">
        <li v-for="v in history" :key="v.id"><span>{{ personName(v.person_id) }}</span> · {{ v.log_count }} {{ t('ht.hrs.logs').toLowerCase() }} · {{ formatMinutes(v.minutes_total) }} · {{ t('ht.hrs.validatedAt') }} {{ formatDate(v.validated_at, locale) }}</li>
      </ul>
      <p v-else class="muted">{{ t('hi.none') }}</p>
    </section>
  </TeachingFrame>
</template>
