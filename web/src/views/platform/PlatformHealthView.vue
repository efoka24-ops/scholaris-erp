<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { fetchPlatformHealth, healthTone, type HealthProbe, type HealthReport } from '@/api/platformBackoffice';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Santé de la plateforme (C-112) : chaque sonde avec son état, sa valeur et ses seuils (réglages de la plateforme).
const { t, locale } = useI18n();
const report = ref<HealthReport | null>(null);
const error = ref<unknown>(null);
const loading = ref(true);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    report.value = await fetchPlatformHealth();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

const probeLabel = (key: string): string => t(`ph.probe.${key}` as MessageKey);
const statusLabel = (s: string): string => t(`ph.status.${s}` as MessageKey);
const value = (p: HealthProbe): string => (p.value === null ? '—' : `${p.value.toLocaleString(locale.value)}${p.unit && p.unit !== 'tasks' && p.unit !== 'messages' && p.unit !== 'errors' && p.unit !== 'sms' ? ` ${p.unit}` : ''}`);
const thresholds = (p: HealthProbe): string =>
  p.thresholds ? t(p.thresholds.direction === 'below' ? 'ph.below' : 'ph.above', { warning: p.thresholds.warning, critical: p.thresholds.critical }) : '—';

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
        <h1>{{ t('ph.title') }}</h1>
        <p class="lede">{{ t('ph.lede') }}</p>
        <p v-if="report" class="muted small">{{ t('plat.dash.asOf', { date: formatDateTime(report.as_of, locale) }) }}</p>
      </div>
      <button type="button" class="btn" :disabled="loading" @click="load">{{ t('ph.refresh') }}</button>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="loading && !report" class="muted" role="status">{{ t('common.loading') }}</p>
    <template v-if="report">
      <p class="card overall" role="status">
        {{ t('ph.overall') }} <StatusTag :tone="healthTone(report.status)">{{ statusLabel(report.status) }}</StatusTag>
      </p>
      <div class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('ph.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('ph.col.probe') }}</th>
              <th scope="col">{{ t('plat.col.status') }}</th>
              <th scope="col" class="num">{{ t('ph.col.value') }}</th>
              <th scope="col">{{ t('ph.col.thresholds') }}</th>
              <th scope="col">{{ t('ph.col.detail') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in report.probes" :key="p.key" :data-test="`probe-${p.key}`">
              <th scope="row">{{ probeLabel(p.key) }}</th>
              <td><StatusTag :tone="healthTone(p.status)">{{ statusLabel(p.status) }}</StatusTag></td>
              <td class="num">{{ value(p) }}</td>
              <td>{{ thresholds(p) }}</td>
              <td class="wrap">
                {{ p.detail ?? '' }}
                <span v-if="p.trace_ids?.length" class="traces">{{ t('ph.traces') }} <code v-for="id in p.trace_ids" :key="id">{{ id }}</code></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted small">{{ t('ph.settingsHint') }}</p>
    </template>
  </div>
</template>

<style scoped>
.small {
  font-size: 13px;
  margin: 6px 0 0;
}
.overall {
  display: flex;
  gap: 8px;
  align-items: center;
}
.wrap {
  white-space: normal;
}
.traces {
  display: block;
  margin-top: 4px;
}
.traces code + code {
  margin-left: 6px;
}
</style>
