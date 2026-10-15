<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PublicLayout from './PublicLayout.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchPublicApplications, type PublicApplication } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { appStatusKey, appStatusTone, targetKey } from './display';

// Catalogue public des applications — design : Main.html (tuiles), Fondations.html (étiquettes de statut).
const { t, locale } = useI18n();
const items = ref<PublicApplication[]>([]);
const loaded = ref(false);
const error = ref<unknown>(null);

onMounted(async () => {
  try {
    items.value = await fetchPublicApplications();
  } catch (e) {
    error.value = e;
  } finally {
    loaded.value = true;
  }
});
</script>

<template>
  <PublicLayout>
    <div class="page">
      <header class="page-head">
        <p class="eyebrow">{{ t('pub.catalog.eyebrow') }}</p>
        <h1>{{ t('pub.catalog.title') }}</h1>
        <p class="lede">{{ t('pub.catalog.lede') }}</p>
      </header>
      <ErrorNotice :error="error" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="!error && items.length === 0" class="card muted">{{ t('pub.catalog.empty') }}</p>
      <ul v-else class="grid grid--tiles apps">
        <li v-for="app in items" :key="app.key">
          <article class="card app" :class="{ 'card--strong': app.status === 'available' }">
            <div class="row row--between">
              <span class="tag">{{ t('pub.catalog.target', { target: t(targetKey(app.target)) }) }}</span>
              <span class="tag" :class="appStatusTone(app.status) ? `tag--${appStatusTone(app.status)}` : ''">{{ t(appStatusKey(app.status)) }}</span>
            </div>
            <h2 class="app__name">{{ app.label }}</h2>
            <p class="muted small">{{ t('pub.catalog.version', { version: app.version }) }}</p>
            <ul class="app__facts">
              <li>{{ t('pub.catalog.trial', { days: app.trial_days }) }}</li>
              <li>{{ t('pub.catalog.price', { amount: formatXaf(app.monthly_price_xaf, locale) }) }}</li>
            </ul>
            <router-link
              v-if="app.status === 'available'"
              class="btn btn--primary app__cta"
              :to="{ name: 'access-request', query: { application: app.key } }"
              :aria-label="t('pub.catalog.requestNamed', { label: app.label })"
            >
              {{ t('pub.catalog.request') }} →
            </router-link>
            <p v-else class="app__soon">{{ t('pub.catalog.soon') }}</p>
          </article>
        </li>
      </ul>
    </div>
  </PublicLayout>
</template>

<style scoped>
.apps {
  list-style: none;
  padding: 0;
  margin: 0;
}
.app {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.app__name {
  font-size: 22px;
  margin: 6px 0 0;
}
.small {
  font-size: 13px;
  margin: 0;
}
.app__facts {
  margin: 0;
  padding-left: 18px;
  color: var(--text-2);
}
.app__cta {
  margin-top: auto;
  align-self: flex-start;
}
.app__soon {
  margin: auto 0 0;
  font-weight: 700;
  color: var(--muted);
  min-height: var(--tap);
  display: flex;
  align-items: center;
}
</style>
