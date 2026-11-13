<script setup lang="ts">
import './deliberation.css';
import HigherFrame from '../HigherFrame.vue';
import DelNav from './DelNav.vue';

// Cadre des écrans de délibération : cadre IPES (sélecteur d'école, lecture seule) et onglets de la rubrique.
withDefaults(defineProps<{ title: string; lede?: string; nav?: boolean; selector?: boolean }>(), { lede: '', nav: true, selector: true });
const emit = defineEmits<{ (e: 'school-change', id: string): void }>();
</script>

<template>
  <HigherFrame :title="title" :lede="lede" section="home" :selector="selector" @school-change="(id: string) => emit('school-change', id)">
    <DelNav v-if="nav" />
    <slot />
  </HigherFrame>
</template>
