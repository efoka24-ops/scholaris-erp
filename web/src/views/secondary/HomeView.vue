<script setup lang="ts">
// Accueil de l'espace « Enseignement secondaire » (T837) : tuiles selon les modules actifs du profil et les permissions du rôle,
// vocabulaire du chef d'établissement (proviseur ou principal) lu sur le profil, rappel si l'application n'est pas encore ouverte.
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryProfile, type SecondaryProfile } from '@/api/secondaryProfile';
import { useI18n } from '@/i18n';
import { S } from './access';
import { headTitleKey, visibleEntries } from './menu';
import { useAction, usePermissions } from './useSec';

const { t } = useI18n();
const { can } = usePermissions();
const { error, run } = useAction();
const router = useRouter();
const profile = ref<SecondaryProfile | null>(null);
const loaded = ref(false);

const tiles = computed(() => visibleEntries(profile.value, can, (name) => router.hasRoute(name)));
const notOpened = computed(() => profile.value !== null && !profile.value.stored);

onMounted(async () => {
  if (can(...S.profileRead)) profile.value = (await run(() => secondaryProfile.get())) ?? null;
  loaded.value = true;
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sec.home.title') }}</h1>
      <p class="lede">{{ t('sec.home.intro') }}</p>
      <p v-if="profile" class="muted">{{ t('sec.home.headIs', { title: t(headTitleKey(profile)) }) }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="notOpened" class="notice" role="status">
      <p><strong>{{ t('sec.home.notOpened') }}</strong></p>
      <router-link v-if="router.hasRoute('sec-profile')" class="btn btn--primary" :to="{ name: 'sec-profile' }">{{ t('sec.home.openProfile') }}</router-link>
    </section>

    <p v-if="loaded && tiles.length === 0" class="muted">{{ t('sec.home.nothing') }}</p>
    <ul class="grid grid--tiles tiles" :aria-label="t('sec.home.shortcuts')">
      <li v-for="tile in tiles" :key="tile.name">
        <router-link class="card tile" :to="{ name: tile.name }">
          <strong>{{ t(tile.title) }}</strong>
          <span class="muted">{{ t(tile.text) }}</span>
        </router-link>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.tiles { list-style: none; margin: 0; padding: 0; }
.tile { display: grid; gap: 4px; height: 100%; color: inherit; text-decoration: none; min-height: 5rem; }
.tile:hover, .tile:focus-visible { border-color: var(--ink); }
</style>
