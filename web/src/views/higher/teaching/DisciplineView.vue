<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useHigherStore } from '../store';
import CouncilsPanel from './CouncilsPanel.vue';
import SanctionsPanel from './SanctionsPanel.vue';
import TeachingFrame from './TeachingFrame.vue';
import { useHt } from './i18n';

// Discipline (EX-PRE-06, RG-PRE-2) : onglets « Sanctions » et « Conseils de discipline ».
const { t } = useHt();
const higher = useHigherStore();
const tab = ref<'sanctions' | 'councils'>('sanctions');
const stamp = ref(0);
onMounted(() => void higher.ensure());
</script>

<template>
  <TeachingFrame group="attendance" :title="t('ht.dis.title')" :lede="t('ht.dis.lede')" @school-change="stamp++">
    <div class="hi-tabs" role="tablist">
      <button type="button" role="tab" data-tab="sanctions" :aria-selected="tab === 'sanctions'" @click="tab = 'sanctions'">{{ t('ht.dis.sanctions') }}</button>
      <button type="button" role="tab" data-tab="councils" :aria-selected="tab === 'councils'" @click="tab = 'councils'">{{ t('ht.dis.councils') }}</button>
    </div>
    <SanctionsPanel v-if="tab === 'sanctions'" :key="`s${stamp}`" />
    <CouncilsPanel v-else :key="`c${stamp}`" />
  </TeachingFrame>
</template>
