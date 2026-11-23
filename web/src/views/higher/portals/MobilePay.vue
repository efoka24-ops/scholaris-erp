<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { MOBILE_CHANNELS, parseXaf, PHONE, portal, type PortalFinance } from '@/api/higher';
import { formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useXt } from '../xi18n';

// Paiement mobile money depuis le portail (étudiant, ou garant pour un de ses étudiants). La clé d'idempotence est gardée
// jusqu'au succès : un double appui ou une nouvelle tentative ne crée jamais deux demandes. Le reçu arrive après confirmation.
const props = defineProps<{ accounts: PortalFinance[]; studentId?: string | null; idPrefix: string; title?: string }>();
const emit = defineEmits<{ (e: 'paid'): void }>();
const { t, tv, locale } = useXt();
const higher = useHigherStore();
const act = useHigherAction();

const due = computed(() => props.accounts.filter((a) => a.balance_xaf > 0));
const form = reactive({ account: due.value[0]?.account_id ?? '', amount: '', channel: 'orange_money' as (typeof MOBILE_CHANNELS)[number], phone: '', tried: false });
const sent = ref(false);
let key = ulid();

const chosen = computed(() => due.value.find((a) => a.account_id === form.account) ?? null);
const amount = computed(() => parseXaf(form.amount));
const tooHigh = computed(() => amount.value !== null && !!chosen.value && amount.value > chosen.value.balance_xaf);
const phoneBad = computed(() => !PHONE.test(form.phone.replace(/\s/g, '')));

async function submit(): Promise<void> {
  form.tried = true;
  sent.value = false;
  if (!chosen.value || amount.value === null || tooHigh.value || phoneBad.value) return;
  const body = { account_id: chosen.value.account_id, amount_xaf: amount.value, channel: form.channel, payer_phone: form.phone.replace(/\s/g, ''), ...(props.studentId ? { student_id: props.studentId } : {}) };
  const res = await act.run(() => portal.pay(body, key));
  if (!res) return;
  key = ulid();
  sent.value = true;
  Object.assign(form, { amount: '', tried: false });
  emit('paid');
}
</script>

<template>
  <form v-if="due.length" class="card card--strong" novalidate data-test="mobile-pay" @submit.prevent="submit">
    <h2>{{ title ?? t('hi.por.pay') }}</h2>
    <p class="muted hi-small">{{ t('hx.por.mobileOnly') }}</p>
    <div v-if="due.length > 1" class="field">
      <label :for="`${idPrefix}-acc`">{{ t('hi.por.payAccount') }}</label>
      <select :id="`${idPrefix}-acc`" v-model="form.account"><option v-for="a in due" :key="a.account_id" :value="a.account_id">{{ t('hx.por.year', { year: higher.yearName(a.academic_year_id) }) }} · {{ formatXaf(a.balance_xaf, locale) }}</option></select>
    </div>
    <div class="field">
      <label :for="`${idPrefix}-amount`">{{ t('hi.amount') }}</label>
      <input :id="`${idPrefix}-amount`" v-model="form.amount" type="text" inputmode="numeric" autocomplete="off" :aria-invalid="form.tried && (amount === null || tooHigh)" />
      <span v-if="form.tried && amount === null" class="error">{{ t('hi.amountBad') }}</span>
      <span v-else-if="form.tried && tooHigh && chosen" class="error">{{ t('hx.por.amountTooHigh', { amount: formatXaf(chosen.balance_xaf, locale) }) }}</span>
    </div>
    <fieldset class="field">
      <legend class="label">{{ t('hi.app.payChannel') }}</legend>
      <label v-for="c in MOBILE_CHANNELS" :key="c" class="check"><input v-model="form.channel" type="radio" :name="`${idPrefix}-channel`" :value="c" /> {{ tv('hi.fin.channel.', c) }}</label>
    </fieldset>
    <div class="field">
      <label :for="`${idPrefix}-phone`">{{ t('hi.app.payPhone') }}</label>
      <input :id="`${idPrefix}-phone`" v-model="form.phone" type="tel" inputmode="tel" autocomplete="tel" :aria-invalid="form.tried && phoneBad" />
      <span v-if="form.tried && phoneBad" class="error">{{ t('hi.app.payPhoneBad') }}</span>
    </div>
    <p class="hint">{{ t('hi.por.payHint') }}</p>
    <ErrorNotice :error="act.error.value" />
    <button type="submit" class="btn btn--primary btn--block" :disabled="act.busy.value || higher.readOnly">{{ t('hx.por.payBtn') }}</button>
    <p v-if="sent" class="notice notice--success hi-section" role="status" data-test="pay-pending">{{ t('hi.por.payPending') }}</p>
  </form>
  <p v-else class="notice notice--success" role="status">{{ t('hi.por.nothingDue') }}</p>
</template>
