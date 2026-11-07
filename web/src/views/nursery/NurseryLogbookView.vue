<script setup lang="ts">
import ComingSoon from '@/components/ComingSoon.vue';
import { useI18n } from '@/i18n';

// Carnet de suivi (maquette CarnetSuivi.html) : grille de compétences Acquis / En cours / Non acquis par enfant, observation, photos.
// Aucun service serveur ne le porte encore : l'écran l'annonce et décrit ce qui est prévu, sans rien simuler.
const { t } = useI18n();
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nursery.eyebrow') }}</p>
      <h1>{{ t('nursery.logbookTitle') }}</h1>
    </header>
    <ComingSoon :title="t('nursery.logbookTitle')" :text="t('nursery.logbookText')" />
    <ul class="plan">
      <li>{{ t('nursery.logbookPlan1') }}</li>
      <li>{{ t('nursery.logbookPlan2') }}</li>
      <li>{{ t('nursery.logbookPlan3') }}</li>
    </ul>
    <router-link class="btn" :to="{ name: 'nursery' }">{{ t('nursery.dashTitle') }}</router-link>
  </div>
</template>

<style scoped>
.plan {
  margin: 16px 0;
  padding-left: 20px;
}
</style>
