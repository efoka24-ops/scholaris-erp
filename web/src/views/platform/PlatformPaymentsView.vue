<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { PAYMENT_STATUSES, fetchClients, fetchPlatformPayments, type PaymentStatus, type SubscriptionPayment, type Tenant } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { channelKey, paymentStatusKey, paymentTone } from './display';

// Paiements d'abonnement de tous les clients — design : Frais.html / Dashboard.html (tableau à filet noir, montants alignés à droite).
const { t, locale } = useI18n();
const status = ref<PaymentStatus | ''>('');
const tenantId = ref('');
const clients = ref<Tenant[]>([]);
const items = ref<SubscriptionPayment[]>([]);
const next = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);

const clientName = computed(() => new Map(clients.value.map((c) => [c.id, c.name])));

async function load(more = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchPlatformPayments({ status: status.value || null, tenantId: tenantId.value || null }, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

watch([status, tenantId], () => load());
onMounted(async () => {
  void load();
  try {
    clients.value = await fetchClients();
  } catch {
    clients.value = []; // le filtre par client reste possible depuis la fiche du client
  }
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('plat.pay.title') }}</h1>
      <p class="lede">{{ t('plat.pay.lede') }}</p>
    </header>

    <div class="row filters">
      <div class="field">
        <label for="pp-status">{{ t('plat.pay.status') }}</label>
        <select id="pp-status" v-model="status">
          <option value="">{{ t('plat.pay.allStatuses') }}</option>
          <option v-for="s in PAYMENT_STATUSES" :key="s" :value="s">{{ t(paymentStatusKey(s)) }}</option>
        </select>
      </div>
      <div class="field">
        <label for="pp-client">{{ t('plat.pay.client') }}</label>
        <select id="pp-client" v-model="tenantId">
          <option value="">{{ t('plat.pay.allClients') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
    </div>

    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('plat.pay.empty') }}</p>
    <div v-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('plat.pay.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('plat.col.reference') }}</th>
            <th scope="col">{{ t('plat.col.client') }}</th>
            <th scope="col">{{ t('plat.col.app') }}</th>
            <th scope="col" class="num">{{ t('plat.col.amount') }}</th>
            <th scope="col" class="num">{{ t('plat.col.months') }}</th>
            <th scope="col">{{ t('plat.col.channel') }}</th>
            <th scope="col">{{ t('plat.col.status') }}</th>
            <th scope="col">{{ t('plat.col.period') }}</th>
            <th scope="col">{{ t('plat.col.paidAt') }}</th>
            <th scope="col">{{ t('plat.col.receipt') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in items" :key="p.id">
            <th scope="row"><code>{{ p.reference }}</code></th>
            <td><router-link :to="{ name: 'platform-client', params: { tenant: p.tenant_id } }">{{ clientName.get(p.tenant_id) ?? p.tenant_id }}</router-link></td>
            <td>{{ p.app_key }}</td>
            <td class="num">{{ formatXaf(p.amount_xaf, locale) }}</td>
            <td class="num">{{ p.months }}</td>
            <td>{{ t(channelKey(p.channel)) }}</td>
            <td><span class="tag" :class="paymentTone(p.status) ? `tag--${paymentTone(p.status)}` : ''">{{ t(paymentStatusKey(p.status)) }}</span></td>
            <td>{{ p.covered_from && p.covered_until ? t('apps.periodRange', { from: formatDate(p.covered_from, locale), to: formatDate(p.covered_until, locale) }) : '—' }}</td>
            <td>{{ p.paid_at ? formatDateTime(p.paid_at, locale) : '—' }}</td>
            <td>{{ p.receipt_number ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ loading ? t('common.loading') : t('plat.loadMore') }}</button>
  </div>
</template>

<style scoped>
.filters {
  align-items: flex-end;
  margin-bottom: 8px;
}
.filters .field {
  flex: 1 1 220px;
  max-width: 320px;
}
.more {
  margin-top: 16px;
}
</style>
