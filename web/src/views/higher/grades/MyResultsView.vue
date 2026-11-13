<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, type GradeQuery, type StudentMark, type StudentResults } from '@/api/higher-grades';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { describe } from './marks';
import { G } from './perms';
import { gk, useGradesI18n } from './i18n';

// « Mes notes » (étudiant) : notes publiées par EC, note de l'EC, résultats semestriels ; requête pendant le délai (EX-NOT-07).
const { tg, locale } = useGradesI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const data = ref<StudentResults | null>(null);
const queries = ref<GradeQuery[]>([]);
const asking = ref<StudentMark | null>(null);
const message = ref('');
const tried = ref(false);
const sent = ref(false);

const canQuery = computed(() => ctx.can(...G.student) && !higher.readOnly);
const semesters = computed(() => {
  const map = new Map<string, StudentResults['elements']>();
  for (const e of data.value?.elements ?? []) {
    const key = `${e.academic_year_id}|${e.semester_ordinal}`;
    map.set(key, [...(map.get(key) ?? []), e]);
  }
  return [...map.entries()].map(([key, elements]) => ({ key, ordinal: elements[0]?.semester_ordinal ?? 0, year: elements[0]?.academic_year_id ?? '', elements }));
});
const pendingQuery = (markId: string): boolean => queries.value.some((q) => q.mark_id === markId && q.status === 'open');
const fmt = (v: number | null): string => describe({ score: v, absence: null }, locale.value);

async function refresh(): Promise<void> {
  const res = await load.run(() => grades.myResults());
  if (res) data.value = res;
  try {
    queries.value = await grades.myQueries();
  } catch {
    queries.value = [];
  }
}

async function send(): Promise<void> {
  tried.value = true;
  const m = asking.value;
  if (!m || !message.value.trim()) return;
  const res = await act.run(() => grades.createQuery(m.mark_id, message.value.trim()));
  if (!res) return;
  asking.value = null;
  message.value = '';
  tried.value = false;
  sent.value = true;
  await refresh();
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <HigherFrame :title="tg('hg.me.title')" :lede="tg('hg.me.lede')" section="portal" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <p v-if="sent" class="notice" role="status">{{ tg('hg.me.querySent') }}</p>
    <p v-if="data && data.elements.length === 0" class="card muted">{{ tg('hg.me.none') }}</p>

    <section v-for="s in semesters" :key="s.key" class="hi-section">
      <h2>{{ tg('hg.me.semester', { n: s.ordinal }) }} <span class="muted hi-small">{{ higher.yearName(s.year) }}</span></h2>
      <article v-for="e in s.elements" :key="e.element_id" class="card hg-ec" data-test="my-element">
        <header class="row row--between">
          <h3>{{ e.element_code }} · {{ e.element_title }}</h3>
          <span class="hi-total" :aria-label="tg('hg.me.ecMark')">{{ fmt(e.ec_mark) }}</span>
        </header>
        <ul class="hi-list">
          <li v-for="m in e.assessments" :key="m.mark_id">
            <span class="hi-grow">{{ m.label }} <span class="muted hi-small">{{ tg(gk('hg.kind.', m.kind)) }} · {{ m.weight_percent }} %</span></span>
            <strong>{{ describe({ score: m.score, absence: m.absence }, locale) }}</strong><span class="muted hi-small"> / {{ m.max_score }}</span>
            <span v-if="m.query_open && m.query_until" class="muted hi-small">{{ tg('hg.me.queryUntil', { date: formatDate(m.query_until, locale) }) }}</span>
            <button v-if="m.query_open && canQuery && !pendingQuery(m.mark_id)" type="button" class="btn btn--quiet" :data-test="`ask-${m.mark_id}`" @click="asking = m; tried = false; sent = false">{{ tg('hg.me.query') }}</button>
          </li>
        </ul>
      </article>
    </section>

    <form v-if="asking" class="card card--strong hi-section" novalidate data-test="query-form" @submit.prevent="send">
      <h2>{{ tg('hg.me.query') }} · {{ asking.label }}</h2>
      <div class="field">
        <label for="mq-text">{{ tg('hg.me.queryText') }}</label>
        <textarea id="mq-text" v-model="message" maxlength="1000" :aria-invalid="tried && !message.trim()" />
        <span v-if="tried && !message.trim()" class="error">{{ tg('hg.required') }}</span>
      </div>
      <ErrorNotice :error="act.error.value" />
      <div class="hi-actions">
        <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ tg('hg.me.querySend') }}</button>
        <button type="button" class="btn btn--quiet" @click="asking = null">{{ tg('hg.life.cancel') }}</button>
      </div>
    </form>

    <section v-if="data && data.semesters.length" class="card hi-section">
      <h2>{{ tg('hg.me.semesters') }}</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col"></th><th scope="col" class="num">{{ tg('hg.me.average') }}</th><th scope="col" class="num">{{ tg('hg.me.credits') }}</th><th scope="col">{{ tg('hg.me.decision') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in data.semesters" :key="r.id">
              <th scope="row">{{ r.period_label }} · {{ r.session }}</th>
              <td class="num">{{ fmt(r.average) }}</td>
              <td class="num">{{ r.credits_earned ?? '—' }} / {{ r.credits_total ?? '—' }}</td>
              <td>{{ r.decision ?? '—' }}<template v-if="r.mention"> · {{ r.mention }}</template></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="queries.length" class="card hi-section">
      <h2>{{ tg('hg.me.queries') }}</h2>
      <ul class="hi-list">
        <li v-for="q in queries" :key="q.id">
          <span class="hi-grow">{{ q.element_code }} · {{ q.assessment_label }} — {{ q.message }}<template v-if="q.response"><br /><strong>{{ tg('hg.q.response') }} :</strong> {{ q.response }}</template></span>
          <span class="tag tag--neutral">{{ tg(gk('hg.query.state.', q.status)) }}</span>
        </li>
      </ul>
    </section>
  </HigherFrame>
</template>

<style scoped>
.hg-ec {
  margin-bottom: 12px;
}
.hg-ec h3 {
  margin: 0;
  font-size: 16px;
}
</style>
