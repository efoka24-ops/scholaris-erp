<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { accessChannels, actions, fetchAdministrators, normalizePhone, type Administrator } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import AccessCodePanel from './AccessCodePanel.vue';

// Administrateurs d'un client (tenant) ou de la plateforme (tenant null) : lister, inviter, renvoyer l'invitation,
// bloquer, débloquer, réinitialiser l'accès. Chaque action exige une double authentification récente.
const props = defineProps<{ tenant: string | null; readonly?: boolean }>();
const { t, locale } = useI18n();
const items = ref<Administrator[]>([]);
const loadError = ref<unknown>(null);
const loaded = ref(false);
const done = ref<string | null>(null);
const inviting = ref(false);
const form = reactive({ first_name: '', last_name: '', email: '', phone: '' });
const errors = ref<Record<string, string>>({});
const action = useSensitiveAction();
/** Compte dont le panneau « Code d'accès » est ouvert. */
const codeFor = ref<Administrator | null>(null);

type Kind = 'resend-invitation' | 'block' | 'unblock' | 'reset-access';
const statusKey = (s: string): MessageKey => `pa.adm.status.${s}` as MessageKey;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

async function load(): Promise<void> {
  loadError.value = null;
  try {
    items.value = await fetchAdministrators(props.tenant);
  } catch (e) {
    loadError.value = e;
  } finally {
    loaded.value = true;
  }
}

function kinds(admin: Administrator): Kind[] {
  if (props.readonly) return [];
  if (admin.status === 'locked') return ['unblock', 'reset-access'];
  if (admin.status === 'pending') return ['resend-invitation', 'block'];
  return ['block', 'reset-access'];
}

const kindLabel = (k: Kind): string =>
  t(k === 'resend-invitation' ? 'pa.adm.resend' : k === 'block' ? 'pa.adm.block' : k === 'unblock' ? 'pa.adm.unblock' : 'pa.adm.reset');

async function act(admin: Administrator, kind: Kind): Promise<void> {
  done.value = null;
  if ((kind === 'block' || kind === 'reset-access') && !window.confirm(t(kind === 'block' ? 'pa.adm.confirmBlock' : 'pa.adm.confirmReset', { name: `${admin.first_name} ${admin.last_name}` }))) return;
  const res = await action.run<Administrator>(actions.administrator(props.tenant, admin.id, kind), `${kindLabel(kind)} · ${admin.first_name} ${admin.last_name}`);
  if (!res) return;
  done.value = t('pa.done');
  await load();
}

async function invite(): Promise<void> {
  const e: Record<string, string> = {};
  if (form.first_name.trim() === '') e.first_name = t('pa.required');
  if (form.last_name.trim() === '') e.last_name = t('pa.required');
  const email = form.email.trim();
  const phone = form.phone.trim() === '' ? null : normalizePhone(form.phone);
  if (email !== '' && !EMAIL.test(email)) e.email = t('pa.emailInvalid');
  if (form.phone.trim() !== '' && phone === null) e.phone = t('pa.phoneInvalid');
  if (email === '' && form.phone.trim() === '') e.email = t('pa.adm.contactRequired');
  errors.value = e;
  if (Object.keys(e).length > 0) return;
  const res = await action.run<Administrator>(
    actions.inviteAdministrator(props.tenant, { first_name: form.first_name.trim(), last_name: form.last_name.trim(), ...(email ? { email } : {}), ...(phone ? { phone } : {}) }),
    t('pa.adm.invite'),
  );
  if (!res) return;
  done.value = res.invitation === 'sent' ? t('pa.adm.invited') : t('pa.adm.invitedNoMail');
  inviting.value = false;
  Object.assign(form, { first_name: '', last_name: '', email: '', phone: '' });
  await load();
}

onMounted(load);
</script>

