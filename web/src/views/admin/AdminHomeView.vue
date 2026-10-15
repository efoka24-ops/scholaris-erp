<script setup lang="ts">
import { computed } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';

// Tableau d'accueil « Administration » — design : Fondations.html (cartes, boutons) et Main.html (lanceur).
const { t } = useI18n();
const ctx = useContextStore();

const hasEstablishment = computed(() => !!ctx.establishmentId);
const firstRun = computed(() => ctx.tiles.length === 0);

const cards: Array<{ name: string; title: AdminKey; text: AdminKey; perms: string[]; needsEstablishment: boolean }> = [
  { name: 'admin-establishments', title: 'admin.nav.establishments', text: 'admin.home.establishmentsText', perms: ['organisation.manage'], needsEstablishment: false },
  { name: 'admin-years', title: 'admin.nav.years', text: 'admin.home.yearsText', perms: ['organisation.manage'], needsEstablishment: true },
  { name: 'admin-structures', title: 'admin.nav.structures', text: 'admin.home.structuresText', perms: ['organisation.manage'], needsEstablishment: true },
  { name: 'admin-users', title: 'admin.nav.users', text: 'admin.home.usersText', perms: ['identity.manage', 'identity.roles.assign'], needsEstablishment: true },
  { name: 'admin-settings', title: 'admin.nav.settings', text: 'admin.home.settingsText', perms: ['settings.manage'], needsEstablishment: true },
];
const visible = computed(() => cards.filter((c) => ctx.can(...c.perms)));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.title') }}</h1>
      <p class="lede">{{ hasEstablishment ? t('admin.home.intro', { name: ctx.activeTile?.name ?? '' }) : t('admin.home.introNone') }}</p>
    </header>

    <AdminSubnav />

    <section v-if="firstRun && ctx.can('organisation.manage')" class="card card--strong first" aria-labelledby="first-title">
      <h2 id="first-title">{{ t('admin.home.firstTitle') }}</h2>
      <p>{{ t('admin.home.firstText') }}</p>
      <router-link class="btn btn--primary btn--large" :to="{ name: 'admin-establishments', query: { nouveau: '1' } }">{{ t('admin.home.firstCta') }}</router-link>
    </section>
    <p v-else-if="!hasEstablishment" class="notice notice--warning">{{ t('admin.home.chooseEstablishment') }}</p>

    <ul class="grid grid--tiles cards">
      <li v-for="c in visible" :key="c.name">
        <article class="card tile">
          <h2>{{ t(c.title) }}</h2>
          <p class="muted">{{ t(c.text) }}</p>
          <p v-if="c.needsEstablishment && !hasEstablishment" class="muted small">{{ t('admin.home.needsEstablishment') }}</p>
          <router-link v-else class="btn" :to="{ name: c.name }" :aria-label="t('admin.home.open', { name: t(c.title) })">{{ t('admin.open') }} →</router-link>
        </article>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.first {
  margin-bottom: 24px;
}
.first h2 {
  margin-top: 0;
}
.cards {
  list-style: none;
  padding: 0;
  margin: 0;
}
.tile {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.tile h2 {
  font-size: 20px;
  margin: 0;
}
.tile .btn {
  margin-top: auto;
  align-self: flex-start;
}
.small {
  font-size: 13px;
}
</style>
