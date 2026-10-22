<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchReceipt, normalizePhone, payApplication, type MyRegularisation, type PaymentChannel, type PaymentReceipt, type RegularisationItem } from '@/api/platform';
import { useAuthStore } from '@/stores/auth';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { channelKey } from './display';
import { refreshRegularisation } from './regularisationGate';

// Écran de régularisation de paiement : seule page ouverte à un client bloqué (niveau « bloqué »), accessible aussi en lecture seule.
// Montant dû et détail, période, jours écoulés, paiement Orange Money ou MTN MoMo (direction et super administrateur du client
// seulement), reçu, contact de TRU GROUP et déconnexion. Le paiement confirmé débloque aussitôt l'accès.
const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();

const data = ref<MyRegularisation | null>(null);
const loadError = ref<unknown>(null);
const loading = ref(true);
const notice = ref<string | null>(null);

const POLL_MS = 5000;
const POLL_MAX = 24;
let timer: ReturnType<typeof setInterval> | null = null;

async function load(quiet = false): Promise<void> {
  if (!quiet) loading.value = true;
  loadError.value = null;
  try {
    data.value = await refreshRegularisation(auth.me?.id);
    if (data.value === null) loadError.value = new Error('regularisation');
  } finally {
    loading.value = false;
  }
  if (data.value && data.value.mode === 'ok') {
    stopPolling();
    notice.value = t('rg.client.unlocked');
  }
}

const mode = computed(() => data.value?.mode ?? 'ok');
const channels: PaymentChannel[] = ['orange_money', 'mtn_momo'];

// --- Paiement ---
const paying = ref<{ key: string; channel: PaymentChannel } | null>(null);
const phone = ref('');
const phoneError = ref<string | null>(null);
const payBusy = ref(false);
const payError = ref<unknown>(null);
const payResult = ref<string | null>(null);
const waiting = ref(false);
let polls = 0;
/** Clé d'idempotence d'une tentative : conservée si l'envoi échoue, renouvelée dès que la demande change ou aboutit. */
let idempotencyKey: string | null = null;

function startPay(item: RegularisationItem, channel: PaymentChannel): void {
  const same = paying.value?.key === item.app_key && paying.value.channel === channel;
  paying.value = same ? null : { key: item.app_key, channel };
  phoneError.value = null;
  payError.value = null;
  payResult.value = null;
  idempotencyKey = null;
}

async function submitPay(item: RegularisationItem): Promise<void> {
  if (!paying.value) return;
  payError.value = null;
  payResult.value = null;
  const normalized = normalizePhone(phone.value);
  phoneError.value = normalized === null ? t('apps.phoneInvalid') : null;
  if (normalized === null) return;
  idempotencyKey ??= ulid();
  payBusy.value = true;
  try {
    const payment = await payApplication(item.app_key, { months: item.months, channel: paying.value.channel, payer_phone: normalized }, idempotencyKey);
    payResult.value = t('rg.client.paySent', { reference: payment.reference });
    idempotencyKey = null;
    startPolling();
  } catch (e) {
    payError.value = e;
  } finally {
    payBusy.value = false;
  }
}

function startPolling(): void {
  stopPolling();
  waiting.value = true;
  polls = 0;
  timer = setInterval(() => {
    polls += 1;
    void load(true);
    if (polls >= POLL_MAX) stopPolling();
  }, POLL_MS);
}

function stopPolling(): void {
  if (timer !== null) clearInterval(timer);
  timer = null;
  waiting.value = false;
}

// --- Reçu ---
const receipt = ref<PaymentReceipt | null>(null);
const receiptError = ref<unknown>(null);
const receiptBox = ref<HTMLElement | null>(null);

async function showReceipt(id: string): Promise<void> {
  receiptError.value = null;
  try {
    receipt.value = await fetchReceipt(id);
    await nextTick();
    receiptBox.value?.focus();
  } catch (e) {
    receiptError.value = e;
  }
}

function print(): void {
  window.print();
}

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'login' });
}

const whoCanPay = computed(() => data.value?.can_pay === true);
onMounted(() => load());
onBeforeUnmount(stopPolling);
</script>

