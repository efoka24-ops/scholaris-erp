<script setup lang="ts">
// Bulletins (DOC-08 unité, DOC-09 trimestre, DOC-10 livret annuel) : préparés par l'enseignant (appréciation, banque de
// formulations), publiés par le directeur seul puis figés (RG-EVA-2) ; une mention publiée ne change que par le directeur, avec motif,
// en « Rectificatif » au même numéro (RG-EVA-3). Aucun rang, aucune donnée APEE sur le bulletin.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicPrimary, publicSchools, type ClassRow, type LearningUnit, type ReportCard } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const yearLabel = ref('');
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const units = ref<LearningUnit[]>([]);
const kind = ref<'unit' | 'trimester' | 'year'>('unit');
const scopeRef = ref('');
const cards = ref<ReportCard[]>([]);
const phrases = ref<{ id: string; phrase: string }[]>([]);
const newPhrase = ref('');
const editing = ref<Record<string, string>>({});
const rectifyFor = ref<string | null>(null);
const rectify = ref({ frame_id: '', mention: '', reason: '' });
const done = ref('');

const canPublish = computed(() => ctx.can('public_primary.evaluation.publish'));
const canRectify = computed(() => ctx.can('public_primary.evaluation.rectify'));
const periods = computed(() => [...new Set(units.value.map((u) => u.period_id))]);
const scopes = computed(() =>
  kind.value === 'unit'
    ? units.value.map((u) => ({ value: u.id, label: t('pp.common.unitLabel', { n: u.unit_no }) }))
    : kind.value === 'trimester'
      ? periods.value.map((p, i) => ({ value: p, label: t('pp.cards.trimester', { n: i + 1 }) }))
      : [{ value: yearLabel.value, label: yearLabel.value }],
);
const periodLabel = (c: ReportCard): string => (locale.value === 'en' ? c.content.period_label_en : c.content.period_label_fr) ?? '';
const stateLabel = (c: ReportCard): string => t(c.state === 'draft' ? 'pp.cards.draft' : c.state === 'published' ? 'pp.cards.published' : 'pp.cards.rectified');

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (!year) return;
    yearLabel.value = year.label;
    classes.value = await publicSchools.classes(year.id);
    phrases.value = await publicPrimary.phrases(locale.value);
  });
});

async function openClass(): Promise<void> {
  const level = classes.value.find((c) => c.structure_id === classId.value)?.level_codes[0];
  units.value = [];
  cards.value = [];
  scopeRef.value = '';
  if (!level) return;
  await run(async () => {
    units.value = await publicPrimary.units(yearLabel.value, level);
  });
}

async function load(): Promise<void> {
  if (!classId.value || !scopeRef.value) return;
  await run(async () => {
    cards.value = await publicPrimary.cards(classId.value, kind.value, scopeRef.value);
    editing.value = Object.fromEntries(cards.value.map((c) => [c.id, c.appraisal ?? '']));
  });
}

async function generate(): Promise<void> {
  await run(async () => {
    await publicPrimary.generateCards({ kind: kind.value, scope_ref: scopeRef.value, structure_id: classId.value, year_label: yearLabel.value });
    await load();
  });
}

async function saveAppraisal(card: ReportCard): Promise<void> {
  await run(async () => {
    await publicPrimary.appraise(card.id, editing.value[card.id] ?? '');
    done.value = t('pp.cards.saved');
  });
}

async function addPhrase(): Promise<void> {
  if (newPhrase.value.trim() === '') return;
  await run(async () => {
    await publicPrimary.addPhrase(locale.value, newPhrase.value.trim());
    phrases.value = await publicPrimary.phrases(locale.value);
    newPhrase.value = '';
  });
}

async function publish(card: ReportCard): Promise<void> {
  if (!window.confirm(t('pp.cards.confirmPublish'))) return;
  await run(async () => {
    await publicPrimary.publish(card.id);
    done.value = t('pp.cards.publishedNotice');
    await load();
  });
}

function startRectify(card: ReportCard): void {
  rectifyFor.value = card.id;
  rectify.value = { frame_id: card.content.disciplines?.[0]?.frame_id ?? '', mention: '', reason: '' };
}

async function sendRectify(card: ReportCard): Promise<void> {
  await run(async () => {
    await publicPrimary.rectify(card.id, rectify.value);
    rectifyFor.value = null;
    done.value = t('pp.cards.rectifiedNotice');
    await load();
  });
}

