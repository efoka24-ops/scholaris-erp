<script setup lang="ts">
import { useI18n } from '@/i18n';

// Carte « Administration » de l'accueil pour la personne qui administre le client : propose de créer le premier établissement quand il n'y en a pas.
defineProps<{ empty: boolean }>();
const { t } = useI18n();
</script>

<template>
  <section class="card card--strong launcher" aria-labelledby="admin-launcher-title">
    <p class="eyebrow">{{ t('admin.launcher.title') }}</p>
    <h2 id="admin-launcher-title">{{ empty ? t('admin.launcher.firstTitle') : t('admin.launcher.title') }}</h2>
    <p>{{ empty ? t('admin.launcher.firstText') : t('admin.launcher.text') }}</p>
    <div class="row">
      <router-link v-if="empty" class="btn btn--primary btn--large" :to="{ name: 'admin-establishments', query: { nouveau: '1' } }">{{ t('admin.home.firstCta') }}</router-link>
      <router-link class="btn" :class="{ 'btn--large': !empty }" :to="{ name: 'admin' }">{{ t('admin.launcher.open') }}</router-link>
    </div>
  </section>
</template>

<style scoped>
.launcher h2 {
  margin: 4px 0 8px;
  font-size: 24px;
}
</style>