<template>
  <div class="page regularisation">
    <header class="page-head">
      <p class="eyebrow">{{ t('rg.client.eyebrow') }}</p>
      <h1>{{ t('rg.client.title') }}</h1>
      <p class="lede">{{ t(`rg.client.lede.${mode}`) }}</p>
    </header>

    <ErrorNotice :error="loadError" />
    <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
    <p v-if="mode === 'locked'" class="notice notice--warning" role="status">{{ t('rg.client.lockedNotice') }}</p>
    <p v-else-if="mode === 'read_only'" class="notice notice--warning" role="status">{{ t('rg.client.readOnlyNotice') }}</p>
    <p v-if="data && !whoCanPay && mode !== 'ok'" class="notice" role="status">{{ t('rg.client.cannotPay') }}</p>
    <p v-if="waiting" class="notice" role="status">{{ t('rg.client.waiting') }}</p>

    <template v-if="data">
      <p v-if="mode === 'ok'" class="card muted">
        {{ t('rg.client.nothingDue') }}
        <router-link :to="{ name: 'home' }">{{ t('rg.client.home') }}</router-link>
      </p>

      <ul v-else class="apps">
        <li v-for="item in data.items" :key="item.app_key" class="card">
          <div class="row row--between">
            <h2 class="name">{{ item.label }}</h2>
            <span class="tag" :class="item.bucket === 'promise' ? 'tag--accent' : 'tag--warning'">
              {{ t(`rg.bucket.${item.bucket}`) }}<template v-if="item.level"> · {{ t(`rg.level.${item.level}`) }}</template>
            </span>
          </div>

          <p class="amount" :aria-label="t('rg.client.amountDue')">
            <span class="amount__label">{{ t('rg.client.amountDue') }}</span>
            <strong class="amount__value">{{ formatXaf(item.amount_due_xaf, locale) }}</strong>
          </p>

          <dl class="facts">
            <div><dt>{{ t('rg.due.base') }}</dt><dd>{{ formatXaf(item.base_price_xaf, locale) }}</dd></div>
            <div v-for="o in item.options" :key="o.option_key">
              <dt>{{ locale === 'en' ? o.label_en : o.label_fr }}</dt>
              <dd>{{ formatXaf(o.price_xaf, locale) }}</dd>
            </div>
            <div><dt>{{ t('rg.client.monthly') }}</dt><dd>{{ formatXaf(item.monthly_total_xaf, locale) }}</dd></div>
            <div><dt>{{ t('rg.client.period') }}</dt><dd>{{ t('rg.client.periodValue', { from: formatDate(item.period_from, locale), months: item.months }) }}</dd></div>
            <div><dt>{{ t('rg.col.dueOn') }}</dt><dd>{{ formatDate(item.due_on, locale) }}</dd></div>
            <div><dt>{{ t('rg.col.late') }}</dt><dd>{{ t('rg.days', { count: item.days_late }) }}</dd></div>
            <div v-if="item.level"><dt>{{ t('rg.client.since') }}</dt><dd>{{ formatDate(item.since, locale) }} ({{ t('rg.days', { count: item.days_elapsed }) }})</dd></div>
            <div v-if="item.promise_until"><dt>{{ t('rg.col.promise') }}</dt><dd>{{ formatDate(item.promise_until, locale) }}</dd></div>
          </dl>

          <div v-if="whoCanPay" class="row" role="group" :aria-label="t('rg.client.payFor', { app: item.label })">
            <button
              v-for="c in channels"
              :key="c"
              type="button"
              class="btn btn--primary"
              :aria-expanded="paying?.key === item.app_key && paying.channel === c"
              :aria-controls="`rg-pay-${item.app_key}`"
              @click="startPay(item, c)"
            >
              {{ t('rg.client.payWith', { channel: t(channelKey(c)) }) }}
            </button>
          </div>

          <form v-if="paying?.key === item.app_key" :id="`rg-pay-${item.app_key}`" class="panel" novalidate @submit.prevent="submitPay(item)">
            <div class="field">
              <label :for="`rg-phone-${item.app_key}`">{{ t('rg.client.phone', { channel: t(channelKey(paying.channel)) }) }}</label>
              <input
                :id="`rg-phone-${item.app_key}`"
                v-model="phone"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                maxlength="25"
                :aria-invalid="!!phoneError"
                :aria-describedby="phoneError ? `rg-phone-${item.app_key}-hint rg-phone-${item.app_key}-err` : `rg-phone-${item.app_key}-hint`"
                @input="idempotencyKey = null"
              />
              <span :id="`rg-phone-${item.app_key}-hint`" class="hint">{{ t('apps.phoneHint') }}</span>
              <span v-if="phoneError" :id="`rg-phone-${item.app_key}-err`" class="error">{{ phoneError }}</span>
            </div>
            <ErrorNotice :error="payError" />
            <p v-if="payResult" class="notice notice--success" role="status">{{ payResult }}</p>
            <div class="row">
              <button type="submit" class="btn btn--primary" :disabled="payBusy">{{ payBusy ? t('common.loading') : t('rg.client.payNow', { amount: formatXaf(item.amount_due_xaf, locale) }) }}</button>
              <button type="button" class="btn btn--quiet" @click="paying = null">{{ t('plat.cancel') }}</button>
            </div>
          </form>

          <div v-if="(item.receipts ?? []).length > 0" class="receipts">
            <h3>{{ t('rg.client.receipts') }}</h3>
            <ul class="list">
              <li v-for="r in item.receipts" :key="r.id" class="row row--between">
                <span>{{ r.receipt_number }} · {{ formatXaf(r.amount_xaf, locale) }}<template v-if="r.paid_at"> · {{ formatDateTime(r.paid_at, locale) }}</template></span>
                <button type="button" class="btn btn--quiet" :aria-label="t('apps.receiptNamed', { reference: r.receipt_number })" @click="showReceipt(r.id)">{{ t('rg.client.downloadReceipt') }}</button>
              </li>
            </ul>
          </div>
        </li>
      </ul>

      <ErrorNotice :error="receiptError" />
      <article v-if="receipt" ref="receiptBox" class="receipt card" tabindex="-1" aria-labelledby="rg-receipt-title">
        <p class="eyebrow">{{ receipt.issuer }}</p>
        <h3 id="rg-receipt-title">{{ t('apps.receiptTitle', { number: receipt.receipt_number }) }}</h3>
        <dl class="facts">
          <div><dt>{{ t('apps.receiptApp') }}</dt><dd>{{ receipt.app_label }}</dd></div>
          <div><dt>{{ t('plat.col.amount') }}</dt><dd>{{ formatXaf(receipt.amount_xaf, locale) }}</dd></div>
          <div><dt>{{ t('apps.receiptPeriod') }}</dt><dd>{{ receipt.covered_from && receipt.covered_until ? t('apps.periodRange', { from: formatDate(receipt.covered_from, locale), to: formatDate(receipt.covered_until, locale) }) : '—' }}</dd></div>
          <div><dt>{{ t('apps.receiptReference') }}</dt><dd><code>{{ receipt.reference }}</code></dd></div>
        </dl>
        <div class="row no-print">
          <button type="button" class="btn" @click="print">{{ t('apps.print') }}</button>
          <button type="button" class="btn btn--quiet" @click="receipt = null">{{ t('rg.client.closeReceipt') }}</button>
        </div>
      </article>

      <section class="card" aria-labelledby="rg-contact">
        <h2 id="rg-contact">{{ t('rg.client.contactTitle', { name: data.contact.name }) }}</h2>
        <p class="muted">{{ t('rg.client.contactText') }}</p>
        <ul class="list">
          <li v-if="data.contact.phone"><a :href="`tel:${data.contact.phone}`">{{ data.contact.phone }}</a></li>
          <li v-if="data.contact.email"><a :href="`mailto:${data.contact.email}`">{{ data.contact.email }}</a></li>
          <li v-if="!data.contact.phone && !data.contact.email" class="muted">{{ t('rg.client.contactNone', { name: data.contact.name }) }}</li>
        </ul>
      </section>
    </template>

    <div class="row no-print">
      <button type="button" class="btn" @click="logout">{{ t('rg.client.logout') }}</button>
      <button v-if="mode !== 'locked' && data" type="button" class="btn btn--quiet" @click="router.push({ name: 'home' })">{{ t('rg.client.home') }}</button>
    </div>
  </div>
</template>

<style scoped>
.apps {
  list-style: none;
  padding: 0;
  margin: 0 0 16px;
  display: grid;
  gap: 12px;
}
.name {
  margin: 0;
  font-size: 19px;
}
.amount {
  margin: 14px 0;
  display: grid;
}
.amount__label {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.amount__value {
  font-size: 28px;
}
.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 32px;
  margin: 14px 0;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.facts dd {
  margin: 2px 0 0;
  font-weight: 700;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
  max-width: 480px;
}
.receipts h3 {
  font-size: 15px;
  margin: 16px 0 8px;
}
.list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.receipt {
  margin-bottom: 16px;
}
.regularisation .row {
  margin-top: 8px;
}
@media print {
  .no-print {
    display: none;
  }
}
</style>
