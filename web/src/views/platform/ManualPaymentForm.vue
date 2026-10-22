<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { MANUAL_CHANNELS, MAX_MONTHS, actions, type ManualChannel, type SubscriptionPayment, type Tenant } from '@/api/platform';
import { useI18n } from '@/i18n';
import { channelKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Paiement manuel (espèces, virement) : confirmé immédiatement, avec reçu ; la période couverte est prolongée.
const props = defineProps<{ clients: Tenant[]; /** Client et application déjà choisis (action rapide de la liste « À régulariser »). */ preset?: { tenant_id: string; app_key: string } }>();
const emit = defineEmits<{ recorded: [payment: SubscriptionPayment] }>();
const { t } = useI18n();
const action = useSensitiveAction();
const form = reactive({ tenant_id: props.preset?.tenant_id ?? '', app_key: props.preset?.app_key ?? '', months: 1, channel: 'cash' as ManualChannel, amount: '', note: '' });
const error = ref<string | null>(null);
const done = ref<string | null>(null);

const apps = computed(() => (props.clients.find((c) => c.id === form.tenant_id)?.applications ?? []).filter((a) => a.state !== 'closed'));

async function submit(): Promise<void> {
  error.value = null;
  done.value = null;
  const raw = form.amount.trim().replace(/\s/g, '');
  if (form.tenant_id === '' || form.app_key === '' || form.months < 1 || form.months > MAX_MONTHS || (raw !== '' && !/^\d+$/.test(raw))) {
    error.value = t('pa.pay.invalid');
    return;
  }
  const res = await action.run<SubscriptionPayment>(
    actions.recordPayment({
      tenant_id: form.tenant_id,
      app_key: form.app_key,
      months: Number(form.months),
      channel: form.channel,
      ...(raw !== '' ? { amount_xaf: Number(raw) } : {}),
      ...(form.note.trim() ? { note: form.note.trim() } : {}),
    }),
    t('pa.pay.record'),
  );
  if (!res) return;
  done.value = t('pa.pay.recorded', { receipt: res.receipt_number ?? '' });
  Object.assign(form, { app_key: '', months: 1, amount: '', note: '' });
  emit('recorded', res);
}
</script>

<template>
  <form class="card form" novalidate @submit.prevent="submit">
    <h2>{{ t('pa.pay.record') }}</h2>
    <p class="muted">{{ t('pa.pay.recordHint') }}</p>
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <div class="grid2">
      <div class="field">
        <label for="mp-client">{{ t('plat.pay.client') }}</label>
        <select id="mp-client" v-model="form.tenant_id" @change="form.app_key = ''">
          <option value="" disabled>{{ t('pa.choose') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="mp-app">{{ t('plat.col.app') }}</label>
        <select id="mp-app" v-model="form.app_key" :disabled="apps.length === 0">
          <option value="" disabled>{{ t('pa.choose') }}</option>
          <option v-for="a in apps" :key="a.id" :value="a.app_key">{{ a.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="mp-months">{{ t('plat.col.months') }}</label>
        <input id="mp-months" v-model.number="form.months" type="number" min="1" :max="MAX_MONTHS" />
      </div>
      <div class="field">
        <label for="mp-channel">{{ t('plat.col.channel') }}</label>
        <select id="mp-channel" v-model="form.channel">
          <option v-for="c in MANUAL_CHANNELS" :key="c" :value="c">{{ t(channelKey(c)) }}</option>
        </select>
      </div>
      <div class="field">
        <label for="mp-amount">{{ t('plat.col.amount') }}</label>
        <input id="mp-amount" v-model="form.amount" type="text" inputmode="numeric" aria-describedby="mp-amount-hint" />
        <span id="mp-amount-hint" class="hint">{{ t('pa.pay.amountHint') }}</span>
      </div>
      <div class="field">
        <label for="mp-note">{{ t('pa.pay.note') }}</label>
        <input id="mp-note" v-model="form.note" type="text" maxlength="500" />
      </div>
    </div>
    <p v-if="error" class="error-line" role="alert">{{ error }}</p>
    <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('pa.pay.record') }}</button>
  </form>
</template>

<style scoped>
.form {
  margin-bottom: 20px;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: 0 16px;
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
