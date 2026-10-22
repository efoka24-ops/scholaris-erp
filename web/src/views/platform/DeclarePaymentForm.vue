<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  declarePayment,
  normalizeCameroonPhone,
  type DeclarationKind,
  type MobileMoneyChannel,
  type PaymentNumber,
  type RegularisationDeclaration,
  type RegularisationItem,
} from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { channelKey } from './display';

// Formulaire de déclaration d'un paiement déjà fait (Orange Money ou MTN MoMo) : numéro payeur, type total ou partiel, moyen,
// référence. Il crée une demande de régularisation que la plateforme vérifie : il ne débloque jamais à lui seul.
const props = defineProps<{ item: RegularisationItem; numbers: PaymentNumber[]; partialMin: number }>();
const emit = defineEmits<{ declared: [declaration: RegularisationDeclaration]; cancel: [] }>();
const { t, locale } = useI18n();

const channels: MobileMoneyChannel[] = ['orange_money', 'mtn_momo'];
const kind = ref<DeclarationKind>('total');
const channel = ref<MobileMoneyChannel>('orange_money');
const amount = ref('');
const payer = ref('');
const reference = ref('');
const errors = ref<{ amount?: string; payer?: string; reference?: string }>({});
const busy = ref(false);
const submitError = ref<unknown>(null);
/** Clé d'idempotence : conservée si l'envoi échoue (réseau), renouvelée dès qu'un champ change. */
let idempotencyKey: string | null = null;

const balance = computed(() => props.item.balance_xaf ?? props.item.amount_due_xaf);
const maxPartial = computed(() => balance.value - 1);
const canPartial = computed(() => props.partialMin <= maxPartial.value);
const number = computed(() => props.numbers.find((n) => n.channel === channel.value) ?? null);
const id = (suffix: string): string => `rg-decl-${props.item.app_key}-${suffix}`;

function changed(): void {
  idempotencyKey = null;
}

function validate(): { phone: string; value: number | null } | null {
  const next: typeof errors.value = {};
  const phone = normalizeCameroonPhone(payer.value);
  if (phone === null) next.payer = t('rg.decl.payerInvalid');
  let value: number | null = null;
  if (kind.value === 'partial') {
    const parsed = Number(amount.value);
    if (!Number.isInteger(parsed) || parsed < props.partialMin || parsed > maxPartial.value) {
      next.amount = t('rg.decl.amountInvalid', { min: formatXaf(props.partialMin, locale.value), max: formatXaf(maxPartial.value, locale.value) });
    } else {
      value = parsed;
    }
  }
  if (reference.value.replace(/[^A-Za-z0-9]/g, '').length < 4 || reference.value.trim().length > 120) next.reference = t('rg.decl.referenceInvalid');
  errors.value = next;
  return Object.keys(next).length > 0 || phone === null ? null : { phone, value };
}

