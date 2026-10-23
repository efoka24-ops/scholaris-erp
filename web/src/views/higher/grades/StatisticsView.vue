<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, type AssessmentStatistics, type ElementStatistics, type StatSeries } from '@/api/higher-grades';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import GradesNav from './GradesNav.vue';
import { gk, useGradesI18n, type HigherGradesKey } from './i18n';

// Statistiques d'une évaluation (id) ou d'un EC (elementId, groupe facultatif) : moyenne, écart type, extrêmes, réussite,
// répartition sur 20 et détail par groupe ; absences par code pour une évaluation.
const props = defineProps<{ id?: string; elementId?: string }>();
const { tg, locale } = useGradesI18n();
const higher = useHigherStore();
const load = useHigherAction();
const data = ref<AssessmentStatistics | ElementStatistics | null>(null);
const group = ref('');

const forAssessment = computed(() => (data.value && 'assessment' in data.value ? data.value : null));
const forElement = computed(() => (data.value && 'element_code' in data.value ? data.value : null));
const title = computed(() => {
  if (forAssessment.value) return `${tg('hg.stat.title')} · ${forAssessment.value.assessment.element_code} · ${forAssessment.value.assessment.label}`;
  if (forElement.value) return tg('hg.stat.element', { code: forElement.value.element_code });
  return tg('hg.stat.title');
});
const peak = computed(() => Math.max(1, ...(data.value?.statistics.distribution ?? []).map((d) => d.count)));
const num = (v: number | null): string => (v === null ? '—' : v.toLocaleString(locale.value === 'fr' ? 'fr-FR' : 'en-GB'));
const ROWS: Array<[keyof StatSeries, HigherGradesKey]> = [
  ['count', 'hg.stat.count'], ['mean', 'hg.stat.mean'], ['std_dev', 'hg.stat.sd'], ['min', 'hg.stat.min'], ['max', 'hg.stat.max'], ['pass_rate', 'hg.stat.pass'],
];
const cell = (s: StatSeries, key: keyof StatSeries): string => {
  const v = s[key];
  if (typeof v !== 'number' && v !== null) return '';
  return key === 'pass_rate' && v !== null ? `${num(v)} %` : num(v);
};

async function fetchData(): Promise<void> {
  const res = props.elementId
    ? await load.run(() => grades.elementStatistics(props.elementId as string, group.value || undefined))
    : await load.run(() => grades.assessmentStatistics(props.id as string));
  if (res) data.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups');
  await fetchData();
});
</script>

<template>
  <HigherFrame :title="title" section="portal" :selector="false">
    <GradesNav />
    <ErrorNotice :error="load.error.value" />
    <template v-if="data">
      <p class="hi-actions">
        <router-link v-if="forAssessment" :to="{ name: 'higher-grades-grid', params: { id: forAssessment.assessment.id } }">{{ tg('hg.open') }}</router-link>
        <router-link v-if="forAssessment" :to="{ name: 'higher-grades-element-stats', params: { elementId: forAssessment.assessment.element_id } }">{{ tg('hg.stat.ecLink') }}</router-link>
        <span v-if="forElement" class="muted">{{ forElement.element_title }} · {{ tg('hg.stat.students') }} : {{ forElement.students }}</span>
      </p>
      <div v-if="forElement" class="field" style="max-width: 280px">
        <label for="st-grp">{{ tg('hg.group') }}</label>
        <select id="st-grp" v-model="group" @change="fetchData"><option value="">{{ tg('hg.allGroups') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ g.code }} · {{ g.name }}</option></select>
      </div>

      <div class="table-wrap">
        <table data-test="stats-table">
          <thead>
            <tr><th scope="col"></th><th scope="col" class="num">{{ tg('hg.allGroups') }}</th><th v-for="g in data.groups" :key="g.group_id ?? '-'" scope="col" class="num">{{ g.group_id ? higher.groupName(g.group_id) : '—' }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="[key, label] in ROWS" :key="key">
              <th scope="row">{{ tg(label) }}</th>
              <td class="num">{{ cell(data.statistics, key) }}</td>
              <td v-for="g in data.groups" :key="g.group_id ?? '-'" class="num">{{ cell(g, key) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <section class="card hi-section">
        <h2>{{ tg('hg.stat.distribution') }}</h2>
        <ul class="hg-bars">
          <li v-for="d in data.statistics.distribution" :key="d.from">
            <span class="hg-bars__label">{{ d.from }}–{{ d.to }}</span>
            <span class="hg-bars__bar" :style="{ width: `${(100 * d.count) / peak}%` }" />
            <span class="hg-bars__count">{{ d.count }}</span>
          </li>
        </ul>
      </section>

      <section v-if="forAssessment && Object.keys(forAssessment.absences).length" class="card hi-section">
        <h2>{{ tg('hg.stat.absences') }}</h2>
        <dl class="hi-kv"><template v-for="(n, code) in forAssessment.absences" :key="code"><dt>{{ String(code).toUpperCase() }}</dt><dd>{{ n }}</dd></template></dl>
      </section>
      <section v-if="forElement" class="card hi-section">
        <h2>{{ tg('hg.nav.assessments') }}</h2>
        <ul class="hi-list">
          <li v-for="x in forElement.assessments" :key="x.id"><router-link :to="{ name: 'higher-grades-stats', params: { id: x.id } }">{{ x.label }}</router-link> <span class="muted hi-small">{{ tg(gk('hg.kind.', x.kind)) }} · {{ x.weight_percent }} %</span></li>
        </ul>
      </section>
    </template>
  </HigherFrame>
</template>

<style scoped>
.hg-bars {
  list-style: none;
  padding: 0;
  margin: 0;
}
.hg-bars li {
  display: grid;
  grid-template-columns: 64px 1fr 40px;
  gap: 8px;
  align-items: center;
  min-height: 28px;
}
.hg-bars__bar {
  display: block;
  height: 16px;
  min-width: 2px;
  background: var(--ink);
}
.hg-bars__label,
.hg-bars__count {
  font-variant-numeric: tabular-nums;
  font-size: 13px;
}
.hg-bars__count {
  text-align: right;
}
</style>
