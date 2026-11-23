<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  MAX_MONTHS,
  PAYMENT_CHANNELS,
  fetchApplicationPayments,
  fetchMyApplications,
  fetchReceipt,
  normalizePhone,
  openApplication,
  payApplication,
  type Entitlement,
  type MyApplications,
  type PaymentChannel,
  type PaymentReceipt,
  type SubscriptionPayment,
} from '@/api/platform';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { appStatusKey, channelKey, paymentStatusKey, paymentTone, sectorKey, stateKey, stateTone, targetKey } from './display';
import { groupByLevel, useRegimePick, type LevelGroup } from './catalogGroups';

// « Mes applications » : état des droits d'usage (essai, actif, en retard, suspendu), ouverture en libre-service, paiement de
// l'abonnement par mobile money (clé d'idempotence conservée entre deux tentatives), historique et reçus.
// Design : Main.html (tuiles), Frais.html (paiement mobile money, reçu), Fondations.html (champs, étiquettes).
const { t, tp, locale } = useI18n();
const ctx = useContextStore();

const data = ref<MyApplications | null>(null);
const loadError = ref<unknown>(null);
const loading = ref(true);
const notice = ref<string | null>(null);

// Catalogue des applications non ouvertes : une carte par niveau, le régime (privé ou public) choisit l'application à ouvrir.
type CatalogEntry = MyApplications['catalog'][number];
const catalogGroups = computed(() => groupByLevel(data.value?.catalog ?? []));
const regime = useRegimePick<CatalogEntry>();
const named = (group: LevelGroup<CatalogEntry>, app: CatalogEntry): string =>
  group.regimes.length > 1 ? `${app.label} (${t(sectorKey(regime.current(group).sector))})` : app.label;

const canManage = computed(() => ctx.can('applications.manage'));
const hasEstablishment = computed(() => !!ctx.establishmentId);
const actionable = computed(() => canManage.value && hasEstablishment.value);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    data.value = await fetchMyApplications();
  } catch (e) {
    loadError.value = e;
  } finally {
    loading.value = false;
  }
}

// --- Ouverture en libre-service ---
const confirmOpen = ref<string | null>(null);
const opening = ref(false);
const openError = ref<unknown>(null);

async function open(key: string, label: string): Promise<void> {
  opening.value = true;
  openError.value = null;
  try {
    await openApplication(key);
    confirmOpen.value = null;
    notice.value = t('apps.openDone', { label });
    await load();
  } catch (e) {
    openError.value = e;
  } finally {
    opening.value = false;
  }
}

// --- Paiement ---
const paying = ref<string | null>(null);
const pay = reactive({ months: 1, channel: '' as PaymentChannel | '', phone: '' });
const payErrors = ref<{ channel?: string; phone?: string }>({});
const payBusy = ref(false);
const payError = ref<unknown>(null);
const payResult = ref<string | null>(null);
/** Clé d'idempotence d'une tentative : conservée si l'envoi échoue, renouvelée dès que la demande change ou aboutit. */
let idempotencyKey: string | null = null;

/** Périodicité imposée par la plateforme à ce client (1 si libre) : les durées payables en sont des multiples. */
const periodOf = (app: Entitlement): number => (app.terms?.billing_period_overridden ? app.terms.billing_period_months : 1);
const monthChoices = (app: Entitlement): number[] => Array.from({ length: MAX_MONTHS }, (_, i) => i + 1).filter((m) => m % periodOf(app) === 0);
const monthlyTotal = (app: Entitlement): number => app.monthly_total_xaf ?? app.monthly_price_xaf;

function startPay(app: Entitlement): void {
  paying.value = paying.value === app.app_key ? null : app.app_key;
  pay.months = periodOf(app);
  pay.channel = '';
  pay.phone = '';
  payErrors.value = {};
  payError.value = null;
  payResult.value = null;
  idempotencyKey = null;
}

function changed(): void {
  idempotencyKey = null;
}

