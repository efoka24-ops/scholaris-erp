<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useContextStore } from '@/stores/context';
import { BOARD_KINDS, BOARD_PERMISSIONS } from '@/api/higher-dashboards';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { dk, useDashboardsI18n } from './i18n';

// Liste des tableaux de bord ouverts à la personne (permission de donnée de chacun ; le serveur impose le périmètre).
const { td } = useDashboardsI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const boards = computed(() => BOARD_KINDS.filter((k) => ctx.can(...BOARD_PERMISSIONS[k])));

onMounted(() => void higher.ensure());
</script>

<template>
  <HigherFrame :title="td('hd.title')" :lede="td('hd.lede')" section="portal" :selector="false">
    <ul class="grid grid--tiles db-index">
      <li v-for="k in boards" :key="k">
        <article class="card hi-card" :data-test="`board-${k}`">
          <h2>{{ td(dk('hd.kind.', k)) }}</h2>
          <p class="muted">{{ td(dk('hd.kindText.', k)) }}</p>
          <router-link class="btn btn--quiet" :to="{ name: 'higher-dashboard', params: { kind: k } }">{{ td('hd.open') }} →</router-link>
        </article>
      </li>
    </ul>
  </HigherFrame>
</template>

<style scoped>
.db-index {
  list-style: none;
  padding: 0;
  margin: 0;
}
</style>
