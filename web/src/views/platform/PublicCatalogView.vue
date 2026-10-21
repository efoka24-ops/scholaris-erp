<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PublicLayout from './PublicLayout.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchPublicApplications, type PublicApplication } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { appStatusKey, appStatusTone, sectorKey, targetKey } from './display';
import { SCHOOL_TYPES, groupByLevel, needsSchoolType, useRegimePick } from './catalogGroups';
import type { MessageKey } from '@/i18n';

// Catalogue public des applications — design : Main.html (tuiles), Fondations.html (étiquettes de statut).
// Une carte par niveau (supérieur, maternelle, primaire, secondaire) ; le régime (privé ou public) désigne l'application demandée.
const { t, locale } = useI18n();
const items = ref<PublicApplication[]>([]);
const loaded = ref(false);
const error = ref<unknown>(null);
const groups = computed(() => groupByLevel(items.value));
const regime = useRegimePick<PublicApplication>();
/** Secondaire : le type d'établissement (obligatoire) est choisi sur la carte puis repris par le formulaire de demande. */
const subtype = ref('');

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
        <li v-for="group in groups" :key="group.level">
          <article class="card app" :class="{ 'card--strong': regime.current(group).app.status === 'available' }" :data-level="group.level">
            <div class="row row--between">
              <span class="tag">{{ t('pub.catalog.target', { target: t(targetKey(group.level)) }) }}</span>
              <span class="tag" :class="appStatusTone(regime.current(group).app.status) ? 'tag--' + appStatusTone(regime.current(group).app.status) : ''">{{ t(appStatusKey(regime.current(group).app.status)) }}</span>
            </div>
            <h2 class="app__name">{{ group.label }}</h2>
            <div v-if="group.regimes.length > 1" class="regimes" role="radiogroup" :aria-label="t('pub.regime.legendNamed', { label: group.label })">
              <label v-for="r in group.regimes" :key="r.app.key" class="regime" :class="{ 'regime--on': regime.current(group).app.key === r.app.key }">
                <input type="radio" :name="'regime-' + group.level" :value="r.sector" :checked="regime.current(group).app.key === r.app.key" @change="regime.choose(group, r.sector)" />
                <span>{{ t(sectorKey(r.sector)) }}</span>
              </label>
            </div>
            <div v-if="needsSchoolType(group.level)" class="field">
              <label :for="'subtype-' + group.level">{{ t('pub.catalog.schoolType') }}</label>
              <select :id="'subtype-' + group.level" v-model="subtype">
                <option value="">{{ t('pub.req.chooseSchoolType') }}</option>
                <option v-for="st in SCHOOL_TYPES" :key="st" :value="st">{{ t(('pub.schoolType.' + st) as MessageKey) }}</option>
              </select>
            </div>
            <p class="muted small">{{ t('pub.catalog.version', { version: regime.current(group).app.version }) }}</p>
            <ul class="app__facts">
              <li>{{ t('pub.catalog.trial', { days: regime.current(group).app.trial_days }) }}</li>
              <li>{{ t('pub.catalog.price', { amount: formatXaf(regime.current(group).app.monthly_price_xaf, locale) }) }}</li>
            </ul>
            <router-link
              v-if="regime.current(group).app.status === 'available'"
              class="btn btn--primary app__cta"
              :to="{ name: 'access-request', query: needsSchoolType(group.level) && subtype !== '' ? { application: regime.current(group).app.key, subtype } : { application: regime.current(group).app.key } }"
              :aria-label="t('pub.catalog.requestNamed', { label: group.regimes.length > 1 ? group.label + ' (' + t(sectorKey(regime.current(group).sector)) + ')' : group.label })"
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
.regimes {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.regime {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: var(--tap);
  padding: 0 14px;
  border: 1.5px solid currentColor;
  border-radius: 999px;
  font-weight: 700;
  cursor: pointer;
}
.regime--on {
  background: var(--ink, #14213d);
  border-color: var(--ink, #14213d);
  color: #fff;
}
.regime input {
  accent-color: currentColor;
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
