<script setup lang="ts">
import type { PortalEnrolment, PortalFinance, Receipt } from '@/api/higher';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useXt } from '../xi18n';

// Inscriptions, situation financière (échéancier) et reçus d'un étudiant, communs aux portails étudiant et garant.
defineProps<{ enrolments: PortalEnrolment[]; finance: PortalFinance[]; receipts: Receipt[] }>();
const { t, tv, locale } = useXt();
const higher = useHigherStore();
</script>

<template>
  <section class="card hi-section" data-test="enrolments">
    <h2>{{ t('hi.por.enrolments') }}</h2>
    <ul v-if="enrolments.length" class="hi-list">
      <li v-for="e in enrolments" :key="e.id">
        <span class="hi-grow">{{ higher.yearName(e.academic_year_id) }} · {{ higher.groupName(e.structure_id) }}<template v-if="e.matricule"> · {{ t('hx.por.matricule') }} <span class="hi-mono">{{ e.matricule }}</span></template></span>
        <span class="tag" :class="`tag--${toneOf(e.status) ?? 'neutral'}`">{{ tv('hx.por.st.', e.status) }}</span>
      </li>
    </ul>
    <p v-else class="muted">{{ t('hx.por.noEnrolment') }}</p>
  </section>

  <section class="card hi-section" data-test="finance">
    <h2>{{ t('hi.por.finance') }}</h2>
    <p v-if="finance.length === 0" class="muted">{{ t('hx.por.noAccount') }}</p>
    <article v-for="a in finance" :key="a.account_id" class="pf-account">
      <h3>{{ t('hx.por.year', { year: higher.yearName(a.academic_year_id) }) }}</h3>
      <div class="grid grid--kpi">
        <div class="kpi"><p class="eyebrow">{{ t('hi.fin.net') }}</p><p class="kpi__value">{{ formatXaf(a.net_xaf, locale) }}</p></div>
        <div class="kpi"><p class="eyebrow">{{ t('hi.fin.paid') }}</p><p class="kpi__value">{{ formatXaf(a.paid_xaf, locale) }}</p></div>
        <div class="kpi"><p class="eyebrow">{{ t('hi.por.balance') }}</p><p class="kpi__value" data-test="balance">{{ formatXaf(a.balance_xaf, locale) }}</p></div>
      </div>
      <ul class="hi-list hi-small">
        <li v-for="i in a.installments" :key="i.id">
          <span class="hi-grow">{{ i.label }} · {{ formatDate(i.due_on, locale) }}</span>
          <span>{{ formatXaf(i.remaining_xaf, locale) }}</span>
          <span class="tag" :class="`tag--${toneOf(i.status) ?? 'neutral'}`">{{ tv('hi.fin.inst.', i.status) }}</span>
        </li>
      </ul>
    </article>
  </section>

  <section class="card hi-section" data-test="receipts">
    <h2>{{ t('hi.por.receipts') }}</h2>
    <ul v-if="receipts.length" class="hi-list">
      <li v-for="r in receipts" :key="r.payment_id">
        <strong class="hi-mono">{{ r.receipt_number ?? t('hi.fin.receiptPending') }}</strong>
        <span class="hi-grow">{{ tv('hi.fin.channel.', r.channel) }} · {{ formatDateTime(r.recorded_at, locale) }}</span>
        <span>{{ formatXaf(r.amount_xaf, locale) }}</span>
      </li>
    </ul>
    <p v-else class="muted">{{ t('hx.por.noReceipts') }}</p>
  </section>
</template>

<style scoped>
.pf-account + .pf-account {
  margin-top: 16px;
  border-top: 1px solid var(--border);
  padding-top: 12px;
}
</style>
