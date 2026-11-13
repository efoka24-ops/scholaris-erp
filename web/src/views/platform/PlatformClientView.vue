<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, fetchClient, fetchPlatformCatalog, fetchServiceOptions, type CatalogApplication, type Entitlement, type ServiceOption, type Tenant } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import { sourceKey, stateKey, stateTone, targetKey } from './display';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import ReplayNotice from './ReplayNotice.vue';
import AdministratorsPanel from './AdministratorsPanel.vue';
import ClientEstablishmentsPanel from './ClientEstablishmentsPanel.vue';
import ClientBillingPanel from './ClientBillingPanel.vue';
import ClientProfilePanel from './ClientProfilePanel.vue';
import ClientUsersPanel from './ClientUsersPanel.vue';
import WorkflowsPanel from '@/views/admin/workflows/WorkflowsPanel.vue';

// Fiche d'un client : établissements (nom, type) et applications : ouvrir, suspendre, fermer, réactiver (motif), tarif spécifique.
// Design : Dashboard.html (tableau), Fondations.html (formulaires). Chaque action exige une 2FA récente.
const props = defineProps<{ tenant: string }>();
const { t, locale } = useI18n();

type Kind = 'suspend' | 'close' | 'reactivate' | 'price';
const client = ref<Tenant | null>(null);
const catalog = ref<CatalogApplication[]>([]);
const serviceOptions = ref<ServiceOption[]>([]);
const loadError = ref<unknown>(null);
const editing = ref<{ key: string; kind: Kind } | null>(null);
const reason = ref('');
const until = ref('');
const price = ref('');
const fieldError = ref<string | null>(null);
const done = ref<string | null>(null);
const openKey = ref('');
const action = useSensitiveAction();

const openable = computed(() => {
  const taken = new Set((client.value?.applications ?? []).filter((a) => a.state !== 'closed').map((a) => a.app_key));
  return catalog.value.filter((a) => a.status === 'available' && !taken.has(a.key));
});

async function load(): Promise<void> {
  loadError.value = null;
  try {
    client.value = await fetchClient(props.tenant);
  } catch (e) {
    loadError.value = e;
  }
  try {
    catalog.value = await fetchPlatformCatalog();
  } catch {
    catalog.value = []; // sans catalogue, seule l'ouverture d'une application est indisponible
  }
  try {
    serviceOptions.value = await fetchServiceOptions();
  } catch {
    serviceOptions.value = []; // sans options, seule leur souscription est indisponible
  }
}

function canDo(app: Entitlement, kind: Kind): boolean {
  if (kind === 'reactivate') return app.state === 'suspended';
  if (kind === 'suspend') return ['trial', 'active', 'past_due'].includes(app.state);
  return app.state !== 'closed';
}

function start(app: Entitlement, kind: Kind): void {
  const same = editing.value?.key === app.app_key && editing.value.kind === kind;
  editing.value = same ? null : { key: app.app_key, kind };
  reason.value = '';
  until.value = '';
  price.value = app.price_overridden ? String(app.monthly_price_xaf) : '';
  fieldError.value = null;
  done.value = null;
  action.dismiss();
}

async function submit(app: Entitlement): Promise<void> {
  if (!editing.value || !client.value) return;
  const kind = editing.value.kind;
  const names = { app: app.label, client: client.value.name };
  const text = reason.value.trim();
  fieldError.value = null;
  let res: Entitlement | undefined;
  if (kind === 'price') {
    const raw = price.value.trim().replace(/\s/g, '');
    const value = raw === '' ? null : /^\d+$/.test(raw) && Number(raw) <= 100_000_000 ? Number(raw) : NaN;
    if (Number.isNaN(value)) {
      fieldError.value = t('plat.cli.priceInvalid');
      return;
    }
    res = await action.run<Entitlement>(actions.setPrice(client.value.id, app.app_key, value), t('plat.act.price', names));
  } else if (kind === 'reactivate') {
    if (text.length > 500) {
      fieldError.value = t('plat.cli.reasonRequired');
      return;
    }
    res = await action.run<Entitlement>(actions.reactivate(client.value.id, app.app_key, text || null, until.value || null), t('plat.act.reactivate', names));
  } else {
    if (text === '' || text.length > 500) {
      fieldError.value = t('plat.cli.reasonRequired');
      return;
    }
    const build = kind === 'suspend' ? actions.suspend : actions.close;
    res = await action.run<Entitlement>(build(client.value.id, app.app_key, text), t(kind === 'suspend' ? 'plat.act.suspend' : 'plat.act.close', names));
  }
  if (!res) return;
  editing.value = null;
  done.value = t('plat.cli.done');
  await load();
}

