<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchClients, type Tenant } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { stateKey, stateTone } from './display';

// Liste des clients et de leurs applications (aucune donnée métier) — design : Dashboard.html (tableau), Fondations.html.
const { t, locale } = useI18n();
const items = ref<Tenant[]>([]);
const loaded = ref(false);
const error = ref<unknown>(null);
const query = ref('');

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q === '' ? items.value : items.value.filter((c) => c.name.toLowerCase().includes(q) || c.id.toLowerCase().includes(q));
});

onMounted(async () => {
  try {
    items.value = await fetchClients();
  } catch (e) {
    error.value = e;
  } finally {
    loaded.value = true;
  }
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('plat.cli.title') }}</h1>
      <p class="lede">{{ t('plat.cli.lede') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="!error && items.length === 0" class="card muted">{{ t('plat.cli.empty') }}</p>
    <template v-else-if="items.length > 0">
      <div class="field search">
        <label for="cli-search">{{ t('plat.cli.search') }}</label>
        <input id="cli-search" v-model="query" type="search" autocomplete="off" />
      </div>
      <p v-if="filtered.length === 0" class="card muted" role="status">{{ t('plat.cli.noMatch') }}</p>
      <div v-else class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('plat.cli.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('plat.col.client') }}</th>
              <th scope="col">{{ t('plat.col.status') }}</th>
              <th scope="col">{{ t('plat.col.created') }}</th>
              <th scope="col">{{ t('plat.col.apps') }}</th>
              <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in filtered" :key="c.id">
              <th scope="row">{{ c.name }}<br /><code class="id">{{ c.id }}</code></th>
              <td><span class="tag">{{ c.status }}</span></td>
              <td>{{ formatDate(c.created_at, locale) }}</td>
              <td class="wrap">
                <span v-if="c.applications.length === 0" class="muted">{{ t('plat.cli.noApps') }}</span>
                <span v-for="a in c.applications" :key="a.id" class="app">{{ a.label }} <span class="tag" :class="stateTone(a.state) ? `tag--${stateTone(a.state)}` : ''">{{ t(stateKey(a.state)) }}</span></span>
              </td>
              <td><router-link class="btn btn--quiet" :to="{ name: 'platform-client', params: { tenant: c.id } }" :aria-label="t('plat.detailsNamed', { name: c.name })">{{ t('plat.details') }}</router-link></td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.search {
  max-width: 420px;
}
.id {
  font-size: 12px;
  font-weight: 400;
  color: var(--muted);
}
.wrap {
  white-space: normal;
}
.app {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 2px 12px 2px 0;
}
</style>
