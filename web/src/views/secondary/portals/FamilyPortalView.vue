<script setup lang="ts">
// Portail parent du secondaire (T834) : les enfants du parent connecté inscrits dans l'établissement (et seulement eux), puis, avec le
// droit secondary.family.pay, le paiement mobile money d'une facture (une clé d'idempotence par demande : un double clic ne paie pas
// deux fois), suivi de l'état jusqu'à la confirmation sur le téléphone.
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryPortals, validPhone, type FamilyPortal, type PortalChannel, type PortalInvoice, type PortalPayment } from '@/api/secondaryPortals';
import { formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { useI18n } from '@/i18n';
import { useAction } from '../useSec';
import StudentCardView from './StudentCardView.vue';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();
const portal = ref<FamilyPortal | null>(null);
const paying = ref<{ childId: string; invoice: PortalInvoice; amount: number; channel: PortalChannel; phone: string; key: string } | null>(null);
const payment = ref<PortalPayment | null>(null);
const phoneInvalid = ref(false);

async function load(): Promise<void> {
  portal.value = (await run(() => secondaryPortals.family())) ?? null;
}

function startPay(childId: string, invoice: PortalInvoice): void {
  payment.value = null;
  phoneInvalid.value = false;
  paying.value = { childId, invoice, amount: invoice.balance_xaf, channel: 'orange_money', phone: '', key: ulid() };
}

async function pay(): Promise<void> {
  const p = paying.value;
  if (!p) return;
  phoneInvalid.value = !validPhone(p.phone);
  if (phoneInvalid.value || p.amount < 1 || p.amount > p.invoice.balance_xaf) return;
  const done = await run(() => secondaryPortals.pay(p.childId, { invoice_id: p.invoice.id, amount_xaf: Math.round(p.amount), channel: p.channel, payer_phone: p.phone, idempotency_key: p.key }));
  if (done) payment.value = done;
}

async function refresh(): Promise<void> {
  const p = payment.value;
  if (!p) return;
  const next = await run(() => secondaryPortals.payment(p.child_id, p.id));
  if (next) {
    payment.value = next;
    if (next.status === 'confirmed') {
      paying.value = null;
      await load();
    }
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sec.portal.familyTitle') }}</h1>
      <p class="lede">{{ t('sec.portal.familyIntro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <template v-if="portal">
      <p v-if="portal.children.length === 0" class="muted">{{ t('sec.portal.noChild') }}</p>
      <p v-else>{{ t('sec.portal.familyBalance') }} : <strong>{{ formatXaf(portal.family_balance_xaf, locale) }}</strong></p>
      <StudentCardView v-for="child in portal.children" :key="child.person.id" :card="child">
        <template v-if="portal.can_pay && child.invoices && child.invoices.length > 0">
          <h3>{{ t('sec.portal.invoices') }}</h3>
          <ul class="rows">
            <li v-for="inv in child.invoices" :key="inv.id">
              {{ inv.reference ?? inv.id }} · {{ t('sec.portal.remaining', { amount: formatXaf(inv.balance_xaf, locale) }) }}
              <button type="button" class="btn btn--quiet" :disabled="busy" @click="startPay(child.person.id, inv)">{{ t('sec.portal.pay') }}</button>
            </li>
          </ul>
        </template>
        <p v-else-if="portal.can_pay && child.balance_xaf > 0" class="muted">{{ t('sec.portal.payAtDesk') }}</p>
      </StudentCardView>

      <form v-if="paying" class="card stack" aria-labelledby="sf-pay" @submit.prevent="pay">
        <h2 id="sf-pay">{{ t('sec.portal.payTitle') }}</h2>
        <div class="field">
          <label for="sf-amount">{{ t('sec.portal.amount') }}</label>
          <input id="sf-amount" v-model.number="paying.amount" type="number" min="1" :max="paying.invoice.balance_xaf" inputmode="numeric" required />
        </div>
        <div class="field">
          <label for="sf-channel">{{ t('sec.portal.channel') }}</label>
          <select id="sf-channel" v-model="paying.channel">
            <option value="orange_money">Orange Money</option>
            <option value="mtn_momo">MTN Mobile Money</option>
          </select>
        </div>
        <div class="field">
          <label for="sf-phone">{{ t('sec.portal.phone') }}</label>
          <input id="sf-phone" v-model="paying.phone" type="tel" inputmode="tel" autocomplete="tel" :aria-invalid="phoneInvalid" required />
          <p v-if="phoneInvalid" class="muted" role="alert">{{ t('sec.portal.phoneInvalid') }}</p>
        </div>
        <div class="row">
          <button type="submit" class="btn btn--primary" :disabled="busy || payment !== null">{{ t('sec.portal.confirmPay') }}</button>
          <button type="button" class="btn btn--quiet" @click="paying = null">{{ t('sec.portal.cancel') }}</button>
        </div>
        <div v-if="payment" class="notice" role="status">
          <p><strong>{{ t(payment.status === 'confirmed' ? 'sec.portal.payConfirmed' : payment.status === 'pending' ? 'sec.portal.payPending' : 'sec.portal.payFailed') }}</strong></p>
          <p v-if="payment.receipt_number">{{ t('sec.portal.receipt', { number: payment.receipt_number }) }}</p>
          <button v-if="payment.status === 'pending'" type="button" class="btn btn--quiet" :disabled="busy" @click="refresh">{{ t('sec.portal.refresh') }}</button>
        </div>
      </form>
    </template>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 10px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
</style>
