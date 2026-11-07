<script setup lang="ts">
import { computed } from 'vue';
import { formatHetd, formatHours, VACATION_KINDS, type Statement } from '@/api/higher-vacations';
import { formatDate, formatXaf } from '@/lib/format';
import { useHv, vk } from './i18n';

// Synthèse d'un état : heures et HETD par nature, taux, brut, retenues, net, retenue du solde, paiement (montants à la française).
const props = defineProps<{ statement: Statement }>();
const { t, locale } = useHv();
const s = computed(() => props.statement);
const kinds = computed(() => VACATION_KINDS.filter((k) => s.value[`${k}_minutes`] > 0));
</script>

<template>
  <div class="hv-summary">
    <table>
      <thead><tr><th scope="col">{{ t('hv.line.kind') }}</th><th scope="col" class="num">{{ t('hv.hours') }}</th><th scope="col" class="num">{{ t('hv.hetd') }}</th></tr></thead>
      <tbody>
        <tr v-for="k in kinds" :key="k"><th scope="row">{{ t(vk('hv.kind.', k)) }}</th><td class="num">{{ formatHours(s[`${k}_minutes`]) }}</td><td class="num">{{ formatHetd(s[`${k}_hetd_milli`], locale) }}</td></tr>
        <tr><th scope="row">{{ t('hv.hetd') }}</th><td /><td class="num" data-test="hetd-total"><strong>{{ formatHetd(s.hetd_milli, locale) }}</strong></td></tr>
      </tbody>
    </table>
    <dl class="hi-dl">
      <dt>{{ t('hv.rate') }}</dt><dd>{{ s.hourly_rate === null ? t('hv.rateMixed') : formatXaf(s.hourly_rate, locale) }}</dd>
      <dt>{{ t('hv.gross') }}</dt><dd data-test="gross">{{ formatXaf(s.gross_amount, locale) }}</dd>
      <dt>{{ t('hv.deductions') }}</dt>
      <dd>
        <span v-if="s.deductions.length === 0">{{ t('hv.noDeduction') }}</span>
        <span v-for="d in s.deductions" :key="d.code">{{ d.label }} ({{ d.percent }} %) : {{ formatXaf(d.amount, locale) }} </span>
      </dd>
      <dt>{{ t('hv.net') }}</dt><dd data-test="net"><strong>{{ formatXaf(s.net_amount, locale) }}</strong></dd>
      <dt>{{ t('hv.status') }}</dt><dd>{{ t(vk('hv.status.', s.status)) }}</dd>
    </dl>
    <p v-if="s.pending_sessions" class="notice notice--warning">{{ t('hv.pending', { count: s.pending_sessions }) }}</p>
    <p v-if="s.excluded_minutes" class="notice notice--warning">{{ t('hv.excluded', { hours: formatHours(s.excluded_minutes) }) }}</p>
    <div v-if="s.hold_reasons.length" class="notice notice--warning" role="status" data-test="hold">
      <strong>{{ t('hv.hold') }}</strong>
      <ul><li v-for="r in s.hold_reasons" :key="r">{{ r }}</li></ul>
    </div>
    <p v-if="s.status === 'rejected' && s.rejection_reason" class="notice notice--warning">{{ t('hv.rejectedBecause', { reason: s.rejection_reason }) }}</p>
    <p v-if="s.status === 'paid'" class="notice notice--success" data-test="paid">
      {{ t('hv.paidInfo', { date: formatDate(s.paid_on, locale), method: t(vk('hv.method.', s.payment_method)), reference: s.payment_reference ?? '' }) }}
    </p>
  </div>
</template>

<style scoped>
.hv-summary {
  display: grid;
  gap: 16px;
}
</style>
