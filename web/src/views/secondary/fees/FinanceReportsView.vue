<script setup lang="ts">
// Restes du lot E1 (E4-B) : situation financière par classe, niveau et établissement avec prévisions (EX-FIN-07, agrégats seulement)
// et fichier des conseils de fin d'année pour cartescolaire.cm (DOC-33, API-04), à déposer à la main. Mobile d'abord.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { saveBlob } from '@/api/exports';
import { secondaryServices, type FinancialSituation, type SituationTotals } from '@/api/secondaryServices';
import { useI18n } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const year = ref('');
const situation = ref<FinancialSituation | null>(null);
const notice = ref('');
const canSituation = computed(() => can('secondary.fees.read', 'secondary.finance.read', 'secondary.billing.manage', 'secondary.fees.catalog', 'secondary.fees.validate'));
const canExport = computed(() => can('secondary.enrol.manage', 'secondary.settings.manage'));
const money = (n: number): string => `${new Intl.NumberFormat('fr-FR').format(n)} FCFA`;
const rate = (r: SituationTotals): string => (r.recovery_rate === null ? '—' : `${r.recovery_rate} %`);

async function load(): Promise<void> {
  if (!canSituation.value) return;
  const s = await run(() => secondaryServices.situation(year.value.trim() || undefined));
  if (s) {
    situation.value = s;
    year.value = s.academic_year_id;
  }
}

async function download(): Promise<void> {
  const id = year.value.trim();
  if (id === '') return;
  const blob = await run(() => secondaryServices.yearEndExport(id));
  if (blob) {
    saveBlob(blob, `conseils-fin-annee-${id}.csv`);
    notice.value = t('secfr.downloaded');
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secfr.title') }}</h1>
      <p class="lede">{{ t('secfr.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <form class="card stack" @submit.prevent="load">
      <div class="field"><label for="fr-year">{{ t('secfr.year') }}</label><input id="fr-year" v-model="year" type="text" maxlength="40" autocomplete="off" /></div>
      <div v-if="canSituation"><button type="submit" class="btn" :disabled="busy">{{ t('secfr.refresh') }}</button></div>
    </form>

    <template v-if="situation">
      <section class="card" aria-labelledby="fr-est">
        <h2 id="fr-est">{{ t('secfr.establishment') }}</h2>
        <p class="muted">{{ t('secfr.asOf', { date: situation.as_of }) }}</p>
        <dl class="pairs">
          <dt>{{ t('secfr.invoiced') }}</dt><dd>{{ money(situation.establishment.invoiced_xaf) }}</dd>
          <dt>{{ t('secfr.paid') }}</dt><dd>{{ money(situation.establishment.paid_xaf) }}</dd>
          <dt>{{ t('secfr.balance') }}</dt><dd>{{ money(situation.establishment.balance_xaf) }}</dd>
          <dt>{{ t('secfr.overdue') }}</dt><dd>{{ money(situation.establishment.overdue_xaf) }}</dd>
          <dt>{{ t('secfr.rate') }}</dt><dd>{{ rate(situation.establishment) }}</dd>
        </dl>
      </section>

      <section class="card" aria-labelledby="fr-levels">
        <h2 id="fr-levels">{{ t('secfr.byLevel') }}</h2>
        <ul class="rows">
          <li v-for="l in situation.levels" :key="l.level_code">
            <strong>{{ l.level_code }}</strong> · {{ t('secfr.rate') }} {{ rate(l) }}
            <div class="muted">{{ t('secfr.paid') }} {{ money(l.paid_xaf) }} · {{ t('secfr.balance') }} {{ money(l.balance_xaf) }} · {{ t('secfr.overdue') }} {{ money(l.overdue_xaf) }}</div>
          </li>
        </ul>
      </section>

      <section class="card" aria-labelledby="fr-classes">
        <h2 id="fr-classes">{{ t('secfr.byClass') }}</h2>
        <ul class="rows">
          <li v-for="c in situation.classes" :key="c.class_id">
            <strong>{{ c.label }}</strong> · {{ t('secfr.rate') }} {{ rate(c) }}
            <div class="muted">{{ t('secfr.learners', { count: c.learners, debtors: c.debtors }) }}</div>
            <div class="muted">{{ t('secfr.invoiced') }} {{ money(c.invoiced_xaf) }} · {{ t('secfr.paid') }} {{ money(c.paid_xaf) }} · {{ t('secfr.balance') }} {{ money(c.balance_xaf) }} · {{ t('secfr.overdue') }} {{ money(c.overdue_xaf) }}</div>
          </li>
        </ul>
      </section>

      <section class="card" aria-labelledby="fr-forecast">
        <h2 id="fr-forecast">{{ t('secfr.forecast') }}</h2>
        <p v-if="situation.forecast.length === 0" class="muted">{{ t('secfr.noForecast') }}</p>
        <dl v-else class="pairs">
          <template v-for="f in situation.forecast" :key="f.month">
            <dt>{{ f.month }}</dt><dd>{{ money(f.expected_xaf) }}</dd>
          </template>
        </dl>
      </section>
    </template>

    <section v-if="canExport" class="card" aria-labelledby="fr-export">
      <h2 id="fr-export">{{ t('secfr.export') }}</h2>
      <p class="muted">{{ t('secfr.exportHelp') }}</p>
      <button type="button" class="btn btn--primary" :disabled="busy || year.trim() === ''" @click="download">{{ t('secfr.download') }}</button>
    </section>
  </div>
</template>
