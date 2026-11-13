<script setup lang="ts">
// Référentiel national et paramètres (RG-TER-6) : disciplines et critères par niveau (lecture pour l'école), import du référentiel
// d'EXEMPLE et génération des unités d'apprentissage (niveau national seulement), et liste des paramètres en vigueur avec leur valeur
// de départ, leur statut (texte officiel, valeur du cahier des charges, à confirmer) et leur source. Aucune valeur n'est en dur.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listPeriods } from '@/api/admin';
import { publicPrimary, type Frame, type LearningUnit, type SettingRow } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';
import { settingLabel, settingValue } from './settingsDisplay';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

interface TrimesterLine {
  period_id: string;
  ordinal: number;
  included: boolean;
  starts_on: string;
  ends_on: string;
}

const settings = ref<SettingRow[]>([]);
const frames = ref<Frame[]>([]);
const units = ref<LearningUnit[]>([]);
const level = ref('CE1');
const subsystem = ref('francophone');
const yearLabel = ref('');
// Trimestres de l'année en cours (Organisation), choisis par leur nom ; jamais d'identifiant technique à saisir.
const trimesters = ref<TrimesterLine[]>([]);
// Début de l'année en cours : le référentiel importé en cours d'année vaut dès la rentrée, sinon les unités déjà passées restent sans discipline.
const yearStart = ref('');
// Le directeur (R09) règle le référentiel de son école si le paramètre national le permet (RG-TER-6) ; le serveur reste juge.
const schoolMayManage = computed(() => settings.value.find((s) => s.key === 'public_primary.referential.school_may_manage')?.value !== false);
const nationalManager = computed(() => ctx.can('public_primary.referential.manage'));
const schoolManager = computed(() => ctx.can('public_primary.referential.school_manage'));
const canManage = computed(() => nationalManager.value || (schoolManager.value && schoolMayManage.value));
const nationalOnly = computed(() => !nationalManager.value && schoolManager.value && !schoolMayManage.value);
const chosen = computed(() => trimesters.value.filter((x) => x.included));
const statusLabel = (s: SettingRow['status']): string => t(s === 'official' ? 'pp.ref.official' : s === 'cdc_default' ? 'pp.ref.cdc' : 'pp.ref.toConfirm');

onMounted(async () => {
  await run(async () => {
    settings.value = await publicPrimary.settings();
    if (!canManage.value) return;
    const year = await currentYear();
    if (!year) return;
    if (!yearLabel.value) yearLabel.value = year.label;
    yearStart.value = year.starts_on.slice(0, 10);
    const periods = (await listPeriods(year.id)).filter((p) => p.kind === 'trimester').sort((a, b) => a.ordinal - b.ordinal);
    trimesters.value = periods.map((p) => ({ period_id: p.id, ordinal: p.ordinal, included: true, starts_on: p.starts_on.slice(0, 10), ends_on: p.ends_on.slice(0, 10) }));
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
    await publicPrimary.importExample(subsystem.value, level.value, yearStart.value || undefined);
    await loadLevel();
  });
}

async function generate(): Promise<void> {
  await run(async () => {
    units.value = await publicPrimary.generateUnits({
      year_label: yearLabel.value,
      level_code: level.value,
      trimesters: chosen.value.map((x) => ({ period_id: x.period_id, starts_on: x.starts_on, ends_on: x.ends_on })),
    });
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
        <p v-if="trimesters.length === 0" class="muted">{{ t('pp.ref.noTrimesters') }}</p>
        <fieldset v-for="(tr, i) in trimesters" :key="tr.period_id" class="line">
          <legend>{{ t('pp.cards.trimester', { n: tr.ordinal }) }}</legend>
          <label class="check"><input :id="`tp-${i}`" v-model="tr.included" type="checkbox" />{{ t('pp.ref.include', { n: tr.ordinal }) }}</label>
          <div class="field"><label :for="`ts-${i}`">{{ t('pp.ref.from') }}</label><input :id="`ts-${i}`" v-model="tr.starts_on" type="date" :disabled="!tr.included" /></div>
          <div class="field"><label :for="`te-${i}`">{{ t('pp.ref.to') }}</label><input :id="`te-${i}`" v-model="tr.ends_on" type="date" :disabled="!tr.included" /></div>
        </fieldset>
        <button type="submit" class="btn btn--primary" :disabled="busy || !yearLabel || chosen.length === 0">{{ t('pp.ref.generate') }}</button>
      </form>
      <p v-else-if="nationalOnly" class="notice" role="note">{{ t('pp.ref.nationalOnly') }}</p>
    </section>

    <section class="card" aria-labelledby="rf-settings">
      <h2 id="rf-settings">{{ t('pp.ref.settings') }}</h2>
      <!-- FIX-R32 : quatre colonnes de largeur fixe et valeurs qui passent à la ligne (lisible à 1280 px sans défilement) ;
           sur un écran étroit, chaque paramètre devient une carte dont les cellules rappellent l'intitulé de leur colonne. -->
      <div class="table-wrap rf-settings-wrap">
        <table class="rf-settings">
          <caption class="sr-only">{{ t('pp.ref.settings') }}</caption>
          <colgroup><col class="rf-c-param" /><col class="rf-c-value" /><col class="rf-c-status" /><col class="rf-c-source" /></colgroup>
          <thead><tr><th scope="col">{{ t('pp.ref.param') }}</th><th scope="col">{{ t('pp.ref.value') }}</th><th scope="col">{{ t('pp.ref.status') }}</th><th scope="col">{{ t('pp.ref.source') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in settings" :key="s.key">
              <th scope="row">{{ settingLabel(s.key, locale) }}</th>
              <td :data-label="t('pp.ref.value')"><span v-for="(line, i) in settingValue(s.key, s.value, locale)" :key="i" class="rf-line">{{ line }}</span></td>
              <td :data-label="t('pp.ref.status')"><span class="tag" :class="s.status === 'to_confirm' ? 'tag--warning' : ''">{{ statusLabel(s.status) }}</span></td>
              <td :data-label="t('pp.ref.source')" class="muted">{{ s.source }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.rf-line { display: block; }
/* FIX-R32 : le style global des tableaux interdit le retour à la ligne ; ici les valeurs et la source (texte long) passent à la ligne. */
.rf-settings-wrap { overflow-x: visible; }
.rf-settings { table-layout: fixed; }
.rf-settings th, .rf-settings td { white-space: normal; overflow-wrap: anywhere; vertical-align: top; }
.rf-settings .tag { white-space: normal; }
.rf-c-param { width: 26%; }
.rf-c-value { width: 34%; }
.rf-c-status { width: 15%; }
.rf-c-source { width: 25%; }
@media (max-width: 720px) {
  .rf-settings, .rf-settings tbody, .rf-settings tr, .rf-settings th, .rf-settings td { display: block; width: auto; }
  .rf-settings colgroup, .rf-settings thead { display: none; }
  .rf-settings tr { border-bottom: 2px solid var(--border); padding: 8px 0; }
  .rf-settings tbody th, .rf-settings td { border-bottom: 0; padding: 4px 12px; }
  .rf-settings td::before { content: attr(data-label); display: block; font-size: 12px; font-weight: 700; color: var(--muted); }
}
.controls { display: grid; gap: 8px; }
.line { border: 1px solid var(--input-border); padding: 8px; display: grid; gap: 6px; }
.check { display: inline-flex; gap: 6px; align-items: center; }
</style>
