<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchRegularisation, fetchRegularisationCsv, type Entitlement, type RegularisationBucket, type RegularisationItem, type RegularisationOverview, type Tenant } from '@/api/platform';
import { saveBlob } from '@/api/exports';
import { useI18n } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import RegularisationActions from './RegularisationActions.vue';
import ManualPaymentForm from './ManualPaymentForm.vue';
import ReplayNotice from './ReplayNotice.vue';

// Liste « À régulariser » : clients en retard, en régularisation de paiement et promesses de paiement en cours, avec les
// actions rapides (bloquer, débloquer, accorder un délai, saisir un paiement manuel) et l'export CSV.
const { t, locale } = useI18n();
const data = ref<RegularisationOverview | null>(null);
const loadError = ref<unknown>(null);
const loading = ref(true);
const bucket = ref<RegularisationBucket | ''>('');
const open = ref<{ key: string; kind: 'lock' | 'unlock' | 'delay' | 'payment' } | null>(null);
const info = ref<string | null>(null);
const exportError = ref<unknown>(null);

const BUCKETS: Array<RegularisationBucket | ''> = ['', 'past_due', 'regularisation', 'promise'];
const rowKey = (i: RegularisationItem): string => `${i.tenant_id}:${i.app_key}`;

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    data.value = await fetchRegularisation(bucket.value === '' ? null : bucket.value);
  } catch (e) {
    loadError.value = e;
  } finally {
    loading.value = false;
  }
}

function toggle(item: RegularisationItem, kind: 'lock' | 'unlock' | 'delay' | 'payment'): void {
  const key = rowKey(item);
  open.value = open.value?.key === key && open.value.kind === kind ? null : { key, kind };
  info.value = null;
}

async function done(): Promise<void> {
  open.value = null;
  info.value = t('rg.list.done');
  await load();
}

async function exportCsv(): Promise<void> {
  exportError.value = null;
  try {
    saveBlob(await fetchRegularisationCsv(bucket.value === '' ? null : bucket.value), 'a-regulariser.csv');
  } catch (e) {
    exportError.value = e;
  }
}

/** Client minimal pour le formulaire de paiement manuel de la ligne (client et application déjà choisis). */
function paymentClient(item: RegularisationItem): Tenant {
  const app = { app_key: item.app_key, label: item.label, state: item.state } as Entitlement;
  return { id: item.tenant_id, name: item.tenant_name ?? item.tenant_id, status: 'active', created_at: '', applications: [app] };
}

