<script setup lang="ts">
import { useI18n } from '@/i18n';

// Barre d'onglets du bas du portail famille (maquette Parent.html), affichée sur mobile : Accueil, Paiements, Bulletins, Messages.
defineProps<{ active?: 'home' | 'pay' }>();
const emit = defineEmits<{ pay: [] }>();
const { t } = useI18n();
</script>

<template>
  <nav class="tabbar" :aria-label="t('family.tabs')">
    <router-link class="tb" :class="{ 'tb--on': active !== 'pay' }" :to="{ name: 'portal-family' }" :aria-current="active !== 'pay' ? 'page' : undefined">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10l9-7 9 7v10a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z" /></svg>
      {{ t('family.tabHome') }}
    </router-link>
    <button type="button" class="tb" :class="{ 'tb--on': active === 'pay' }" @click="emit('pay')">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="5" width="20" height="14" /><path d="M2 10h20" /></svg>
      {{ t('family.tabPay') }}
    </button>
    <router-link class="tb" :to="{ name: 'documents' }">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" /><path d="M14 2v6h6" /></svg>
      {{ t('family.tabReports') }}
    </router-link>
    <router-link class="tb" :to="{ name: 'messages' }">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" /></svg>
      {{ t('family.tabMessages') }}
    </router-link>
  </nav>
</template>

<style scoped>
.tabbar {
  display: none;
}
@media (max-width: 720px) {
  .tabbar {
    display: flex;
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 20;
    background: var(--surface, #fff);
    border-top: 1px solid var(--border, #dcdcd8);
  }
}
.tb {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  min-height: 56px;
  flex: 1;
  font: 700 11px var(--font);
  color: #595959;
  text-decoration: none;
  border: 0;
  background: none;
  cursor: pointer;
}
.tb--on {
  color: var(--ink, #000);
}
</style>
