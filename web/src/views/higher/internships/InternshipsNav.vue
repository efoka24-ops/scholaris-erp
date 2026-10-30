<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useContextStore } from '@/stores/context';
import { S } from './perms';
import { useInternshipsI18n, type HigherInternshipsKey } from './i18n';

// Sous-navigation de la rubrique « Stages » (HigherFrame n'a pas de section dédiée) : liens filtrés par route et permission.
const { ts } = useInternshipsI18n();
const ctx = useContextStore();
const router = useRouter();

const LINKS: Array<{ name: string; label: HigherInternshipsKey; perms: readonly string[] }> = [
  { name: 'higher-internships', label: 'hs.nav.conventions', perms: S.read },
  { name: 'higher-internship-companies', label: 'hs.nav.companies', perms: S.read },
  { name: 'higher-defenses', label: 'hs.nav.defenses', perms: S.jury },
];
const links = computed(() => LINKS.filter((l) => router.hasRoute(l.name) && ctx.can(...l.perms)));
</script>

<template>
  <nav v-if="links.length > 1" class="hs-nav" :aria-label="ts('nav.higherInternships')">
    <router-link v-for="l in links" :key="l.name" :to="{ name: l.name }" class="hs-nav__link">{{ ts(l.label) }}</router-link>
  </nav>
</template>

<style scoped>
.hs-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}
.hs-nav__link {
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
.hs-nav__link.router-link-exact-active {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
</style>
