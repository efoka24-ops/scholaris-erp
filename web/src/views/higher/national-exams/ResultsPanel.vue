<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { nationalExams, type ImportResult, type SessionStatistics } from '@/api/higher-nationalexams';
import { useHigherAction } from '../useHigherAction';
import { useNationalI18n, xk } from './i18n';

// Import CSV des résultats (aperçu des lignes rejetées) et taux de réussite de la session.
const props = defineProps<{ sessionId: string; canManage: boolean; stats: SessionStatistics | null }>();
const emit = defineEmits<{ (e: 'changed'): void }>();
const { tx } = useNationalI18n();
const imp = useHigherAction();

const csv = ref('');
const outcome = ref<ImportResult | null>(null);
const RESULTS = ['passed', 'failed', 'absent'];

async function onFile(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (file) csv.value = await file.text();
}

async function run(): Promise<void> {
  if (csv.value.trim() === '') return;
  const res = await imp.run(() => nationalExams.importResults(props.sessionId, csv.value));
  if (!res) return;
  outcome.value = res;
  emit('changed');
}

const total = (s: SessionStatistics): number => (s.counts.passed ?? 0) + (s.counts.failed ?? 0) + (s.counts.absent ?? 0);
const label = (key: string): string => tx(RESULTS.includes(key) ? xk('hx.result.', key) : xk('hx.state.', key));
</script>

<template>
  <div class="hi-section">
    <section class="card" data-test="stats">
      <h2>{{ tx('hx.successRate') }}</h2>
      <p v-if="!stats || stats.success_rate === null" class="muted">{{ tx('hx.rateNone') }}</p>
      <p v-else data-test="rate"><strong>{{ tx('hx.rateValue', { rate: String(stats.success_rate).replace('.', ','), total: total(stats) }) }}</strong></p>
      <ul v-if="stats" class="hx-counts">
        <li v-for="(n, key) in stats.counts" :key="key">{{ label(String(key)) }} : {{ n }}</li>
      </ul>
    </section>

    <form v-if="canManage" class="card hi-form" data-test="import" @submit.prevent="run">
      <h2>{{ tx('hx.import') }}</h2>
      <p class="muted">{{ tx('hx.importHelp') }}</p>
      <div class="field"><label for="xi-file">{{ tx('hx.csvFile') }}</label><input id="xi-file" type="file" accept=".csv,text/csv" @change="onFile" /></div>
      <div class="field"><label for="xi-csv">{{ tx('hx.csvText') }}</label><textarea id="xi-csv" v-model="csv" rows="5"></textarea></div>
      <button type="submit" class="btn btn--primary" :disabled="imp.busy.value || csv.trim() === ''">{{ tx('hx.import') }}</button>
      <ErrorNotice :error="imp.error.value" />
      <div v-if="outcome" data-test="import-outcome">
        <p class="notice" role="status">{{ tx('hx.imported', { imported: outcome.imported, rejected: outcome.rejected.length }) }}</p>
        <div v-if="outcome.rejected.length > 0" class="table-wrap">
          <table>
            <caption>{{ tx('hx.rejectedTitle') }}</caption>
            <thead><tr><th scope="col" class="num">{{ tx('hx.line') }}</th><th scope="col">{{ tx('hx.key') }}</th><th scope="col">{{ tx('hx.reason') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in outcome.rejected" :key="`${r.line}-${r.key}`" data-test="rejected-row"><td class="num">{{ r.line }}</td><td>{{ r.key }}</td><td>{{ r.reason }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>
.hx-counts { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; }
</style>
