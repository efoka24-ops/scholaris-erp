<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { accessChannels, actions, fetchAccessRequest, fetchClients, type AccessChannel, type AccessRequest, type Tenant } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';
import { requestStatusKey, requestTone, targetKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';
import RequestArchivePanel from './RequestArchivePanel.vue';
import AccessCodePanel from './AccessCodePanel.vue';

// Détail d'une demande d'accès et décision : accepter (client existant facultatif), refuser avec motif, demander un complément.
// Design : Inscription.html (sections), Fondations.html (champs, boutons). Accepter et refuser exigent une 2FA récente.
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();

const request = ref<AccessRequest | null>(null);
const loadError = ref<unknown>(null);
const mode = ref<'accept' | 'reject' | 'info' | null>(null);
const clients = ref<Tenant[]>([]);
const tenantId = ref('');
const reason = ref('');
const message = ref('');
const fieldError = ref<string | null>(null);
const success = ref<string | null>(null);
const acceptResult = ref<AccessRequest | null>(null);
const action = useSensitiveAction();
/** Partage de l'accès de l'administrateur invité : envoi automatique (coché si un contact existe) et canal. */
const autoSend = ref(true);
const sendChannel = ref<AccessChannel>('email');
const requestChannels = computed<AccessChannel[]>(() => (request.value ? accessChannels(request.value.contact.email, request.value.contact.phone) : []));

const open = computed(() => !request.value?.archived_at && (request.value?.status === 'pending' || request.value?.status === 'info_requested'));

async function load(): Promise<void> {
  loadError.value = null;
  try {
    request.value = await fetchAccessRequest(props.id);
  } catch (e) {
    loadError.value = e;
  }
}

async function choose(next: 'accept' | 'reject' | 'info'): Promise<void> {
  mode.value = mode.value === next ? null : next;
  fieldError.value = null;
  success.value = null;
  action.dismiss();
  if (next === 'accept') {
    autoSend.value = requestChannels.value.length > 0;
    sendChannel.value = requestChannels.value[0] ?? 'email';
  }
  if (next === 'accept' && clients.value.length === 0) {
    try {
      clients.value = await fetchClients();
    } catch {
      clients.value = []; // la liste n'est qu'une aide : sans elle, on crée un nouveau client
    }
  }
}

async function accept(): Promise<void> {
  if (!request.value) return;
  const res = await action.run<AccessRequest>(actions.acceptRequest(request.value.id, tenantId.value || null, autoSend.value && !tenantId.value ? sendChannel.value : 'none'), t('plat.act.accept', { ref: request.value.reference }));
  if (!res) return;
  acceptResult.value = res;
  request.value = { ...request.value, ...res };
  success.value = t('plat.req.accepted');
  mode.value = null;
}

async function reject(): Promise<void> {
  if (!request.value) return;
  const text = reason.value.trim();
  fieldError.value = text === '' || text.length > 1000 ? t('plat.req.reasonRequired') : null;
  if (fieldError.value) return;
  const res = await action.run<AccessRequest>(actions.rejectRequest(request.value.id, text), t('plat.act.reject', { ref: request.value.reference }));
  if (!res) return;
  request.value = { ...request.value, ...res };
  success.value = t('plat.req.rejected');
  mode.value = null;
  reason.value = '';
}

async function askInfo(): Promise<void> {
  if (!request.value) return;
  const text = message.value.trim();
  fieldError.value = text === '' || text.length > 1000 ? t('plat.req.messageRequired') : null;
  if (fieldError.value) return;
  const res = await action.run<AccessRequest>(actions.requestInfo(request.value.id, text), t('plat.act.info', { ref: request.value.reference }));
  if (!res) return;
  request.value = { ...request.value, ...res };
  success.value = t('plat.req.infoSent');
  mode.value = null;
  message.value = '';
}

function onArchived(next: AccessRequest): void {
  if (request.value) request.value = { ...request.value, ...next };
}

onMounted(load);
</script>

<template>
  <div class="page">
    <router-link class="back" :to="{ name: 'platform-requests' }">← {{ t('plat.req.title') }}</router-link>
    <ErrorNotice :error="loadError" />
    <ReplayNotice @done="load" />

    <template v-if="request">
      <header class="page-head row row--between">
        <div>
          <p class="eyebrow">{{ t('plat.req.detailTitle', { ref: request.reference }) }}</p>
          <h1>{{ request.institution_name }}</h1>
          <p class="lede">{{ request.app_label }} · {{ t(targetKey(request.target_type)) }}</p>
        </div>
        <span class="tag big" :class="requestTone(request.status) ? `tag--${requestTone(request.status)}` : ''">{{ t(requestStatusKey(request.status)) }}</span>
      </header>

      <div v-if="success" class="notice notice--success" role="status">
        <p><strong>{{ success }}</strong></p>
        <template v-if="acceptResult">
          <p v-if="acceptResult.entitlement">{{ t('plat.req.acceptedTrial', { date: formatDate(acceptResult.entitlement.trial_ends_on, locale) }) }}</p>
          <p v-if="acceptResult.administrator">{{ t('plat.req.acceptedAdmin', { login: acceptResult.administrator.login }) }}</p>
          <AccessCodePanel
            v-if="acceptResult.access && acceptResult.tenant_id && acceptResult.administrator?.user_id"
            :tenant="acceptResult.tenant_id"
            :user-id="acceptResult.administrator.user_id"
            :name="`${request.contact.first_name} ${request.contact.last_name}`"
            :channels="acceptResult.access.share.channels"
            :initial="acceptResult.access"
            @close="acceptResult = { ...acceptResult, access: null }"
          />
          <router-link v-if="acceptResult.tenant_id" class="btn" :to="{ name: 'platform-client', params: { tenant: acceptResult.tenant_id } }">{{ t('plat.req.seeClient') }} →</router-link>
        </template>
      </div>

      <RequestArchivePanel :request="request" @changed="onArchived" />

      <div class="layout">
        <section class="card" aria-labelledby="rq-facts">
          <h2 id="rq-facts" class="sr-only">{{ t('plat.req.detailTitle', { ref: request.reference }) }}</h2>
          <dl class="facts">
            <div><dt>{{ t('plat.req.institution') }}</dt><dd>{{ request.institution_name }}</dd></div>
            <div><dt>{{ t('plat.req.location') }}</dt><dd>{{ request.city }}, {{ request.region }} ({{ request.country }})</dd></div>
            <div>
              <dt>{{ t('plat.req.contact') }}</dt>
              <dd>
                {{ request.contact.first_name }} {{ request.contact.last_name }}<template v-if="request.contact.position"> · {{ request.contact.position }}</template><br />
                <a :href="`mailto:${request.contact.email}`">{{ request.contact.email }}</a>
                <template v-if="request.contact.phone"><br /><a :href="`tel:${request.contact.phone}`">{{ request.contact.phone }}</a></template>
              </dd>
            </div>
            <div><dt>{{ t('plat.req.authorization') }}</dt><dd>{{ request.ministerial_authorization || '—' }}</dd></div>
            <div><dt>{{ t('plat.req.app') }}</dt><dd>{{ request.app_label }} <code>{{ request.app_key }}</code></dd></div>
            <div><dt>{{ t('plat.req.locale') }}</dt><dd>{{ request.locale.toUpperCase() }}</dd></div>
            <div><dt>{{ t('plat.col.date') }}</dt><dd>{{ formatDateTime(request.created_at, locale) }}</dd></div>
          </dl>
          <div v-if="request.message" class="text-block">
            <h3>{{ t('plat.req.message') }}</h3>
            <p class="pre">{{ request.message }}</p>
          </div>
          <div v-if="request.info_request" class="text-block">
            <h3>{{ t('plat.req.infoRequest') }}</h3>
            <p class="pre">{{ request.info_request }}</p>
          </div>
          <div v-if="request.complement" class="text-block">
            <h3>{{ t('plat.req.complement') }}</h3>
            <p class="pre">{{ request.complement }}</p>
          </div>
          <div v-if="request.decision_reason" class="text-block">
            <h3>{{ t('plat.req.reason') }}</h3>
            <p class="pre">{{ request.decision_reason }}</p>
          </div>
          <p v-if="request.decided_at" class="muted">{{ t('plat.req.decidedAt', { date: formatDateTime(request.decided_at, locale) }) }}</p>
        </section>

        <section class="card decide" aria-labelledby="rq-decide">
          <h2 id="rq-decide">{{ t('plat.req.decide') }}</h2>
          <p v-if="!open" class="muted">{{ t('plat.req.closed') }}</p>
          <template v-else>
            <div class="row">
              <button type="button" class="btn btn--primary" :aria-expanded="mode === 'accept'" aria-controls="rq-panel" @click="choose('accept')">{{ t('plat.req.accept') }}</button>
              <button type="button" class="btn" :aria-expanded="mode === 'reject'" aria-controls="rq-panel" @click="choose('reject')">{{ t('plat.req.reject') }}</button>
              <button type="button" class="btn btn--quiet" :aria-expanded="mode === 'info'" aria-controls="rq-panel" @click="choose('info')">{{ t('plat.req.askInfo') }}</button>
            </div>

            <div id="rq-panel">
              <form v-if="mode === 'accept'" class="panel" novalidate @submit.prevent="accept">
                <h3>{{ t('plat.req.acceptTitle') }}</h3>
                <p>{{ t('plat.req.acceptText') }}</p>
                <div class="field">
                  <label for="rq-tenant">{{ t('plat.req.tenant') }}</label>
                  <select id="rq-tenant" v-model="tenantId" aria-describedby="rq-tenant-hint">
                    <option value="">{{ t('plat.req.newTenant') }}</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} ({{ c.id }})</option>
                  </select>
                  <span id="rq-tenant-hint" class="hint">{{ t('plat.req.tenantHint') }}</span>
                </div>
                <div v-if="requestChannels.length > 0 && !tenantId" class="field">
                  <label class="choice"><input v-model="autoSend" type="checkbox" data-test="auto-send" /> {{ t('pa.code.autoSend') }}</label>
                  <label v-if="autoSend" for="rq-send">{{ t('pa.code.autoSendChannel') }}</label>
                  <select v-if="autoSend" id="rq-send" v-model="sendChannel">
                    <option v-for="c in requestChannels" :key="c" :value="c">{{ t(c === 'sms' ? 'pa.code.sendSms' : 'pa.code.sendEmail') }}</option>
                  </select>
                </div>
                <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('plat.req.confirmAccept') }}</button>
              </form>

              <form v-if="mode === 'reject'" class="panel" novalidate @submit.prevent="reject">
                <h3>{{ t('plat.req.rejectTitle') }}</h3>
                <div class="field">
                  <label for="rq-reason">{{ t('plat.req.rejectReason') }}</label>
                  <textarea id="rq-reason" v-model="reason" rows="4" maxlength="1000" :aria-invalid="!!fieldError" :aria-describedby="fieldError ? 'rq-reason-hint rq-reason-err' : 'rq-reason-hint'" />
                  <span id="rq-reason-hint" class="hint">{{ t('plat.req.rejectHint') }}</span>
                  <span v-if="fieldError" id="rq-reason-err" class="error">{{ fieldError }}</span>
                </div>
                <button type="submit" class="btn btn--ink" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('plat.req.confirmReject') }}</button>
              </form>

              <form v-if="mode === 'info'" class="panel" novalidate @submit.prevent="askInfo">
                <h3>{{ t('plat.req.infoTitle') }}</h3>
                <div class="field">
                  <label for="rq-message">{{ t('plat.req.infoMessage') }}</label>
                  <textarea id="rq-message" v-model="message" rows="4" maxlength="1000" :aria-invalid="!!fieldError" :aria-describedby="fieldError ? 'rq-message-hint rq-message-err' : 'rq-message-hint'" />
                  <span id="rq-message-hint" class="hint">{{ t('plat.req.infoHint') }}</span>
                  <span v-if="fieldError" id="rq-message-err" class="error">{{ fieldError }}</span>
                </div>
                <button type="submit" class="btn btn--ink" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : t('plat.req.sendInfo') }}</button>
              </form>
            </div>
          </template>
          <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
          <ErrorNotice :error="action.error.value" />
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.back {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  font-weight: 700;
  margin-bottom: 8px;
}
.big {
  font-size: 14px;
  padding: 6px 12px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: 16px;
  align-items: start;
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px 24px;
  margin: 0;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.facts dd {
  margin: 2px 0 0;
  overflow-wrap: anywhere;
}
.text-block {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 12px;
}
.pre {
  white-space: pre-line;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
}
.decide .notice {
  margin-top: 16px;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
