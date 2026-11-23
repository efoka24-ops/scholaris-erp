<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from '@/i18n';
import AdministratorsPanel from './AdministratorsPanel.vue';
import ReplayNotice from './ReplayNotice.vue';

// Administrateurs de la plateforme : la double authentification est obligatoire pour ce rôle ; on ne se désactive jamais
// soi-même et le dernier administrateur actif ne peut pas être désactivé (règle vérifiée par le serveur).
const { t } = useI18n();
const version = ref(0);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('pa.adm.platformTitle') }}</h1>
      <p class="lede">{{ t('pa.adm.platformLede') }}</p>
    </header>
    <ReplayNotice @done="version++" />
    <AdministratorsPanel :key="version" :tenant="null" />
  </div>
</template>
