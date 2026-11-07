<script setup lang="ts">
// Tableau de bord de la direction et du promoteur : effectifs, absences et arrivées du jour, retards de récupération, impayés,
// observations en retard, présences aux services. Agrégats seulement : aucun nom d'enfant n'est rendu par le serveur.
import { onMounted, ref } from 'vue';
import { nursery, type Dashboard } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import { today, useAction } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const date = ref(today());
const board = ref<Dashboard | null>(null);

async function load(): Promise<void> {
  board.value = (await run(() => nursery.dashboard(date.value))) ?? null;
}
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.dash.title') }}</h1>
      <p class="lede">{{ t('nur.dash.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <form class="card row" @submit.prevent="load">
      <div class="field"><label for="db-date">{{ t('nur.register.date') }}</label><input id="db-date" v-model="date" type="date" required /></div>
      <button type="submit" class="btn" :disabled="busy">{{ t('nur.register.show') }}</button>
    </form>

    <template v-if="board">
      <section class="tiles" :aria-label="t('nur.dash.title')">
        <div class="card tile"><p class="muted">{{ t('nur.dash.enrolled') }}</p><p class="value">{{ board.totals.enrolled }}</p></div>
        <div class="card tile"><p class="muted">{{ t('nur.dash.arrived') }}</p><p class="value">{{ board.totals.arrived }}</p></div>
        <div class="card tile"><p class="muted">{{ t('nur.dash.absent') }}</p><p class="value">{{ board.totals.absent }}</p></div>
        <div class="card tile" :class="{ alert: board.late_pickups.today.parent > 0 }">
          <p class="muted">{{ t('nur.dash.late') }}</p>
          <p class="value">{{ board.late_pickups.today.parent }}</p>
          <p class="muted">{{ t('nur.dash.lateMonth', { count: board.late_pickups.month.parent, director: board.late_pickups.month.director }) }}</p>
        </div>
        <div class="card tile"><p class="muted">{{ t('nur.dash.arrears') }}</p><p class="value">{{ board.totals.in_arrears }}</p><p class="muted">{{ formatXaf(board.totals.balance_xaf) }}</p></div>
        <div class="card tile" :class="{ alert: board.totals.observation_gaps > 0 }"><p class="muted">{{ t('nur.dash.gaps') }}</p><p class="value">{{ board.totals.observation_gaps }}</p></div>
        <div class="card tile"><p class="muted">{{ t('nur.dash.services') }}</p><p class="value">{{ board.services_present_today }}</p></div>
      </section>
      <section class="card" aria-labelledby="db-sec">
        <h2 id="db-sec">{{ t('nur.home.sections') }}</h2>
        <div class="scroll">
          <table class="table">
            <thead><tr><th scope="col">{{ t('nur.dash.section') }}</th><th scope="col">{{ t('nur.dash.enrolled') }}</th><th scope="col">{{ t('nur.dash.arrived') }}</th><th scope="col">{{ t('nur.dash.absent') }}</th><th scope="col">{{ t('nur.dash.arrears') }}</th><th scope="col">{{ t('nur.dash.gaps') }}</th></tr></thead>
            <tbody>
              <tr v-for="s in board.sections" :key="s.id">
                <th scope="row">{{ s.label }} <span v-if="s.over_capacity" class="tag tag--warn">{{ t('nur.dash.over', { capacity: s.capacity ?? 0 }) }}</span></th>
                <td>{{ s.enrolled }}<span v-if="s.capacity"> / {{ s.capacity }}</span></td>
                <td>{{ s.arrived }}</td>
                <td>{{ s.absent }}</td>
                <td>{{ s.in_arrears }}</td>
                <td>{{ s.observation_gaps }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
.tile .value { font-size: 1.8rem; font-weight: 700; margin: 4px 0; }
.tile.alert { border-color: var(--warn, #b45309); }
.scroll { overflow-x: auto; }
</style>
