<script setup lang="ts">
// Espace enseignant du secondaire (T974) : affectations de l'année avec l'avancement du programme (cahier de textes) et devoirs donnés
// récemment. Les saisies de notes à faire restent celles du portail enseignant du socle (aucun doublon).
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryServices, type TeacherPortal } from '@/api/secondaryServices';
import { useI18n } from '@/i18n';
import { useAction } from '../useSec';

const { t } = useI18n();
const { error, run } = useAction();
const portal = ref<TeacherPortal | null>(null);

onMounted(async () => {
  portal.value = (await run(() => secondaryServices.teacher())) ?? null;
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sect.title') }}</h1>
      <p class="lede">{{ t('sect.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <template v-if="portal">
      <section class="card" aria-labelledby="tp-assign">
        <h2 id="tp-assign">{{ t('sect.assignments') }}</h2>
        <p v-if="portal.assignments.length === 0" class="muted">{{ t('sect.noAssignments') }}</p>
        <ul v-else class="rows">
          <li v-for="a in portal.assignments" :key="a.assignment_id">
            <strong>{{ a.class_name ?? a.class_id }}</strong><template v-if="a.group_code"> ({{ a.group_code }})</template> · {{ a.subject_label }}
            <div class="muted">
              <template v-if="a.coverage && a.coverage.planned_hours !== null">{{ t('sect.coverage', { done: a.coverage.done_hours, planned: a.coverage.planned_hours }) }}</template>
              <template v-else>{{ t('sect.noCoverage') }}</template>
            </div>
          </li>
        </ul>
      </section>
      <section class="card" aria-labelledby="tp-homework">
        <h2 id="tp-homework">{{ t('sect.homework') }}</h2>
        <p v-if="portal.homework_given.length === 0" class="muted">{{ t('sect.noHomework') }}</p>
        <ul v-else class="rows">
          <li v-for="(h, index) in portal.homework_given" :key="index"><strong>{{ h.session_date }}</strong> · {{ h.subject_label }}<div>{{ h.homework }}</div></li>
        </ul>
      </section>
    </template>
  </div>
</template>
