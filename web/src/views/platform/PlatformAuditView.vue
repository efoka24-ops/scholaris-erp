<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchPlatformAudit, type AuditEntry } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Journal d'audit de la plateforme (lecture seule, le plus récent d'abord) : filtres par famille d'action et par période.
const { t, locale } = useI18n();
const filters = reactive({ action: '', from: '', to: '' });
const items = ref<AuditEntry[]>([]);
const next = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);

const FAMILIES = ['', 'platform.access_request', 'platform.client', 'platform.establishment', 'platform.administrator', 'platform.application', 'platform.payment', 'platform.catalog'];

async function load(more = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchPlatformAudit({ ...filters }, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

function summary(entry: AuditEntry): string {
  return Object.entries(entry.details)
    .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
    .map(([k, v]) => `${k} : ${String(v)}`)
    .join(' · ');
}

onMounted(() => load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('pa.audit.title') }}</h1>
      <p class="lede">{{ t('pa.audit.lede') }}</p>
    </header>

    <form class="row filters" novalidate @submit.prevent="load()">
      <div class="field">
        <label for="au-action">{{ t('pa.audit.family') }}</label>
        <select id="au-action" v-model="filters.action">
          <option v-for="f in FAMILIES" :key="f" :value="f">{{ f === '' ? t('pa.audit.allFamilies') : f }}</option>
        </select>
      </div>
      <div class="field"><label for="au-from">{{ t('pa.audit.from') }}</label><input id="au-from" v-model="filters.from" type="date" /></div>
      <div class="field"><label for="au-to">{{ t('pa.audit.to') }}</label><input id="au-to" v-model="filters.to" type="date" /></div>
      <button type="submit" class="btn" :disabled="loading">{{ t('pa.audit.apply') }}</button>
    </form>

    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('pa.audit.empty') }}</p>
    <div v-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('pa.audit.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('plat.col.date') }}</th>
            <th scope="col">{{ t('pa.audit.action') }}</th>
            <th scope="col">{{ t('pa.audit.object') }}</th>
            <th scope="col">{{ t('pa.audit.actor') }}</th>
            <th scope="col">{{ t('pa.audit.details') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in items" :key="e.id">
            <td>{{ formatDateTime(e.occurred_at, locale) }}</td>
            <th scope="row"><code>{{ e.action }}</code></th>
            <td>{{ e.object_type }} <code class="id">{{ e.object_id }}</code></td>
            <td><code class="id">{{ e.actor_id ?? '—' }}</code></td>
            <td class="wrap">{{ summary(e) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ loading ? t('common.loading') : t('plat.loadMore') }}</button>
  </div>
</template>

<style scoped>
.filters {
  align-items: flex-end;
  margin-bottom: 8px;
}
.filters .field {
  flex: 1 1 200px;
  max-width: 300px;
}
.id {
  font-size: 12px;
  color: var(--muted);
}
.wrap {
  white-space: normal;
}
.more {
  margin-top: 16px;
}
</style>
