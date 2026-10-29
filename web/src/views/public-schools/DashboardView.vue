<script setup lang="ts">
// Tableau de bord territorial (DOC-28, API-12) pour les rôles R01 à R07 et R19 : agrégats calculés chaque nuit, MASQUÉS sous le seuil
// de petits effectifs (aucun nom), indicateurs du CDC (parité, élèves par enseignant, réussite = admis ÷ présents, écart O1…) et
// descente vers les territoires enfants. Le point d'entrée suit l'école active ; sans école, saisir l'identifiant du territoire.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicDashboards, type TerritoryDashboard, type TerritoryNodeView } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const entry = ref<TerritoryNodeView[]>([]);
const children = ref<TerritoryNodeView[]>([]);
const territoryId = ref('');
const dashboard = ref<TerritoryDashboard | null>(null);
const label = (n: { label_fr: string; label_en: string }): string => (locale.value === 'en' ? n.label_en : n.label_fr);
const canIssue = computed(() => ctx.establishmentId !== null && ctx.can('documents.read'));

onMounted(async () => {
  if (ctx.establishmentId) {
    await run(async () => {
      entry.value = await publicDashboards.entry();
    });
  }
  territoryId.value = entry.value[0]?.id ?? '';
  if (territoryId.value) await open(territoryId.value);
});

async function open(id: string): Promise<void> {
  territoryId.value = id;
  await run(async () => {
    dashboard.value = await publicDashboards.show(id);
    children.value = await publicDashboards.children(id);
  });
}

async function issue(): Promise<void> {
  await run(async () => openDocument((await publicDashboards.document(territoryId.value, locale.value === 'en' ? 'en' : 'fr')).document.id));
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.dash.title') }}</h1>
      <p class="lede">{{ t('ps.dash.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card controls">
      <div v-if="entry.length > 0" class="actions">
        <button v-for="n in entry" :key="n.id" type="button" class="btn btn--small" :class="{ 'btn--primary': n.id === territoryId }" @click="open(n.id)">{{ label(n) }}</button>
      </div>
      <form class="controls" @submit.prevent="open(territoryId)">
        <div class="field">
          <label for="db-territory">{{ t('ps.dash.territoryId') }}</label>
          <input id="db-territory" v-model="territoryId" type="text" maxlength="40" required />
        </div>
        <button type="submit" class="btn" :disabled="busy">{{ t('ps.dash.show') }}</button>
      </form>
    </section>

    <section v-if="dashboard" class="card" aria-labelledby="db-head">
      <h2 id="db-head">{{ label(dashboard.territory) }}</h2>
      <p class="muted">
        {{ dashboard.reference_date ? t('ps.dash.asOf', { date: formatDate(dashboard.reference_date) }) : t('ps.dash.noData') }}
        · {{ t('ps.dash.masking', { n: dashboard.minimum_cell_size }) }}
      </p>
      <ul class="grid grid--tiles tiles">
        <li v-for="i in dashboard.derived" :key="i.code" class="card tile">
          <span class="muted">{{ label(i) }}</span>
          <strong>{{ i.masked ? t('ps.dash.masked') : i.value === null ? '—' : i.value }}</strong>
          <span class="muted small">{{ i.formula }}</span>
        </li>
      </ul>
      <button v-if="canIssue" type="button" class="btn" :disabled="busy" @click="issue">{{ t('ps.dash.doc28') }}</button>
    </section>

    <section v-if="children.length > 0" class="card" aria-labelledby="db-children">
      <h2 id="db-children">{{ t('ps.dash.children') }}</h2>
      <div class="actions">
        <button v-for="c in children" :key="c.id" type="button" class="btn btn--small" @click="open(c.id)">{{ label(c) }}</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.tiles { list-style: none; margin: 0; padding: 0; }
.tile { display: grid; gap: 4px; }
.small { font-size: 0.8rem; }
</style>
