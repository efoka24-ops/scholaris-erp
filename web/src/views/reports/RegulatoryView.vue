<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { getRegulatory, type CountList, type RegulatoryStats, type Unavailable } from '@/api/reports';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Statistiques réglementaires : effectifs actifs par année, classe et sexe ; une ventilation sans source reste « Indisponible » avec sa raison — design : Dashboard.html (cartes indicateurs).
const { t, locale } = useI18n();
const data = ref<RegulatoryStats | null>(null);
const error = ref<unknown>(null);
const loading = ref(true);

onMounted(async () => {
  try {
    data.value = await getRegulatory();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
});

const blocks: Array<{ key: 'by_academic_year' | 'by_structure' | 'by_sex' | 'by_level'; title: MessageKey; idKey: string }> = [
  { key: 'by_academic_year', title: 'regstats.byYear', idKey: 'academic_year_id' },
  { key: 'by_structure', title: 'regstats.byStructure', idKey: 'structure_id' },
  { key: 'by_sex', title: 'regstats.bySex', idKey: 'sex' },
  { key: 'by_level', title: 'regstats.byLevel', idKey: 'level' },
];

const isUnavailable = (b: CountList | Unavailable): b is Unavailable => b.available === false;
function label(idKey: string, value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return t('regstats.none');
  if (idKey === 'sex') return t(`regstats.sex.${value}` as MessageKey);
  return String(value);
}
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('reports.eyebrow') }}</p>
      <h1>{{ t('regstats.title') }}</h1>
      <p class="lede">{{ t('regstats.lede') }}</p>
    </header>

    <ErrorNotice v-if="error" :error="error" />
    <p v-else-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-else-if="data">
      <div class="grid grid--kpi">
        <article class="kpi">
          <h3 class="eyebrow">{{ t('regstats.total') }}</h3>
          <p class="kpi__value">{{ data.total_enrolled }}</p>
          <p class="kpi__detail">{{ t('regstats.generated', { date: formatDateTime(data.generated_at, locale) }) }}</p>
        </article>
      </div>

      <div v-for="b in blocks" :key="b.key" class="card" :data-test="b.key">
        <h2>{{ t(b.title) }}</h2>
        <div v-if="isUnavailable(data[b.key])" class="stack">
          <StatusTag tone="neutral">{{ t('common.unavailable') }}</StatusTag>
          <p class="muted">{{ (data[b.key] as Unavailable).reason }}</p>
        </div>
        <p v-else-if="(data[b.key] as CountList).items.length === 0" class="muted">{{ t('regstats.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead><tr><th>{{ t(b.title) }}</th><th class="num">{{ t('regstats.enrolled') }}</th></tr></thead>
            <tbody>
              <tr v-for="(item, i) in (data[b.key] as CountList).items" :key="i">
                <th scope="row">{{ label(b.idKey, item[b.idKey]) }}</th>
                <td class="num">{{ item.enrolled }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>
