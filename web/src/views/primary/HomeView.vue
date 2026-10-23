<script setup lang="ts">
// Accueil de l'espace « École primaire » : démarrage (préréglage de l'appel par demi-journée) et un raccourci par écran,
// selon les permissions du rôle actif.
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primary, type SetupStatus } from '@/api/primary';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import { useAction, usePermissions } from './usePri';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const router = useRouter();
const status = ref<SetupStatus | null>(null);

interface Tile {
  name: string;
  title: MessageKey;
  text: MessageKey;
  perms: readonly string[];
}
const tiles: Tile[] = [
  { name: 'pri-dashboard', title: 'pri.dash.title', text: 'pri.home.dashText', perms: P.dashboardRead },
  { name: 'pri-levels', title: 'pri.levels.title', text: 'pri.home.levelsText', perms: P.referentialRead },
  { name: 'pri-referential', title: 'pri.ref.title', text: 'pri.home.refText', perms: P.referentialRead },
  { name: 'pri-desk', title: 'pri.desk.title', text: 'pri.home.deskText', perms: P.learnerRead },
  { name: 'pri-call', title: 'pri.call.title', text: 'pri.home.callText', perms: P.attendance },
  { name: 'pri-sheet', title: 'pri.sheet.title', text: 'pri.home.sheetText', perms: P.competencyRead },
  { name: 'pri-conduct', title: 'pri.conduct.title', text: 'pri.home.conductText', perms: P.conductRead },
  { name: 'pri-journal', title: 'pri.journal.title', text: 'pri.home.journalText', perms: P.journalRead },
];
const visible = computed(() => tiles.filter((tile) => router.hasRoute(tile.name) && can(...tile.perms)));
const needsSetup = computed(() => status.value !== null && !status.value.ready);

async function load(): Promise<void> {
  status.value = (await run(() => primary.setup())) ?? null;
}

async function apply(): Promise<void> {
  const done = await run(() => primary.applySetup());
  if (done) status.value = done;
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.home.title') }}</h1>
      <p class="lede">{{ t('pri.home.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="status" class="card" aria-labelledby="pri-setup">
      <h2 id="pri-setup">{{ t('pri.setup.title') }}</h2>
      <p v-if="!needsSetup" class="muted">{{ t('pri.setup.ready') }}</p>
      <template v-else>
        <p>{{ t('pri.setup.intro') }}</p>
        <ul class="presets">
          <li v-for="p in status.presets" :key="p.key">
            <code>{{ p.key }}</code> : {{ t('pri.setup.current') }} <strong>{{ p.current ?? t('pri.setup.unset') }}</strong>, {{ t('pri.setup.recommended') }}
            <strong>{{ p.recommended }}</strong>
            <span class="tag" :class="p.ok ? 'tag--success' : 'tag--warning'">{{ p.ok ? t('pri.setup.ok') : t('pri.setup.todo') }}</span>
          </li>
        </ul>
        <button v-if="can(...P.settingsManage)" type="button" class="btn btn--primary" :disabled="busy" @click="apply">{{ t('pri.setup.apply') }}</button>
        <p v-else class="muted">{{ t('pri.setup.askDirector') }}</p>
      </template>
      <p class="muted">{{ t('pri.setup.absentToday', { count: status.absent_today }) }}</p>
    </section>

    <ul class="grid grid--tiles tiles" :aria-label="t('pri.home.shortcuts')">
      <li v-for="tile in visible" :key="tile.name">
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
.presets { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 8px; }
</style>
