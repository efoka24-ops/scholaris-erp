<script setup lang="ts">
import { ref } from 'vue';
import { PAYMENT_METHODS, type PayInput, type PaymentMethod } from '@/api/higher-vacations';
import { useHv, vk } from './i18n';

// Paiement par le comptable (EX-ENS-07) : virement ou mobile money, référence obligatoire, date facultative (aujourd'hui sinon).
defineProps<{ busy: boolean }>();
const emit = defineEmits<{ (e: 'pay', input: PayInput): void }>();
const { t } = useHv();
const method = ref<PaymentMethod>('mobile_money');
const reference = ref('');
const paidOn = ref('');
const missing = ref(false);

function submit(): void {
  missing.value = reference.value.trim() === '';
  if (missing.value) return;
  emit('pay', { payment_method: method.value, payment_reference: reference.value.trim(), ...(paidOn.value ? { paid_on: paidOn.value } : {}) });
}
</script>

<template>
  <form class="hi-form" data-test="pay-form" @submit.prevent="submit">
    <div class="field">
      <label for="hv-method">{{ t('hv.payMethod') }}</label>
      <select id="hv-method" v-model="method"><option v-for="m in PAYMENT_METHODS" :key="m" :value="m">{{ t(vk('hv.method.', m)) }}</option></select>
    </div>
    <div class="field">
      <label for="hv-ref">{{ t('hv.payReference') }}</label>
      <input id="hv-ref" v-model="reference" maxlength="80" :aria-invalid="missing" />
      <span v-if="missing" class="error">{{ t('hv.required') }}</span>
    </div>
    <div class="field"><label for="hv-paid-on">{{ t('hv.paidOn') }}</label><input id="hv-paid-on" v-model="paidOn" type="date" /></div>
    <div class="field"><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('hv.pay') }}</button></div>
  </form>
</template>
