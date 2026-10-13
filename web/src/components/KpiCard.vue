<script setup lang="ts">
import { computed } from 'vue';
import type { Indicator } from '@/api/types';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';

const props = defineProps<{ indicator: Indicator }>();
const { t, locale } = useI18n();

const formatted = computed(() => {
  const i = props.indicator;
  if (!i.available || i.value === null) return null;
  const nf = new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'fr-FR', { maximumFractionDigits: 1 });
  if (i.unit === 'xaf' || i.unit === 'XAF') return formatXaf(i.value, locale.value);
  if (i.unit === 'percent' || i.unit === '%') return `${nf.format(i.value)} %`;
  return nf.format(i.value);
});
const isPercent = computed(() => ["percent", "%"].includes(props.indicator.unit) && props.indicator.available && props.indicator.value !== null);
const showBar = isPercent;
const barWidth = computed(() => `${Math.max(0, Math.min(100, props.indicator.value ?? 0))}%`);
const detailText = computed(() => {
  const d = props.indicator.detail;
  if (!d) return "";
  return Object.values(d).filter((v) => v !== null && v !== "").join(" · ");
});
const unitLabel = computed(() => {
  const u = props.indicator.unit;
  return u && !['count', 'xaf', 'XAF', 'percent', '%'].includes(u) ? u : '';
});
</script>

<template>
  <article class="kpi" :class="{ 'kpi--off': !indicator.available }">
    <h3 class="eyebrow">{{ indicator.label }}</h3>
    <template v-if="formatted !== null">
      <p class="kpi__value">{{ formatted }}<span v-if="unitLabel" class="kpi__unit">{{ unitLabel }}</span></p>
      <div v-if="showBar" class="kpi__bar" aria-hidden="true"><span :style="{ width: barWidth }" /></div>
      <p v-if="detailText" class="kpi__detail">{{ detailText }}</p>
    </template>
    <template v-else>
      <p class="kpi__value">{{ t('common.unavailable') }}</p>
      <p v-if="indicator.reason" class="kpi__detail">{{ indicator.reason }}</p>
    </template>
  </article>
</template>