async function submitPay(app: Entitlement): Promise<void> {
  payError.value = null;
  payResult.value = null;
  const phone = normalizePhone(pay.phone);
  payErrors.value = {
    channel: pay.channel === '' ? t('apps.channelRequired') : undefined,
    phone: phone === null ? t('apps.phoneInvalid') : undefined,
  };
  if (payErrors.value.channel || payErrors.value.phone) return;
  idempotencyKey ??= ulid();
  payBusy.value = true;
  try {
    const payment = await payApplication(app.app_key, { months: pay.months, channel: pay.channel as PaymentChannel, payer_phone: phone as string }, idempotencyKey);
    payResult.value = t('apps.paySent', { reference: payment.reference });
    idempotencyKey = null;
    if (history.value === app.app_key) await loadHistory(app.app_key);
  } catch (e) {
    payError.value = e;
  } finally {
    payBusy.value = false;
  }
}

// --- Historique et reçus ---
const history = ref<string | null>(null);
const payments = ref<SubscriptionPayment[]>([]);
const historyError = ref<unknown>(null);
const historyLoading = ref(false);
const receipt = ref<PaymentReceipt | null>(null);
const receiptError = ref<unknown>(null);
const receiptBox = ref<HTMLElement | null>(null);

async function loadHistory(key: string): Promise<void> {
  historyLoading.value = true;
  historyError.value = null;
  try {
    payments.value = (await fetchApplicationPayments(key)).payments;
  } catch (e) {
    historyError.value = e;
  } finally {
    historyLoading.value = false;
  }
}

async function toggleHistory(app: Entitlement): Promise<void> {
  receipt.value = null;
  if (history.value === app.app_key) {
    history.value = null;
    return;
  }
  history.value = app.app_key;
  payments.value = [];
  await loadHistory(app.app_key);
}

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

