<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, type AccessChannel, type AccessCode, type AccessDelivery, type AccessSend } from '@/api/platform';
import { useI18n } from '@/i18n';
import { copyText } from './clipboard';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Code d'accès à usage unique pour un compte : création (canal au choix), affichage unique en gros caractères avec
// « Copier », récapitulatif d'accès, message prêt (« Copier le message » pour WhatsApp), renvoi du même code par
// e-mail ou SMS. Jamais rejoué après la double authentification : la personne relance l'action (le code doit s'afficher).
const props = defineProps<{ tenant: string | null; userId: string; name: string; channels: AccessChannel[]; initial?: AccessCode | null }>();
defineEmits<{ close: [] }>();
const { t, locale } = useI18n();
const action = useSensitiveAction();
const issued = ref<AccessCode | null>(props.initial ?? null);
const send = ref<AccessSend>('none');
const delivery = ref<AccessDelivery | null>(props.initial?.delivery ?? null);
const copyStatus = ref<string | null>(null);

const expiry = computed(() => {
  if (!issued.value) return '';
  const time = new Date(issued.value.expires_at).toLocaleTimeString(locale.value === 'en' ? 'en-GB' : 'fr-FR', { hour: '2-digit', minute: '2-digit' });
  return t('pa.code.expires', { time, minutes: String(Math.round(issued.value.expires_in / 60)) });
});

const deliveryText = computed(() => {
  const d = delivery.value;
  if (!d || d.channel === 'none' || d.status === 'not_sent') return null;
  const channel = t(d.channel === 'sms' ? 'pa.code.channel.sms' : 'pa.code.channel.email');
  if (d.status === 'sent') return t('pa.code.delivery.sent', { channel, to: d.masked_to ?? '' });
  return t(d.status === 'failed' ? 'pa.code.delivery.failed' : 'pa.code.delivery.unavailable', { channel });
});

async function generate(): Promise<void> {
  copyStatus.value = null;
  const res = await action.run<AccessCode>(actions.issueAccessCode(props.tenant, props.userId, send.value), `${t('pa.code.action')} · ${props.name}`, false);
  if (!res) return;
  issued.value = res;
  delivery.value = res.delivery;
}

async function sendBy(channel: AccessChannel): Promise<void> {
  if (!issued.value) return;
  const label = `${t(channel === 'sms' ? 'pa.code.sendSms' : 'pa.code.sendEmail')} · ${props.name}`;
  const res = await action.run<{ delivery: AccessDelivery }>(actions.sendAccessCode(props.tenant, props.userId, channel, issued.value.code), label, false);
  if (res) delivery.value = res.delivery;
}

async function copy(text: string): Promise<void> {
  copyStatus.value = (await copyText(text)) ? t('pa.code.copied') : t('pa.code.copyFailed');
}
</script>

<template>
  <section class="card access" :aria-label="t('pa.code.title', { name })">
    <div class="row row--between">
      <h3>{{ t('pa.code.title', { name }) }}</h3>
      <button type="button" class="btn btn--quiet" @click="$emit('close')">{{ t('pa.code.close') }}</button>
    </div>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <form v-if="!issued" class="form" novalidate @submit.prevent="generate">
      <p class="muted">{{ t('pa.code.hint') }}</p>
      <fieldset>
        <legend>{{ t('pa.code.channel') }}</legend>
        <label class="choice"><input v-model="send" type="radio" value="none" /> {{ t('pa.code.send.none') }}</label>
        <label class="choice"><input v-model="send" type="radio" value="email" :disabled="!channels.includes('email')" /> {{ t('pa.code.send.email') }}</label>
        <label class="choice"><input v-model="send" type="radio" value="sms" :disabled="!channels.includes('sms')" /> {{ t('pa.code.send.sms') }}</label>
      </fieldset>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('pa.code.generate') }}</button>
    </form>

    <template v-else>
      <p class="muted">{{ t(issued.purpose === 'reset' ? 'pa.code.purpose.reset' : 'pa.code.purpose.activation') }}</p>
      <div class="row code-row">
        <output class="code" data-test="access-code" :aria-label="t('pa.code.value')">{{ issued.code }}</output>
        <button type="button" class="btn" data-test="copy-code" @click="copy(issued.code)">{{ t('pa.code.copy') }}</button>
      </div>
      <p class="hint">{{ expiry }}</p>
      <p v-if="deliveryText" class="notice" :class="delivery?.status === 'sent' ? 'notice--success' : 'notice--warning'" role="status" data-test="delivery">{{ deliveryText }}</p>
      <p v-if="copyStatus" class="notice" role="status">{{ copyStatus }}</p>

      <h4>{{ t('pa.code.summary') }}</h4>
      <dl class="summary">
        <dt>{{ t('pa.code.establishment') }}</dt>
        <dd>{{ issued.share.establishment_name }}</dd>
        <dt>{{ t('pa.login') }}</dt>
        <dd>{{ issued.share.login }}</dd>
        <dt>{{ t('pa.code.activationUrl') }}</dt>
        <dd>{{ issued.share.activation_url }}</dd>
        <dt>{{ t('pa.code.loginUrl') }}</dt>
        <dd>{{ issued.share.login_url }}</dd>
      </dl>
      <label class="field">
        <span>{{ t('pa.code.message') }}</span>
        <textarea readonly rows="9" :value="issued.share.message.text" data-test="share-message"></textarea>
      </label>
      <div class="row actions">
        <button type="button" class="btn" data-test="copy-message" @click="copy(issued.share.message.text)">{{ t('pa.code.copyMessage') }}</button>
        <button v-if="issued.share.channels.includes('email')" type="button" class="btn" data-test="send-email" :disabled="action.busy.value" @click="sendBy('email')">{{ t('pa.code.sendEmail') }}</button>
        <button v-if="issued.share.channels.includes('sms')" type="button" class="btn" data-test="send-sms" :disabled="action.busy.value" @click="sendBy('sms')">{{ t('pa.code.sendSms') }}</button>
        <button type="button" class="btn btn--quiet" :disabled="action.busy.value" @click="issued = null">{{ t('pa.code.regenerate') }}</button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.access {
  margin: 12px 0;
}
.choice {
  display: block;
  margin: 4px 0;
}
.code-row {
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}
.code {
  font-size: 36px;
  font-weight: 700;
  letter-spacing: 0.25em;
  font-variant-numeric: tabular-nums;
  user-select: all;
}
.summary {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 12px;
}
.summary dd {
  margin: 0;
  overflow-wrap: anywhere;
}
textarea {
  width: 100%;
}
.actions {
  gap: 8px;
  flex-wrap: wrap;
}
</style>
