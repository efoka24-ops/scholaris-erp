<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from '@/i18n';
import { useHigherStore } from './store';

// Raccourci du lanceur (Main.html : carte à bordure forte) vers l'application IPES, affiché seulement si elle est ouverte.
const { t } = useI18n();
const higher = useHigherStore();
onMounted(() => void higher.ensure());
</script>

<template>
  <section v-if="higher.visible" class="card card--strong hi-home-card" aria-labelledby="hi-home-card-title">
    <p class="eyebrow">{{ t('hi.eyebrow') }}</p>
    <h2 id="hi-home-card-title">{{ t('hi.home.cardTitle') }}</h2>
    <p>{{ t('hi.home.cardText') }}</p>
    <router-link class="btn" :to="{ name: 'higher' }">{{ t('hi.home.open') }} →</router-link>
  </section>
</template>

<style scoped>
.hi-home-card {
  margin-bottom: 24px;
}
.hi-home-card h2 {
  margin: 4px 0 8px;
  font-size: 24px;
}
</style>