const establishmentsOf = (app: Entitlement): string => (app.establishments ?? []).map((e) => e.name).join(', ');

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('apps.eyebrow') }}</p>
      <h1>{{ t('apps.title') }}</h1>
      <p class="lede">{{ t('apps.lede') }}</p>
    </header>

    <ErrorNotice :error="loadError" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
    <p v-if="!hasEstablishment" class="notice notice--warning">{{ t('apps.chooseEstablishment') }}</p>
    <p v-else-if="!canManage" class="notice">{{ t('apps.readOnly') }}</p>
    <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-if="data">
      <section aria-labelledby="apps-opened">
        <h2 id="apps-opened">{{ t('apps.opened') }}</h2>
        <p v-if="data.opened.length === 0" class="card muted">{{ t('apps.noneOpened') }}</p>
        <ul v-else class="list">
          <li v-for="app in data.opened" :key="app.id" class="card" :class="{ 'card--strong': app.state === 'past_due' || app.state === 'suspended' }">
            <div class="row row--between">
              <div>
                <h3 class="name">{{ app.label }}</h3>
                <p class="muted small">{{ t(targetKey(app.target)) }}<template v-if="app.target === 'nursery' || app.target === 'primary'"> · {{ t(sectorKey(app.sector ?? 'private')) }}</template><template v-if="establishmentsOf(app)"> · {{ t('apps.for', { names: establishmentsOf(app) }) }}</template></p>
              </div>
              <span class="tag" :class="stateTone(app.state) ? `tag--${stateTone(app.state)}` : app.state === 'trial' ? 'tag--accent' : ''">{{ t(stateKey(app.state)) }}</span>
            </div>

            <ul class="facts">
              <li v-if="app.state === 'trial'"><strong>{{ tp('apps.trialLeft', app.trial_days_left) }}</strong> · {{ t('apps.trialEnds', { date: formatDate(app.trial_ends_on, locale) }) }}</li>
              <li v-if="app.paid_until">{{ t('apps.paidUntil', { date: formatDate(app.paid_until, locale) }) }}</li>
              <li v-if="app.due_on && app.state !== 'trial'">{{ t('apps.dueOn', { date: formatDate(app.due_on, locale) }) }}</li>
              <li>{{ t('apps.price', { amount: formatXaf(monthlyTotal(app), locale) }) }}</li>
            </ul>
            <p v-if="app.state === 'past_due'" class="notice notice--warning">{{ t('apps.pastDueText') }}</p>
            <p v-if="app.state === 'suspended'" class="notice notice--warning">{{ t('apps.suspendedText') }}</p>
            <p v-if="app.state_reason" class="muted small">{{ t('apps.reason', { reason: app.state_reason }) }}</p>

            <div v-if="actionable" class="row">
              <button type="button" class="btn btn--primary" :aria-expanded="paying === app.app_key" :aria-controls="`pay-${app.id}`" :aria-label="t('apps.payNamed', { label: app.label })" @click="startPay(app)">{{ t('apps.pay') }}</button>
              <button type="button" class="btn" :aria-expanded="history === app.app_key" :aria-controls="`hist-${app.id}`" :aria-label="history === app.app_key ? t('apps.hideHistory') : t('apps.historyNamed', { label: app.label })" @click="toggleHistory(app)">{{ history === app.app_key ? t('apps.hideHistory') : t('apps.history') }}</button>
            </div>

            <form v-if="paying === app.app_key" :id="`pay-${app.id}`" class="panel" novalidate @submit.prevent="submitPay(app)">
              <h4>{{ t('apps.payTitle', { label: app.label }) }}</h4>
              <div class="cols">
                <div class="field">
                  <label :for="`pay-months-${app.id}`">{{ t('apps.months') }}</label>
                  <select :id="`pay-months-${app.id}`" v-model.number="pay.months" @change="changed">
                    <option v-for="m in monthChoices(app)" :key="m" :value="m">{{ tp('apps.monthsOption', m) }}</option>
                  </select>
                </div>
                <div class="field">
                  <label :for="`pay-phone-${app.id}`">{{ t('apps.phone') }}</label>
                  <input :id="`pay-phone-${app.id}`" v-model="pay.phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="25" :aria-invalid="!!payErrors.phone" :aria-describedby="payErrors.phone ? `pay-phone-${app.id}-hint pay-phone-${app.id}-err` : `pay-phone-${app.id}-hint`" @input="changed" />
                  <span :id="`pay-phone-${app.id}-hint`" class="hint">{{ t('apps.phoneHint') }}</span>
                  <span v-if="payErrors.phone" :id="`pay-phone-${app.id}-err`" class="error">{{ payErrors.phone }}</span>
                </div>
              </div>
              <fieldset class="channels" :aria-describedby="payErrors.channel ? `pay-channel-${app.id}-err` : undefined">
                <legend class="label">{{ t('apps.channel') }}</legend>
                <label v-for="c in PAYMENT_CHANNELS" :key="c" class="channel" :class="{ 'channel--on': pay.channel === c }">
                  <input v-model="pay.channel" type="radio" :name="`pay-channel-${app.id}`" :value="c" @change="changed" />
                  <span>{{ t(channelKey(c)) }}</span>
                </label>
                <span v-if="payErrors.channel" :id="`pay-channel-${app.id}-err`" class="error">{{ payErrors.channel }}</span>
              </fieldset>
              <p class="total">{{ t('apps.total', { amount: formatXaf(monthlyTotal(app) * pay.months, locale) }) }}</p>
              <ErrorNotice :error="payError" />
              <p v-if="payResult" class="notice notice--success" role="status">{{ payResult }}</p>
              <div class="row">
                <button type="submit" class="btn btn--primary" :disabled="payBusy">{{ payBusy ? t('common.loading') : t('apps.payNow') }}</button>
                <button type="button" class="btn btn--quiet" @click="paying = null">{{ t('plat.cancel') }}</button>
              </div>
            </form>

            <section v-if="history === app.app_key" :id="`hist-${app.id}`" class="panel" :aria-label="t('apps.historyTitle', { label: app.label })">
              <h4>{{ t('apps.historyTitle', { label: app.label }) }}</h4>
              <ErrorNotice :error="historyError" />
              <p v-if="historyLoading" class="muted" role="status">{{ t('common.loading') }}</p>
              <p v-else-if="!historyError && payments.length === 0" class="muted">{{ t('apps.noPayments') }}</p>
              <div v-else-if="payments.length > 0" class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">{{ t('plat.col.reference') }}</th>
                      <th scope="col" class="num">{{ t('plat.col.amount') }}</th>
                      <th scope="col" class="num">{{ t('plat.col.months') }}</th>
                      <th scope="col">{{ t('plat.col.channel') }}</th>
                      <th scope="col">{{ t('plat.col.status') }}</th>
                      <th scope="col">{{ t('plat.col.period') }}</th>
                      <th scope="col"><span class="sr-only">{{ t('plat.col.receipt') }}</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in payments" :key="p.id">
                      <th scope="row"><code>{{ p.reference }}</code></th>
                      <td class="num">{{ formatXaf(p.amount_xaf, locale) }}</td>
                      <td class="num">{{ p.months }}</td>
                      <td>{{ t(channelKey(p.channel)) }}</td>
                      <td><span class="tag" :class="paymentTone(p.status) ? `tag--${paymentTone(p.status)}` : ''">{{ t(paymentStatusKey(p.status)) }}</span></td>
                      <td>{{ p.covered_from && p.covered_until ? t('apps.periodRange', { from: formatDate(p.covered_from, locale), to: formatDate(p.covered_until, locale) }) : '—' }}</td>
                      <td><button v-if="p.status === 'succeeded'" type="button" class="btn btn--quiet" :aria-label="t('apps.receiptNamed', { reference: p.reference })" @click="showReceipt(p.id)">{{ t('apps.receipt') }}</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <ErrorNotice :error="receiptError" />
              <article v-if="receipt" ref="receiptBox" class="receipt" tabindex="-1" aria-labelledby="receipt-title">
                <p class="eyebrow">{{ receipt.issuer }}</p>
                <h5 id="receipt-title">{{ t('apps.receiptTitle', { number: receipt.receipt_number }) }}</h5>
                <dl>
                  <div><dt>{{ t('apps.receiptApp') }}</dt><dd>{{ receipt.app_label }}</dd></div>
                  <div><dt>{{ t('plat.col.amount') }}</dt><dd>{{ formatXaf(receipt.amount_xaf, locale) }} · {{ tp('apps.monthsOption', receipt.months) }}</dd></div>
                  <div><dt>{{ t('apps.receiptPeriod') }}</dt><dd>{{ receipt.covered_from && receipt.covered_until ? t('apps.periodRange', { from: formatDate(receipt.covered_from, locale), to: formatDate(receipt.covered_until, locale) }) : '—' }}</dd></div>
                  <div><dt>{{ t('plat.col.channel') }}</dt><dd>{{ t(channelKey(receipt.channel)) }}</dd></div>
                  <div><dt>{{ t('apps.receiptReference') }}</dt><dd><code>{{ receipt.reference }}</code></dd></div>
                  <div><dt>{{ t('apps.receiptPaidAt') }}</dt><dd>{{ receipt.paid_at ? formatDateTime(receipt.paid_at, locale) : '—' }}</dd></div>
                  <div><dt>{{ t('apps.receiptIssuer') }}</dt><dd>{{ receipt.issuer }}</dd></div>
                </dl>
                <div class="row no-print">
                  <button type="button" class="btn btn--ink" @click="print">{{ t('apps.print') }}</button>
                  <button type="button" class="btn btn--quiet" @click="receipt = null">{{ t('apps.closeReceipt') }}</button>
                </div>
              </article>
            </section>
          </li>
        </ul>
      </section>

      <section v-if="data.catalog.length > 0" class="block" aria-labelledby="apps-others">
        <h2 id="apps-others">{{ t('apps.others') }}</h2>
        <ErrorNotice :error="openError" />
        <ul class="grid grid--tiles others">
          <li v-for="group in catalogGroups" :key="group.level" class="card other" :data-level="group.level">
            <template v-for="app in [regime.current(group).app]" :key="app.key">
            <div class="row row--between">
              <span class="tag">{{ t(targetKey(group.level)) }}</span>
              <span class="tag" :class="app.status === 'available' ? 'tag--success' : ''">{{ t(appStatusKey(app.status)) }}</span>
            </div>
            <h3 class="name">{{ group.label }}</h3>
            <div v-if="group.regimes.length > 1" class="regimes" role="radiogroup" :aria-label="t('pub.regime.legendNamed', { label: group.label })">
              <label v-for="r in group.regimes" :key="r.app.key" class="regime" :class="{ 'regime--on': app.key === r.app.key }">
                <input type="radio" :name="'regime-' + group.level" :value="r.sector" :checked="app.key === r.app.key" @change="regime.choose(group, r.sector); confirmOpen = null" />
                <span>{{ t(sectorKey(r.sector)) }}</span>
              </label>
            </div>

            <p class="muted small">{{ t('pub.catalog.trial', { days: app.trial_days }) }} · {{ t('pub.catalog.price', { amount: formatXaf(app.monthly_price_xaf, locale) }) }}</p>
            <template v-if="app.status === 'available'">
              <template v-if="actionable">
                <div v-if="confirmOpen === app.key" class="confirm" role="group" :aria-label="t('apps.openNamed', { label: named(group, app) })">
                  <p>{{ t('apps.openConfirm', { label: named(group, app), days: app.trial_days, amount: formatXaf(app.monthly_price_xaf, locale) }) }}</p>
                  <div class="row">
                    <button type="button" class="btn btn--primary" :disabled="opening" @click="open(app.key, named(group, app))">{{ opening ? t('common.loading') : t('apps.openConfirmBtn') }}</button>
                    <button type="button" class="btn btn--quiet" @click="confirmOpen = null">{{ t('plat.cancel') }}</button>
                  </div>
                </div>
                <button v-else type="button" class="btn btn--primary open-btn" :aria-label="t('apps.openNamed', { label: named(group, app) })" @click="confirmOpen = app.key">{{ t('apps.openApp') }}</button>
              </template>
            </template>
            <p v-else class="soon">{{ t('apps.soon') }}</p>
            </template>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.name {
  margin: 0;
  font-size: 20px;
}
.small {
  font-size: 13px;
  margin: 2px 0 0;
}
.facts {
  list-style: none;
  padding: 0;
  margin: 14px 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px 24px;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
}
.panel h4 {
  margin: 0 0 12px;
  font-size: 17px;
}
.cols {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.channels {
  border: 0;
  padding: 0;
  margin: 0 0 16px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.channels legend {
  margin-bottom: 6px;
}
.channel {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: var(--tap);
  padding: 0 16px;
  border: 1px solid var(--input-border);
  background: var(--surface);
  font-weight: 700;
  cursor: pointer;
}
.channel input {
  width: 18px;
  height: 18px;
  accent-color: var(--ink);
}
.channel--on {
  border: 2px solid var(--ink);
}
.channels .error {
  flex-basis: 100%;
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
.total {
  font-size: 18px;
  font-weight: 800;
}
.block {
  margin-top: 32px;
}
.others {
  list-style: none;
  padding: 0;
  margin: 0;
}
.regimes {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.regime {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: var(--tap);
  padding: 0 14px;
  border: 1.5px solid currentColor;
  border-radius: 999px;
  font-weight: 700;
  cursor: pointer;
}
.regime--on {
  background: var(--ink, #14213d);
  border-color: var(--ink, #14213d);
  color: #fff;
}
.regime input {
  accent-color: currentColor;
}
.other {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.open-btn {
  align-self: flex-start;
  margin-top: auto;
}
.soon {
  margin: auto 0 0;
  font-weight: 700;
  color: var(--muted);
}
.confirm p {
  margin-bottom: 10px;
}
.receipt {
  margin-top: 16px;
  border: 2px solid var(--ink);
  padding: 20px;
  background: var(--surface);
  max-width: 560px;
}
.receipt h5 {
  font-size: 20px;
  margin: 4px 0 12px;
}
.receipt dl {
  margin: 0 0 16px;
  display: grid;
  gap: 8px;
}
.receipt dl div {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 4px 16px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 6px;
}
.receipt dt {
  color: var(--muted);
  font-size: 13px;
  font-weight: 700;
}
.receipt dd {
  margin: 0;
  font-weight: 700;
}
@media print {
  .no-print {
    display: none;
  }
}
</style>
