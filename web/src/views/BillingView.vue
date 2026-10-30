<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import KpiCard from '@/components/KpiCard.vue';
import ExportButton from '@/components/ExportButton.vue';
import { http } from '@/api/http';
import type { Invoice, Payment } from '@/api/types';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDateTime, formatXaf, todayIso } from '@/lib/format';
import { ulid } from '@/lib/ulid';

// Frais et paiements : journal du jour, caisse (encaissement d'espèces par référence famille), factures et reçus.
const { t, locale } = useI18n();
const ctx = useContextStore();

interface CashSession {
  id: string;
  register_no: number | null;
  status: string;
  opened_at: string;
  float_xaf: number;
  cash_collected_xaf?: number;
  receipts_count?: number;
  expected_now_xaf?: number;
}
interface Receipt {
  id: string;
  number: string;
  amount_xaf: number;
  status: string;
  issued_at: string;
}

const tab = ref<'journal' | 'cash' | 'invoices' | 'receipts'>('journal');
const payments = ref<Payment[]>([]);
const invoices = ref<Invoice[]>([]);
const receipts = ref<Receipt[]>([]);
const session = ref<CashSession | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const info = ref<string | null>(null);

const canCash = computed(() => ctx.can('billing.cash', 'finance.collect', 'billing.manage'));
const canManage = computed(() => ctx.can('billing.manage'));

const reference = ref('');
const amount = ref<number | null>(null);
let cashKey = ulid(); // même clé tant que l'envoi n'a pas abouti : un double appui ne crée pas deux reçus
const floatXaf = ref<number | null>(0);
const counted = ref<number | null>(null);

const totals = computed(() => {
  const confirmed = payments.value.filter((p) => p.status === 'confirmed');
  const toReconcile = payments.value.filter((p) => p.journal_status === 'Sans référence').length;
  return { count: confirmed.length, sum: confirmed.reduce((s, p) => s + p.amount_xaf, 0), toReconcile };
});

