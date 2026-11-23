<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';
import StatusTag from './StatusTag.vue';
import { requestMobilePayment, waitForPayment, type MobileChannel } from '@/api/payments';
import type { Payment } from '@/api/types';
import type { EstablishmentDue } from '@/lib/family';
import { formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { useI18n } from '@/i18n';

// « Tout payer » : une demande de paiement mobile money par établissement, confirmée sur le téléphone du payeur.
const props = withDefaults(defineProps<{ dues: EstablishmentDue[]; guarantorId: string; pollMs?: number }>(), { pollMs: 3000 });
const emit = defineEmits<{ paid: [] }>();
const { t, locale } = useI18n();

type LineState = { status: 'idle' | 'sending' | 'pending' | 'confirmed' | 'failed' | 'error'; payment?: Payment; error?: unknown };
const channel = ref<MobileChannel>('orange_money');
const phone = ref('');
const phoneInvalid = ref(false);
const states = ref<Record<string, LineState>>({});
const keys = new Map<string, string>(); // une clé d'idempotence par établissement et par montant
const running = ref(false);

const total = computed(() => props.dues.reduce((s, d) => s + d.amount_xaf, 0));
const stateOf = (id: string): LineState => states.value[id] ?? { status: 'idle' };

function keyFor(d: EstablishmentDue): string {
  const k = `${d.establishment_id}:${d.amount_xaf}:${channel.value}:${phone.value}`;
  if (!keys.has(k)) keys.set(k, ulid());
  return keys.get(k) as string;
}

async function pay(d: EstablishmentDue): Promise<void> {
  states.value = { ...states.value, [d.establishment_id]: { status: 'sending' } };
  try {
    let payment = await requestMobilePayment({
      establishmentId: d.establishment_id,
      channel: channel.value,
      amountXaf: d.amount_xaf,
      payerPhone: phone.value.trim(),
      guarantorId: props.guarantorId,
      idempotencyKey: keyFor(d),
    });
    if (payment.status === 'pending') {
      states.value = { ...states.value, [d.establishment_id]: { status: 'pending', payment } };
      payment = await waitForPayment(d.establishment_id, payment.id, { intervalMs: props.pollMs, timeoutMs: props.pollMs * 40 });
    }
    const status = payment.status === 'confirmed' ? 'confirmed' : payment.status === 'pending' ? 'pending' : 'failed';
    states.value = { ...states.value, [d.establishment_id]: { status, payment } };
    if (status === 'confirmed') emit('paid');
  } catch (e) {
    states.value = { ...states.value, [d.establishment_id]: { status: 'error', error: e } };
  }
}

async function payAll(): Promise<void> {
  phoneInvalid.value = !/^\+?\d{8,15}$/.test(phone.value.replace(/[\s.-]/g, ''));
  if (phoneInvalid.value) return;
  running.value = true;
  for (const d of props.dues) {
    if (stateOf(d.establishment_id).status === 'confirmed') continue;
    await pay(d);
  }
  running.value = false;
}
</script>

<template>
  <section class="card pay" aria-labelledby="pay-title">
    <h2 id="pay-title">{{ t('pay.title') }}</h2>
    <p class="muted">{{ t('pay.intro') }}</p>

    <fieldset class="channels">
      <legend class="label">{{ t('pay.channel') }}</legend>
      <label class="check"><input v-model="channel" type="radio" value="orange_money" name="channel" /> Orange Money</label>
      <label class="check"><input v-model="channel" type="radio" value="mtn_momo" name="channel" /> MTN MoMo</label>
    </fieldset>

    <div class="field">
      <label for="pay-phone">{{ t('pay.phone') }}</label>
      <input id="pay-phone" v-model="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+237 6 00 00 00 00" :aria-invalid="phoneInvalid" />
      <span v-if="phoneInvalid" class="error">{{ t('pay.phoneInvalid') }}</span>
    </div>

    <ul class="lines">
      <li v-for="d in dues" :key="d.establishment_id" class="row row--between line">
        <div>
          <strong>{{ d.establishment_name }}</strong>
          <div class="muted">{{ formatXaf(d.amount_xaf, locale) }}</div>
        </div>
        <div class="state" aria-live="polite">
          <StatusTag v-if="stateOf(d.establishment_id).status === 'pending'" tone="warning">{{ t('pay.pending') }}</StatusTag>
          <StatusTag v-else-if="stateOf(d.establishment_id).status === 'confirmed'" tone="success">{{ t('pay.confirmed') }}</StatusTag>
          <StatusTag v-else-if="stateOf(d.establishment_id).status === 'failed'" tone="warning">{{ t('pay.failed') }}</StatusTag>
          <StatusTag v-else-if="stateOf(d.establishment_id).status === 'sending'" tone="neutral">{{ t('common.loading') }}</StatusTag>
        </div>
        <p v-if="stateOf(d.establishment_id).status === 'pending'" class="muted full">{{ t('pay.pendingHint') }}</p>
        <p v-if="stateOf(d.establishment_id).payment?.receipt_number" class="full">{{ t('pay.receipt', { number: stateOf(d.establishment_id).payment?.receipt_number ?? '' }) }}</p>
        <ErrorNotice v-if="stateOf(d.establishment_id).status === 'error'" class="full" :error="stateOf(d.establishment_id).error" />
      </li>
    </ul>

    <button type="button" class="btn btn--primary btn--block" :disabled="running || dues.length === 0" @click="payAll">
      {{ running ? t('common.loading') : t('pay.payAll', { amount: formatXaf(total, locale) }) }}
    </button>
  </section>
</template>

<style scoped>
.channels {
  border: 0;
  padding: 0;
  margin: 0 0 0.75rem;
}
.lines {
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
}
.line {
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--border);
}
.full {
  width: 100%;
  margin: 0;
}
</style>
