<script setup lang="ts">
// Cantine et santé scolaire (CAN) : repas servis par jour et par classe, synthèse du mois, stocks reçus et consommés, campagnes de
// santé saisies en effectifs avec le nombre de consentements des parents (aucun enfant traité sans consentement, aucun nom, aucun
// dossier médical). Le directeur consulte ; le responsable de la cantine et l'agent de santé saisissent.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicCanteen, publicSchools, type ClassRow, type HealthCampaign, type MealSummary, type StockBalance, type StockMove } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const canWrite = computed(() => ctx.can('public_schools.canteen.write'));
const day = ref(todayIso());
const classes = ref<ClassRow[]>([]);
const served = ref<Record<string, number>>({});
const warnings = ref<string[]>([]);
const summary = ref<MealSummary | null>(null);
const moves = ref<StockMove[]>([]);
const balance = ref<StockBalance[]>([]);
const campaigns = ref<HealthCampaign[]>([]);
const done = ref('');
const move = ref({ item_label: '', unit_label: 'kg', direction: 'in' as 'in' | 'out', quantity: 1, moved_on: todayIso(), source_label: '' });
const campaign = ref({ campaign_kind: 'deworming', held_on: todayIso(), label: '', girls_count: 0, boys_count: 0, consents_count: 0, refusals_count: 0 });

const KINDS: Record<string, MessageKey> = {
  deworming: 'ps.can.deworming',
  vaccination: 'ps.can.vaccination',
  bednets: 'ps.can.bednets',
  vitamin_a: 'ps.can.vitaminA',
  other: 'ps.can.other',
};

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (year) classes.value = await publicSchools.classes(year.id);
  });
  await load();
});

async function load(): Promise<void> {
  await run(async () => {
    const meals = await publicCanteen.meals(day.value);
    served.value = Object.fromEntries(meals.map((m) => [m.structure_id, m.served_count]));
    summary.value = await publicCanteen.summary(day.value.slice(0, 7));
    moves.value = await publicCanteen.moves();
    balance.value = await publicCanteen.balance();
    campaigns.value = await publicCanteen.campaigns();
  });
}

async function saveMeals(): Promise<void> {
  const lines = classes.value
    .filter((c) => typeof served.value[c.structure_id] === 'number')
    .map((c) => ({ structure_id: c.structure_id, served_count: served.value[c.structure_id] ?? 0 }));
  if (lines.length === 0) return;
  const res = await run(() => publicCanteen.saveMeals(day.value, lines));
  if (res) {
    warnings.value = res.warnings;
    done.value = t('ps.can.saved');
    await load();
  }
}

async function addMove(): Promise<void> {
  const { source_label, ...rest } = move.value;
  if ((await run(() => publicCanteen.addMove({ ...rest, ...(source_label ? { source_label } : {}) }))) !== undefined) {
    done.value = t('ps.can.saved');
    await load();
  }
}

