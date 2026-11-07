<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ChildCard from '@/components/ChildCard.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { http } from '@/api/http';
import type { LearnerPortal } from '@/api/types';
import { formatTime } from '@/lib/format';
import { useI18n } from '@/i18n';

// Portail apprenant : sa fiche (inscriptions, solde, assiduité, résultats publiés, documents) et l'emploi du temps du jour.
const { t } = useI18n();
const portal = ref<LearnerPortal | null>(null);
const error = ref<unknown>(null);
const loading = ref(true);

onMounted(async () => {
  try {
    portal.value = (await http.get<{ data: LearnerPortal }>('/portal/learner')).data;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('learner.eyebrow') }}</p>
      <h1>{{ t('learner.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
    <template v-if="portal">
      <div class="narrow">
        <ChildCard :child="portal" />
        <section class="card" aria-labelledby="today-title">
          <h2 id="today-title">{{ t('learner.today') }}</h2>
          <p v-if="portal.timetable_today.length === 0" class="muted">{{ t('learner.noLesson') }}</p>
          <ul v-else class="lessons">
            <li v-for="(l, i) in portal.timetable_today" :key="i" class="row">
              <strong>{{ formatTime(l.start) }} – {{ formatTime(l.end) }}</strong>
              <span>{{ l.subject }}</span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 760px;
}
.lessons {
  list-style: none;
  padding: 0;
  margin: 0;
}
.lessons li {
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border);
}
</style>
