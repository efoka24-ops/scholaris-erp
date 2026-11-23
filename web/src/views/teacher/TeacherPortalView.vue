<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { getTeacherPortal, type TeacherPortal } from '@/api/reports';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, formatTime } from '@/lib/format';
import { formatMinutes } from '@/lib/hr';

// Portail enseignant : mes classes, mon emploi du temps du jour, mes saisies de notes à faire, mes heures du mois — design : Dashboard.html, Main.html (cartes et tableaux).
const { t, locale } = useI18n();
const data = ref<TeacherPortal | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

onMounted(async () => {
  try {
    data.value = await getTeacherPortal();
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
});

const kindLabel = (k: string): string => t(`hr.session.${k}` as MessageKey);
const todoLabel = (item: Record<string, unknown>): string => String(item.label ?? item.title ?? item.name ?? item.assessment_label ?? item.id ?? '');
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('teacher.eyebrow') }}</p>
      <h1>{{ t('teacher.title') }}</h1>
      <p class="lede">{{ t('teacher.lede') }}</p>
    </header>

    <ErrorNotice v-if="error" :error="error" />
    <p v-else-if="loading" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-else-if="data">
      <div class="row" style="margin-bottom: 16px">
        <router-link class="btn btn--primary" :to="{ name: 'attendance' }">{{ t('teacher.openAttendance') }}</router-link>
        <router-link class="btn" :to="{ name: 'grades' }">{{ t('teacher.openGrades') }}</router-link>
      </div>

      <div class="grid grid--kpi" data-test="hours">
        <article class="kpi">
          <h3 class="eyebrow">{{ t('teacher.hours.declared') }}</h3>
          <p class="kpi__value">{{ formatMinutes(data.hours_month.declared_minutes) }}</p>
          <p class="kpi__detail">{{ t('teacher.hours.month', { month: data.hours_month.month }) }}</p>
        </article>
        <article class="kpi">
          <h3 class="eyebrow">{{ t('teacher.hours.validated') }}</h3>
          <p class="kpi__value">{{ formatMinutes(data.hours_month.validated_minutes) }}</p>
          <p class="kpi__detail">{{ t('teacher.hours.validatedHint') }}</p>
        </article>
        <article class="kpi">
          <h3 class="eyebrow">{{ t('teacher.hours.rejected') }}</h3>
          <p class="kpi__value">{{ formatMinutes(data.hours_month.rejected_minutes) }}</p>
          <p class="kpi__detail">{{ t('teacher.hours.rejectedHint') }}</p>
        </article>
      </div>

      <div class="card" style="margin-top: 16px" data-test="timetable">
        <h2>{{ t('teacher.timetable', { date: formatDate(data.today, locale) }) }}</h2>
        <p v-if="data.timetable_today.length === 0" class="muted">{{ t('teacher.noLessons') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr><th>{{ t('teacher.col.time') }}</th><th>{{ t('teacher.col.subject') }}</th><th>{{ t('teacher.col.room') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="(l, i) in data.timetable_today" :key="i">
                <td>{{ formatTime(l.start) }} – {{ formatTime(l.end) }}</td>
                <td>{{ l.subject ?? '—' }}</td>
                <td>{{ l.room_id ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card" data-test="classes">
        <h2>{{ t('teacher.classes') }}</h2>
        <p v-if="data.classes.length === 0" class="muted">{{ t('teacher.noClasses') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr><th>{{ t('teacher.col.establishment') }}</th><th>{{ t('teacher.col.class') }}</th><th>{{ t('teacher.col.subject') }}</th><th>{{ t('teacher.col.kind') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="(c, i) in data.classes" :key="i">
                <td>{{ c.establishment_name ?? c.establishment_id }}</td>
                <td>{{ c.structure_id ?? '—' }}</td>
                <td>{{ c.subject_label ?? '—' }}</td>
                <td>{{ kindLabel(c.session_kind) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card" data-test="grading">
        <h2>{{ t('teacher.grading') }}</h2>
        <div v-if="!data.grading_todo.available" class="stack">
          <StatusTag tone="neutral">{{ t('common.unavailable') }}</StatusTag>
          <p class="muted">{{ data.grading_todo.reason ?? t('teacher.gradingNone') }}</p>
        </div>
        <p v-else-if="data.grading_todo.items.length === 0" class="muted">{{ t('teacher.gradingEmpty') }}</p>
        <ul v-else>
          <li v-for="(item, i) in data.grading_todo.items" :key="i">{{ todoLabel(item) }}</li>
        </ul>
      </div>
    </template>
  </section>
</template>