async function addCampaign(): Promise<void> {
  const { label, ...rest } = campaign.value;
  if ((await run(() => publicCanteen.addCampaign({ ...rest, ...(label ? { label } : {}) }))) !== undefined) {
    done.value = t('ps.can.saved');
    await load();
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.can.title') }}</h1>
      <p class="lede">{{ t('ps.can.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls" aria-labelledby="ca-meals">
      <h2 id="ca-meals">{{ t('ps.can.meals') }}</h2>
      <div class="field">
        <label for="ca-day">{{ t('pp.common.date') }}</label>
        <input id="ca-day" v-model="day" type="date" @change="load" />
      </div>
      <div v-for="c in classes" :key="c.structure_id" class="field">
        <label :for="`ca-${c.structure_id}`">{{ c.label }}</label>
        <input :id="`ca-${c.structure_id}`" v-model.number="served[c.structure_id]" type="number" min="0" inputmode="numeric" :disabled="!canWrite" />
      </div>
      <p v-for="w in warnings" :key="w" class="notice">{{ t('ps.can.aboveHeadcount') }}</p>
      <button v-if="canWrite" type="button" class="btn btn--primary" :disabled="busy" @click="saveMeals">{{ t('pp.common.save') }}</button>
      <p v-if="summary" class="muted">{{ t('ps.can.summary', { month: summary.month, days: summary.service_days, total: summary.served_total }) }}</p>
    </section>

    <section class="card controls" aria-labelledby="ca-stock">
      <h2 id="ca-stock">{{ t('ps.can.stock') }}</h2>
      <table class="table">
        <thead><tr><th>{{ t('ps.can.item') }}</th><th>{{ t('ps.can.received') }}</th><th>{{ t('ps.can.consumed') }}</th><th>{{ t('ps.can.balance') }}</th></tr></thead>
        <tbody>
          <tr v-for="b in balance" :key="b.item_label"><td>{{ b.item_label }} ({{ b.unit_label }})</td><td>{{ b.received }}</td><td>{{ b.consumed }}</td><td>{{ b.balance }}</td></tr>
        </tbody>
      </table>
      <p class="muted">{{ t('ps.can.moves', { n: moves.length }) }}</p>
      <form v-if="canWrite" class="controls" @submit.prevent="addMove">
        <div class="field"><label for="ca-item">{{ t('ps.can.item') }}</label><input id="ca-item" v-model="move.item_label" type="text" required maxlength="120" /></div>
        <div class="field"><label for="ca-unit">{{ t('ps.can.unit') }}</label><input id="ca-unit" v-model="move.unit_label" type="text" required maxlength="30" /></div>
        <div class="field">
          <label for="ca-dir">{{ t('ps.can.direction') }}</label>
          <select id="ca-dir" v-model="move.direction">
            <option value="in">{{ t('ps.can.in') }}</option>
            <option value="out">{{ t('ps.can.out') }}</option>
          </select>
        </div>
        <div class="field"><label for="ca-qty">{{ t('ps.can.quantity') }}</label><input id="ca-qty" v-model.number="move.quantity" type="number" min="1" required /></div>
        <div class="field"><label for="ca-date">{{ t('pp.common.date') }}</label><input id="ca-date" v-model="move.moved_on" type="date" required /></div>
        <div class="field"><label for="ca-src">{{ t('ps.can.source') }}</label><input id="ca-src" v-model="move.source_label" type="text" maxlength="160" /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.common.save') }}</button>
      </form>
    </section>

    <section class="card controls" aria-labelledby="ca-health">
      <h2 id="ca-health">{{ t('ps.can.health') }}</h2>
      <p class="muted">{{ t('ps.can.healthHelp') }}</p>
      <ul class="rows">
        <li v-for="c in campaigns" :key="c.id" class="row">
          {{ formatDate(c.held_on) }} · {{ t(KINDS[c.campaign_kind] ?? 'ps.can.other') }} · {{ t('ps.can.treated', { girls: c.girls_count, boys: c.boys_count, consents: c.consents_count }) }}
        </li>
      </ul>
      <form v-if="canWrite" class="controls" @submit.prevent="addCampaign">
        <div class="field">
          <label for="ca-kind">{{ t('ps.can.kind') }}</label>
          <select id="ca-kind" v-model="campaign.campaign_kind">
            <option v-for="(key, code) in KINDS" :key="code" :value="code">{{ t(key) }}</option>
          </select>
        </div>
        <div class="field"><label for="ca-held">{{ t('pp.common.date') }}</label><input id="ca-held" v-model="campaign.held_on" type="date" required /></div>
        <div class="field"><label for="ca-girls">{{ t('ps.can.girls') }}</label><input id="ca-girls" v-model.number="campaign.girls_count" type="number" min="0" /></div>
        <div class="field"><label for="ca-boys">{{ t('ps.can.boys') }}</label><input id="ca-boys" v-model.number="campaign.boys_count" type="number" min="0" /></div>
        <div class="field"><label for="ca-consents">{{ t('ps.can.consents') }}</label><input id="ca-consents" v-model.number="campaign.consents_count" type="number" min="0" /></div>
        <div class="field"><label for="ca-refusals">{{ t('ps.can.refusals') }}</label><input id="ca-refusals" v-model.number="campaign.refusals_count" type="number" min="0" /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.common.save') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.row { border: 1px solid var(--input-border); padding: 8px; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { text-align: left; padding: 4px; border-bottom: 1px solid var(--input-border); }
</style>
