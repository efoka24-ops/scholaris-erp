<script setup lang="ts">
// Exports à la tutelle (inspection, délégation) : effectifs par classe et sexe, résultats des conseils, recensement par âge,
// examen de fin de cycle. CSV ou Excel ; colonnes réglables par l'école (format officiel non publié : à confirmer).
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import { primaryP2, type TutelageType } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { currentYear, useAction } from './usePri';

const { t } = useI18n();
const { error, run } = useAction();
const types = ref<TutelageType[]>([]);
const yearId = ref<string | undefined>(undefined);
const typeLabel = (key: string, fallback: string): string => {
  const known: Record<string, MessageKey> = {
    'primary.enrolment': 'pri.exports.enrolment', 'primary.results': 'pri.exports.results', 'primary.census': 'pri.exports.census', 'primary.exam': 'pri.exports.exam',
  };
  const k = known[key];
  return k ? t(k) : fallback;
};

onMounted(async () => {
  await run(async () => {
    yearId.value = (await currentYear())?.id;
    types.value = await primaryP2.tutelageTypes();
  });
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.exports.title') }}</h1>
      <p class="lede">{{ t('pri.exports.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <ul class="list">
      <li v-for="type in types" :key="type.key" class="card">
        <h2>{{ typeLabel(type.key, type.label) }}</h2>
        <p class="muted">{{ t('pri.exports.columns') }} {{ Object.keys(type.columns).join(', ') }}</p>
        <ExportButton :path="`/primary/tutelage/exports/${type.key}`" :query="type.key === 'primary.census' ? {} : { academic_year_id: yearId }" :name="type.key.replace('.', '-')" />
      </li>
    </ul>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
</style>
