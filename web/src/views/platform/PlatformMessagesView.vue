<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchClients, fetchClientUsers, fetchPlatformCatalog, type CatalogApplication, type ClientUser, type Tenant } from '@/api/platform';
import {
  fetchClientDeliveries, fetchDirectMessages, fetchInternalStats, messageActions, previewDirectMessage,
  type ClientDelivery, type DirectChannel, type DirectMessageInput, type DirectPreview, type DirectRow, type DirectTarget, type InternalStats,
} from '@/api/platformBackoffice';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDateTime, formatXaf } from '@/lib/format';
import { platformCan } from './platformAccess';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';

// Suivi des messages et envoi direct (C-112 point 4) : journaux des SMS et e-mails (destinataire masqué, statut TeeeOTP,
// motif d'échec, relance), envois directs de la plateforme, statistiques des messages internes (comptes seulement), envoi
// direct avec aperçu (destinataires, coût SMS estimé) ; envoi en masse confirmé par double authentification récente.
type Tab = 'sms' | 'email' | 'direct' | 'internal' | 'send';
const { t, locale } = useI18n();
const tab = ref<Tab>('sms');
const tabs = computed<Tab[]>(() => ['sms', 'email', 'direct', 'internal', ...(platformCan('platform.messages.send', 'platform.messages.broadcast') ? (['send'] as Tab[]) : [])]);
const deliveries = ref<Array<ClientDelivery & { tenant_name: string }>>([]);
const direct = ref<DirectRow[]>([]);
const internal = ref<InternalStats[]>([]);
const next = ref<string | null>(null);
const error = ref<unknown>(null);
const done = ref<string | null>(null);
const action = useSensitiveAction();
const canRetry = platformCan('platform.messages.retry');
const canBroadcast = platformCan('platform.messages.broadcast');

const clients = ref<Tenant[]>([]);
const users = ref<ClientUser[]>([]);
const apps = ref<CatalogApplication[]>([]);
const form = reactive<{ target_type: DirectTarget; tenant_id: string; user_id: string; app_key: string; channel: DirectChannel; subject: string; body: string }>({
  target_type: 'person', tenant_id: '', user_id: '', app_key: '', channel: 'sms', subject: '', body: '',
});
const preview = ref<DirectPreview | null>(null);
const statusKey = (s: string): string => (['pending', 'queued', 'sent', 'delivered', 'failed', 'blocked'].includes(s) ? t(`pm.status.${s}` as MessageKey) : s);

async function load(more = false): Promise<void> {
  error.value = null;
  try {
    if (tab.value === 'sms' || tab.value === 'email') {
      const page = await fetchClientDeliveries({ channel: tab.value }, more ? next.value : null);
      deliveries.value = more ? [...deliveries.value, ...page.items] : page.items;
      next.value = page.nextCursor;
    } else if (tab.value === 'direct') {
      const page = await fetchDirectMessages({}, more ? next.value : null);
      direct.value = more ? [...direct.value, ...page.items] : page.items;
      next.value = page.nextCursor;
    } else if (tab.value === 'internal') {
      internal.value = await fetchInternalStats();
      next.value = null;
    } else {
      next.value = null;
      if (clients.value.length === 0) clients.value = await fetchClients();
      if (apps.value.length === 0) apps.value = await fetchPlatformCatalog().catch(() => []);
    }
  } catch (e) {
    error.value = e;
  }
}

watch(tab, () => load());
watch(() => form.tenant_id, async (tenant) => {
  users.value = [];
  form.user_id = '';
  if (tenant && form.target_type === 'person') users.value = await fetchClientUsers(tenant).catch(() => []);
});

function input(): DirectMessageInput {
  return {
    target_type: form.target_type,
    ...(form.target_type !== 'app_clients' && form.tenant_id ? { tenant_id: form.tenant_id } : {}),
    ...(form.target_type === 'person' && form.user_id ? { user_id: form.user_id } : {}),
    ...(form.target_type === 'app_clients' && form.app_key ? { app_key: form.app_key } : {}),
    channel: form.channel,
    subject: form.subject.trim(),
    body: form.body.trim(),
  };
}

async function showPreview(): Promise<void> {
  error.value = null;
  done.value = null;
  try {
    preview.value = await previewDirectMessage(input());
  } catch (e) {
    error.value = e;
  }
}

