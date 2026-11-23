<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatHetd, MONTH, vacations, type Statement, type ToPrepare } from '@/api/higher-vacations';
import { useContextStore } from '@/stores/context';
import { formatXaf } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { VP } from './access';
import { useHv, vk } from './i18n';

// États mensuels des vacations (EX-ENS-06) : états du périmètre et heures validées encore sans état ; le chef de département ou le
// comptable établit (ou recalcule) l'état, puis l'école le vise, la direction l'approuve et le comptable le paie (écran de l'état).
const { t, locale } = useHv();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();
const linkable = useRouter().hasRoute('higher-vacation-statement');

const now = new Date();
now.setMonth(now.getMonth() - 1);
const month = ref(now.toISOString().slice(0, 7));
const rows = ref<Statement[]>([]);
const prepared = ref<ToPrepare[]>([]);
const done = ref<number | null>(null);
const monthBad = computed(() => !MONTH.test(month.value));
const canPrepare = computed(() => ctx.can(...VP.prepare) && !higher.readOnly);
const { name: personName } = useHigherNames(() => [...rows.value.map((r) => r.person_id), ...prepared.value.map((r) => r.person_id)]);

async function load(): Promise<void> {
  if (monthBad.value) return;
  const scope = higher.scope();
  const res = await list.run(() => vacations.statements({ ...scope, 'filter[period_month]': month.value }));
  if (res) rows.value = res;
  if (ctx.can(...VP.prepare)) {
    const todo = await list.run(() => vacations.toPrepare(month.value, scope));
    if (todo) prepared.value = todo;
  }
}

async function prepare(r: ToPrepare): Promise<void> {
  const res = await act.run(() => vacations.prepare({ person_id: r.person_id, period_month: month.value, department_id: r.department_id }));
  if (!res) return;
  done.value = res.length;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hv.list.title')" :lede="t('hv.list.lede')" section="curriculum" @school-change="load">
    <form class="hi-form" @submit.prevent="load">
      <div class="field"><label for="hv-month">{{ t('hv.month') }}</label><input id="hv-month" v-model="month" type="month" :aria-invalid="monthBad" /><span v-if="monthBad" class="error">{{ t('hv.monthBad') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="list.busy.value">{{ t('hv.load') }}</button></div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="done" class="notice notice--success" role="status" data-test="prepared">{{ t('hv.prep.done', { count: done }) }}</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ t('hv.teacher') }}</th><th scope="col" class="num">{{ t('hv.hetd') }}</th><th scope="col" class="num">{{ t('hv.gross') }}</th><th scope="col" class="num">{{ t('hv.net') }}</th><th scope="col">{{ t('hv.status') }}</th><th scope="col">{{ t('hv.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id" data-test="statement-row">
            <th scope="row">{{ personName(r.person_id) }}</th>
            <td class="num">{{ formatHetd(r.hetd_milli, locale) }}</td>
            <td class="num">{{ formatXaf(r.gross_amount, locale) }}</td>
            <td class="num">{{ formatXaf(r.net_amount, locale) }}</td>
            <td><span class="tag tag--neutral">{{ t(vk('hv.status.', r.status)) }}</span><span v-if="r.hold_reasons.length" class="tag tag--warning">{{ t('hv.hold') }}</span></td>
            <td class="hi-actions"><router-link v-if="linkable" :to="{ name: 'higher-vacation-statement', params: { id: r.id } }">{{ t('hv.open') }}</router-link></td>
          </tr>
          <tr v-if="rows.length === 0"><td colspan="6" class="muted">{{ t('hv.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="ctx.can(...VP.prepare)" class="hi-section">
      <h2>{{ t('hv.prep.title') }}</h2>
      <ul v-if="prepared.length" class="hi-list">
        <li v-for="p in prepared" :key="p.person_id + p.department_id" data-test="to-prepare">
          <span>{{ personName(p.person_id) }}</span>
          <span v-if="p.statement_status" class="tag tag--neutral">{{ t(vk('hv.status.', p.statement_status)) }}</span>
          <button v-if="canPrepare && (p.statement_status === null || p.statement_status === 'draft' || p.statement_status === 'rejected')" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="prepare(p)">
            {{ t(p.statement_id ? 'hv.prep.redo' : 'hv.prep.do') }}
          </button>
        </li>
      </ul>
      <p v-else class="muted">{{ t('hv.prep.none') }}</p>
    </section>
  </HigherFrame>
</template>
