<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ASSESSMENT_STATUSES, grades, type Progress } from '@/api/higher-grades';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import GradesNav from './GradesNav.vue';
import { gk, useGradesI18n } from './i18n';

// Suivi de la saisie (EX-NOT-08) : progression par évaluation, retards (date limite dépassée, relance NOT-11).
const { tg, locale } = useGradesI18n();
const higher = useHigherStore();
const list = useHigherAction();
const data = ref<Progress | null>(null);
const status = ref('');
const onlyOverdue = ref(false);

const lines = computed(() => (data.value?.lines ?? []).filter((l) => !onlyOverdue.value || l.overdue));
const pct = (entered: number, total: number): number => (total > 0 ? Math.round((100 * entered) / total) : 0);

async function load(): Promise<void> {
  await higher.loadRefs('groups');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (status.value) q['filter[status]'] = status.value;
  const res = await list.run(() => grades.progress(q));
  if (res) data.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="tg('hg.prog.title')" :lede="tg('hg.prog.lede')" section="portal" @school-change="load">
    <GradesNav />
    <ErrorNotice :error="list.error.value" />
    <dl v-if="data" class="hg-kpis" data-test="progress-summary">
      <div class="card"><dt>{{ tg('hg.prog.assessments') }}</dt><dd class="hi-total">{{ data.summary.assessments }}</dd></div>
      <div class="card"><dt>{{ tg('hg.prog.complete') }}</dt><dd class="hi-total">{{ data.summary.complete }}</dd></div>
      <div class="card"><dt>{{ tg('hg.prog.overdue') }}</dt><dd class="hi-total" :class="{ 'hg-warn': data.summary.overdue > 0 }">{{ data.summary.overdue }}</dd></div>
    </dl>
    <div class="hi-form">
      <div class="field">
        <label for="pf-st">{{ tg('hg.status') }}</label>
        <select id="pf-st" v-model="status" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="s in ASSESSMENT_STATUSES" :key="s" :value="s">{{ tg(gk('hg.state.', s)) }}</option></select>
      </div>
      <div class="field"><label class="row"><input v-model="onlyOverdue" type="checkbox" /> {{ tg('hg.prog.onlyOverdue') }}</label></div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ tg('hg.element') }}</th><th scope="col">{{ tg('hg.label') }}</th><th scope="col">{{ tg('hg.group') }}</th>
            <th scope="col">{{ tg('hg.dueOn') }}</th><th scope="col">{{ tg('hg.progress') }}</th><th scope="col">{{ tg('hg.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in lines" :key="l.id" data-test="progress-line">
            <th scope="row"><router-link :to="{ name: 'higher-grades-grid', params: { id: l.id } }">{{ l.element_code }}</router-link></th>
            <td>{{ l.label }}</td>
            <td>{{ l.group_id ? higher.groupName(l.group_id) : tg('hg.allGroups') }}</td>
            <td>{{ l.entry_due_on ? formatDate(l.entry_due_on, locale) : '—' }} <span v-if="l.overdue" class="tag tag--warning">{{ tg('hg.overdue') }}</span></td>
            <td>
              <progress :value="l.entered" :max="Math.max(l.total, 1)" :aria-label="`${l.element_code} · ${pct(l.entered, l.total)} %`" />
              <span class="hi-small"> {{ l.entered }} / {{ l.total }}</span>
            </td>
            <td><span class="tag" :class="`tag--${toneOf(l.status) ?? 'neutral'}`">{{ tg(gk('hg.state.', l.status)) }}</span></td>
          </tr>
          <tr v-if="lines.length === 0"><td colspan="6" class="muted">{{ tg('hg.none') }}</td></tr>
        </tbody>
      </table>
    </div>
  </HigherFrame>
</template>

<style scoped>
.hg-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin: 0 0 20px;
}
.hg-kpis dt {
  color: var(--muted);
  font-size: 13px;
  font-weight: 700;
}
.hg-kpis dd {
  margin: 0;
}
.hg-warn {
  color: var(--warning);
}
progress {
  width: 120px;
  accent-color: var(--accent);
}
</style>
