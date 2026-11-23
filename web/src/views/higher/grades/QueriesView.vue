<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, type GradeQuery, type QueryStatus } from '@/api/higher-grades';
import { useContextStore } from '@/stores/context';
import { formatDate, formatDateTime } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import GradesNav from './GradesNav.vue';
import { G } from './perms';
import { gk, useGradesI18n } from './i18n';

// Requêtes des étudiants (EX-NOT-07) : réponse motivée, acceptée ou rejetée (assessment.validate sur le périmètre).
const { tg, locale } = useGradesI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<GradeQuery[]>([]);
const status = ref<QueryStatus | ''>('open');
const responses = reactive<Record<string, string>>({});
const tried = ref<string | null>(null);
const canRespond = computed(() => ctx.can(...G.validate) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => grades.queries(status.value ? { 'filter[status]': status.value } : {}));
  if (res) items.value = res;
}

async function respond(q: GradeQuery, decision: 'accepted' | 'rejected'): Promise<void> {
  tried.value = q.id;
  const text = (responses[q.id] ?? '').trim();
  if (!text) return;
  const res = await act.run(() => grades.respondQuery(q.id, decision, text));
  if (!res) return;
  tried.value = null;
  delete responses[q.id];
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="tg('hg.q.title')" :lede="tg('hg.q.lede')" section="portal" :selector="false">
    <GradesNav />
    <div class="hi-form">
      <div class="field">
        <label for="qf-st">{{ tg('hg.status') }}</label>
        <select id="qf-st" v-model="status" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="s in ['open', 'accepted', 'rejected']" :key="s" :value="s">{{ tg(gk('hg.query.state.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <ul class="hi-list card">
      <li v-for="q in items" :key="q.id" data-test="query">
        <div class="hi-grow">
          <strong>{{ q.element_code }} · {{ q.assessment_label }}</strong>
          <p>{{ q.message }}</p>
          <p class="muted hi-small">{{ formatDateTime(q.created_at, locale) }}</p>
          <p v-if="q.response" class="hi-small"><strong>{{ tg('hg.q.response') }} :</strong> {{ q.response }} <span class="muted">({{ tg('hg.q.answered', { date: formatDate(q.responded_at, locale) }) }})</span></p>
        </div>
        <span class="tag" :class="`tag--${q.status === 'accepted' ? 'success' : (toneOf(q.status) ?? 'neutral')}`">{{ tg(gk('hg.query.state.', q.status)) }}</span>
        <div v-if="q.status === 'open' && canRespond" class="hi-wide" style="flex-basis: 100%">
          <div class="field">
            <label :for="`qr-${q.id}`">{{ tg('hg.q.response') }}</label>
            <textarea :id="`qr-${q.id}`" v-model="responses[q.id]" maxlength="1000" :aria-invalid="tried === q.id && !(responses[q.id] ?? '').trim()" />
            <span v-if="tried === q.id && !(responses[q.id] ?? '').trim()" class="error">{{ tg('hg.required') }}</span>
          </div>
          <div class="hi-actions">
            <button type="button" class="btn btn--primary" :disabled="act.busy.value" @click="respond(q, 'accepted')">{{ tg('hg.q.accept') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="respond(q, 'rejected')">{{ tg('hg.q.reject') }}</button>
          </div>
        </div>
      </li>
      <li v-if="items.length === 0" class="muted">{{ tg('hg.q.none') }}</li>
    </ul>
  </HigherFrame>
</template>