async function pdf(card: ReportCard): Promise<void> {
  if (card.document_id) await run(() => openDocument(card.document_id as string));
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.cards.title') }}</h1>
      <p class="lede">{{ t('pp.cards.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls" :aria-label="t('pp.cards.choose')">
      <div class="field">
        <label for="rc-class">{{ t('pp.common.class') }}</label>
        <select id="rc-class" v-model="classId" @change="openClass">
          <option value="">—</option>
          <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <fieldset class="field">
        <legend class="label">{{ t('pp.cards.kind') }}</legend>
        <label class="check"><input v-model="kind" type="radio" value="unit" @change="scopeRef = ''" />{{ t('pp.cards.kindUnit') }}</label>
        <label class="check"><input v-model="kind" type="radio" value="trimester" @change="scopeRef = ''" />{{ t('pp.cards.kindTrimester') }}</label>
        <label class="check"><input v-model="kind" type="radio" value="year" @change="scopeRef = yearLabel" />{{ t('pp.cards.kindYear') }}</label>
      </fieldset>
      <div class="field">
        <label for="rc-scope">{{ t('pp.cards.scope') }}</label>
        <select id="rc-scope" v-model="scopeRef" @change="load">
          <option value="">—</option>
          <option v-for="s in scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>
      <button type="button" class="btn btn--primary" :disabled="busy || !classId || !scopeRef" @click="generate">{{ t('pp.cards.generate') }}</button>
    </section>

    <section class="card" :aria-label="t('pp.cards.phrases')">
      <h2>{{ t('pp.cards.phrases') }}</h2>
      <ul v-if="phrases.length > 0" class="phrases">
        <li v-for="p in phrases" :key="p.id">{{ p.phrase }}</li>
      </ul>
      <div class="field">
        <label for="rc-phrase">{{ t('pp.cards.newPhrase') }}</label>
        <input id="rc-phrase" v-model="newPhrase" type="text" maxlength="300" />
      </div>
      <button type="button" class="btn" :disabled="busy" @click="addPhrase">{{ t('pp.cards.addPhrase') }}</button>
    </section>

    <p v-if="scopeRef && cards.length === 0" class="muted">{{ t('pp.cards.none') }}</p>
    <article v-for="c in cards" :key="c.id" class="card">
      <h2>{{ c.name }} <span class="tag">{{ stateLabel(c) }}</span></h2>
      <p class="muted">{{ periodLabel(c) }} · {{ c.year_label }}</p>
      <div class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('pp.cards.mentions') }}</caption>
          <thead><tr><th scope="col">{{ t('pp.common.discipline') }}</th><th scope="col">{{ t('pp.eval.mention') }}</th></tr></thead>
          <tbody>
            <tr v-for="d in c.content.disciplines ?? []" :key="d.frame_id">
              <th scope="row">{{ locale === 'en' ? d.label_en : d.label_fr }}</th>
              <td>{{ d.mention === 'not_assessed' ? t('pp.eval.notAssessed') : d.mention }}<span v-if="d.perfection"> · {{ t('pp.eval.perfection') }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="c.state === 'draft'" class="field">
        <label :for="`ap-${c.id}`">{{ t('pp.cards.appraisal') }}</label>
        <textarea :id="`ap-${c.id}`" v-model="editing[c.id]" maxlength="1000" rows="2"></textarea>
        <select :aria-label="t('pp.cards.usePhrase')" @change="editing[c.id] = ($event.target as HTMLSelectElement).value">
          <option value="">{{ t('pp.cards.usePhrase') }}</option>
          <option v-for="p in phrases" :key="p.id" :value="p.phrase">{{ p.phrase }}</option>
        </select>
        <button type="button" class="btn" :disabled="busy" @click="saveAppraisal(c)">{{ t('pp.cards.saveAppraisal') }}</button>
      </div>
      <p v-else-if="c.appraisal">{{ t('pp.cards.appraisal') }} : {{ c.appraisal }}</p>
      <div class="actions">
        <button v-if="c.state === 'draft' && canPublish" type="button" class="btn btn--primary" :disabled="busy" @click="publish(c)">{{ t('pp.cards.publish') }}</button>
        <button v-if="c.document_id" type="button" class="btn" :disabled="busy" @click="pdf(c)">{{ t('pp.common.pdf') }}</button>
        <button v-if="c.state !== 'draft' && canRectify" type="button" class="btn" @click="startRectify(c)">{{ t('pp.cards.rectify') }}</button>
      </div>
      <form v-if="rectifyFor === c.id" class="rectify" @submit.prevent="sendRectify(c)">
        <div class="field">
          <label :for="`rf-${c.id}`">{{ t('pp.common.discipline') }}</label>
          <select :id="`rf-${c.id}`" v-model="rectify.frame_id">
            <option v-for="d in c.content.disciplines ?? []" :key="d.frame_id" :value="d.frame_id">{{ locale === 'en' ? d.label_en : d.label_fr }}</option>
          </select>
        </div>
        <div class="field">
          <label :for="`rm-${c.id}`">{{ t('pp.cards.newMention') }}</label>
          <input :id="`rm-${c.id}`" v-model="rectify.mention" type="text" maxlength="16" required />
        </div>
        <div class="field">
          <label :for="`rr-${c.id}`">{{ t('pp.common.reason') }}</label>
          <input :id="`rr-${c.id}`" v-model="rectify.reason" type="text" maxlength="500" required />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.cards.rectifyGo') }}</button>
      </form>
    </article>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.check { display: inline-flex; margin-right: 1rem; }
.phrases { margin: 0 0 8px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.rectify { display: grid; gap: 6px; margin-top: 8px; }
</style>
