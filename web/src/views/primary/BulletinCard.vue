<script setup lang="ts">
// Bulletin (aperçu ou copie figée publiée) : matières et moyennes facultatives, rang si l'école l'affiche, compétences par domaine,
// assiduité en demi-journées, conduite, remédiation, appréciation, décision. Texte seulement (aucun HTML injecté).
import { computed } from 'vue';
import type { ReportSnapshot } from '@/api/primary';
import { useI18n } from '@/i18n';
import { formatAverage } from './usePicker';

const props = defineProps<{ snapshot: ReportSnapshot; number?: string | null }>();
const { t, locale } = useI18n();
const s = computed(() => props.snapshot);
const avg = (v: number | null | undefined): string => formatAverage(v, locale.value);
const hasNumeric = computed(() => s.value.numeric.average !== null || s.value.numeric.subjects.some((x) => x.average !== null));
</script>

<template>
  <article class="bulletin" :aria-label="t('pri.card.ariaLabel', { name: s.learner.name })">
    <header class="bulletin__head">
      <p v-if="s.school" class="muted">{{ s.school }}</p>
      <h3>{{ s.kind === 'annual' ? t('pri.card.annualTitle') : t('pri.card.termTitle') }} · {{ s.kind === 'annual' ? s.academic_year : s.period_label }}</h3>
      <p>
        <strong>{{ s.learner.name }}</strong> · {{ s.class_name }}<template v-if="s.level_name"> ({{ s.level_name }})</template>
        <span v-if="s.repeater" class="tag">{{ t('pri.card.repeater') }}</span>
      </p>
      <p v-if="number ?? s.number" class="muted">{{ t('pri.card.number') }} {{ number ?? s.number }}<template v-if="s.rectified"> · {{ t('pri.card.rectified') }}</template></p>
    </header>

    <section v-if="s.kind === 'annual' && s.annual" class="bulletin__block">
      <h4>{{ t('pri.card.terms') }}</h4>
      <ul class="rows">
        <li v-for="term in s.annual.terms" :key="term.period_id"><span>{{ term.label }}</span><span>{{ avg(term.average) }}</span></li>
      </ul>
      <p v-if="s.annual.average !== null"><strong>{{ t('pri.card.annualAverage') }}</strong> {{ avg(s.annual.average) }} / {{ avg(s.numeric.scale) }}</p>
      <p v-if="s.annual.rank !== null">{{ t('pri.card.rank', { rank: s.annual.rank, size: s.numeric.class_size ?? '—' }) }}</p>
      <p>{{ t('pri.card.absences', { count: s.annual.absences_half_days }) }}</p>
    </section>

    <template v-else>
      <section v-if="hasNumeric" class="bulletin__block">
        <h4>{{ t('pri.card.subjects') }}</h4>
        <ul class="rows">
          <li v-for="sub in s.numeric.subjects" :key="sub.subject_id"><span>{{ sub.name ?? sub.code }}</span><span>{{ avg(sub.average) }}</span></li>
        </ul>
        <p v-if="s.numeric.average !== null"><strong>{{ t('pri.card.average') }}</strong> {{ avg(s.numeric.average) }} / {{ avg(s.numeric.scale) }}</p>
        <p v-if="s.numeric.rank !== null">{{ t('pri.card.rank', { rank: s.numeric.rank, size: s.numeric.class_size ?? '—' }) }}</p>
      </section>

      <section class="bulletin__block">
        <h4>{{ t('pri.card.competencies') }}</h4>
        <p v-if="s.competencies.length === 0" class="muted">{{ t('pri.card.noCompetency') }}</p>
        <div v-for="d in s.competencies" :key="d.domain_id" class="domain">
          <p class="domain__label">{{ d.label }}</p>
          <ul class="rows">
            <li v-for="c in d.items" :key="c.competency_id"><span>{{ c.label }}</span><span class="tag">{{ c.value_label ?? '—' }}</span></li>
          </ul>
        </div>
        <p v-if="s.scale.length" class="muted small">{{ t('pri.card.scale') }} {{ s.scale.map((x) => x.label).join(', ') }}</p>
      </section>

      <section class="bulletin__block">
        <h4>{{ t('pri.card.attendance') }}</h4>
        <p>{{ t('pri.card.absencesTerm', { count: s.attendance.absences_half_days, unjustified: s.attendance.unjustified_half_days, lates: s.attendance.lates }) }}</p>
        <p>{{ t('pri.card.conduct') }} {{ s.conduct?.label ?? '—' }}</p>
      </section>
    </template>

    <section class="bulletin__block">
      <h4>{{ t('pri.card.remediation') }}</h4>
      <p v-if="s.remediation.sessions === 0 && s.remediation.open_plans.length === 0 && !s.remediation.note" class="muted">{{ t('pri.card.noRemediation') }}</p>
      <p v-if="s.remediation.sessions > 0">{{ t('pri.card.remediationSessions', { count: s.remediation.sessions, minutes: s.remediation.minutes }) }}</p>
      <p v-if="s.remediation.open_plans.length">{{ t('pri.card.remediationPlans') }} {{ s.remediation.open_plans.join(', ') }}</p>
      <p v-if="s.remediation.note">{{ s.remediation.note }}</p>
    </section>

    <section class="bulletin__block">
      <p><strong>{{ t('pri.card.appraisal') }}</strong> {{ s.appraisal ?? '—' }}</p>
      <p v-if="s.decision"><strong>{{ t('pri.card.decision') }}</strong> {{ s.decision.label }}</p>
    </section>
  </article>
</template>

<style scoped>
.bulletin { display: grid; gap: 12px; }
.bulletin__head h3 { margin: 4px 0; }
.bulletin__block h4 { margin: 0 0 6px; font-size: 1rem; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; }
.rows li { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border, #dcdcd8); padding: 4px 0; }
.domain__label { font-weight: 600; margin: 8px 0 4px; }
.small { font-size: 0.875rem; }
</style>
