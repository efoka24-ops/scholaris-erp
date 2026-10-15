<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useContextStore } from '@/stores/context';
import { G } from './perms';
import { useGradesI18n, type HigherGradesKey } from './i18n';

// Sous-navigation de la rubrique « Notes » (HigherFrame n'a pas de section dédiée) : liens filtrés par route et permission.
const { tg } = useGradesI18n();
const ctx = useContextStore();
const router = useRouter();

const LINKS: Array<{ name: string; label: HigherGradesKey; perms: readonly string[] }> = [
  { name: 'higher-grades', label: 'hg.nav.assessments', perms: G.read },
  { name: 'higher-grades-progress', label: 'hg.nav.progress', perms: G.progress },
  { name: 'higher-grades-corrections', label: 'hg.nav.corrections', perms: G.corrections },
  { name: 'higher-grades-queries', label: 'hg.nav.queries', perms: G.queries },
];
const links = computed(() => LINKS.filter((l) => router.hasRoute(l.name) && ctx.can(...l.perms)));
</script>

<template>
  <nav v-if="links.length > 1" class="hg-nav" :aria-label="tg('nav.higherGrades')">
    <router-link v-for="l in links" :key="l.name" :to="{ name: l.name }" class="hg-nav__link">{{ tg(l.label) }}</router-link>
  </nav>
</template>

<style scoped>
.hg-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}
.hg-nav__link {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  color: var(--ink);
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
}
.hg-nav__link.router-link-exact-active {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
</style>
