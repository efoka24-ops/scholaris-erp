<script setup lang="ts">
// Remédiation (EX-EVA-05, DOC-11 ; arrêté conjoint du 14 août 2026 : registre de remédiation et fiche de suivi individualisée
// obligatoires, au moins le minimum hebdomadaire paramétré par discipline fondamentale). Liste par discipline et critère à l'issue
// d'une unité, séances tenues (registre), état de chaque remédiation et fiche de suivi d'un élève.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  publicPrimary,
  publicSchools,
  type ClassRow,
  type LearningUnit,
  type RemediationGroup,
  type RemediationSession,
  type RemediationSheet,
  type WeeklySummary,
} from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { currentYear, useAction } from './usePs';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();

const FORMS = ['need_group', 'differentiated_sheet', 'peer_tutoring'] as const;
const yearLabel = ref('');
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const units = ref<LearningUnit[]>([]);
const unitId = ref('');
const groups = ref<RemediationGroup[]>([]);
const sessions = ref<RemediationSession[]>([]);
const summary = ref<WeeklySummary | null>(null);
const sheet = ref<RemediationSheet | null>(null);
const session = ref({ group: '', held_on: todayIso(), duration_minutes: 60, form: 'need_group', notes: '' });
const done = ref('');

const label = (fr: string | null, en: string | null): string => (locale.value === 'en' ? en : fr) ?? '';
const formLabel = (form: string): string => t(form === 'need_group' ? 'pp.rem.form.need_group' : form === 'differentiated_sheet' ? 'pp.rem.form.differentiated_sheet' : 'pp.rem.form.peer_tutoring');
const stateLabel = (s: string): string => t(s === 'open' ? 'pp.rem.state.open' : s === 'in_progress' ? 'pp.rem.state.in_progress' : 'pp.rem.state.resolved');
const selectedGroup = computed(() => groups.value.find((g) => g.criterion_id === session.value.group) ?? null);

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (!year) return;
    yearLabel.value = year.label;
    classes.value = await publicSchools.classes(year.id);
  });
});

async function openClass(): Promise<void> {
  const level = classes.value.find((c) => c.structure_id === classId.value)?.level_codes[0];
  units.value = [];
  groups.value = [];
  if (!level) return;
  await run(async () => {
    units.value = await publicPrimary.units(yearLabel.value, level);
    sessions.value = await publicPrimary.sessions(classId.value);
    summary.value = await publicPrimary.weeklySummary(classId.value);
  });
}

async function openUnit(): Promise<void> {
  if (!unitId.value) return;
  await run(async () => {
    groups.value = await publicPrimary.remediation(unitId.value, classId.value);
  });
}

async function setState(remediationId: string, state: string): Promise<void> {
  const note = state === 'resolved' ? window.prompt(t('pp.rem.notePrompt')) ?? '' : undefined;
  await run(async () => {
    await publicPrimary.updateRemediation(remediationId, { state, ...(note !== undefined ? { outcome_note: note } : {}) });
    await openUnit();
  });
}

async function record(): Promise<void> {
  const group = selectedGroup.value;
  if (!group) return;
  await run(async () => {
    await publicPrimary.recordSession({
      structure_id: classId.value,
      frame_id: group.frame_id,
      unit_id: unitId.value || undefined,
      held_on: session.value.held_on,
      duration_minutes: Number(session.value.duration_minutes),
      form: session.value.form,
      criterion_ids: [group.criterion_id],
      person_ids: group.pupils.map((p) => p.person_id),
      notes: session.value.notes || undefined,
    });
    done.value = t('pp.rem.recorded');
    sessions.value = await publicPrimary.sessions(classId.value);
    summary.value = await publicPrimary.weeklySummary(classId.value);
  });
}

function printPage(): void {
  window.print();
}

