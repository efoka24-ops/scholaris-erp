<script setup lang="ts">
import { computed } from 'vue';

// Graphique à barres horizontales sobre : une série, une couleur d'accent, valeurs écrites en toutes lettres (le tableau voisin
// porte les mêmes données). Les lignes masquées (petits effectifs) sont ignorées par l'appelant.
const props = defineProps<{ title: string; items: Array<{ label: string; value: number }>; unit?: string; max?: number }>();

const ceiling = computed(() => props.max ?? Math.max(1, ...props.items.map((i) => i.value)));
const width = (v: number): string => `${Math.max(0, Math.min(100, (100 * v) / ceiling.value)).toFixed(1)}%`;
</script>

<template>
  <figure v-if="items.length > 0" class="bar" data-test="chart">
    <figcaption>{{ title }}</figcaption>
    <ul role="list">
      <li v-for="(item, i) in items" :key="i">
        <span class="bar__label">{{ item.label }}</span>
        <span class="bar__track" aria-hidden="true"><span class="bar__fill" :style="{ width: width(item.value) }" /></span>
        <span class="bar__value">{{ item.value.toLocaleString() }}{{ unit ?? '' }}</span>
      </li>
    </ul>
  </figure>
</template>

<style scoped>
.bar {
  margin: 0 0 20px;
}
.bar figcaption {
  font-weight: 600;
  margin-bottom: 8px;
}
.bar ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 6px;
}
.bar li {
  display: grid;
  grid-template-columns: minmax(90px, 30%) 1fr auto;
  gap: 8px;
  align-items: center;
  font-size: 14px;
}
.bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bar__track {
  background: var(--surface-2, rgb(128 128 128 / 15%));
  border-radius: 4px;
  height: 12px;
  overflow: hidden;
}
.bar__fill {
  background: var(--accent, #1f6f5c);
  display: block;
  height: 100%;
}
.bar__value {
  font-variant-numeric: tabular-nums;
}
@media (max-width: 520px) {
  .bar li {
    grid-template-columns: 1fr auto;
  }
  .bar__track {
    grid-column: 1 / -1;
    order: 3;
  }
}
</style>