const canUnlock = (i: RegularisationItem): boolean => i.bucket === 'regularisation' || (i.bucket === 'promise' && i.state === 'suspended');
const canLock = (i: RegularisationItem): boolean => i.bucket !== 'regularisation';
const total = computed(() => data.value?.total ?? 0);

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
        <h1>{{ t('rg.list.title') }}</h1>
        <p class="lede">{{ t('rg.list.lede') }}</p>
      </div>
      <button type="button" class="btn" :disabled="total === 0" @click="exportCsv">{{ t('rg.list.export') }}</button>
    </header>

    <ErrorNotice :error="loadError" />
    <ErrorNotice :error="exportError" />
    <ReplayNotice @done="load" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>

    <div v-if="data" class="kpis" role="group" :aria-label="t('rg.list.summary')">
      <p class="kpi"><span class="kpi__v">{{ data.counts.past_due }}</span> {{ t('rg.bucket.past_due') }}</p>
      <p class="kpi"><span class="kpi__v">{{ data.counts.regularisation }}</span> {{ t('rg.bucket.regularisation') }}</p>
      <p class="kpi"><span class="kpi__v">{{ data.counts.promise }}</span> {{ t('rg.bucket.promise') }}</p>
      <p class="kpi"><span class="kpi__v">{{ formatXaf(data.amountDueXaf, locale) }}</span> {{ t('rg.list.amountDue') }}</p>
    </div>

    <fieldset class="filters">
      <legend>{{ t('rg.list.filter') }}</legend>
      <label v-for="b in BUCKETS" :key="b || 'all'" class="chip">
        <input v-model="bucket" type="radio" name="rg-bucket" :value="b" @change="load" />
        <span>{{ b === '' ? t('rg.bucket.all') : t(`rg.bucket.${b}`) }}</span>
      </label>
    </fieldset>

    <p v-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="!loadError && total === 0" class="card muted">{{ t('rg.list.empty') }}</p>

    <ul v-if="data && total > 0" class="rows">
      <li v-for="item in data.items" :key="rowKey(item)" class="card">
        <div class="row row--between">
          <div>
            <h2 class="name">
              <router-link :to="{ name: 'platform-client', params: { tenant: item.tenant_id } }">{{ item.tenant_name ?? item.tenant_id }}</router-link>
            </h2>
            <p class="muted small">{{ item.label }}</p>
          </div>
          <span class="tag" :class="item.bucket === 'promise' ? 'tag--accent' : 'tag--warning'">
            {{ t(`rg.bucket.${item.bucket}`) }}<template v-if="item.level"> · {{ t(`rg.level.${item.level}`) }}</template>
          </span>
        </div>
        <dl class="facts">
          <div><dt>{{ t('rg.col.dueOn') }}</dt><dd>{{ formatDate(item.due_on, locale) }}</dd></div>
          <div><dt>{{ t('rg.col.late') }}</dt><dd>{{ t('rg.days', { count: item.days_late }) }}</dd></div>
          <div><dt>{{ t('rg.col.amount') }}</dt><dd>{{ formatXaf(item.amount_due_xaf, locale) }}</dd></div>
          <div v-if="item.promise_until"><dt>{{ t('rg.col.promise') }}</dt><dd>{{ formatDate(item.promise_until, locale) }}</dd></div>
        </dl>
        <div class="row" role="group" :aria-label="t('rg.list.actionsFor', { client: item.tenant_name ?? item.tenant_id, app: item.label })">
          <button v-if="canLock(item)" type="button" class="btn" :aria-expanded="open?.key === rowKey(item) && open.kind === 'lock'" @click="toggle(item, 'lock')">{{ t('rg.reg.lock') }}</button>
          <button v-if="canUnlock(item)" type="button" class="btn" :aria-expanded="open?.key === rowKey(item) && open.kind === 'unlock'" @click="toggle(item, 'unlock')">{{ t('rg.reg.unlock') }}</button>
          <button type="button" class="btn" :aria-expanded="open?.key === rowKey(item) && open.kind === 'delay'" @click="toggle(item, 'delay')">{{ t('rg.reg.delay') }}</button>
          <button type="button" class="btn" :aria-expanded="open?.key === rowKey(item) && open.kind === 'payment'" @click="toggle(item, 'payment')">{{ t('rg.list.payment') }}</button>
        </div>
        <template v-if="open?.key === rowKey(item)">
          <ManualPaymentForm v-if="open.kind === 'payment'" :clients="[paymentClient(item)]" :preset="{ tenant_id: item.tenant_id, app_key: item.app_key }" @recorded="done" />
          <RegularisationActions
            v-else
            :tenant="item.tenant_id"
            :client-name="item.tenant_name ?? item.tenant_id"
            :app-key="item.app_key"
            :app-label="item.label"
            :kind="open.kind"
            :id-prefix="`rgl-${item.tenant_id}-${item.app_key}`"
            @done="done"
            @cancel="open = null"
          />
        </template>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin: 16px 0;
}
.kpi {
  margin: 0;
  padding: 12px;
  border: 1px solid var(--border);
}
.kpi__v {
  display: block;
  font-size: 22px;
  font-weight: 700;
}
.filters {
  border: 0;
  padding: 0;
  margin: 0 0 16px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.filters legend {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
  margin-bottom: 4px;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: var(--tap);
  padding: 0 12px;
  border: 1px solid var(--border);
}
.rows {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.name {
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
</style>
