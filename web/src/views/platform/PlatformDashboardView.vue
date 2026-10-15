<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import KpiCard from '@/components/KpiCard.vue';
import type { Indicator } from '@/api/types';
import { fetchPlatformDashboard, type Metric, type PlatformDashboard } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { appStatusKey, appStatusTone, stateKey, stateTone } from './display';

// Tableau de bord de l'administrateur de plateforme — design : Dashboard.html (cartes KPI, tableaux à filet noir).
// Un indicateur sans source (available:false) est affiché « Indisponible » avec la raison donnée par le serveur.
const { t, locale } = useI18n();
const data = ref<PlatformDashboard | null>(null);
const error = ref<unknown>(null);
const loading = ref(true);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    data.value = await fetchPlatformDashboard();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

function indicator(key: string, label: string, metric: Metric | undefined, value: number | undefined, unit = 'count', detail?: Record<string, string>): Indicator {
  if (!metric) return { key, label, available: false, value: null, unit, reason: undefined };
  if (!metric.available) return { key, label, available: false, value: null, unit, reason: metric.reason };
  return { key, label, available: true, value: typeof value === 'number' ? value : null, unit, detail };
}

const horizon = computed(() => data.value?.trials_ending?.horizon_days ?? 14);
const kpis = computed<Indicator[]>(() => {
  const d = data.value;
  if (!d) return [];
  return [
    indicator('clients', t('plat.kpi.clients'), d.clients, d.clients?.total),
    indicator('pending', t('plat.kpi.pending'), d.access_requests_pending, d.access_requests_pending?.total),
    indicator('open', t('plat.kpi.openApps'), d.applications, d.applications?.open_total),
    indicator('trials', t('plat.kpi.trials', { days: horizon.value }), d.trials_ending, d.trials_ending?.total),
    indicator('unpaid', t('plat.kpi.unpaid'), d.unpaid, d.unpaid?.total),
    indicator('mrr', t('plat.kpi.mrr'), d.mrr_xaf, d.mrr_xaf?.value, 'xaf', typeof d.mrr_xaf?.paying_subscriptions === 'number' ? { paying: t('plat.kpi.mrrDetail', { count: d.mrr_xaf.paying_subscriptions }) } : undefined),
    indicator('collected', t('plat.kpi.collected'), d.collected_last_30_days_xaf, d.collected_last_30_days_xaf?.value, 'xaf'),
    indicator('health', t('plat.kpi.health'), d.health, d.health?.value),
  ];
});

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
        <h1>{{ t('plat.dash.title') }}</h1>
        <p class="lede">{{ t('plat.dash.lede') }}</p>
        <p v-if="data?.as_of" class="muted small">{{ t('plat.dash.asOf', { date: formatDateTime(data.as_of, locale) }) }}</p>
      </div>
      <router-link v-if="(data?.access_requests_pending?.total ?? 0) > 0" class="btn btn--primary" :to="{ name: 'platform-requests' }">{{ t('plat.dash.seeRequests') }} →</router-link>
    </header>

    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-if="data">
      <section class="grid grid--kpi" :aria-label="t('plat.dash.title')">
        <KpiCard v-for="k in kpis" :key="k.key" :indicator="k" />
      </section>

      <section v-if="data.applications?.available && data.applications.items?.length" class="block" aria-labelledby="pd-apps">
        <h2 id="pd-apps">{{ t('plat.dash.apps') }}</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ t('plat.col.app') }}</th>
                <th scope="col">{{ t('plat.col.status') }}</th>
                <th scope="col" class="num">{{ t('plat.col.open') }}</th>
                <th scope="col">{{ t('plat.col.state') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in data.applications.items" :key="a.key">
                <th scope="row">{{ a.label }}</th>
                <td><span class="tag" :class="appStatusTone(a.status) ? `tag--${appStatusTone(a.status)}` : ''">{{ t(appStatusKey(a.status)) }}</span></td>
                <td class="num">{{ a.open }}</td>
                <td class="wrap">
                  <span v-for="(count, state) in a.states" :key="state" class="state">{{ t(stateKey(String(state))) }} : {{ count }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="two">
        <section class="block" aria-labelledby="pd-trials">
          <h2 id="pd-trials">{{ t('plat.dash.trialsTitle') }}</h2>
          <p v-if="data.trials_ending && !data.trials_ending.available" class="card muted">{{ t('common.unavailable') }} — {{ data.trials_ending.reason }}</p>
          <p v-else-if="!data.trials_ending?.items?.length" class="card muted">{{ t('plat.dash.noTrials', { days: horizon }) }}</p>
          <div v-else class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ t('plat.col.client') }}</th>
                  <th scope="col">{{ t('plat.col.app') }}</th>
                  <th scope="col">{{ t('plat.col.trialEnds') }}</th>
                  <th scope="col" class="num">{{ t('plat.col.daysLeft') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in data.trials_ending.items" :key="e.id">
                  <th scope="row"><router-link :to="{ name: 'platform-client', params: { tenant: e.tenant_id } }">{{ e.tenant_name || e.tenant_id }}</router-link></th>
                  <td>{{ e.label }}</td>
                  <td>{{ formatDate(e.trial_ends_on, locale) }}</td>
                  <td class="num">{{ e.trial_days_left }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="block" aria-labelledby="pd-unpaid">
          <h2 id="pd-unpaid">{{ t('plat.dash.unpaidTitle') }}</h2>
          <p v-if="data.unpaid && !data.unpaid.available" class="card muted">{{ t('common.unavailable') }} — {{ data.unpaid.reason }}</p>
          <p v-else-if="!data.unpaid?.items?.length" class="card muted">{{ t('plat.dash.noUnpaid') }}</p>
          <div v-else class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ t('plat.col.client') }}</th>
                  <th scope="col">{{ t('plat.col.app') }}</th>
                  <th scope="col">{{ t('plat.col.state') }}</th>
                  <th scope="col">{{ t('plat.col.dueOn') }}</th>
                  <th scope="col" class="num">{{ t('plat.col.price') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in data.unpaid.items" :key="e.id">
                  <th scope="row"><router-link :to="{ name: 'platform-client', params: { tenant: e.tenant_id } }">{{ e.tenant_name || e.tenant_id }}</router-link></th>
                  <td>{{ e.label }}</td>
                  <td><span class="tag" :class="stateTone(e.state) ? `tag--${stateTone(e.state)}` : ''">{{ t(stateKey(e.state)) }}</span></td>
                  <td>{{ formatDate(e.due_on, locale) }}</td>
                  <td class="num">{{ formatXaf(e.monthly_price_xaf, locale) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.small {
  font-size: 13px;
  margin: 6px 0 0;
}
.block {
  margin-top: 32px;
}
.two {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr));
  gap: 0 24px;
}
.wrap {
  white-space: normal;
}
.state + .state::before {
  content: ' · ';
}
</style>
