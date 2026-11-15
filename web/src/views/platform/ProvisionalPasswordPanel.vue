<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, type AccessChannel, type AccessDelivery, type AccessSend, type ProvisionalPassword } from '@/api/platform';
import { useI18n } from '@/i18n';
import { copyText } from './clipboard';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Mot de passe provisoire donné par la plateforme (mot de passe perdu) : case « changement exigé » (défaut : réglage de la
// plateforme), choix de l'envoi selon les coordonnées connues, confirmation, puis affichage UNIQUE du mot de passe et du message
// prêt à copier. Jamais rejoué après la double authentification : la personne relance l'action (le mot de passe doit s'afficher).
const props = defineProps<{ tenant: string | null; userId: string; name: string; channels: AccessChannel[]; requireChangeDefault: boolean }>();
defineEmits<{ close: [] }>();
const { t } = useI18n();
const action = useSensitiveAction();
const requireChange = ref(props.requireChangeDefault);
const send = ref<AccessSend>('none');
const confirming = ref(false);
const issued = ref<ProvisionalPassword | null>(null);
const copyStatus = ref<string | null>(null);

const deliveryText = computed(() => {
  const d: AccessDelivery | undefined = issued.value?.delivery;
  if (!d || d.channel === 'none' || d.status === 'not_sent') return null;
  const channel = t(d.channel === 'sms' ? 'pa.code.channel.sms' : 'pa.code.channel.email');
  if (d.status === 'sent') return t('pa.code.delivery.sent', { channel, to: d.masked_to ?? '' });
  return t(d.status === 'failed' ? 'pa.pwd.delivery.failed' : 'pa.code.delivery.unavailable', { channel });
});

async function give(): Promise<void> {
  copyStatus.value = null;
  const res = await action.run<ProvisionalPassword>(actions.provisionalPassword(props.tenant, props.userId, send.value, requireChange.value), `${t('pa.pwd.action')} · ${props.name}`, false);
  confirming.value = false;
  if (res) issued.value = res;
}

async function copy(text: string): Promise<void> {
  copyStatus.value = (await copyText(text)) ? t('pa.code.copied') : t('pa.code.copyFailed');
}
</script>

<template>
  <section class="card access" :aria-label="t('pa.pwd.title', { name })">
    <div class="row row--between">
      <h3>{{ t('pa.pwd.title', { name }) }}</h3>
      <button type="button" class="btn btn--quiet" @click="$emit('close')">{{ t('pa.code.close') }}</button>
    </div>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />

    <form v-if="!issued" class="form" novalidate @submit.prevent="confirming = true">
      <p class="muted">{{ t('pa.pwd.hint') }}</p>
      <label class="choice"><input v-model="requireChange" type="checkbox" data-test="pwd-require-change" /> {{ t('pa.pwd.requireChange') }}</label>
      <fieldset>
        <legend>{{ t('pa.code.channel') }}</legend>
        <label class="choice"><input v-model="send" type="radio" value="none" /> {{ t('pa.pwd.send.none') }}</label>
        <label class="choice"><input v-model="send" type="radio" value="email" data-test="pwd-send-email" :disabled="!channels.includes('email')" /> {{ t('pa.pwd.send.email') }}</label>
        <label class="choice"><input v-model="send" type="radio" value="sms" data-test="pwd-send-sms" :disabled="!channels.includes('sms')" /> {{ t('pa.pwd.send.sms') }}</label>
      </fieldset>
      <div v-if="confirming" class="notice notice--warning stack" role="alertdialog" :aria-label="t('pa.pwd.confirmTitle')">
        <p>{{ t('pa.pwd.confirm', { name }) }}</p>
        <div class="row actions">
          <button type="button" class="btn btn--primary" data-test="pwd-confirm" :disabled="action.busy.value" @click="give">{{ action.busy.value ? t('common.loading') : t('pa.pwd.confirmYes') }}</button>
          <button type="button" class="btn btn--quiet" @click="confirming = false">{{ t('pa.pwd.cancel') }}</button>
        </div>
      </div>
      <button v-else type="submit" class="btn btn--primary" data-test="pwd-give">{{ t('pa.pwd.give') }}</button>
    </form>

    <template v-else>
      <p class="notice notice--warning" role="status">{{ t('pa.pwd.once') }}</p>
      <div class="row code-row">
        <output class="code" data-test="provisional-password" :aria-label="t('pa.pwd.value')">{{ issued.password }}</output>
        <button type="button" class="btn" data-test="copy-password" @click="copy(issued.password)">{{ t('pa.code.copy') }}</button>
      </div>
      <p class="hint">{{ t(issued.must_change_password ? 'pa.pwd.mustChange' : 'pa.pwd.noChange') }}<span v-if="issued.two_factor_enabled"> {{ t('pa.pwd.twoFactorKept') }}</span></p>
      <p v-if="deliveryText" class="notice" :class="issued.delivery.status === 'sent' ? 'notice--success' : 'notice--warning'" role="status" data-test="pwd-delivery">{{ deliveryText }}</p>
      <p v-if="copyStatus" class="notice" role="status">{{ copyStatus }}</p>
      <label class="field">
        <span>{{ t('pa.code.message') }}</span>
        <textarea readonly rows="9" :value="issued.share.message.text" data-test="pwd-message"></textarea>
      </label>
      <div class="row actions">
        <button type="button" class="btn" data-test="pwd-copy-message" @click="copy(issued.share.message.text)">{{ t('pa.code.copyMessage') }}</button>
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
  font-size: 28px;
  font-weight: 700;
  letter-spacing: 0.12em;
  font-family: ui-monospace, monospace;
  user-select: all;
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
