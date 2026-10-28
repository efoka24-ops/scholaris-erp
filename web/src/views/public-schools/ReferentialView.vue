<script setup lang="ts">
// Référentiel national et paramètres (RG-TER-6) : disciplines et critères par niveau (lecture pour l'école), import du référentiel
// d'EXEMPLE et génération des unités d'apprentissage (niveau national seulement), et liste des paramètres en vigueur avec leur valeur
// de départ, leur statut (texte officiel, valeur du cahier des charges, à confirmer) et leur source. Aucune valeur n'est en dur.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicPrimary, type Frame, type LearningUnit, type SettingRow } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const settings = ref<SettingRow[]>([]);
const frames = ref<Frame[]>([]);
const units = ref<LearningUnit[]>([]);
const level = ref('CE1');
const subsystem = ref('francophone');
const yearLabel = ref('');
const trimesters = ref([
  { period_id: '', starts_on: '', ends_on: '' },
  { period_id: '', starts_on: '', ends_on: '' },
  { period_id: '', starts_on: '', ends_on: '' },
]);
const canManage = computed(() => ctx.can('public_primary.referential.manage'));
const statusLabel = (s: SettingRow['status']): string => t(s === 'official' ? 'pp.ref.official' : s === 'cdc_default' ? 'pp.ref.cdc' : 'pp.ref.toConfirm');
const show = (v: unknown): string => (typeof v === 'string' ? v : JSON.stringify(v));

onMounted(async () => {
  await run(async () => {
    settings.value = await publicPrimary.settings();
  });
});

async function loadLevel(): Promise<void> {
  await run(async () => {
    frames.value = await publicPrimary.frames(level.value);
    units.value = yearLabel.value ? await publicPrimary.units(yearLabel.value, level.value) : [];
  });
}

async function importExample(): Promise<void> {
  await run(async () => {
    await publicPrimary.importExample(subsystem.value, level.value);
    await loadLevel();
  });
}

async function generate(): Promise<void> {
  await run(async () => {
    units.value = await publicPrimary.generateUnits({ year_label: yearLabel.value, level_code: level.value, trimesters: trimesters.value.filter((x) => x.period_id !== '') });
  });
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.ref.title') }}</h1>
      <p class="lede">{{ t('pp.ref.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card controls">
      <div class="field"><label for="rf-level">{{ t('pp.ref.level') }}</label><input id="rf-level" v-model="level" type="text" maxlength="16" /></div>
      <div class="field"><label for="rf-year">{{ t('pp.apee.yearLabel') }}</label><input id="rf-year" v-model="yearLabel" type="text" pattern="\d{4}-\d{4}" /></div>
      <button type="button" class="btn" :disabled="busy" @click="loadLevel">{{ t('pp.ref.show') }}</button>
    </section>

    <section class="card" aria-labelledby="rf-frames">
      <h2 id="rf-frames">{{ t('pp.ref.frames') }}</h2>
      <p v-if="frames.length === 0" class="muted">{{ t('pp.ref.noFrames') }}</p>
      <div v-for="f in frames" :key="f.id">
        <h3>{{ locale === 'en' ? f.label_en : f.label_fr }} · v{{ f.version }}<span v-if="f.is_key_discipline" class="tag">{{ t('pp.ref.key') }}</span></h3>
        <ul><li v-for="c in f.criteria" :key="c.id">{{ c.code }} {{ locale === 'en' ? c.label_en : c.label_fr }}</li></ul>
      </div>
      <template v-if="canManage">
        <div class="field">
          <label for="rf-sub">{{ t('pp.ref.subsystem') }}</label>
          <select id="rf-sub" v-model="subsystem"><option value="francophone">{{ t('pp.ref.francophone') }}</option><option value="anglophone">{{ t('pp.ref.anglophone') }}</option></select>
        </div>
        <button type="button" class="btn" :disabled="busy" @click="importExample">{{ t('pp.ref.importExample') }}</button>
      </template>
    </section>

    <section class="card" aria-labelledby="rf-units">
      <h2 id="rf-units">{{ t('pp.ref.units') }}</h2>
      <ul><li v-for="u in units" :key="u.id">{{ t('pp.common.unitLabel', { n: u.unit_no }) }} · {{ formatDate(u.starts_on) }} – {{ formatDate(u.ends_on) }} · {{ t('pp.ref.evalWeek') }} {{ formatDate(u.evaluation_week_from) }}<span v-if="u.is_final_integration"> · {{ t('pp.ref.final') }}</span></li></ul>
      <form v-if="canManage" class="controls" @submit.prevent="generate">
        <fieldset v-for="(tr, i) in trimesters" :key="i" class="line">
          <legend>{{ t('pp.cards.trimester', { n: i + 1 }) }}</legend>
          <div class="field"><label :for="`tp-${i}`">{{ t('pp.ref.periodId') }}</label><input :id="`tp-${i}`" v-model="tr.period_id" type="text" /></div>
          <div class="field"><label :for="`ts-${i}`">{{ t('pp.ref.from') }}</label><input :id="`ts-${i}`" v-model="tr.starts_on" type="date" /></div>
          <div class="field"><label :for="`te-${i}`">{{ t('pp.ref.to') }}</label><input :id="`te-${i}`" v-model="tr.ends_on" type="date" /></div>
        </fieldset>
        <button type="submit" class="btn btn--primary" :disabled="busy || !yearLabel">{{ t('pp.ref.generate') }}</button>
      </form>
    </section>

    <section class="card" aria-labelledby="rf-settings">
      <h2 id="rf-settings">{{ t('pp.ref.settings') }}</h2>
      <div class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('pp.ref.settings') }}</caption>
          <thead><tr><th scope="col">{{ t('pp.ref.param') }}</th><th scope="col">{{ t('pp.ref.value') }}</th><th scope="col">{{ t('pp.ref.status') }}</th><th scope="col">{{ t('pp.ref.source') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in settings" :key="s.key">
              <th scope="row"><code>{{ s.key }}</code></th>
              <td><code>{{ show(s.value) }}</code></td>
              <td><span class="tag" :class="s.status === 'to_confirm' ? 'tag--warning' : ''">{{ statusLabel(s.status) }}</span></td>
              <td class="muted">{{ s.source }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.line { border: 1px solid var(--input-border); padding: 8px; display: grid; gap: 6px; }
</style>