async function loadJournal(): Promise<void> {
  payments.value = await http.all<Payment>('/payments', { 'filter[day]': todayIso() }, {}, 5);
}
async function loadSession(): Promise<void> {
  const open = await http.get<{ data: CashSession[] }>('/cash-sessions', { 'filter[status]': 'open', per_page: 1 });
  session.value = open.data[0] ?? null;
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    if (tab.value === 'journal') await loadJournal();
    else if (tab.value === 'cash') await loadSession();
    else if (tab.value === 'invoices') invoices.value = await http.all<Invoice>('/invoices', {}, {}, 5);
    else receipts.value = await http.all<Receipt>('/receipts', {}, {}, 5);
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function openCash(): Promise<void> {
  error.value = null;
  try {
    await http.post('/cash-sessions', { float_xaf: floatXaf.value ?? 0 });
    await loadSession();
  } catch (e) {
    error.value = e;
  }
}

async function collect(): Promise<void> {
  error.value = null;
  info.value = null;
  if (!amount.value || amount.value < 1) return;
  loading.value = true;
  try {
    const res = await http.post<{ data: Payment }>('/cash/receipts', { amount_xaf: amount.value, family_reference: reference.value.trim() || undefined }, { idempotencyKey: cashKey });
    info.value = t('billing.collected', { number: res.data.receipt_number ?? res.data.receipt?.number ?? '' });
    cashKey = ulid();
    amount.value = null;
    reference.value = '';
    await loadSession();
  } catch (e) {
    error.value = e; // la clé est conservée : une nouvelle tentative rejoue le même encaissement sans doublon
  } finally {
    loading.value = false;
  }
}

async function closeCash(): Promise<void> {
  if (!session.value || counted.value === null) return;
  error.value = null;
  try {
    await http.post(`/cash-sessions/${session.value.id}/close`, { counted_xaf: counted.value });
    counted.value = null;
    info.value = t('billing.closed');
    await loadSession();
  } catch (e) {
    error.value = e;
  }
}

async function openPdf(id: string): Promise<void> {
  error.value = null;
  try {
    // Le PDF est lu avec l'en-tête d'établissement puis ouvert depuis la mémoire : un lien direct serait refusé (403).
    const blob = await http.get<Blob>(`/receipts/${id}/pdf`, undefined, { expect: 'blob' });
    window.open(URL.createObjectURL(blob), '_blank', 'noopener');
  } catch (e) {
    error.value = e;
  }
}

const journalTone = (s?: string): 'success' | 'warning' | 'ink' | 'neutral' => {
  if (!s) return 'neutral';
  if (s === 'Rapproché') return 'success';
  if (s.startsWith('Annulation')) return 'ink';
  if (s === 'Sans référence' || s === 'En attente' || s === 'Échoué') return 'warning';
  return 'neutral';
};

watch(tab, load);
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('billing.eyebrow') }}</p>
      <h1>{{ t('billing.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>

    <div class="tabs" role="tablist">
      <button v-for="k in ['journal', 'cash', 'invoices', 'receipts'] as const" :key="k" role="tab" type="button" :aria-selected="tab === k" @click="tab = k">{{ t(`billing.tab.${k}`) }}</button>
    </div>
    <ExportButton v-if="tab === 'journal'" path="/payments/export" :query="{ 'filter[day]': todayIso() }" name="paiements" />
    <ExportButton v-else-if="tab === 'invoices'" path="/invoices/export" name="factures" />
    <ExportButton v-else-if="tab === 'receipts'" path="/receipts/export" name="recus" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

    <section v-if="tab === 'journal'">
      <div class="grid grid--kpi">
        <KpiCard :indicator="{ key: 'today', label: t('billing.today'), available: true, value: totals.sum, unit: 'xaf' }" />
        <KpiCard :indicator="{ key: 'count', label: t('billing.receiptsIssued'), available: true, value: totals.count, unit: 'count' }" />
        <KpiCard :indicator="{ key: 'rec', label: t('billing.toReconcile'), available: true, value: totals.toReconcile, unit: 'count' }" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('billing.receipt') }}</th><th scope="col">{{ t('billing.time') }}</th><th scope="col">{{ t('billing.channel') }}</th><th class="num" scope="col">{{ t('billing.amount') }}</th><th scope="col">{{ t('billing.status') }}</th></tr></thead>
          <tbody>
            <tr v-if="payments.length === 0"><td colspan="5" class="muted">{{ t('billing.noPayments') }}</td></tr>
            <tr v-for="p in payments" :key="p.id">
              <th scope="row">{{ p.receipt_number ?? '—' }}</th>
              <td>{{ formatDateTime(p.received_at, locale) }}</td>
              <td>{{ p.channel }}</td>
              <td class="num" :class="{ neg: (p.journal_amount_xaf ?? p.amount_xaf) < 0 }">{{ formatXaf(p.journal_amount_xaf ?? p.amount_xaf, locale) }}</td>
              <td><StatusTag :tone="journalTone(p.journal_status)">{{ p.journal_status ?? p.status }}</StatusTag></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-else-if="tab === 'cash'" class="narrow">
      <template v-if="session">
        <div class="card">
          <p class="eyebrow">{{ t('billing.cashOpen', { no: session.register_no ?? '' }) }}</p>
          <p>{{ t('billing.float') }} : <strong>{{ formatXaf(session.float_xaf, locale) }}</strong> · {{ t('billing.collectedToday') }} : <strong>{{ formatXaf(session.cash_collected_xaf ?? 0, locale) }}</strong></p>
        </div>
        <form v-if="canCash" class="card" novalidate @submit.prevent="collect">
          <h2>{{ t('billing.collect') }}</h2>
          <div class="field"><label for="b-ref">{{ t('billing.familyRef') }}</label><input id="b-ref" v-model="reference" type="text" placeholder="FAM-0412" autocomplete="off" /></div>
          <div class="field"><label for="b-amt">{{ t('billing.amountXaf') }}</label><input id="b-amt" v-model.number="amount" type="number" min="1" step="1" inputmode="numeric" /></div>
          <p class="muted">{{ t('billing.allocationRule') }}</p>
          <button type="submit" class="btn btn--primary" :disabled="loading || !amount">{{ t('billing.collectGo') }}</button>
        </form>
        <form class="card" novalidate @submit.prevent="closeCash">
          <h2>{{ t('billing.closeCash') }}</h2>
          <div class="field"><label for="b-counted">{{ t('billing.counted') }}</label><input id="b-counted" v-model.number="counted" type="number" min="0" step="1" inputmode="numeric" /></div>
          <button type="submit" class="btn" :disabled="counted === null">{{ t('billing.closeGo') }}</button>
        </form>
      </template>
      <form v-else class="card" novalidate @submit.prevent="openCash">
        <h2>{{ t('billing.noCash') }}</h2>
        <div class="field"><label for="b-float">{{ t('billing.float') }}</label><input id="b-float" v-model.number="floatXaf" type="number" min="0" step="1" inputmode="numeric" /></div>
        <button v-if="canCash" type="submit" class="btn btn--primary">{{ t('billing.openCash') }}</button>
      </form>
    </section>

    <section v-else-if="tab === 'invoices'" class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('billing.invoice') }}</th><th class="num" scope="col">{{ t('billing.net') }}</th><th class="num" scope="col">{{ t('billing.paid') }}</th><th class="num" scope="col">{{ t('billing.balance') }}</th><th scope="col">{{ t('billing.status') }}</th></tr></thead>
        <tbody>
          <tr v-if="invoices.length === 0"><td colspan="5" class="muted">{{ t('billing.noInvoices') }}</td></tr>
          <tr v-for="i in invoices" :key="i.id">
            <th scope="row">{{ i.id.slice(-8) }}</th>
            <td class="num">{{ formatXaf(i.net_xaf, locale) }}</td>
            <td class="num">{{ formatXaf(i.paid_xaf, locale) }}</td>
            <td class="num">{{ formatXaf(i.balance_xaf, locale) }}</td>
            <td><StatusTag :tone="i.balance_xaf > 0 ? 'warning' : 'success'">{{ i.status }}</StatusTag></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('billing.receipt') }}</th><th scope="col">{{ t('billing.time') }}</th><th class="num" scope="col">{{ t('billing.amount') }}</th><th scope="col">{{ t('billing.status') }}</th><th scope="col"><span class="sr-only">PDF</span></th></tr></thead>
        <tbody>
          <tr v-if="receipts.length === 0"><td colspan="5" class="muted">{{ t('billing.noReceipts') }}</td></tr>
          <tr v-for="r in receipts" :key="r.id">
            <th scope="row">{{ r.number }}</th>
            <td>{{ formatDateTime(r.issued_at, locale) }}</td>
            <td class="num">{{ formatXaf(r.amount_xaf, locale) }}</td>
            <td><StatusTag tone="neutral">{{ r.status }}</StatusTag></td>
            <td><button type="button" class="btn" @click="openPdf(r.id)">PDF</button></td>
          </tr>
        </tbody>
      </table>
    </section>
    <p v-if="canManage" class="muted foot">{{ t('billing.manageHint') }}</p>
  </div>
</template>

<style scoped>
.tabs {
  display: flex;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--ink);
  margin: 1rem 0;
}
.tabs button {
  min-height: var(--tap);
  padding: 0 1rem;
  font: inherit;
  font-weight: 700;
  background: transparent;
  border: 1px solid transparent;
  border-bottom: 0;
  cursor: pointer;
}
.tabs button[aria-selected='true'] {
  background: var(--surface);
  border-color: var(--ink);
  border-bottom: 3px solid var(--accent);
}
.narrow {
  max-width: 560px;
}
.neg {
  color: var(--danger);
}
.foot {
  margin-top: 1rem;
}
</style>
