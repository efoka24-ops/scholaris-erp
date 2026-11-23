<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { fetchMyApplications, type Entitlement } from '@/api/platform';
import { useI18n } from '@/i18n';
import { stateKey, stateTone } from './display';

// Tuile « Mes applications » du lanceur d'accueil — design : Main.html (carte à bordure forte, lien d'action).
// Résumé facultatif : si /me/applications échoue, la tuile reste un simple raccourci vers la page dédiée.
const { t, tp } = useI18n();
const opened = ref<Entitlement[]>([]);

onMounted(async () => {
  try {
    opened.value = (await fetchMyApplications()).opened;
  } catch {
    opened.value = [];
  }
});
</script>

<template>
  <section class="card card--strong apps-tile" aria-labelledby="apps-tile-title">
    <p class="eyebrow">{{ t('apps.eyebrow') }}</p>
    <h2 id="apps-tile-title">{{ t('apps.tile.title') }}</h2>
    <p>{{ t('apps.tile.text') }}</p>
    <ul v-if="opened.length > 0" class="summary">
      <li v-for="app in opened" :key="app.id">
        <strong>{{ app.label }}</strong>
        <span class="tag" :class="stateTone(app.state) ? `tag--${stateTone(app.state)}` : ''">{{ t(stateKey(app.state)) }}</span>
        <span v-if="app.state === 'trial'" class="muted">{{ tp('apps.trialLeft', app.trial_days_left) }}</span>
      </li>
    </ul>
    <router-link class="btn" :to="{ name: 'applications' }">{{ t('apps.tile.open') }} →</router-link>
  </section>
</template>

<style scoped>
.apps-tile h2 {
  margin: 4px 0 8px;
  font-size: 24px;
}
.summary {
  list-style: none;
  padding: 0;
  margin: 0 0 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.summary li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
</style>