<template>
  <section class="block" aria-labelledby="adm-title">
    <div class="row row--between">
      <h2 id="adm-title">{{ tenant ? t('pa.adm.clientTitle') : t('pa.adm.platformTitle') }}</h2>
      <button v-if="!readonly" type="button" class="btn" :aria-expanded="inviting" aria-controls="adm-invite" @click="inviting = !inviting">{{ t('pa.adm.invite') }}</button>
    </div>
    <p class="muted">{{ tenant ? t('pa.adm.clientHint') : t('pa.adm.platformHint') }}</p>
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <ErrorNotice :error="loadError" />

    <form v-if="inviting" id="adm-invite" class="card form" novalidate @submit.prevent="invite">
      <div class="grid2">
        <div class="field">
          <label for="adm-first">{{ t('pa.firstName') }}</label>
          <input id="adm-first" v-model="form.first_name" type="text" maxlength="80" autocomplete="off" :aria-invalid="!!errors.first_name" />
          <span v-if="errors.first_name" class="error-line">{{ errors.first_name }}</span>
        </div>
        <div class="field">
          <label for="adm-last">{{ t('pa.lastName') }}</label>
          <input id="adm-last" v-model="form.last_name" type="text" maxlength="80" autocomplete="off" :aria-invalid="!!errors.last_name" />
          <span v-if="errors.last_name" class="error-line">{{ errors.last_name }}</span>
        </div>
        <div class="field">
          <label for="adm-email">{{ t('pa.email') }}</label>
          <input id="adm-email" v-model="form.email" type="email" maxlength="190" autocomplete="off" :aria-invalid="!!errors.email" aria-describedby="adm-email-hint" />
          <span id="adm-email-hint" class="hint">{{ t('pa.adm.emailHint') }}</span>
          <span v-if="errors.email" class="error-line">{{ errors.email }}</span>
        </div>
        <div class="field">
          <label for="adm-phone">{{ t('pa.phone') }}</label>
          <input id="adm-phone" v-model="form.phone" type="tel" maxlength="25" autocomplete="off" :aria-invalid="!!errors.phone" />
          <span v-if="errors.phone" class="error-line">{{ errors.phone }}</span>
        </div>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('pa.adm.sendInvite') }}</button>
    </form>

    <AccessCodePanel
      v-if="codeFor"
      :key="codeFor.id"
      :tenant="tenant"
      :user-id="codeFor.id"
      :name="`${codeFor.first_name} ${codeFor.last_name}`"
      :channels="accessChannels(codeFor.email, codeFor.phone)"
      @close="codeFor = null"
    />
    <p v-if="loaded && !loadError && items.length === 0" class="card muted">{{ t('pa.adm.empty') }}</p>
    <div v-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ tenant ? t('pa.adm.clientTitle') : t('pa.adm.platformTitle') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('pa.name') }}</th>
            <th scope="col">{{ t('pa.login') }}</th>
            <th scope="col">{{ t('plat.col.status') }}</th>
            <th scope="col">{{ t('pa.adm.twoFactor') }}</th>
            <th scope="col">{{ t('pa.adm.lastLogin') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in items" :key="a.id">
            <th scope="row">{{ a.first_name }} {{ a.last_name }}</th>
            <td>{{ a.email ?? a.phone }}</td>
            <td><span class="tag" :class="a.status === 'active' ? 'tag--success' : a.status === 'locked' ? 'tag--warning' : ''">{{ t(statusKey(a.status)) }}</span></td>
            <td>{{ a.two_factor_enabled ? t('pa.yes') : t('pa.no') }}</td>
            <td>{{ a.last_login_at ? formatDateTime(a.last_login_at, locale) : '—' }}</td>
            <td>
              <div class="row actions">
                <button v-if="!readonly && a.status !== 'locked'" type="button" class="btn btn--quiet" data-test="access-code-action" @click="codeFor = a">{{ t('pa.code.action') }}</button>
                <button v-for="k in kinds(a)" :key="k" type="button" class="btn btn--quiet" :disabled="action.busy.value" @click="act(a, k)">{{ kindLabel(k) }}</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.block {
  margin-top: 28px;
}
.form {
  margin-bottom: 16px;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.actions {
  gap: 4px;
  flex-wrap: wrap;
}
.error-line {
  display: block;
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
