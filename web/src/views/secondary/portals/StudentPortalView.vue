<script setup lang="ts">
// Portail élève du secondaire (T834) : sa propre fiche dans l'établissement actif (droit secondary.self.read).
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryPortals, type StudentCard } from '@/api/secondaryPortals';
import { useI18n } from '@/i18n';
import { useAction } from '../useSec';
import StudentCardView from './StudentCardView.vue';

const { t } = useI18n();
const { error, run } = useAction();
const card = ref<StudentCard | null>(null);

onMounted(async () => {
  card.value = (await run(() => secondaryPortals.student())) ?? null;
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sec.portal.studentTitle') }}</h1>
      <p class="lede">{{ t('sec.portal.studentIntro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <StudentCardView v-if="card" :card="card" />
  </div>
</template>
