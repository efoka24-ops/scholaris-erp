<script setup lang="ts">
// Vie scolaire d'un élève (T974) dans les portails élève et parent : résultats publiés, bulletins émis (retenus si blocage),
// assiduité de l'année, devoirs récents, services. Sans `childId` : l'élève connecté ; sinon un de SES enfants (le serveur juge).
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryServices, type SchoolLife } from '@/api/secondaryServices';
import { useI18n } from '@/i18n';
import { useAction } from '../useSec';

const props = defineProps<{ childId?: string }>();
const { t } = useI18n();
const { error, run } = useAction();
const life = ref<SchoolLife | null>(null);

onMounted(async () => {
  life.value = (await run(() => (props.childId ? secondaryServices.childLife(props.childId) : secondaryServices.studentLife()))) ?? null;
});
</script>

<template>
  <section class="card" :aria-label="t('secl.title')">
    <h2>{{ t('secl.title') }}</h2>
    <ErrorNotice :error="error" />
    <template v-if="life">
      <p v-if="life.access === 'restricted'" class="muted">{{ t('secl.restricted') }}</p>
      <template v-else>
        <h3>{{ t('secl.results') }}</h3>
        <p v-if="!life.results || life.results.length === 0" class="muted">{{ t('secl.noResults') }}</p>
        <ul v-else class="rows">
          <li v-for="r in life.results" :key="r.period_id">
            <strong>{{ r.period_label }}</strong>
            <template v-if="r.average !== null"> · {{ t('secl.average', { average: r.average }) }}</template>
            <template v-if="r.rank !== null && r.class_size !== null"> · {{ t('secl.rank', { rank: r.rank, size: r.class_size }) }}</template>
            <div v-if="r.decision" class="muted">{{ r.decision }}</div>
          </li>
        </ul>

        <h3>{{ t('secl.reportCards') }}</h3>
        <p v-if="life.report_cards?.blocked" class="notice notice--warning" role="status">{{ t('secl.reportCardsBlocked') }}</p>
        <p v-else-if="!life.report_cards || life.report_cards.items.length === 0" class="muted">{{ t('secl.noReportCards') }}</p>
        <ul v-else class="rows">
          <li v-for="d in life.report_cards.items" :key="d.id">{{ d.number }}</li>
        </ul>

        <template v-if="life.attendance">
          <h3>{{ t('secl.attendance', { from: life.attendance.from }) }}</h3>
          <p>{{ t('secl.attendanceLine', { absences: life.attendance.absences, unjustified: life.attendance.unjustified_absences, lates: life.attendance.lates }) }}</p>
        </template>

        <h3>{{ t('secl.homework') }}</h3>
        <p v-if="!life.homework || life.homework.length === 0" class="muted">{{ t('secl.noHomework') }}</p>
        <ul v-else class="rows">
          <li v-for="(h, index) in life.homework" :key="index"><strong>{{ h.session_date }}</strong> · {{ h.subject_label }}<div>{{ h.homework }}</div></li>
        </ul>

        <h3>{{ t('secl.services') }}</h3>
        <p v-if="!life.services || life.services.length === 0" class="muted">{{ t('secl.noServices') }}</p>
        <ul v-else class="rows">
          <li v-for="s in life.services" :key="s.id">
            {{ t(`secsv.kind.${s.service_kind}`) }} · {{ s.offer_label }}<template v-if="s.stop_label"> · {{ s.stop_label }}</template>
            <div class="muted">{{ s.starts_on }}<template v-if="s.ends_on"> → {{ s.ends_on }}</template></div>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>
