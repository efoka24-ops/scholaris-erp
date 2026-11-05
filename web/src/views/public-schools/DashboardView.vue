<script setup lang="ts">
// Tableau de bord territorial (DOC-28, API-12) pour les rôles R01 à R07 et R19 : agrégats calculés chaque nuit, MASQUÉS sous le seuil
// de petits effectifs (aucun nom), indicateurs du CDC (parité, élèves par enseignant, réussite = admis ÷ présents, écart O1…) et
// descente vers les territoires enfants. Le point d'entrée suit l'école active ; sans école (rôle national), on choisit dans la liste des
// régions, départements et arrondissements (jamais un identifiant à saisir). La direction des examens (R02) a ici son entrée vers la
// consolidation des examens.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicDashboards, type TerritoryDashboard, type TerritoryNodeView } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { P } from './access';
import { useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const entry = ref<TerritoryNodeView[]>([]);
const catalogue = ref<TerritoryNodeView[]>([]);
const regionId = ref('');
const departmentId = ref('');
const subdivisionId = ref('');
const children = ref<TerritoryNodeView[]>([]);
const territoryId = ref('');
const dashboard = ref<TerritoryDashboard | null>(null);
const label = (n: { label_fr: string; label_en: string }): string => (locale.value === 'en' ? n.label_en : n.label_fr);
const canIssue = computed(() => ctx.establishmentId !== null && ctx.can('documents.read'));
const canExams = computed(() => ctx.can(...P.examsConsolidation));
const regions = computed(() => catalogue.value.filter((n) => n.type === 'region'));
const departments = computed(() => catalogue.value.filter((n) => n.type === 'departement' && n.parent_id === regionId.value));
const subdivisions = computed(() => catalogue.value.filter((n) => n.type === 'arrondissement' && n.parent_id === departmentId.value));
const chosen = computed(() => subdivisionId.value || departmentId.value || regionId.value);

onMounted(async () => {
  await run(async () => {
    if (ctx.establishmentId) entry.value = await publicDashboards.entry();
    if (entry.value.length === 0) catalogue.value = await publicDashboards.territories();
  });
  territoryId.value = entry.value[0]?.id ?? '';
  if (territoryId.value) await open(territoryId.value);
});

function pickRegion(): void {
  departmentId.value = '';
  subdivisionId.value = '';
}
function pickDepartment(): void {
  subdivisionId.value = '';
}

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

    <p v-if="canExams" class="card">
      <router-link :to="{ name: 'pp-exams-delegation' }">{{ t('ps.dash.examsEntry') }}</router-link>
    </p>

    <section class="card controls">
      <div v-if="entry.length > 0" class="actions">
        <button v-for="n in entry" :key="n.id" type="button" class="btn btn--small" :class="{ 'btn--primary': n.id === territoryId }" @click="open(n.id)">{{ label(n) }}</button>
      </div>
      <form v-else-if="catalogue.length > 0" class="controls" @submit.prevent="open(chosen)">
        <div class="field">
          <label for="db-region">{{ t('ps.dash.region') }}</label>
          <select id="db-region" v-model="regionId" @change="pickRegion">
            <option value="">{{ t('ps.dash.anyOption') }}</option>
            <option v-for="n in regions" :key="n.id" :value="n.id">{{ label(n) }}</option>
          </select>
        </div>
        <div v-if="regionId" class="field">
          <label for="db-department">{{ t('ps.dash.department') }}</label>
          <select id="db-department" v-model="departmentId" @change="pickDepartment">
            <option value="">{{ t('ps.dash.anyOption') }}</option>
            <option v-for="n in departments" :key="n.id" :value="n.id">{{ label(n) }}</option>
          </select>
        </div>
        <div v-if="departmentId" class="field">
          <label for="db-subdivision">{{ t('ps.dash.subdivision') }}</label>
          <select id="db-subdivision" v-model="subdivisionId">
            <option value="">{{ t('ps.dash.anyOption') }}</option>
            <option v-for="n in subdivisions" :key="n.id" :value="n.id">{{ label(n) }}</option>
          </select>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy || chosen === ''">{{ t('ps.dash.show') }}</button>
      </form>
      <p v-else class="muted">{{ t('ps.dash.noTerritory') }}</p>
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