async function openApp(): Promise<void> {
  if (!client.value || openKey.value === '') return;
  const app = catalog.value.find((a) => a.key === openKey.value);
  const res = await action.run<Entitlement>(actions.openForClient(client.value.id, openKey.value), t('plat.act.open', { app: app?.label ?? openKey.value, client: client.value.name }));
  if (!res) return;
  openKey.value = '';
  done.value = t('plat.cli.done');
  await load();
}

const kindLabel = (k: Kind): string => t(k === 'suspend' ? 'plat.cli.suspend' : k === 'close' ? 'plat.cli.close' : k === 'reactivate' ? 'plat.cli.reactivate' : 'plat.cli.price');
const kindText = (k: Kind): string => (k === 'suspend' ? t('plat.cli.suspendText') : k === 'close' ? t('plat.cli.closeText') : k === 'reactivate' ? t('plat.cli.reactivateText') : '');
const KINDS: Kind[] = ['suspend', 'close', 'reactivate', 'price'];

onMounted(load);
</script>

<template>
  <div class="page">
    <router-link class="back" :to="{ name: 'platform-clients' }">← {{ t('plat.cli.title') }}</router-link>
    <ErrorNotice :error="loadError" />
    <ReplayNotice @done="load" />

    <template v-if="client">
      <header class="page-head">
        <p class="eyebrow">{{ t('plat.col.client') }} · <code>{{ client.id }}</code></p>
        <h1>{{ client.name }}</h1>
        <p class="lede">{{ t('plat.cli.since', { date: formatDate(client.created_at, locale) }) }} · <span class="tag">{{ client.status }}</span></p>
      </header>

      <ClientProfilePanel :client="client" @changed="load" />

      <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
      <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
      <ErrorNotice :error="action.error.value" />

      <section class="block" aria-labelledby="cl-apps">
        <h2 id="cl-apps">{{ t('plat.cli.apps') }}</h2>
        <p v-if="client.applications.length === 0" class="card muted">{{ t('plat.cli.noApps') }}</p>
        <ul v-else class="apps">
          <li v-for="app in client.applications" :key="app.id" class="card">
            <div class="row row--between">
              <div>
                <h3 class="app__name">{{ app.label }}</h3>
                <p class="muted small">{{ t(targetKey(app.target)) }} · {{ t(sourceKey(app.source)) }}</p>
              </div>
              <span class="tag" :class="stateTone(app.state) ? `tag--${stateTone(app.state)}` : ''">{{ t(stateKey(app.state)) }}</span>
            </div>
            <dl class="facts">
              <div v-if="app.state === 'trial'"><dt>{{ t('plat.col.trialEnds') }}</dt><dd>{{ formatDate(app.trial_ends_on, locale) }} ({{ app.trial_days_left }})</dd></div>
              <div v-if="app.due_on"><dt>{{ t('plat.col.dueOn') }}</dt><dd>{{ formatDate(app.due_on, locale) }}</dd></div>
              <div>
                <dt>{{ t('plat.col.price') }}</dt>
                <dd>{{ formatXaf(app.monthly_price_xaf, locale) }}<span v-if="app.price_overridden" class="tag tag--accent over">{{ t('plat.cli.overridden') }}</span></dd>
              </div>
            </dl>
            <p v-if="app.state_reason" class="muted">{{ t('plat.cli.reasonShown', { reason: app.state_reason }) }}</p>

            <div class="row" role="group" :aria-label="t('plat.cli.actionsFor', { app: app.label })">
              <template v-for="k in KINDS" :key="k">
                <button
                  v-if="canDo(app, k)"
                  type="button"
                  class="btn"
                  :class="{ 'btn--quiet': k === 'price', 'btn--ink': editing?.key === app.app_key && editing?.kind === k }"
                  :aria-expanded="editing?.key === app.app_key && editing?.kind === k"
                  :aria-controls="`cl-form-${app.id}`"
                  @click="start(app, k)"
                >
                  {{ kindLabel(k) }}
                </button>
              </template>
            </div>

            <form v-if="editing?.key === app.app_key" :id="`cl-form-${app.id}`" class="panel" novalidate @submit.prevent="submit(app)">
              <p v-if="kindText(editing.kind)">{{ kindText(editing.kind) }}</p>
              <div v-if="editing.kind === 'price'" class="field">
                <label :for="`cl-price-${app.id}`">{{ t('plat.cli.priceLabel') }}</label>
                <input :id="`cl-price-${app.id}`" v-model="price" type="text" inputmode="numeric" autocomplete="off" :aria-invalid="!!fieldError" :aria-describedby="fieldError ? `cl-price-${app.id}-hint cl-err-${app.id}` : `cl-price-${app.id}-hint`" />
                <span :id="`cl-price-${app.id}-hint`" class="hint">{{ t('plat.cli.priceHint') }}</span>
              </div>
              <template v-else>
                <div class="field">
                  <label :for="`cl-reason-${app.id}`">{{ editing.kind === 'reactivate' ? t('plat.cli.reasonOptional') : t('plat.cli.reason') }}</label>
                  <textarea :id="`cl-reason-${app.id}`" v-model="reason" rows="3" maxlength="500" :aria-invalid="!!fieldError" :aria-describedby="fieldError ? `cl-reason-${app.id}-hint cl-err-${app.id}` : `cl-reason-${app.id}-hint`" />
                  <span :id="`cl-reason-${app.id}-hint`" class="hint">{{ t('plat.cli.reasonHint') }}</span>
                </div>
                <div v-if="editing.kind === 'reactivate'" class="field">
                  <label :for="`cl-until-${app.id}`">{{ t('plat.cli.until') }}</label>
                  <input :id="`cl-until-${app.id}`" v-model="until" type="date" :aria-describedby="`cl-until-${app.id}-hint`" />
                  <span :id="`cl-until-${app.id}-hint`" class="hint">{{ t('plat.cli.untilHint') }}</span>
                </div>
              </template>
              <p v-if="fieldError" :id="`cl-err-${app.id}`" class="error-line">{{ fieldError }}</p>
              <div class="row">
                <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ action.busy.value ? t('common.loading') : kindLabel(editing.kind) }}</button>
                <button type="button" class="btn btn--quiet" @click="editing = null">{{ t('plat.cancel') }}</button>
              </div>
            </form>

            <ClientBillingPanel v-if="client.state !== 'archived' && app.state !== 'closed'" :tenant="client.id" :client-name="client.name" :app="app" :service-options="serviceOptions" @changed="load" />
          </li>
        </ul>
      </section>

      <section class="card block" aria-labelledby="cl-open">
        <h2 id="cl-open">{{ t('plat.cli.openApp') }}</h2>
        <p class="muted">{{ t('plat.cli.openAppHint') }}</p>
        <p v-if="openable.length === 0" class="muted">{{ t('plat.cli.noAppToOpen') }}</p>
        <form v-else class="row open" novalidate @submit.prevent="openApp">
          <div class="field grow">
            <label for="cl-open-app">{{ t('plat.cli.chooseApp') }}</label>
            <select id="cl-open-app" v-model="openKey">
              <option value="" disabled>{{ t('pub.req.chooseApp') }}</option>
              <option v-for="a in openable" :key="a.key" :value="a.key">{{ a.label }} · {{ t(targetKey(a.target)) }}</option>
            </select>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="openKey === '' || action.busy.value">{{ t('apps.openApp') }}</button>
        </form>
      </section>

      <ClientEstablishmentsPanel :tenant="client.id" :establishments="client.establishments ?? []" :readonly="client.state === 'archived'" @changed="load" />
      <AdministratorsPanel :tenant="client.id" :readonly="client.state === 'archived'" />
      <ClientUsersPanel :tenant="client.id" :readonly="client.state === 'archived'" />
      <WorkflowsPanel :scope="{ kind: 'client', tenant: client.id }" :readonly="client.state === 'archived'" />
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
.block {
  margin-top: 28px;
}
.apps {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.app__name {
  margin: 0;
  font-size: 19px;
}
.small {
  font-size: 13px;
  margin: 2px 0 0;
}
.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 32px;
  margin: 14px 0;
}
.facts dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.facts dd {
  margin: 2px 0 0;
  font-weight: 700;
}
.over {
  margin-left: 8px;
}
.panel {
  border-top: 1px solid var(--border);
  margin-top: 16px;
  padding-top: 16px;
  max-width: 560px;
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
.open {
  align-items: flex-end;
}
.open .field {
  margin-bottom: 0;
}
.grow {
  flex: 1 1 260px;
}
</style>
