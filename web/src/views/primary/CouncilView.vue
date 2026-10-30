<script setup lang="ts">
// Conseil des maîtres d'une classe pour un trimestre : lignes préremplies par le serveur (moyennes facultatives, rang, absences en
// demi-journées, conduite, compétences, remédiation), séance (appréciation, conduite, remédiation, décision de fin d'année motivée
// au dernier trimestre), validation par la présidence. Aucune règle de calcul ici : tout vient du serveur et des paramètres.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primaryP2, type Council, type CouncilLine, type Decision } from '@/api/primary';
import { useI18n } from '@/i18n';
import { P } from './access';
import { formatAverage, sequencesOf, usePicker } from './usePicker';
import { useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const picker = usePicker();
const council = ref<Council | null>(null);
const missing = ref(false);
const drafts = reactive<Record<string, { appraisal: string; conduct: string; decision: string; reason: string; remediation: boolean; note: string }>>({});
const saved = ref<string | null>(null);
const reopenReason = ref('');
const canPrepare = computed(() => can(...P.councilPrepare));
const canPreside = computed(() => can(...P.councilPreside));
const editable = computed(() => council.value?.status === 'open' && canPrepare.value);
const decisionLabel = (code: string | null): string => {
  const d = council.value?.decisions.find((x) => x.code === code);
  return d ? (locale.value === 'en' ? d.label_en : d.label_fr) : '—';
};
const scaleLabel = (code: string): string => {
  const s = council.value?.competency_scale.find((x) => x.code === code);
  return s ? (locale.value === 'en' ? s.label_en : s.label_fr) : code;
};
const avg = (v: number | null): string => formatAverage(v, locale.value);
const rows = computed(() => (council.value?.lines ?? []).map((l) => ({ l, d: drafts[l.id] })));

function fill(c: Council): void {
  council.value = c;
  for (const key of Object.keys(drafts)) delete drafts[key];
  for (const l of c.lines) {
    drafts[l.id] = { appraisal: l.appraisal ?? '', conduct: l.conduct_code ?? '', decision: l.decision ?? '', reason: l.reason ?? '', remediation: l.remediation_flag, note: l.remediation_note ?? '' };
  }
}

async function load(): Promise<void> {
  council.value = null;
  missing.value = false;
  saved.value = null;
  if (picker.classId.value === '' || picker.periodId.value === '') return;
  await run(async () => {
    const list = await primaryP2.councils({ class_id: picker.classId.value, academic_year_id: picker.year.value?.id });
    const found = list.find((c) => c.period_id === picker.periodId.value);
    if (found) fill(await primaryP2.council(found.id));
    else missing.value = true;
  });
}

async function open(): Promise<void> {
  const period = picker.period.value;
  if (!period) return;
  const sequences = sequencesOf(period, picker.periods.value).map((s) => ({ id: s.id, starts_on: s.starts_on }));
  const created = await run(() => primaryP2.openCouncil({ class_id: picker.classId.value, period_id: period.id, starts_on: period.starts_on, period_label: t('pri.council.termLabel', { n: period.ordinal }), sequences }));
  if (created) {
    missing.value = false;
    fill(created);
  }
}

const differs = (l: CouncilLine): boolean => {
  const d = drafts[l.id];
  return d !== undefined && d.decision !== '' && l.proposal !== null && d.decision !== l.proposal;
};

async function saveLine(l: CouncilLine): Promise<void> {
  const c = council.value;
  const d = drafts[l.id];
  if (!c || !d) return;
  const changes: Record<string, unknown> = { appraisal: d.appraisal.trim() === '' ? null : d.appraisal.trim(), conduct_code: d.conduct === '' ? null : d.conduct, remediation_flag: d.remediation, remediation_note: d.note.trim() === '' ? null : d.note.trim() };
  if (c.is_final_term && d.decision !== '') {
    changes.decision = d.decision as Decision;
    changes.reason = d.reason.trim() === '' ? null : d.reason.trim();
  }
  const updated = await run(() => primaryP2.updateLine(c.id, l.id, changes));
  if (updated) {
    fill(updated);
    saved.value = l.name;
  }
}

async function act(action: 'refresh' | 'validate' | 'reopen'): Promise<void> {
  const c = council.value;
  if (!c) return;
  const done = await run(() => (action === 'refresh' ? primaryP2.refreshCouncil(c.id) : action === 'validate' ? primaryP2.validateCouncil(c.id) : primaryP2.reopenCouncil(c.id, reopenReason.value.trim())));
  if (done) fill(done);
}

watch([picker.classId, picker.periodId], () => void load());
onMounted(async () => {
  await run(() => picker.load());
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.council.title') }}</h1>
      <p class="lede">{{ t('pri.council.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="grid2">
        <div class="field">
          <label for="co-class">{{ t('pri.call.class') }}</label>
          <select id="co-class" v-model="picker.classId.value"><option v-for="c in picker.classes.value" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <div class="field">
          <label for="co-term">{{ t('pri.council.term') }}</label>
          <select id="co-term" v-model="picker.periodId.value"><option v-for="p in picker.trimesters.value" :key="p.id" :value="p.id">{{ t('pri.council.termLabel', { n: p.ordinal }) }}</option></select>
        </div>
      </div>
    </section>

    <section v-if="missing" class="card">
      <p>{{ t('pri.council.none') }}</p>
      <button v-if="canPrepare" type="button" class="btn btn--primary" :disabled="busy" @click="open">{{ t('pri.council.open') }}</button>
      <p v-else class="muted">{{ t('pri.council.askDirector') }}</p>
    </section>

    <template v-if="council">
      <section class="card" aria-labelledby="co-head">
        <h2 id="co-head">{{ council.class_name }} · {{ council.period_label }}</h2>
        <p>
          <span class="tag" :class="council.status === 'validated' ? 'tag--success' : 'tag--warning'">{{ t(council.status === 'validated' ? 'pri.council.validated' : 'pri.council.openStatus') }}</span>
          <span v-if="council.is_final_term" class="tag">{{ t('pri.council.finalTerm') }}</span>
        </p>
        <p v-if="council.summary" class="muted">
          {{ t('pri.council.summary', { learners: council.summary.learners, remediation: council.summary.remediation }) }}
          <template v-if="council.summary.class_average !== null"> · {{ t('pri.council.classAverage') }} {{ avg(council.summary.class_average) }}</template>
        </p>
        <p v-if="!council.numeric_enabled" class="muted">{{ t('pri.council.noNumeric') }}</p>
        <div class="actions">
          <button v-if="editable" type="button" class="btn" :disabled="busy" @click="act('refresh')">{{ t('pri.council.refresh') }}</button>
          <button v-if="council.status === 'open' && canPreside" type="button" class="btn btn--primary" :disabled="busy" @click="act('validate')">{{ t('pri.council.validate') }}</button>
          <router-link v-if="council.status === 'validated'" class="btn" :to="{ name: 'pri-cards', query: { council: council.id } }">{{ t('pri.council.toCards') }}</router-link>
        </div>
        <div v-if="council.status === 'validated' && canPreside" class="reopen">
          <label for="co-reopen">{{ t('pri.council.reopenReason') }}</label>
          <input id="co-reopen" v-model="reopenReason" type="text" maxlength="255" />
          <button type="button" class="btn btn--quiet" :disabled="busy || reopenReason.trim() === ''" @click="act('reopen')">{{ t('pri.council.reopen') }}</button>
        </div>
        <p v-if="saved" class="notice" role="status">{{ t('pri.council.saved', { name: saved }) }}</p>
      </section>

      <section class="card" :aria-label="t('pri.council.lines')">
        <ul class="lines">
          <li v-for="{ l, d } in rows" :key="l.id" class="line">
            <div class="line__head">
              <strong>{{ l.name }}</strong>
              <span v-if="l.repeater" class="tag">{{ t('pri.card.repeater') }}</span>
            </div>
            <dl class="facts">
              <div v-if="l.average !== null"><dt>{{ t('pri.card.average') }}</dt><dd>{{ avg(l.average) }}<template v-if="council.show_rank && l.rank !== null"> · {{ t('pri.council.rankShort', { rank: l.rank, size: l.class_size ?? '—' }) }}</template></dd></div>
              <div v-if="council.is_final_term && l.annual_average !== null"><dt>{{ t('pri.card.annualAverage') }}</dt><dd>{{ avg(l.annual_average) }}</dd></div>
              <div><dt>{{ t('pri.council.absences') }}</dt><dd>{{ l.absences_half_days }} ({{ l.unjustified_half_days }})</dd></div>
              <div><dt>{{ t('pri.card.competencies') }}</dt><dd>{{ Object.entries(l.competencies).map(([k, v]) => `${scaleLabel(k)} : ${v}`).join(' · ') || '—' }}</dd></div>
            </dl>
            <template v-if="d">
              <div class="field">
                <label :for="`co-cd-${l.id}`">{{ t('pri.card.conduct') }}</label>
                <select :id="`co-cd-${l.id}`" v-model="d.conduct" :disabled="!editable">
                  <option value="">—</option>
                  <option v-for="s in council.conduct_scale" :key="s.code" :value="s.code">{{ locale === 'en' ? s.label_en : s.label_fr }}</option>
                </select>
              </div>
              <div class="field">
                <label :for="`co-ap-${l.id}`">{{ t('pri.card.appraisal') }}</label>
                <textarea :id="`co-ap-${l.id}`" v-model="d.appraisal" rows="2" maxlength="500" :disabled="!editable" />
              </div>
              <div class="field field--check">
                <input :id="`co-rm-${l.id}`" v-model="d.remediation" type="checkbox" :disabled="!editable" />
                <label :for="`co-rm-${l.id}`">{{ t('pri.council.remediation') }}</label>
              </div>
              <div v-if="d.remediation" class="field">
                <label :for="`co-rn-${l.id}`">{{ t('pri.council.remediationNote') }}</label>
                <input :id="`co-rn-${l.id}`" v-model="d.note" type="text" maxlength="255" :disabled="!editable" />
              </div>
              <template v-if="council.is_final_term">
                <p class="muted">{{ t('pri.council.proposal') }} {{ decisionLabel(l.proposal) }}</p>
                <div class="field">
                  <label :for="`co-dc-${l.id}`">{{ t('pri.card.decision') }}</label>
                  <select :id="`co-dc-${l.id}`" v-model="d.decision" :disabled="!editable">
                    <option value="">—</option>
                    <option v-for="dec in council.decisions" :key="dec.code" :value="dec.code">{{ locale === 'en' ? dec.label_en : dec.label_fr }}</option>
                  </select>
                </div>
                <div v-if="differs(l)" class="field">
                  <label :for="`co-rs-${l.id}`">{{ t('pri.council.reason') }}</label>
                  <input :id="`co-rs-${l.id}`" v-model="d.reason" type="text" maxlength="255" required :disabled="!editable" />
                </div>
              </template>
              <button v-if="editable" type="button" class="btn" :disabled="busy" @click="saveLine(l)">{{ t('pri.council.saveLine') }}</button>
            </template>
          </li>
        </ul>
      </section>

      <section v-if="council.minutes" class="card">
        <h2>{{ t('pri.council.minutes') }}</h2>
        <pre class="minutes">{{ council.minutes }}</pre>
      </section>
    </template>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.reopen { display: grid; gap: 6px; margin-top: 12px; max-width: 32rem; }
.lines { list-style: none; margin: 0; padding: 0; display: grid; gap: 16px; }
.line { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); display: grid; gap: 6px; }
.line__head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 6px 12px; margin: 0; }
.facts dt { font-size: 0.8rem; color: var(--muted, #555); }
.facts dd { margin: 0; }
.field--check { display: flex; gap: 8px; align-items: center; }
.minutes { white-space: pre-wrap; font-family: inherit; margin: 0; }
textarea { width: 100%; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
