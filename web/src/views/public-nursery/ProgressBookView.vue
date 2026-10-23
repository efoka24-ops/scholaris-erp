<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import ComingSoon from '@/components/ComingSoon.vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

const draft = reactive({
  period: 'T1',
  learner: '',
  summary: 'Beau progrès dans les interactions et les activités de langage.',
  points: ['Prendre la parole en groupe', 'Reconnaître les formes simples', 'Participer aux ateliers'],
});
const pointInput = ref('');

const hasDraft = computed(() => draft.learner.trim() !== '');

function addPoint(): void {
  const point = pointInput.value.trim();
  if (!point) return;
  draft.points.push(point);
  pointInput.value = '';
}

function removePoint(index: number): void {
  draft.points.splice(index, 1);
}
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.progressBook.eyebrow') }}</p>
      <h1>{{ t('pn.progressBook.title') }}</h1>
      <p class="lead">{{ t('pn.progressBook.intro') }}</p>
    </header>

    <div class="grid">
      <section class="card panel">
        <h2>{{ t('pn.progressBook.formTitle') }}</h2>
        <div class="field">
          <label for="pb-learner">{{ t('nursery.child') }}</label>
          <input id="pb-learner" v-model="draft.learner" type="text" placeholder="LEARNER-01" />
        </div>
        <div class="field grid2">
          <div>
            <label for="pb-period">{{ t('pn.progressBook.period') }}</label>
            <select id="pb-period" v-model="draft.period">
              <option value="T1">T1</option>
              <option value="T2">T2</option>
              <option value="T3">T3</option>
            </select>
          </div>
          <div>
            <label for="pb-point">{{ t('pn.progressBook.items') }}</label>
            <div class="row add-point">
              <input id="pb-point" v-model="pointInput" type="text" placeholder="Point fort" @keydown.enter.prevent="addPoint" />
              <button type="button" class="btn" :aria-label="t('pn.progressBook.addPoint')" @click="addPoint">+</button>
            </div>
          </div>
        </div>
        <div class="field">
          <label for="pb-summary">{{ t('pn.progressBook.summary') }}</label>
          <textarea id="pb-summary" v-model="draft.summary" rows="4"></textarea>
        </div>
        <ul class="points">
          <li v-for="(point, index) in draft.points" :key="point + index">
            <span>{{ point }}</span>
            <button type="button" class="btn btn--quiet" :aria-label="`${t('pn.progressBook.removePoint')} : ${point}`" @click="removePoint(index)">×</button>
          </li>
        </ul>
        <div class="row">
          <button type="button" class="btn btn--primary">{{ t('pn.progressBook.saveLocal') }}</button>
          <button type="button" class="btn" :disabled="!hasDraft">{{ t('pn.progressBook.validate') }}</button>
        </div>
      </section>

      <aside class="card panel preview">
        <h2>{{ t('pn.progressBook.preview') }}</h2>
        <ComingSoon v-if="!hasDraft" :title="t('pn.progressBook.empty')" :text="t('pn.progressBook.notice')" />
        <template v-else>
          <p class="muted small">{{ t('pn.progressBook.period') }} : {{ draft.period }}</p>
          <p class="strong">{{ draft.learner }}</p>
          <p>{{ draft.summary }}</p>
          <ul class="preview-points">
            <li v-for="point in draft.points" :key="point">{{ point }}</li>
          </ul>
        </template>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.pn-screen {
  max-width: 1120px;
}
.lead {
  max-width: 760px;
  color: var(--muted, #5f5b52);
}
.grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.grid2 {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
.points,
.preview-points {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.points li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid var(--border, #dcdcd8);
  border-radius: 12px;
}
.add-point {
  gap: 8px;
}
.strong {
  font-weight: 800;
  font-size: 18px;
}
@media (min-width: 600px) {
  .grid2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (min-width: 901px) {
  .grid {
    grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
  }
}
</style>