async function openSheet(personId: string): Promise<void> {
  await run(async () => {
    sheet.value = await publicPrimary.sheet(classId.value, personId);
  });
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.rem.title') }}</h1>
      <p class="lede">{{ t('pp.rem.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls">
      <div class="field">
        <label for="rm-class">{{ t('pp.common.class') }}</label>
        <select id="rm-class" v-model="classId" @change="openClass">
          <option value="">—</option>
          <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
        </select>
      </div>
      <div class="field">
        <label for="rm-unit">{{ t('pp.common.unit') }}</label>
        <select id="rm-unit" v-model="unitId" :disabled="units.length === 0" @change="openUnit">
          <option value="">—</option>
          <option v-for="u in units" :key="u.id" :value="u.id">{{ t('pp.common.unitLabel', { n: u.unit_no }) }}</option>
        </select>
      </div>
    </section>

    <section v-if="summary" class="card" aria-labelledby="rm-week">
      <h2 id="rm-week">{{ t('pp.rem.week', { from: formatDate(summary.week_from), to: formatDate(summary.week_to) }) }}</h2>
      <ul>
        <li v-for="d in summary.disciplines" :key="d.frame_id">
          {{ label(d.discipline_fr, d.discipline_en) }} : {{ d.minutes }} / {{ d.minimum }} min
          <span class="tag" :class="d.met ? 'tag--success' : 'tag--warning'">{{ t(d.met ? 'pp.rem.met' : 'pp.rem.notMet') }}</span>
        </li>
      </ul>
    </section>

    <section v-if="unitId" class="card" aria-labelledby="rm-list">
      <h2 id="rm-list">{{ t('pp.rem.list') }}</h2>
      <p v-if="groups.length === 0" class="muted">{{ t('pp.rem.none') }}</p>
      <div v-for="g in groups" :key="g.criterion_id" class="group">
        <h3>{{ label(g.discipline_fr, g.discipline_en) }} · {{ g.criterion_code }} {{ label(g.criterion_fr, g.criterion_en) }}</h3>
        <ul>
          <li v-for="p in g.pupils" :key="p.remediation_id">
            <button type="button" class="link" @click="openSheet(p.person_id)">{{ p.name }}</button>
            <span class="tag">{{ stateLabel(p.state) }}</span>
            <button v-if="p.state === 'open'" type="button" class="btn btn--small" :disabled="busy" @click="setState(p.remediation_id, 'in_progress')">{{ t('pp.rem.start') }}</button>
            <button v-if="p.state !== 'resolved'" type="button" class="btn btn--small" :disabled="busy" @click="setState(p.remediation_id, 'resolved')">{{ t('pp.rem.resolve') }}</button>
          </li>
        </ul>
      </div>
    </section>

    <section v-if="groups.length > 0" class="card" aria-labelledby="rm-session">
      <h2 id="rm-session">{{ t('pp.rem.register') }}</h2>
      <form class="controls" @submit.prevent="record">
        <div class="field">
          <label for="rs-group">{{ t('pp.rem.group') }}</label>
          <select id="rs-group" v-model="session.group" required>
            <option value="">—</option>
            <option v-for="g in groups" :key="g.criterion_id" :value="g.criterion_id">{{ label(g.discipline_fr, g.discipline_en) }} · {{ g.criterion_code }} ({{ g.pupils.length }})</option>
          </select>
        </div>
        <div class="field">
          <label for="rs-date">{{ t('pp.common.date') }}</label>
          <input id="rs-date" v-model="session.held_on" type="date" required />
        </div>
        <div class="field">
          <label for="rs-min">{{ t('pp.rem.minutes') }}</label>
          <input id="rs-min" v-model.number="session.duration_minutes" type="number" min="1" max="600" required />
        </div>
        <div class="field">
          <label for="rs-form">{{ t('pp.rem.formLabel') }}</label>
          <select id="rs-form" v-model="session.form">
            <option v-for="f in FORMS" :key="f" :value="f">{{ formLabel(f) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="rs-notes">{{ t('pp.rem.notes') }}</label>
          <input id="rs-notes" v-model="session.notes" type="text" maxlength="500" />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.rem.record') }}</button>
      </form>
      <h3>{{ t('pp.rem.sessions') }}</h3>
      <ul>
        <li v-for="s in sessions" :key="s.id">{{ formatDate(s.held_on) }} · {{ s.duration_minutes }} min · {{ formLabel(s.form) }} · {{ s.person_ids.length }}</li>
      </ul>
    </section>

    <section v-if="sheet" class="card" aria-labelledby="rm-sheet">
      <h2 id="rm-sheet">{{ t('pp.rem.sheet', { name: sheet.name }) }}</h2>
      <p>{{ t('pp.rem.sheetSummary', { minutes: sheet.minutes_attended, open: sheet.open }) }}</p>
      <ul>
        <li v-for="r in sheet.remediations" :key="r.id">
          {{ label(r.discipline_fr, r.discipline_en) }} · {{ r.criterion_code }} · {{ t('pp.common.unitLabel', { n: r.unit_no ?? 0 }) }} · {{ stateLabel(r.state) }}
          <span v-if="r.outcome_note" class="muted"> — {{ r.outcome_note }}</span>
        </li>
      </ul>
      <button type="button" class="btn" @click="printPage">{{ t('pp.common.print') }}</button>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.group h3 { font-size: 1rem; margin: 12px 0 4px; }
.link { background: none; border: 0; padding: 0; color: inherit; text-decoration: underline; cursor: pointer; font: inherit; }
</style>
