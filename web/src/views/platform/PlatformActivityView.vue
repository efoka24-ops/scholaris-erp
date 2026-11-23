<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { saveBlob } from '@/api/exports';
import { fetchClients, type Tenant } from '@/api/platform';
import { fetchActivity, fetchActivityCsv, type ActivityEntry, type ActivityFilters } from '@/api/platformBackoffice';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { platformCan } from './platformAccess';

// Journal d'activité transversal (C-112 point 3) : toutes les actions de tous les clients, filtres et export CSV.
const { t, locale } = useI18n();
const filters = reactive<ActivityFilters>({ tenantId: '', actorId: '', action: '', from: '', to: '' });
const items = ref<ActivityEntry[]>([]);
const next = ref<string | null>(null);
const clients = ref<Tenant[]>([]);
const error = ref<unknown>(null);
const loading = ref(false);
const canExport = platformCan('platform.audit.export');

async function load(more = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchActivity({ ...filters }, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function exportCsv(): Promise<void> {
  error.value = null;
  try {
    saveBlob(await fetchActivityCsv({ ...filters }), 'journal-activite.csv');
  } catch (e) {
    error.value = e;
  }
}

onMounted(async () => {
  try {
    clients.value = await fetchClients();
  } catch {
    // Sans la liste des clients, le filtre reste un identifiant libre.
  }
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('pact.title') }}</h1>
      <p class="lede">{{ t('pact.lede') }}</p>
    </header>
    <form class="card filters" @submit.prevent="load()">
      <div class="field">
        <label for="act-tenant">{{ t('plat.col.client') }}</label>
        <select id="act-tenant" v-model="filters.tenantId">
          <option value="">{{ t('pact.all') }}</option>
          <option value="platform">{{ t('pact.platform') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="act-actor">{{ t('pact.actor') }}</label>
        <input id="act-actor" v-model="filters.actorId" type="text" maxlength="40" autocomplete="off" />
      </div>
      <div class="field">
        <label for="act-action">{{ t('pact.action') }}</label>
        <input id="act-action" v-model="filters.action" type="text" maxlength="100" autocomplete="off" :placeholder="t('pact.actionHint')" />
      </div>
      <div class="field">
        <label for="act-from">{{ t('pact.from') }}</label>
        <input id="act-from" v-model="filters.from" type="date" />
      </div>
      <div class="field">
        <label for="act-to">{{ t('pact.to') }}</label>
        <input id="act-to" v-model="filters.to" type="date" />
      </div>
      <div class="row">
        <button type="submit" class="btn btn--primary" :disabled="loading">{{ t('pact.apply') }}</button>
        <button v-if="canExport" type="button" class="btn" data-test="activity-export" @click="exportCsv">{{ t('pact.export') }}</button>
      </div>
    </form>
    <ErrorNotice :error="error" />
    <p v-if="!loading && items.length === 0 && !error" class="card muted">{{ t('pact.empty') }}</p>
    <div v-if="items.length" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('pact.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('pk.date') }}</th>
            <th scope="col">{{ t('plat.col.client') }}</th>
            <th scope="col">{{ t('pact.actor') }}</th>
            <th scope="col">{{ t('pact.action') }}</th>
            <th scope="col">{{ t('pact.object') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in items" :key="e.id">
            <td>{{ formatDateTime(e.occurred_at, locale) }}</td>
            <td>{{ e.tenant_name }}</td>
            <td>
              <router-link v-if="e.actor_id && e.tenant_id !== 'platform' && platformCan('platform.users.read')" :to="{ name: 'platform-user', params: { tenant: e.tenant_id, user: e.actor_id } }">{{ e.actor_id }}</router-link>
              <span v-else>{{ e.actor_id ?? '—' }}</span>
            </td>
            <td><code>{{ e.action }}</code></td>
            <td>{{ e.object_type }} · {{ e.object_id }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="next" type="button" class="btn" :disabled="loading" @click="load(true)">{{ t('pact.more') }}</button>
  </div>
</template>

<style scoped>
.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
  gap: 0 12px;
  align-items: end;
  margin-bottom: 16px;
}
</style>
