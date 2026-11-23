<script setup lang="ts">
import { watch } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from '@/components/AppShell.vue';
import OfflineBanner from '@/components/OfflineBanner.vue';
import { useContextStore } from '@/stores/context';

const route = useRoute();
const ctx = useContextStore();

// Accent propre à l'établissement actif (identité visuelle paramétrable, texte toujours noir dessus).
watch(
  () => ctx.accent,
  (accent) => document.documentElement.style.setProperty('--accent', accent),
  { immediate: true },
);
</script>

<template>
  <router-view v-if="route.meta.bare || route.meta.public" v-slot="{ Component }">
    <OfflineBanner />
    <component :is="Component" />
  </router-view>
  <AppShell v-else>
    <router-view />
  </AppShell>
</template>
