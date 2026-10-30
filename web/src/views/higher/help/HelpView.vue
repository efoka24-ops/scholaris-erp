<script setup lang="ts">
import { useI18n, type MessageKey } from '@/i18n';
import HigherFrame from '../HigherFrame.vue';

// Fiches rapides de l'IPES : contenu statique (textes hhp.*), cinq étapes par fiche.
const { t } = useI18n();
const cards = ['grades', 'attendance', 'payment', 'enrol', 'results'] as const;
const steps = [1, 2, 3, 4, 5] as const;
const key = (card: string, suffix: string): MessageKey => `hhp.${card}.${suffix}` as MessageKey;
</script>

<template>
  <HigherFrame :title="t('hhp.title')" :lede="t('hhp.lede')" section="home">
    <ul class="grid grid--tiles hhp-cards">
      <li v-for="c in cards" :key="c">
        <article class="card" :data-test="`help-${c}`">
          <h2>{{ t(key(c, 'title')) }}</h2>
          <ol>
            <li v-for="n in steps" :key="n">{{ t(key(c, `s${n}`)) }}</li>
          </ol>
        </article>
      </li>
    </ul>
  </HigherFrame>
</template>

<style scoped>
.hhp-cards {
  list-style: none;
  padding: 0;
  margin: 0;
}
</style>
