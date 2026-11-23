<script setup lang="ts">
// Objectifs O1 à O7 en cartes (mobile d'abord) : valeur, cible du CDC, état (atteint, non atteint, sans comparaison, indisponible) et
// motif d'indisponibilité. Les libellés viennent de l'i18n, pas du serveur.
import { useI18n, locale, type MessageKey } from '@/i18n';
import { formatObjective, objectiveState, type Objective } from '@/api/secondaryDashboards';

defineProps<{ objectives: Objective[] }>();
const { t } = useI18n();

function target(o: Objective): string {
  const value = formatObjective(o.target, o.direction === 'drop' ? 'count' : o.unit, locale.value);
  const key: MessageKey = o.direction === 'max' ? 'secdb.targetMax' : o.direction === 'min' ? 'secdb.targetMin' : 'secdb.targetDrop';
  return t('secdb.target', { value: t(key, { value }) });
}
function change(o: Objective): string | null {
  const value = o.detail.change_percent;
  return typeof value === 'number' ? t('secdb.change', { value: formatObjective(value, 'count', locale.value) }) : null;
}
</script>

<template>
  <ul class="objectives">
    <li v-for="o in objectives" :key="o.code" class="objective" :data-state="objectiveState(o)">
      <p class="objective-code">{{ o.code }}</p>
      <h3>{{ t(`secdb.o.${o.code}` as MessageKey) }}</h3>
      <p class="objective-value">{{ formatObjective(o.value, o.unit, locale) }}</p>
      <p class="muted">{{ target(o) }}</p>
      <p v-if="change(o)" class="muted">{{ change(o) }}</p>
      <p class="objective-state">{{ t(`secdb.state.${objectiveState(o)}` as MessageKey) }}</p>
      <p v-if="!o.available && o.reason" class="muted">{{ t(`secdb.reason.${o.reason}` as MessageKey) }}</p>
    </li>
  </ul>
</template>

<style scoped>
.objectives { display: grid; grid-template-columns: 1fr; gap: 12px; list-style: none; padding: 0; margin: 0; }
@media (min-width: 640px) { .objectives { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .objectives { grid-template-columns: repeat(3, 1fr); } }
.objective { border: 1px solid var(--border, #d9dee5); border-left-width: 6px; border-radius: 8px; padding: 12px; }
.objective h3 { font-size: 1rem; margin: 0 0 6px; }
.objective p { margin: 2px 0; }
.objective-code { font-weight: 700; }
.objective-value { font-size: 1.6rem; font-weight: 700; }
.objective[data-state='met'] { border-left-color: #1f7a3d; }
.objective[data-state='missed'] { border-left-color: #b42318; }
.objective[data-state='open'] { border-left-color: #8a6d00; }
.objective[data-state='unavailable'] { border-left-color: #8a94a3; }
.muted { color: var(--text-muted, #5b6573); }
</style>
