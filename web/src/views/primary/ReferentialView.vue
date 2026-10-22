<script setup lang="ts">
// Référentiel pédagogique de l'année : modèles (dont le modèle « exemple », non officiel), domaines, matières par niveau et
// compétences ; copie d'une année à l'autre et export. Les libellés viennent de l'école : rien n'est imposé par l'application.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listYears, type AcademicYear } from '@/api/admin';
import {
  primary,
  type ApplyResult,
  type Competency,
  type Domain,
  type EvaluationMode,
  type LevelProfile,
  type LevelSubject,
  type ReferentialTemplate,
} from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import { pickYear, structureIndex, useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const years = ref<AcademicYear[]>([]);
const yearId = ref('');
const levelId = ref('');
const profiles = ref<LevelProfile[]>([]);
const names = ref<Record<string, string>>({});
const templates = ref<ReferentialTemplate[]>([]);
const domains = ref<Domain[]>([]);
const subjects = ref<LevelSubject[]>([]);
const competencies = ref<Competency[]>([]);
const applied = ref<ApplyResult | null>(null);
const copyTarget = ref('');
const canWrite = computed(() => can(...P.referentialWrite));
const domainForm = reactive({ code: '', fr: '', en: '' });
const subjectForm = reactive({ code: '', name: '', domainId: '', mode: 'both' as EvaluationMode });
const competencyForm = reactive({ code: '', fr: '', en: '', domainId: '', subjectId: '' });

const domainLabel = (id: string): string => {
  const d = domains.value.find((x) => x.id === id);
  return d ? (locale.value === 'en' && d.label_en ? d.label_en : d.label_fr) : id;
};
const levelName = (id: string): string => names.value[id] ?? id;

async function loadYear(): Promise<void> {
  if (yearId.value === '') return;
  await run(async () => {
    domains.value = await primary.domains(yearId.value);
    await loadLevel();
  });
}

async function loadLevel(): Promise<void> {
  if (yearId.value === '' || levelId.value === '') {
    subjects.value = [];
    competencies.value = [];
    return;
  }
  subjects.value = await primary.levelSubjects(yearId.value, levelId.value);
  competencies.value = await primary.competencies(yearId.value, levelId.value);
}

async function init(): Promise<void> {
  await run(async () => {
    years.value = await listYears();
    yearId.value = pickYear(years.value)?.id ?? '';
    const index = await structureIndex();
    names.value = Object.fromEntries(index.list.map((s) => [s.id, s.name]));
    profiles.value = await primary.profiles();
    levelId.value = profiles.value[0]?.level_structure_id ?? '';
    templates.value = await primary.templates();
  });
  await loadYear();
}

async function applyTemplate(code: string): Promise<void> {
  const done = await run(() => primary.applyTemplate(code, yearId.value));
  if (done) {
    applied.value = done;
    profiles.value = await primary.profiles();
    const index = await structureIndex();
    names.value = Object.fromEntries(index.list.map((s) => [s.id, s.name]));
    if (levelId.value === '') levelId.value = profiles.value[0]?.level_structure_id ?? '';
    await loadYear();
  }
}

async function copyYear(): Promise<void> {
  const done = await run(() => primary.copyYear(yearId.value, copyTarget.value));
  if (done) applied.value = { academic_year_id: copyTarget.value, template: '', origin: 'copy', created: done.created, existing: done.existing };
}

async function addDomain(): Promise<void> {
  const done = await run(() => primary.createDomain({ academic_year_id: yearId.value, code: domainForm.code.trim(), label_fr: domainForm.fr.trim(), label_en: domainForm.en.trim() === '' ? null : domainForm.en.trim(), sort_order: domains.value.length + 1 }));
  if (done) {
    domains.value.push(done);
    Object.assign(domainForm, { code: '', fr: '', en: '' });
  }
}

async function addSubject(): Promise<void> {
  const done = await run(() => primary.createLevelSubject({ academic_year_id: yearId.value, level_structure_id: levelId.value, domain_id: subjectForm.domainId, subject_code: subjectForm.code.trim(), subject_name: subjectForm.name.trim(), evaluation_mode: subjectForm.mode, sort_order: subjects.value.length + 1 }));
  if (done) {
    subjects.value.push(done);
    Object.assign(subjectForm, { code: '', name: '' });
  }
}

async function addCompetency(): Promise<void> {
  const done = await run(() =>
    primary.createCompetency({
      academic_year_id: yearId.value,
      level_structure_id: levelId.value,
      domain_id: competencyForm.domainId,
      ...(competencyForm.subjectId === '' ? {} : { subject_id: competencyForm.subjectId }),
      code: competencyForm.code.trim(),
      label_fr: competencyForm.fr.trim(),
      label_en: competencyForm.en.trim() === '' ? null : competencyForm.en.trim(),
      sort_order: competencies.value.length + 1,
    }),
  );
  if (done) {
    competencies.value.push(done);
    Object.assign(competencyForm, { code: '', fr: '', en: '' });
  }
}

async function deactivate(c: Competency): Promise<void> {
  const done = await run(() => primary.updateCompetency(c.id, { active: !c.active }));
  if (done) Object.assign(c, done);
}

watch(yearId, () => void loadYear());
watch(levelId, () => void run(loadLevel));
onMounted(() => void init());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.ref.title') }}</h1>
      <p class="lede">{{ t('pri.ref.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="field">
        <label for="rf-year">{{ t('pri.ref.year') }}</label>
        <select id="rf-year" v-model="yearId">
          <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
        </select>
      </div>
    </section>

    <section class="card" aria-labelledby="rf-tpl">
      <h2 id="rf-tpl">{{ t('pri.ref.templates') }}</h2>
      <ul class="list">
        <li v-for="tpl in templates" :key="tpl.code" class="row row--between">
          <div class="grow">
            <strong>{{ tpl.label }}</strong>
            <span class="tag" :class="tpl.origin === 'example' ? 'tag--warning' : 'tag--neutral'">{{ t(`pri.ref.origin.${tpl.origin}` as MessageKey) }}</span>
            <div class="muted">{{ t('pri.ref.tplLevels', { count: tpl.levels }) }}</div>
          </div>
          <button v-if="canWrite" type="button" class="btn" :disabled="busy || yearId === ''" @click="applyTemplate(tpl.code)">{{ t('pri.ref.apply') }}</button>
        </li>
      </ul>
      <p v-if="applied" class="notice" role="status">
        {{ t('pri.ref.applied', { domains: applied.created.domains ?? 0, subjects: applied.created.subjects ?? 0, competencies: applied.created.competencies ?? 0 }) }}
      </p>
      <form v-if="canWrite" class="row" @submit.prevent="copyYear">
        <div class="field">
          <label for="rf-copy">{{ t('pri.ref.copyTo') }}</label>
          <select id="rf-copy" v-model="copyTarget" required>
            <option value="" disabled>{{ t('pri.ref.pick') }}</option>
            <option v-for="y in years.filter((x) => x.id !== yearId)" :key="y.id" :value="y.id">{{ y.label }}</option>
          </select>
        </div>
        <button type="submit" class="btn" :disabled="busy || copyTarget === ''">{{ t('pri.ref.copy') }}</button>
      </form>
    </section>

    <section class="card" aria-labelledby="rf-dom">
      <h2 id="rf-dom">{{ t('pri.ref.domains') }}</h2>
      <p v-if="domains.length === 0" class="muted">{{ t('pri.ref.noDomains') }}</p>
      <ul class="list">
        <li v-for="d in domains" :key="d.id"><strong>{{ d.code }}</strong> · {{ d.label_fr }}<span v-if="d.label_en" class="muted"> / {{ d.label_en }}</span></li>
      </ul>
      <form v-if="canWrite" class="grid2" @submit.prevent="addDomain">
        <div class="field"><label for="dm-code">{{ t('pri.ref.code') }}</label><input id="dm-code" v-model="domainForm.code" type="text" maxlength="40" required /></div>
        <div class="field"><label for="dm-fr">{{ t('pri.ref.labelFr') }}</label><input id="dm-fr" v-model="domainForm.fr" type="text" maxlength="160" required /></div>
        <div class="field"><label for="dm-en">{{ t('pri.ref.labelEn') }}</label><input id="dm-en" v-model="domainForm.en" type="text" maxlength="160" /></div>
        <div><button type="submit" class="btn" :disabled="busy || yearId === ''">{{ t('pri.ref.addDomain') }}</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="rf-lvl">
      <h2 id="rf-lvl">{{ t('pri.ref.byLevel') }}</h2>
      <p v-if="profiles.length === 0" class="muted">{{ t('pri.ref.noLevels') }}</p>
      <div class="field">
        <label for="rf-level">{{ t('pri.ref.level') }}</label>
        <select id="rf-level" v-model="levelId">
          <option v-for="p in profiles" :key="p.id" :value="p.level_structure_id">{{ levelName(p.level_structure_id) }}</option>
        </select>
      </div>

      <h3>{{ t('pri.ref.subjects') }}</h3>
      <ul class="list">
        <li v-for="s in subjects" :key="s.id"><strong>{{ s.subject_code }}</strong> · {{ s.subject_name }} <span class="muted">({{ domainLabel(s.domain_id) }} · {{ t(`pri.ref.mode.${s.evaluation_mode}` as MessageKey) }})</span></li>
      </ul>
      <form v-if="canWrite && levelId" class="grid2" @submit.prevent="addSubject">
        <div class="field"><label for="sj-code">{{ t('pri.ref.code') }}</label><input id="sj-code" v-model="subjectForm.code" type="text" maxlength="40" required /></div>
        <div class="field"><label for="sj-name">{{ t('pri.ref.subjectName') }}</label><input id="sj-name" v-model="subjectForm.name" type="text" maxlength="160" required /></div>
        <div class="field">
          <label for="sj-dom">{{ t('pri.ref.domain') }}</label>
          <select id="sj-dom" v-model="subjectForm.domainId" required>
            <option value="" disabled>{{ t('pri.ref.pick') }}</option>
            <option v-for="d in domains" :key="d.id" :value="d.id">{{ d.label_fr }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sj-mode">{{ t('pri.ref.modeLabel') }}</label>
          <select id="sj-mode" v-model="subjectForm.mode">
            <option value="both">{{ t('pri.ref.mode.both') }}</option>
            <option value="numeric">{{ t('pri.ref.mode.numeric') }}</option>
            <option value="competency">{{ t('pri.ref.mode.competency') }}</option>
          </select>
        </div>
        <div><button type="submit" class="btn" :disabled="busy">{{ t('pri.ref.addSubject') }}</button></div>
      </form>

      <h3>{{ t('pri.ref.competencies') }}</h3>
      <ul class="list">
        <li v-for="c in competencies" :key="c.id" class="row row--between" :class="{ 'is-off': !c.active }">
          <span><strong>{{ c.code }}</strong> · {{ locale === 'en' && c.label_en ? c.label_en : c.label_fr }} <span class="muted">({{ domainLabel(c.domain_id) }})</span></span>
          <button v-if="canWrite" type="button" class="btn btn--quiet" :disabled="busy" @click="deactivate(c)">{{ c.active ? t('pri.ref.disable') : t('pri.ref.enable') }}</button>
        </li>
      </ul>
      <form v-if="canWrite && levelId" class="grid2" @submit.prevent="addCompetency">
        <div class="field"><label for="cp-code">{{ t('pri.ref.code') }}</label><input id="cp-code" v-model="competencyForm.code" type="text" maxlength="40" required /></div>
        <div class="field"><label for="cp-fr">{{ t('pri.ref.labelFr') }}</label><input id="cp-fr" v-model="competencyForm.fr" type="text" maxlength="255" required /></div>
        <div class="field"><label for="cp-en">{{ t('pri.ref.labelEn') }}</label><input id="cp-en" v-model="competencyForm.en" type="text" maxlength="255" /></div>
        <div class="field">
          <label for="cp-dom">{{ t('pri.ref.domain') }}</label>
          <select id="cp-dom" v-model="competencyForm.domainId" required>
            <option value="" disabled>{{ t('pri.ref.pick') }}</option>
            <option v-for="d in domains" :key="d.id" :value="d.id">{{ d.label_fr }}</option>
          </select>
        </div>
        <div class="field">
          <label for="cp-sub">{{ t('pri.ref.subject') }}</label>
          <select id="cp-sub" v-model="competencyForm.subjectId">
            <option value="">{{ t('pri.ref.noSubject') }}</option>
            <option v-for="s in subjects" :key="s.id" :value="s.subject_id">{{ s.subject_name }}</option>
          </select>
        </div>
        <div><button type="submit" class="btn" :disabled="busy">{{ t('pri.ref.addCompetency') }}</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="rf-exp">
      <h2 id="rf-exp">{{ t('pri.ref.export') }}</h2>
      <p class="row">
        <a v-for="k in (['levels', 'domains', 'subjects', 'competencies'] as const)" :key="k" class="btn btn--quiet" :href="primary.exportUrl(k, yearId)" download>{{ t(`pri.ref.export.${k}` as MessageKey) }}</a>
      </p>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 8px; }
.grow { flex: 1; }
.is-off { opacity: 0.55; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
