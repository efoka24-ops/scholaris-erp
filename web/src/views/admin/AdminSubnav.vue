<script setup lang="ts">
import { computed } from 'vue';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';

// Barre de sections de l'administration : n'affiche que ce que la personne peut ouvrir dans son contexte.
const { t } = useI18n();
const ctx = useContextStore();

const sections: Array<{ name: string; label: MessageKey; perms: string[]; needsEstablishment: boolean }> = [
  { name: 'admin', label: 'admin.nav.home', perms: [], needsEstablishment: false },
  { name: 'admin-establishments', label: 'admin.nav.establishments', perms: ['organisation.manage'], needsEstablishment: false },
  { name: 'admin-years', label: 'admin.nav.years', perms: ['organisation.manage'], needsEstablishment: true },
  { name: 'admin-structures', label: 'admin.nav.structures', perms: ['organisation.manage'], needsEstablishment: true },
  { name: 'admin-users', label: 'admin.nav.users', perms: ['identity.manage', 'identity.roles.assign'], needsEstablishment: true },
  { name: 'admin-import', label: 'imports.nav', perms: ['imports.people.manage'], needsEstablishment: true },
  { name: 'admin-workflows', label: 'wf.nav', perms: ['workflows.manage'], needsEstablishment: true },
  { name: 'admin-settings', label: 'admin.nav.settings', perms: ['settings.manage'], needsEstablishment: true },
];

const visible = computed(() =>
  sections.filter((s) => (s.perms.length === 0 || ctx.can(...s.perms)) && (!s.needsEstablishment || !!ctx.establishmentId)),
);
</script>

<template>
  <nav class="subnav" :aria-label="t('admin.nav.label')">
    <router-link v-for="s in visible" :key="s.name" class="subnav__link" :to="{ name: s.name }">{{ t(s.label) }}</router-link>
  </nav>
</template>

<style scoped>
.subnav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 24px;
}
.subnav__link {
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
.subnav__link.router-link-exact-active {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
</style>
