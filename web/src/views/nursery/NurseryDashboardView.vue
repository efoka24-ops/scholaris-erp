<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ComingSoon from '@/components/ComingSoon.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { http } from '@/api/http';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Tableau de bord maternelle (maquette MatDashboard.html). Seules les données qui existent côté serveur sont affichées :
// les personnes autorisées à récupérer les enfants. Présences par section, cantine, carnets et cahier de vie : annoncés.
const { t } = useI18n();
const ctx = useContextStore();
const activePickups = ref<number | null>(null);
const error = ref<unknown>(null);

onMounted(async () => {
  try {
    const rows = await http.all<{ status: string }>('/authorized-pickups', { 'filter[status]': 'active' }, {}, 5);
    activePickups.value = rows.length;
  } catch (e) {
    error.value = e;
  }
});
</script>

<template>
  <div class="page nursery">
    <header class="page-head">
      <p class="eyebrow">{{ ctx.activeTile?.name }}</p>
      <h1>{{ t('nursery.dashTitle') }}</h1>
    </header>
    <ErrorNotice :error="error" />

    <nav class="row" :aria-label="t('nursery.dashTitle')">
      <router-link class="btn btn--primary" :to="{ name: 'nursery-pickup' }">{{ t('nursery.pickupTitle') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-logbook' }">{{ t('nursery.logbookTitle') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-observation' }">{{ t('pn.observation.title') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-progress-book' }">{{ t('pn.progressBook.title') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-lifebook' }">{{ t('pn.lifebook.title') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-transition-decisions' }">{{ t('pn.transition.title') }}</router-link>
      <router-link class="btn" :to="{ name: 'nursery-transition-liaison' }">{{ t('pn.liaison.title') }}</router-link>
      <router-link class="btn" :to="{ name: 'pn-register' }">{{ t('pn.nav.register') }}</router-link>
      <router-link class="btn" :to="{ name: 'pn-contribution' }">{{ t('pn.nav.contribution') }}</router-link>
    </nav>

    <section class="card kpi-line" aria-labelledby="nd-pick">
      <h2 id="nd-pick">{{ t('nursery.pickupTitle') }}</h2>
      <p class="big">{{ activePickups ?? '—' }}</p>
      <p class="muted">{{ t('nursery.activeAuthorisations') }}</p>
    </section>

    <div class="soon-grid">
      <ComingSoon :title="t('nursery.liveTitle')" :text="t('nursery.liveText')" />
      <ComingSoon :title="t('nursery.sectionsTitle')" :text="t('nursery.sectionsText')" />
      <ComingSoon :title="t('nursery.canteenTitle')" :text="t('nursery.canteenText')" />
      <ComingSoon :title="t('nursery.lifebookTitle')" :text="t('nursery.lifebookText')" />
    </div>
  </div>
</template>

<style scoped>
.nursery {
  max-width: 900px;
}
.kpi-line {
  margin: 16px 0;
}
.kpi-line h2 {
  margin: 0;
  font-size: 17px;
}
.kpi-line p {
  margin: 4px 0 0;
}
.big {
  font-size: 34px;
  font-weight: 800;
}
.soon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}
</style>
