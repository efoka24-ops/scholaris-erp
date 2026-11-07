<script setup lang="ts">
// Portail parent du primaire : pour chaque enfant, les bulletins PUBLIÉS (copie figée, numéro vérifiable) et les résultats
// d'examen publiés. Le serveur ne rend que les enfants du parent connecté.
import { onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primaryP2, type FamilyChild, type FamilyExam, type ReportCard } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import BulletinCard from './BulletinCard.vue';
import { useAction } from './usePri';

const { t } = useI18n();
const { error, run } = useAction();
const children = ref<FamilyChild[]>([]);
const childId = ref('');
const cards = ref<ReportCard[]>([]);
const exams = ref<FamilyExam[]>([]);
const openId = ref<string | null>(null);

async function loadChild(): Promise<void> {
  cards.value = [];
  exams.value = [];
  openId.value = null;
  if (childId.value === '') return;
  await run(async () => {
    [cards.value, exams.value] = await Promise.all([primaryP2.familyReportCards(childId.value), primaryP2.familyExams(childId.value)]);
  });
}

watch(childId, () => void loadChild());
onMounted(async () => {
  await run(async () => {
    children.value = await primaryP2.familyChildren();
    childId.value = children.value[0]?.person_id ?? '';
  });
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.family.title') }}</h1>
      <p class="lede">{{ t('pri.family.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="children.length === 0" class="muted">{{ t('pri.family.noChild') }}</p>
    <div v-else-if="children.length > 1" class="field">
      <label for="fa-child">{{ t('pri.family.child') }}</label>
      <select id="fa-child" v-model="childId"><option v-for="c in children" :key="c.person_id" :value="c.person_id">{{ c.name }}<template v-if="c.class"> · {{ c.class.name }}</template></option></select>
    </div>

    <section class="card" aria-labelledby="fa-cards">
      <h2 id="fa-cards">{{ t('pri.family.cards') }}</h2>
      <p v-if="cards.length === 0" class="muted">{{ t('pri.family.noCard') }}</p>
      <ul class="rows">
        <li v-for="c in cards" :key="c.id">
          <button type="button" class="btn btn--quiet" :aria-expanded="openId === c.id" @click="openId = openId === c.id ? null : c.id">
            {{ c.snapshot?.kind === 'annual' ? t('pri.card.annualTitle') : t('pri.card.termTitle') }} · {{ c.snapshot?.kind === 'annual' ? c.snapshot?.academic_year : c.snapshot?.period_label }}
          </button>
          <div v-if="openId === c.id && c.snapshot" class="sheet"><BulletinCard :snapshot="c.snapshot" :number="c.number" /></div>
        </li>
      </ul>
    </section>

    <section v-if="exams.length" class="card" aria-labelledby="fa-exams">
      <h2 id="fa-exams">{{ t('pri.family.exams') }}</h2>
      <ul class="rows">
        <li v-for="e in exams" :key="e.exam_code">
          <span><strong>{{ e.exam }}</strong><template v-if="e.candidate_number"> · {{ e.candidate_number }}</template><template v-if="e.sub_centre"> · {{ e.sub_centre }}</template></span>
          <span class="tag">{{ e.outcome ? t(`pri.exam.outcomes.${e.outcome}` as MessageKey) : t('pri.family.notPublished') }}</span>
          <span v-if="e.outcome_detail" class="muted">{{ e.outcome_detail }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.sheet { margin-top: 8px; }
</style>
