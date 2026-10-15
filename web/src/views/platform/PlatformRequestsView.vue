<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { REQUEST_STATUSES, fetchAccessRequests, type AccessRequest, type AccessRequestStatus } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { requestStatusKey, requestTone, targetKey } from './display';

// Liste des demandes d'accès, filtrée par état — design : Dashboard.html (tableau), Fondations.html (onglets, étiquettes).
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();

const initial = typeof route.query.statut === 'string' ? route.query.statut : 'pending';
const status = ref<AccessRequestStatus | 'all'>((REQUEST_STATUSES as string[]).includes(initial) || initial === 'all' ? (initial as AccessRequestStatus | 'all') : 'pending');
const items = ref<AccessRequest[]>([]);
const next = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);

async function load(more = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchAccessRequests(status.value === 'all' ? null : status.value, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

watch(status, (value) => {
  void router.replace({ query: { ...route.query, statut: value } });
  void load();
});
onMounted(() => load());

const filters: Array<AccessRequestStatus | 'all'> = [...REQUEST_STATUSES, 'all'];
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('plat.req.title') }}</h1>
      <p class="lede">{{ t('plat.req.lede') }}</p>
    </header>

    <fieldset class="filters">
      <legend class="sr-only">{{ t('plat.req.filter') }}</legend>
      <label v-for="f in filters" :key="f" class="chip" :class="{ 'chip--on': status === f }">
        <input v-model="status" type="radio" name="req-status" :value="f" class="sr-only" />
        {{ f === 'all' ? t('plat.req.all') : t(requestStatusKey(f)) }}
      </label>
    </fieldset>

    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('plat.req.empty') }}</p>

    <div v-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('plat.req.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('plat.col.reference') }}</th>
            <th scope="col">{{ t('plat.col.institution') }}</th>
            <th scope="col">{{ t('plat.col.app') }}</th>
            <th scope="col">{{ t('plat.col.target') }}</th>
            <th scope="col">{{ t('plat.col.city') }}</th>
            <th scope="col">{{ t('plat.col.date') }}</th>
            <th scope="col">{{ t('plat.col.status') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in items" :key="r.id">
            <td><code>{{ r.reference }}</code></td>
            <th scope="row">{{ r.institution_name }}</th>
            <td>{{ r.app_label }}</td>
            <td>{{ t(targetKey(r.target_type)) }}</td>
            <td>{{ r.city }}, {{ r.region }}</td>
            <td>{{ formatDate(r.created_at, locale) }}</td>
            <td><span class="tag" :class="requestTone(r.status) ? `tag--${requestTone(r.status)}` : ''">{{ t(requestStatusKey(r.status)) }}</span></td>
            <td><router-link class="btn btn--quiet" :to="{ name: 'platform-request', params: { id: r.id } }" :aria-label="t('plat.detailsNamed', { name: r.institution_name })">{{ t('plat.details') }}</router-link></td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ loading ? t('common.loading') : t('plat.loadMore') }}</button>
  </div>
</template>

<style scoped>
.filters {
  border: 0;
  padding: 0;
  margin: 0 0 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.chip {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}
.chip--on {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.chip:focus-within {
  outline: 3px solid var(--ink);
  outline-offset: 2px;
  box-shadow: 0 0 0 5px var(--accent);
}
.more {
  margin-top: 16px;
}
</style>