async function send(): Promise<void> {
  if (!preview.value) return;
  if (preview.value.bulk && !window.confirm(t('pm.confirmBulk', { count: preview.value.recipients }))) return;
  const res = await action.run<{ recipients: number; sent: number; failed: number }>(messageActions.send(input()), t('pm.send'));
  if (!res) return;
  done.value = t('pm.sent', { sent: res.sent, failed: res.failed });
  preview.value = null;
}

async function retry(kind: 'delivery' | 'direct', tenant: string, id: string): Promise<void> {
  const res = await action.run(kind === 'delivery' ? messageActions.retryDelivery(tenant, id) : messageActions.retryDirect(id), t('pm.retry'));
  if (res === undefined) return;
  done.value = t('pa.done');
  await load();
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('pm.title') }}</h1>
      <p class="lede">{{ t('pm.lede') }}</p>
    </header>
    <ReplayNotice @done="load()" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <div class="row tabs" role="tablist">
      <button v-for="k in tabs" :key="k" type="button" role="tab" class="btn" :class="{ 'btn--primary': tab === k }" :aria-selected="tab === k" :data-test="`tab-${k}`" @click="tab = k">{{ t(`pm.tab.${k}` as MessageKey) }}</button>
    </div>

    <div v-if="tab === 'sms' || tab === 'email'" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t(`pm.tab.${tab}` as MessageKey) }}</caption>
        <thead><tr><th scope="col">{{ t('pk.date') }}</th><th scope="col">{{ t('plat.col.client') }}</th><th scope="col">{{ t('pm.col.recipient') }}</th><th scope="col">{{ t('pm.col.template') }}</th><th scope="col">{{ t('plat.col.status') }}</th><th scope="col">{{ t('pm.col.reason') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
        <tbody>
          <tr v-for="d in deliveries" :key="d.id">
            <td>{{ formatDateTime(d.created_at, locale) }}</td>
            <td>{{ d.tenant_name }}</td>
            <td>{{ d.recipient }}</td>
            <td>{{ d.template_key ?? '—' }}</td>
            <td>{{ statusKey(d.status) }}<span v-if="d.provider_reference" class="muted small"> · {{ d.provider_reference }}</span></td>
            <td>{{ d.reason ?? '' }}</td>
            <td><button v-if="canRetry && d.status === 'failed'" type="button" class="btn btn--quiet" :data-test="`retry-${d.id}`" @click="retry('delivery', d.tenant_id, d.id)">{{ t('pm.retry') }}</button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="deliveries.length === 0" class="card muted">{{ t('pm.empty') }}</p>
    </div>

    <div v-if="tab === 'direct'" class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('pk.date') }}</th><th scope="col">{{ t('plat.col.client') }}</th><th scope="col">{{ t('pm.col.recipient') }}</th><th scope="col">{{ t('pm.col.channel') }}</th><th scope="col">{{ t('pm.subject') }}</th><th scope="col">{{ t('plat.col.status') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
        <tbody>
          <tr v-for="d in direct" :key="d.id">
            <td>{{ formatDateTime(d.created_at, locale) }}</td>
            <td>{{ d.tenant_name }}</td>
            <td>{{ d.recipient_masked }}</td>
            <td>{{ t(`pm.channel.${d.channel}` as MessageKey) }}</td>
            <td>{{ d.subject }}</td>
            <td>{{ statusKey(d.status) }}<span v-if="d.reason" class="muted small"> · {{ d.reason }}</span></td>
            <td><button v-if="canRetry && d.status !== 'sent' && d.channel !== 'app'" type="button" class="btn btn--quiet" @click="retry('direct', d.tenant_id, d.id)">{{ t('pm.retry') }}</button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="direct.length === 0" class="card muted">{{ t('pm.empty') }}</p>
    </div>

    <div v-if="tab === 'internal'" class="table-wrap">
      <p class="muted">{{ t('pm.internalHint') }}</p>
      <table data-test="internal-stats">
        <thead><tr><th scope="col">{{ t('plat.col.client') }}</th><th scope="col" class="num">{{ t('pm.conversations') }}</th><th scope="col" class="num">{{ t('pm.messages') }}</th><th scope="col" class="num">{{ t('pm.hidden') }}</th></tr></thead>
        <tbody><tr v-for="s in internal" :key="s.tenant_id"><th scope="row">{{ s.tenant_name }}</th><td class="num">{{ s.conversations }}</td><td class="num">{{ s.messages }}</td><td class="num">{{ s.hidden }}</td></tr></tbody>
      </table>
    </div>

    <button v-if="next && tab !== 'internal' && tab !== 'send'" type="button" class="btn" @click="load(true)">{{ t('pact.more') }}</button>

    <form v-if="tab === 'send'" class="card form" novalidate @submit.prevent="showPreview">
      <fieldset>
        <legend>{{ t('pm.target') }}</legend>
        <label class="radio"><input v-model="form.target_type" type="radio" value="person" /> {{ t('pm.target.person') }}</label>
        <label v-if="canBroadcast" class="radio"><input v-model="form.target_type" type="radio" value="client_admins" /> {{ t('pm.target.client_admins') }}</label>
        <label v-if="canBroadcast" class="radio"><input v-model="form.target_type" type="radio" value="app_clients" /> {{ t('pm.target.app_clients') }}</label>
      </fieldset>
      <div v-if="form.target_type !== 'app_clients'" class="field">
        <label for="dm-tenant">{{ t('plat.col.client') }}</label>
        <select id="dm-tenant" v-model="form.tenant_id">
          <option value="">{{ t('pa.choose') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div v-if="form.target_type === 'person'" class="field">
        <label for="dm-user">{{ t('pm.person') }}</label>
        <select id="dm-user" v-model="form.user_id">
          <option value="">{{ t('pa.choose') }}</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.first_name }} {{ u.last_name }}</option>
        </select>
      </div>
      <div v-if="form.target_type === 'app_clients'" class="field">
        <label for="dm-app">{{ t('plat.col.app') }}</label>
        <select id="dm-app" v-model="form.app_key">
          <option value="">{{ t('pa.choose') }}</option>
          <option v-for="a in apps" :key="a.key" :value="a.key">{{ a.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="dm-channel">{{ t('pm.col.channel') }}</label>
        <select id="dm-channel" v-model="form.channel">
          <option v-for="c in (['sms', 'email', 'app'] as const)" :key="c" :value="c">{{ t(`pm.channel.${c}` as MessageKey) }}</option>
        </select>
      </div>
      <div class="field">
        <label for="dm-subject">{{ t('pm.subject') }}</label>
        <input id="dm-subject" v-model="form.subject" type="text" maxlength="120" />
      </div>
      <div class="field">
        <label for="dm-body">{{ t('pm.body') }}</label>
        <textarea id="dm-body" v-model="form.body" rows="5" :maxlength="form.channel === 'sms' ? 400 : 4000" />
      </div>
      <button type="submit" class="btn" data-test="dm-preview">{{ t('pm.preview') }}</button>
    </form>

    <section v-if="tab === 'send' && preview" class="card preview" aria-live="polite" data-test="dm-preview-result">
      <h2>{{ t('pm.preview') }}</h2>
      <p>{{ t('pm.previewCount', { count: preview.recipients, reachable: preview.reachable }) }}</p>
      <p v-if="form.channel === 'sms'">{{ t('pm.previewCost', { segments: preview.sms_segments, cost: formatXaf(preview.estimated_cost_xaf, locale) }) }}</p>
      <p v-if="preview.recipients > preview.max_recipients" class="notice notice--warning">{{ t('pm.overCap', { max: preview.max_recipients }) }}</p>
      <ul class="sample"><li v-for="(s, i) in preview.sample" :key="i">{{ s.first_name }} · {{ s.address }}</li></ul>
      <iframe v-if="preview.email_html" class="mail" :title="t('pm.emailPreview')" sandbox="" :srcdoc="preview.email_html" />
      <p v-if="preview.bulk" class="muted small">{{ t('pm.bulkHint') }}</p>
      <button type="button" class="btn btn--primary" data-test="dm-send" :disabled="action.busy.value || preview.recipients === 0 || preview.recipients > preview.max_recipients" @click="send">{{ t('pm.send') }}</button>
    </section>
  </div>
</template>

<style scoped>
.tabs {
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.small {
  font-size: 13px;
}
.radio {
  display: block;
  margin: 4px 0;
}
.mail {
  width: 100%;
  min-height: 360px;
  border: 1px solid #e4e4de;
  margin: 12px 0;
}
.preview {
  margin-top: 16px;
}
</style>
