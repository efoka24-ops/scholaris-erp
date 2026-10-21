<script setup lang="ts">
// Accueil de l'espace « École publique » : un raccourci par écran, selon les permissions du rôle actif.
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { P } from './access';

const { t } = useI18n();
const ctx = useContextStore();

interface Tile {
  name: string;
  title: MessageKey;
  text: MessageKey;
  perms: readonly string[];
}
const tiles: Tile[] = [
  { name: 'ps-call', title: 'ps.call.title', text: 'ps.home.callText', perms: P.attendanceWrite },
  { name: 'ps-classes', title: 'ps.classes.title', text: 'ps.home.classesText', perms: P.pupilsRead },
  { name: 'ps-pupils', title: 'ps.pupils.title', text: 'ps.home.pupilsText', perms: P.pupilsRead },
  { name: 'ps-transfers', title: 'ps.transfers.title', text: 'ps.home.transfersText', perms: P.pupilsWrite },
  { name: 'ps-dropouts', title: 'ps.dropouts.title', text: 'ps.home.dropoutsText', perms: P.pupilsWrite },
  { name: 'ps-presence', title: 'ps.presence.title', text: 'ps.home.presenceText', perms: P.presenceRead },
  { name: 'ps-staff', title: 'ps.staff.title', text: 'ps.home.staffText', perms: P.staffRead },
  { name: 'ps-messages', title: 'ps.messages.title', text: 'ps.home.messagesText', perms: P.messagesSend },
  { name: 'ps-school', title: 'ps.school.title', text: 'ps.home.schoolText', perms: P.schoolRead },
  { name: 'ps-device', title: 'ps.device.title', text: 'ps.home.deviceText', perms: P.attendanceWrite },
];
const visible = computed(() => tiles.filter((tile) => ctx.can(...tile.perms)));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.home.title') }}</h1>
      <p class="lede">{{ t('ps.home.intro') }}</p>
    </header>
    <ul class="grid grid--tiles tiles" :aria-label="t('ps.home.shortcuts')">
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
</style>
