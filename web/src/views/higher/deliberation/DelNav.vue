<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useContextStore } from '@/stores/context';
import { DP } from './access';
import { dt, type DelKey } from './i18n';

// Onglets de la rubrique Délibérations (liens visibles selon les permissions).
const ctx = useContextStore();
const router = useRouter();
const TABS: Array<{ name: string; label: DelKey; perms: readonly string[] }> = [
  { name: 'higher-del-juries', label: 'hdl.tab.juries', perms: DP.read },
  { name: 'higher-del-years', label: 'hdl.tab.years', perms: DP.read },
  { name: 'higher-del-transcripts', label: 'hdl.tab.transcripts', perms: DP.read },
  { name: 'higher-del-portal', label: 'hdl.tab.portal', perms: DP.portal },
];
const tabs = computed(() => TABS.filter((tab) => router.hasRoute(tab.name) && ctx.can(...tab.perms)));
</script>

<template>
  <nav v-if="tabs.length > 1" class="hi-tabs" :aria-label="dt('hdl.eyebrow')">
    <router-link v-for="tab in tabs" :key="tab.name" :to="{ name: tab.name }" class="hdl-tab" active-class="hdl-tab--on">{{ dt(tab.label) }}</router-link>
  </nav>
</template>