async function submit(): Promise<void> {
  submitError.value = null;
  const valid = validate();
  if (valid === null) return;
  idempotencyKey ??= ulid();
  busy.value = true;
  try {
    const declaration = await declarePayment({
      app_key: props.item.app_key,
      kind: kind.value,
      ...(valid.value !== null ? { amount_xaf: valid.value } : {}),
      channel: channel.value,
      payer_phone: valid.phone,
      reference: reference.value.trim(),
      idempotency_key: idempotencyKey,
    });
    idempotencyKey = null;
    emit('declared', declaration);
  } catch (e) {
    submitError.value = e;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <form class="declare" novalidate :aria-labelledby="id('title')" @submit.prevent="submit">
    <h3 :id="id('title')">{{ t('rg.decl.title') }}</h3>
    <p class="muted">{{ t('rg.decl.lede') }}</p>
    <p class="balance">
      <span>{{ t('rg.decl.balance') }}</span>
      <strong>{{ formatXaf(balance, locale) }}</strong>
      <span v-if="(item.credit_xaf ?? 0) > 0" class="muted">{{ t('rg.decl.credit', { amount: formatXaf(item.credit_xaf ?? 0, locale) }) }}</span>
    </p>

    <fieldset class="field">
      <legend>{{ t('rg.decl.kind') }}</legend>
      <label class="choice">
        <input v-model="kind" type="radio" :name="id('kind')" value="total" @change="changed" />
        <span>{{ t('rg.decl.kindTotal', { amount: formatXaf(balance, locale) }) }}</span>
      </label>
      <label v-if="canPartial" class="choice">
        <input v-model="kind" type="radio" :name="id('kind')" value="partial" @change="changed" />
        <span>{{ t('rg.decl.kindPartial') }}</span>
      </label>
    </fieldset>

    <div v-if="kind === 'partial'" class="field">
      <label :for="id('amount')">{{ t('rg.decl.amount') }}</label>
      <input
        :id="id('amount')"
        v-model="amount"
        type="number"
        inputmode="numeric"
        :min="partialMin"
        :max="maxPartial"
        :aria-invalid="!!errors.amount"
        :aria-describedby="errors.amount ? `${id('amount-hint')} ${id('amount-err')}` : id('amount-hint')"
        @input="changed"
      />
      <span :id="id('amount-hint')" class="hint">{{ t('rg.decl.amountHint', { min: formatXaf(partialMin, locale), max: formatXaf(maxPartial, locale), balance: formatXaf(balance, locale) }) }}</span>
      <span v-if="errors.amount" :id="id('amount-err')" class="error">{{ errors.amount }}</span>
    </div>

    <fieldset class="field">
      <legend>{{ t('rg.decl.channel') }}</legend>
      <label v-for="c in channels" :key="c" class="choice">
        <input v-model="channel" type="radio" :name="id('channel')" :value="c" @change="changed" />
        <span>{{ t(channelKey(c)) }}</span>
      </label>
      <p v-if="number" class="pay-to" role="status">{{ t('rg.decl.paidTo', { phone: number.phone, holder: number.holder }) }}</p>
    </fieldset>

    <div class="field">
      <label :for="id('payer')">{{ t('rg.decl.payer') }}</label>
      <input
        :id="id('payer')"
        v-model="payer"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        maxlength="25"
        :aria-invalid="!!errors.payer"
        :aria-describedby="errors.payer ? `${id('payer-hint')} ${id('payer-err')}` : id('payer-hint')"
        @input="changed"
      />
      <span :id="id('payer-hint')" class="hint">{{ t('rg.decl.payerHint') }}</span>
      <span v-if="errors.payer" :id="id('payer-err')" class="error">{{ errors.payer }}</span>
    </div>

    <div class="field">
      <label :for="id('reference')">{{ t('rg.decl.reference') }}</label>
      <input
        :id="id('reference')"
        v-model="reference"
        type="text"
        maxlength="120"
        autocomplete="off"
        :aria-invalid="!!errors.reference"
        :aria-describedby="errors.reference ? `${id('reference-hint')} ${id('reference-err')}` : id('reference-hint')"
        @input="changed"
      />
      <span :id="id('reference-hint')" class="hint">{{ t('rg.decl.referenceHint') }}</span>
      <span v-if="errors.reference" :id="id('reference-err')" class="error">{{ errors.reference }}</span>
    </div>

    <ErrorNotice :error="submitError" />
    <div class="row">
      <button type="submit" class="btn btn--primary" :disabled="busy">{{ busy ? t('common.loading') : t('rg.decl.submit') }}</button>
      <button type="button" class="btn btn--quiet" @click="emit('cancel')">{{ t('plat.cancel') }}</button>
    </div>
  </form>
</template>

<style scoped>
.declare {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
  max-width: 520px;
}
.declare h3 {
  margin: 0 0 6px;
  font-size: 16px;
}
.balance {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 12px;
  margin: 10px 0;
}
.balance strong {
  font-size: 20px;
}
fieldset.field {
  border: 0;
  padding: 0;
  margin: 0 0 12px;
}
fieldset.field legend {
  font-weight: 700;
  font-size: 13px;
  padding: 0;
  margin-bottom: 4px;
}
.choice {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: var(--tap);
}
.pay-to {
  margin: 4px 0 0;
  font-weight: 700;
}
</style>
