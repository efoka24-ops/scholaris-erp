<script setup lang="ts">
import { computed } from 'vue';
import { useContextStore } from '@/stores/context';
import { useNationalI18n, type HigherNationalExamsKey } from '../national-exams/i18n';
import { T } from '../national-exams/perms';
import HigherFrame from '../HigherFrame.vue';

// Cadre des écrans de tutelle : cadre IPES et onglets (conventions, transmissions, dossiers de diplôme, accès de l'université).
withDefaults(defineProps<{ title: string; lede?: string }>(), { lede: '' });
const emit = defineEmits<{ (e: 'school-change', id: string): void }>();
const { tx } = useNationalI18n();
const ctx = useContextStore();

const TABS: Array<{ name: string; label: HigherNationalExamsKey; internal: boolean }> = [
  { name: 'higher-tutelage', label: 'htu.nav.conventions', internal: true },
  { name: 'higher-tutelage-transmissions', label: 'htu.nav.transmissions', internal: true },
  { name: 'higher-tutelage-files', label: 'htu.nav.files', internal: true },
  { name: 'higher-tutelage-portal', label: 'htu.nav.portal', internal: false },
];
const tabs = computed(() => TABS.filter((t) => (t.internal ? ctx.can(...T.internal) : ctx.can(...T.internal, ...T.external))));
</script>

<template>
  <HigherFrame :title="title" :lede="lede" section="portal" @school-change="(id: string) => emit('school-change', id)">
    <nav class="hx-tabs" :aria-label="tx('htu.title')">
      <router-link v-for="t in tabs" :key="t.name" class="btn btn--quiet" :to="{ name: t.name }">{{ tx(t.label) }}</router-link>
    </nav>
    <slot />
  </HigherFrame>
</template>

<style scoped>
.hx-tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
</style>
