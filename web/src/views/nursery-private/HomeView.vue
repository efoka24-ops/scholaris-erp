<script setup lang="ts">
// Accueil de l'espace « Maternelle privée » : un raccourci par écran selon les permissions du rôle actif (aucun lien vers une maternelle publique).
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { nursery, type Section } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import { useAction, usePermissions } from './useNur';

const { t, tp } = useI18n();
const { can } = usePermissions();
const router = useRouter();
const { error, run } = useAction();
const sections = ref<Section[]>([]);

interface Tile {
  name: string;
  title: MessageKey;
  text: MessageKey;
  perms: readonly string[];
}
const tiles: Tile[] = [
  { name: 'nur-pickup', title: 'nur.pickup.title', text: 'nur.home.pickupText', perms: P.handover },
  { name: 'nur-call', title: 'nur.call.title', text: 'nur.home.callText', perms: P.call },
  { name: 'nur-register', title: 'nur.register.title', text: 'nur.home.registerText', perms: P.register },
  { name: 'nur-observe', title: 'nur.observe.title', text: 'nur.home.observeText', perms: P.observe },
  { name: 'nur-books', title: 'nur.books.title', text: 'nur.home.booksText', perms: P.devRead },
  { name: 'nur-lifebook', title: 'nur.life.title', text: 'nur.home.lifeText', perms: P.lifebook },
  { name: 'nur-needs', title: 'nur.needs.title', text: 'nur.home.needsText', perms: P.needs },
  { name: 'nur-authorized', title: 'nur.auth.title', text: 'nur.home.authText', perms: P.authorized },
  { name: 'nur-enrol', title: 'nur.enrol.title', text: 'nur.home.enrolText', perms: P.enrol },
  { name: 'nur-fees', title: 'nur.fees.title', text: 'nur.home.feesText', perms: P.fees },
  { name: 'nur-messages', title: 'nur.msg.title', text: 'nur.home.msgText', perms: P.messages },
  { name: 'nur-sections', title: 'nur.section.title', text: 'nur.home.sectionsText', perms: P.sections },
  { name: 'nur-dashboard', title: 'nur.dash.title', text: 'nur.home.dashText', perms: P.dashboard },
  { name: 'nur-frames', title: 'nur.frames.title', text: 'nur.home.framesText', perms: P.frames },
  { name: 'nur-consent', title: 'nur.consent.title', text: 'nur.home.consentText', perms: P.consent },
  { name: 'nur-transition', title: 'nur.trans.title', text: 'nur.home.transText', perms: P.transition },
  { name: 'nur-services', title: 'nur.svc.title', text: 'nur.home.svcText', perms: P.services },
];
const visible = computed(() => tiles.filter((tile) => router.hasRoute(tile.name) && can(...tile.perms)));

onMounted(async () => {
  sections.value = (await run(() => nursery.sections())) ?? [];
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.home.title') }}</h1>
      <p class="lede">{{ t('nur.home.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="sections.length > 0" class="card" aria-labelledby="nur-sec-h">
      <h2 id="nur-sec-h">{{ t('nur.home.sections') }}</h2>
      <ul class="plain">
        <li v-for="s in sections" :key="s.id">
          <strong>{{ s.label }}</strong> : {{ tp('nur.home.enrolled', s.enrolled) }}<span v-if="s.capacity"> / {{ s.capacity }}</span>
        </li>
      </ul>
    </section>

    <ul class="grid grid--tiles tiles" :aria-label="t('nur.home.shortcuts')">
      <li v-for="tile in visible" :key="tile.name">
        <router-link class="card tile" :to="{ name: tile.name }">
          <strong>{{ t(tile.title) }}</strong>
          <span class="muted">{{ t(tile.text) }}</span>
        </router-link>
      </li>
    </ul>
    <p v-if="visible.length === 0" class="muted">{{ t('nur.home.none') }}</p>
  </div>
</template>

<style scoped>
.plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.tiles { list-style: none; padding: 0; }
.tile { display: grid; gap: 4px; text-decoration: none; min-height: 64px; }
</style>
