<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchPlatformKpi, type PlatformKpi } from '@/api/platformBackoffice';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';

// Indicateurs d'usage de la plateforme (C-112 point 2) : graphiques simples, chacun doublé d'un tableau de données accessible.
const { t, locale } = useI18n();
const kpi = ref<PlatformKpi | null>(null);
const error = ref<unknown>(null);

onMounted(async () => {
  try {
    kpi.value = await fetchPlatformKpi();
  } catch (e) {
    error.value = e;
  }
});

const peak = computed(() => Math.max(1, ...(kpi.value?.logins.series.map((d) => d.count) ?? [1])));
const n = (v: number | null | undefined): string => (typeof v === 'number' ? v.toLocaleString(locale.value) : '—');
</script>

<template>
  <section class="block" aria-labelledby="kpi-title">
    <h2 id="kpi-title">{{ t('pk.title') }}</h2>
    <ErrorNotice :error="error" />
    <template v-if="kpi">
      <div class="cards">
        <div class="card stat" data-test="kpi-online"><span class="eyebrow">{{ t('pk.online', { minutes: kpi.online.window_minutes }) }}</span><strong>{{ n(kpi.online.total) }}</strong></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.logins') }}</span><strong>{{ n(kpi.logins.today) }}</strong><span class="muted small">{{ t('pk.loginsDetail', { week: n(kpi.logins.week), month: n(kpi.logins.month) }) }}</span></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.active') }}</span><strong>{{ n(kpi.active_users.d7) }}</strong><span class="muted small">{{ t('pk.active30', { count: n(kpi.active_users.d30) }) }}</span></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.security') }}</span><strong>{{ n(kpi.security.failed_logins_24h) }}</strong><span class="muted small">{{ t('pk.securityDetail', { lockouts: n(kpi.security.lockouts_24h), locked: n(kpi.security.locked_accounts) }) }}</span></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.clients') }}</span><strong>{{ n(kpi.clients_activity.active) }}</strong><span class="muted small">{{ t('pk.inactive', { count: n(kpi.clients_activity.inactive), days: kpi.clients_activity.inactive_days }) }}</span></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.conversion') }}</span><strong>{{ kpi.conversion.rate_percent === null ? '—' : `${kpi.conversion.rate_percent} %` }}</strong><span class="muted small">{{ t('pk.conversionDetail', { converted: n(kpi.conversion.converted), trials: n(kpi.conversion.trials) }) }}</span></div>
        <div class="card stat" data-test="kpi-sms"><span class="eyebrow">{{ t('pk.sms') }}</span><strong>{{ n(kpi.messages.today.sms.sent) }}</strong><span class="muted small">{{ t('pk.smsDetail', { delivered: n(kpi.messages.today.sms.delivered), failed: n(kpi.messages.today.sms.failed), month: n(kpi.messages.month.sms.sent), quota: n(kpi.messages.sms_quota_remaining) }) }}</span></div>
        <div class="card stat"><span class="eyebrow">{{ t('pk.email') }}</span><strong>{{ n(kpi.messages.today.email.sent) }}</strong><span class="muted small">{{ t('pk.emailDetail', { failed: n(kpi.messages.today.email.failed), month: n(kpi.messages.month.email.sent) }) }}</span></div>
      </div>

      <h3>{{ t('pk.series') }}</h3>
      <div class="chart" role="img" :aria-label="t('pk.seriesAria', { month: n(kpi.logins.month) })">
        <span v-for="d in kpi.logins.series" :key="d.date" class="bar" :style="{ height: `${Math.round((100 * d.count) / peak)}%` }" :title="`${formatDate(d.date, locale)} : ${d.count}`" />
      </div>
      <details>
        <summary>{{ t('pk.table') }}</summary>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">{{ t('pk.series') }}</caption>
            <thead><tr><th scope="col">{{ t('pk.date') }}</th><th scope="col" class="num">{{ t('pk.count') }}</th></tr></thead>
            <tbody><tr v-for="d in kpi.logins.series" :key="d.date"><th scope="row">{{ formatDate(d.date, locale) }}</th><td class="num">{{ d.count }}</td></tr></tbody>
          </table>
        </div>
      </details>

      <h3>{{ t('pk.byApp') }}</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('plat.col.app') }}</th><th scope="col" class="num">{{ t('pk.col.clients') }}</th><th scope="col" class="num">{{ t('pk.col.establishments') }}</th><th scope="col" class="num">{{ t('pk.col.learners') }}</th><th scope="col" class="num">{{ t('pk.col.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="a in kpi.usage_by_app" :key="a.key"><th scope="row">{{ a.label }}</th><td class="num">{{ n(a.clients) }}</td><td class="num">{{ n(a.establishments) }}</td><td class="num">{{ n(a.learners) }}</td><td class="num">{{ n(a.actions_7d) }}</td></tr>
          </tbody>
        </table>
      </div>

      <div class="two">
        <div v-for="list in (['top_active', 'least_active'] as const)" :key="list">
          <h3>{{ t(list === 'top_active' ? 'pk.top' : 'pk.least') }}</h3>
          <ol class="rank">
            <li v-for="c in kpi.clients_activity[list]" :key="c.tenant_id">
              <router-link :to="{ name: 'platform-client', params: { tenant: c.tenant_id } }">{{ c.name }}</router-link>
              <span class="muted small"> — {{ t('pk.actions', { count: n(c.actions_7d) }) }}</span>
            </li>
          </ol>
        </div>
      </div>

      <template v-if="kpi.online.items.length">
        <h3>{{ t('pk.onlineList') }}</h3>
        <div class="table-wrap">
          <table data-test="kpi-online-list">
            <thead><tr><th scope="col">{{ t('pa.name') }}</th><th scope="col">{{ t('plat.col.client') }}</th><th scope="col">{{ t('pa.role.label') }}</th><th scope="col">{{ t('pk.since') }}</th><th scope="col">{{ t('pk.device') }}</th></tr></thead>
            <tbody>
              <tr v-for="s in kpi.online.items" :key="`${s.tenant_id}-${s.user_id}-${s.since}`">
                <th scope="row">{{ s.first_name }} {{ s.last_name }}</th>
                <td>{{ s.tenant_name }}</td>
                <td>{{ s.role ?? '—' }}</td>
                <td>{{ formatDateTime(s.since, locale) }}</td>
                <td>{{ s.device ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </section>
</template>

<style scoped>
.block {
  margin-top: 32px;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
  gap: 12px;
}
.stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.stat strong {
  font-size: 28px;
}
.small {
  font-size: 13px;
}
.chart {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 120px;
  border-bottom: 2px solid var(--ink, #1a1a1a);
  margin: 8px 0;
}
.bar {
  flex: 1;
  min-height: 2px;
  background: var(--accent, #f26b1d);
}
.two {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: 0 24px;
}
.rank {
  padding-left: 20px;
}
</style>
