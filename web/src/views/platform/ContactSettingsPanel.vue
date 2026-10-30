<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchPlatformContact, normalizeCameroonPhone, type MobileMoneyChannel, type PaymentNumber, type PlatformContact } from '@/api/platform';
import { useI18n } from '@/i18n';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';

// Contact de la plateforme (nom, e-mail, téléphone) et numéros de paiement mobile money de TRU GROUP (Orange Money, MTN MoMo,
// avec le nom du bénéficiaire) : montrés aux établissements sur l'écran de régularisation et utilisés par les notifications.
// Un champ vide se replie sur la configuration du serveur. Permission « réglages », double authentification récente, audit.
const { t } = useI18n();
const action = useSensitiveAction();

const contact = ref<PlatformContact | null>(null);
const loadError = ref<unknown>(null);
const saved = ref(false);
const name = ref('');
const email = ref('');
const phone = ref('');
const numbers = ref<Record<MobileMoneyChannel, { phone: string; holder: string }>>({
  orange_money: { phone: '', holder: '' },
  mtn_momo: { phone: '', holder: '' },
});
const errors = ref<Record<string, string>>({});
const CHANNELS: MobileMoneyChannel[] = ['orange_money', 'mtn_momo'];

function fill(value: PlatformContact): void {
  contact.value = value;
  name.value = value.stored.name ?? '';
  email.value = value.stored.email ?? '';
  phone.value = value.stored.phone ?? '';
  for (const c of CHANNELS) {
    const stored = value.stored.payments.find((p) => p.channel === c);
    numbers.value[c] = { phone: stored?.phone ?? '', holder: stored?.holder ?? '' };
  }
}

async function load(): Promise<void> {
  loadError.value = null;
  try {
    fill(await fetchPlatformContact());
  } catch (e) {
    loadError.value = e;
  }
}

function validate(): { payments: PaymentNumber[] } | null {
  const next: Record<string, string> = {};
  const trimmed = name.value.trim();
  if (trimmed !== '' && (trimmed.length < 2 || trimmed.length > 120)) next.name = t('rg.contact.invalidName');
  if (email.value.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())) next.email = t('rg.contact.invalidEmail');
  if (phone.value.trim() !== '' && normalizeCameroonPhone(phone.value) === null) next.phone = t('rg.contact.invalidPhone');
  const payments: PaymentNumber[] = [];
  for (const c of CHANNELS) {
    const n = numbers.value[c];
    if (n.phone.trim() === '' && n.holder.trim() === '') continue;
    const normalized = normalizeCameroonPhone(n.phone);
    if (normalized === null) next[`${c}.phone`] = t('rg.contact.invalidPhone');
    if (n.holder.trim() === '' || n.holder.trim().length > 120) next[`${c}.holder`] = t('rg.contact.holderRequired');
    if (normalized !== null && n.holder.trim() !== '') payments.push({ channel: c, phone: normalized, holder: n.holder.trim() });
  }
  errors.value = next;
  return Object.keys(next).length === 0 ? { payments } : null;
}

async function save(): Promise<void> {
  saved.value = false;
  const valid = validate();
  if (!valid) return;
  const res = await action.run<PlatformContact>(
    actions.updateContact({
      name: name.value.trim() || null,
      email: email.value.trim() || null,
      phone: phone.value.trim() === '' ? null : normalizeCameroonPhone(phone.value),
      payments: valid.payments,
    }),
    t('rg.contact.title'),
  );
  if (res) {
    fill(res);
    saved.value = true;
  }
}

const shown = (value: string | null | undefined): string => value || t('rg.contact.none');
onMounted(load);
</script>

<template>
  <form v-if="contact" class="card stack" novalidate aria-labelledby="rg-contact-title" @submit.prevent="save">
    <h2 id="rg-contact-title">{{ t('rg.contact.title') }}</h2>
    <p class="muted">{{ t('rg.contact.lede') }}</p>
    <ErrorNotice :error="loadError" />
    <ReplayNotice @done="load" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <p v-if="saved" class="notice notice--success" role="status">{{ t('rg.contact.saved') }}</p>

    <div class="field">
      <label for="rg-c-name">{{ t('rg.contact.name') }}</label>
      <input id="rg-c-name" v-model="name" type="text" maxlength="120" :aria-invalid="!!errors.name" aria-describedby="rg-c-name-hint" />
      <span id="rg-c-name-hint" class="hint">{{ t('rg.contact.effective', { value: shown(contact.effective.name) }) }}</span>
      <span v-if="errors.name" class="error">{{ errors.name }}</span>
    </div>
    <div class="field">
      <label for="rg-c-email">{{ t('rg.contact.email') }}</label>
      <input id="rg-c-email" v-model="email" type="email" autocomplete="off" maxlength="190" :aria-invalid="!!errors.email" aria-describedby="rg-c-email-hint" />
      <span id="rg-c-email-hint" class="hint">{{ t('rg.contact.effective', { value: shown(contact.effective.email) }) }}</span>
      <span v-if="errors.email" class="error">{{ errors.email }}</span>
    </div>
    <div class="field">
      <label for="rg-c-phone">{{ t('rg.contact.phone') }}</label>
      <input id="rg-c-phone" v-model="phone" type="tel" inputmode="tel" maxlength="25" :aria-invalid="!!errors.phone" aria-describedby="rg-c-phone-hint" />
      <span id="rg-c-phone-hint" class="hint">{{ t('rg.contact.phoneHint') }} {{ t('rg.contact.effective', { value: shown(contact.effective.phone) }) }}</span>
      <span v-if="errors.phone" class="error">{{ errors.phone }}</span>
    </div>

    <fieldset v-for="c in CHANNELS" :key="c" class="pay">
      <legend>{{ c === 'orange_money' ? t('rg.contact.orange') : t('rg.contact.mtn') }}</legend>
      <div class="field">
        <label :for="`rg-c-${c}-phone`">{{ t('rg.contact.phone') }}</label>
        <input :id="`rg-c-${c}-phone`" v-model="numbers[c].phone" type="tel" inputmode="tel" maxlength="25" :aria-invalid="!!errors[`${c}.phone`]" :aria-describedby="`rg-c-${c}-phone-hint`" />
        <span :id="`rg-c-${c}-phone-hint`" class="hint">{{ t('rg.contact.phoneHint') }}</span>
        <span v-if="errors[`${c}.phone`]" class="error">{{ errors[`${c}.phone`] }}</span>
      </div>
      <div class="field">
        <label :for="`rg-c-${c}-holder`">{{ t('rg.contact.holder') }}</label>
        <input :id="`rg-c-${c}-holder`" v-model="numbers[c].holder" type="text" maxlength="120" :aria-invalid="!!errors[`${c}.holder`]" />
        <span v-if="errors[`${c}.holder`]" class="error">{{ errors[`${c}.holder`] }}</span>
      </div>
    </fieldset>

    <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('admin.save') }}</button>
  </form>
  <ErrorNotice v-else :error="loadError" />
</template>

<style scoped>
.pay {
  border: 1px solid var(--border);
  padding: 12px;
  margin: 0;
}
.pay legend {
  font-weight: 700;
  padding: 0 6px;
}
</style>
