<script setup lang="ts">
// Paramétrage du secondaire (T820 à T822, T978) : niveaux (exemples à confirmer), séries et spécialités ouvertes, classes (cycle du
// profil, série obligatoire au 2nd cycle ou en technique), matières, coefficients et volumes saisis par l'établissement (versions avec
// motif, année gelée, copie), salles, règlement intérieur. Mobile d'abord ; le serveur reste juge (422 et 409 affichés tels quels).
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { AcademicYear } from '@/api/admin';
import {
  groupByLevel, officialTotal, parseRuleValue, ORIGINS, ROOM_KINDS, SUBJECT_ROLES, secondarySetup,
  type CatalogueStream, type Coefficient, type Freeze, type Level, type OpenedStream, type Room, type Rule, type SchoolClass, type Subject,
} from '@/api/secondarySetup';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

type Tab = 'levels' | 'streams' | 'classes' | 'curriculum' | 'rooms' | 'rules';
const TABS: Tab[] = ['levels', 'streams', 'classes', 'curriculum', 'rooms', 'rules'];
const SETTINGS_WRITE = ['secondary.settings.write', 'secondary.settings.manage'] as const;

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canWrite = computed(() => can(...SETTINGS_WRITE));
const canClasses = computed(() => can(...SETTINGS_WRITE, 'secondary.enrol.manage'));
const canManage = computed(() => can('secondary.settings.manage'));

const tab = ref<Tab>('levels');
const done = ref<MessageKey | null>(null);
const years = ref<AcademicYear[]>([]);
const year = ref('');
const levels = ref<Level[]>([]);
const streams = ref<OpenedStream[]>([]);
const catalogue = ref<CatalogueStream[]>([]);
const classes = ref<SchoolClass[]>([]);
const subjects = ref<Subject[]>([]);
const coefficients = ref<Coefficient[]>([]);
const history = ref(false);
const freezes = ref<Freeze[]>([]);
const rooms = ref<Room[]>([]);
const rules = ref<Record<string, Rule>>({});

const today = new Date().toISOString().slice(0, 10);
const levelForm = reactive({ code: '', label: '', cycle_code: 'first' as Level['cycle_code'], section_code: 'fr' as Level['section_code'], teaching_kind: 'general' as Level['teaching_kind'] });
const streamRef = ref('');
const classForm = reactive({ label: '', level_id: '', stream_id: '', capacity: '' });
const subjectForm = reactive({ code: '', label: '', label_en: '' });
const coefForm = reactive({ level_code: '', stream_id: '', subject_ref: '', coefficient: '', weekly_hours: '', subject_role: 'official' as Coefficient['subject_role'], origin: 'custom' as Coefficient['origin'], source_ref: '', valid_from: today, reason: '' });
const copyForm = reactive({ to: '', reason: '' });
const freezeReason = ref('');
const roomForm = reactive({ room_id: '', label: '', room_kind: 'classroom' as Room['room_kind'], capacity: '', equipment: '' });
const ruleDrafts = reactive<Record<string, { text: string; reason: string; invalid: boolean }>>({});

const frozen = computed(() => freezes.value.some((f) => f.academic_year_id === year.value));
const activeLevels = computed(() => levels.value.filter((l) => l.active));
const selectedLevel = computed(() => levels.value.find((l) => l.id === classForm.level_id) ?? null);
const streamChoices = computed(() => streams.value.filter((s) => s.active && selectedLevel.value !== null
  && s.cycle_code === selectedLevel.value.cycle_code && s.teaching_kind === selectedLevel.value.teaching_kind && s.section_code === selectedLevel.value.section_code));
const needsStream = computed(() => selectedLevel.value !== null && (selectedLevel.value.cycle_code === 'second' || selectedLevel.value.teaching_kind === 'technical'));
const openableCatalogue = computed(() => catalogue.value.filter((c) => !c.hidden && !streams.value.some((s) => s.stream_ref_id === c.id)));
const groups = computed(() => groupByLevel(coefficients.value));
const streamLabel = (id: string | null): string => (id === null ? '' : streams.value.find((s) => s.id === id)?.code ?? id);
const levelLabel = (id: string): string => levels.value.find((l) => l.id === id)?.label ?? id;

function flash(key: MessageKey): void {
  done.value = key;
}

async function loadYearData(): Promise<void> {
  if (year.value === '') return;
  classes.value = (await run(() => secondarySetup.classes(year.value))) ?? [];
  coefficients.value = (await run(() => secondarySetup.coefficients(year.value, history.value))) ?? [];
}
async function load(): Promise<void> {
  years.value = (await run(() => secondarySetup.years())) ?? [];
  if (year.value === '' && years.value.length > 0) year.value = years.value.find((y) => y.status === 'open')?.id ?? years.value[0]?.id ?? '';
  levels.value = (await run(() => secondarySetup.levels())) ?? [];
  streams.value = (await run(() => secondarySetup.streams())) ?? [];
  subjects.value = (await run(() => secondarySetup.subjects())) ?? [];
  freezes.value = (await run(() => secondarySetup.freezes())) ?? [];
  rooms.value = (await run(() => secondarySetup.rooms())) ?? [];
  rules.value = (await run(() => secondarySetup.rules())) ?? {};
  for (const [key, rule] of Object.entries(rules.value)) {
    ruleDrafts[key] = { text: rule.value === null ? '' : JSON.stringify(rule.value), reason: '', invalid: false };
  }
  await loadYearData();
}

async function loadExamples(): Promise<void> {
  const list = await run(() => secondarySetup.loadExampleLevels());
  if (list) {
    levels.value = list;
    flash('secs.examplesLoaded');
  }
}
async function addLevel(): Promise<void> {
  if (await run(() => secondarySetup.createLevel({ ...levelForm, code: levelForm.code.trim(), label: levelForm.label.trim() }))) {
    Object.assign(levelForm, { code: '', label: '' });
    levels.value = (await run(() => secondarySetup.levels())) ?? levels.value;
    flash('secs.saved');
  }
}
async function toggleLevel(level: Level): Promise<void> {
  const updated = await run(() => secondarySetup.updateLevel(level.id, { active: !level.active }));
  if (updated) levels.value = levels.value.map((l) => (l.id === updated.id ? updated : l));
}
async function renameLevel(level: Level): Promise<void> {
  const label = (window.prompt(t('secs.renamePrompt'), level.label) ?? '').trim();
  if (label === '' || label === level.label) return;
  const updated = await run(() => secondarySetup.updateLevel(level.id, { label }));
  if (updated) levels.value = levels.value.map((l) => (l.id === updated.id ? updated : l));
}

async function loadCatalogue(): Promise<void> {
  if (catalogue.value.length === 0) catalogue.value = (await run(() => secondarySetup.catalogue())) ?? [];
}
async function openStream(): Promise<void> {
  if (streamRef.value === '') return;
  if (await run(() => secondarySetup.openStream(streamRef.value))) {
    streamRef.value = '';
    streams.value = (await run(() => secondarySetup.streams())) ?? streams.value;
    flash('secs.saved');
  }
}
async function toggleStream(stream: OpenedStream): Promise<void> {
  const updated = await run(() => secondarySetup.updateStream(stream.id, { active: !stream.active }));
  if (updated) streams.value = streams.value.map((s) => (s.id === updated.id ? updated : s));
}

async function addClass(): Promise<void> {
  const capacity = classForm.capacity === '' ? null : Number(classForm.capacity);
  const created = await run(() => secondarySetup.createClass({
    label: classForm.label.trim(), level_id: classForm.level_id, academic_year_id: year.value,
    stream_id: classForm.stream_id === '' ? null : classForm.stream_id, capacity,
  }));
  if (created) {
    classes.value = [...classes.value, created];
    Object.assign(classForm, { label: '', stream_id: '', capacity: '' });
    flash('secs.saved');
  }
}

async function addSubject(): Promise<void> {
  const created = await run(() => secondarySetup.createSubject({ code: subjectForm.code.trim(), label: subjectForm.label.trim(), label_en: subjectForm.label_en.trim() === '' ? null : subjectForm.label_en.trim() }));
  if (created) {
    subjects.value = [...subjects.value, created];
    Object.assign(subjectForm, { code: '', label: '', label_en: '' });
    flash('secs.saved');
  }
}
async function saveCoefficient(): Promise<void> {
  const saved = await run(() => secondarySetup.setCoefficient({
    academic_year_id: year.value, level_code: coefForm.level_code, stream_id: coefForm.stream_id === '' ? null : coefForm.stream_id,
    subject_ref: coefForm.subject_ref, coefficient: Number(coefForm.coefficient), weekly_hours: Number(coefForm.weekly_hours),
    subject_role: coefForm.subject_role, origin: coefForm.origin, source_ref: coefForm.source_ref.trim() === '' ? null : coefForm.source_ref.trim(),
    valid_from: coefForm.valid_from, reason: coefForm.reason.trim(),
  }));
  if (saved) {
    Object.assign(coefForm, { coefficient: '', weekly_hours: '', reason: '' });
    await loadYearData();
    flash('secs.versionSaved');
  }
}
function editCoefficient(line: Coefficient): void {
  Object.assign(coefForm, {
    level_code: line.level_code, stream_id: line.stream_id ?? '', subject_ref: line.subject_ref, coefficient: String(line.coefficient),
    weekly_hours: String(line.weekly_hours), subject_role: line.subject_role, origin: line.origin, source_ref: line.source_ref ?? '', reason: '',
  });
}
async function freezeYear(): Promise<void> {
  if (freezeReason.value.trim() === '' || !window.confirm(t('secs.freezeConfirm'))) return;
  if (await run(() => secondarySetup.freeze(year.value, freezeReason.value.trim()))) {
    freezeReason.value = '';
    freezes.value = (await run(() => secondarySetup.freezes())) ?? freezes.value;
    flash('secs.frozenDone');
  }
}
async function copyYear(): Promise<void> {
  if (copyForm.to === '' || copyForm.reason.trim() === '') return;
  const target = years.value.find((y) => y.id === copyForm.to);
  const result = await run(() => secondarySetup.copy(year.value, copyForm.to, target?.starts_on ?? today, copyForm.reason.trim()));
  if (result) {
    done.value = null;
    window.alert(t('secs.copied', { copied: result.copied, skipped: result.skipped }));
    copyForm.reason = '';
  }
}

async function saveRoom(): Promise<void> {
  const saved = await run(() => secondarySetup.saveRoom({
    room_id: roomForm.room_id.trim(), label: roomForm.label.trim(), room_kind: roomForm.room_kind,
    capacity: roomForm.capacity === '' ? null : Number(roomForm.capacity), specialty_stream_id: null,
    equipment: roomForm.equipment.trim() === '' ? null : roomForm.equipment.trim(), safety_notes: null,
  }));
  if (saved) {
    rooms.value = (await run(() => secondarySetup.rooms())) ?? rooms.value;
    Object.assign(roomForm, { room_id: '', label: '', capacity: '', equipment: '' });
    flash('secs.saved');
  }
}

async function saveRule(key: string): Promise<void> {
  const draft = ruleDrafts[key];
  if (!draft) return;
  const parsed = parseRuleValue(draft.text);
  draft.invalid = !parsed.ok;
  if (!parsed.ok || draft.reason.trim() === '') return;
  const saved = await run(() => secondarySetup.setRule(key, parsed.value, today, draft.reason.trim()));
  if (saved) {
    rules.value = { ...rules.value, [key]: saved };
    draft.reason = '';
    flash('secs.versionSaved');
  }
}

watch(year, loadYearData);
watch(history, loadYearData);
watch(tab, async (value) => {
  done.value = null;
  if (value === 'streams') await loadCatalogue();
});
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secs.title') }}</h1>
      <p class="lede">{{ t('secs.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ t(done) }}</p>

    <div class="field">
      <label for="secs-year">{{ t('secs.year') }}</label>
      <select id="secs-year" v-model="year">
        <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
      </select>
      <p v-if="years.length === 0" class="muted">{{ t('secs.noYear') }}</p>
    </div>

    <nav class="tabs" :aria-label="t('secs.title')">
      <button v-for="item in TABS" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`secs.tab.${item}`) }}</button>
    </nav>

    <section v-if="tab === 'levels'" class="card" aria-labelledby="secs-levels">
      <h2 id="secs-levels">{{ t('secs.tab.levels') }}</h2>
      <p class="muted">{{ t('secs.levelsHelp') }}</p>
      <button v-if="canWrite" type="button" class="btn" :disabled="busy" @click="loadExamples">{{ t('secs.loadExamples') }}</button>
      <p v-if="levels.length === 0" class="muted">{{ t('secs.noLevels') }}</p>
      <ul v-else class="rows">
        <li v-for="l in levels" :key="l.id" :class="{ off: !l.active }">
          <strong>{{ l.label }}</strong> <span class="muted">· {{ l.code }} · {{ t(`secs.cycle.${l.cycle_code}`) }} · {{ t(`secs.teaching.${l.teaching_kind}`) }} · {{ l.section_code.toUpperCase() }}</span>
          <span v-if="l.origin === 'example'" class="tag tag--warning">{{ t('secs.toConfirm') }}</span>
          <div v-if="canWrite" class="actions">
            <button type="button" class="btn" :disabled="busy" @click="renameLevel(l)">{{ t('secs.rename') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="toggleLevel(l)">{{ l.active ? t('secs.disable') : t('secs.enable') }}</button>
          </div>
        </li>
      </ul>
      <form v-if="canWrite" class="grid" @submit.prevent="addLevel">
        <h3>{{ t('secs.addLevel') }}</h3>
        <div class="field"><label for="lv-code">{{ t('secs.code') }}</label><input id="lv-code" v-model="levelForm.code" maxlength="20" required /></div>
        <div class="field"><label for="lv-label">{{ t('secs.label') }}</label><input id="lv-label" v-model="levelForm.label" maxlength="120" required /></div>
        <div class="field">
<label for="lv-cycle">{{ t('secs.cycleLabel') }}</label>
          <select id="lv-cycle" v-model="levelForm.cycle_code"><option value="first">{{ t('secs.cycle.first') }}</option><option value="second">{{ t('secs.cycle.second') }}</option></select>
</div>
        <div class="field">
<label for="lv-teach">{{ t('secs.teachingLabel') }}</label>
          <select id="lv-teach" v-model="levelForm.teaching_kind"><option value="general">{{ t('secs.teaching.general') }}</option><option value="technical">{{ t('secs.teaching.technical') }}</option></select>
</div>
        <div class="field">
<label for="lv-section">{{ t('secs.sectionLabel') }}</label>
          <select id="lv-section" v-model="levelForm.section_code"><option value="fr">FR</option><option value="en">EN</option></select>
</div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secs.add') }}</button>
      </form>
    </section>

    <section v-if="tab === 'streams'" class="card" aria-labelledby="secs-streams">
      <h2 id="secs-streams">{{ t('secs.tab.streams') }}</h2>
      <p class="muted">{{ t('secs.streamsHelp') }}</p>
      <ul class="rows">
        <li v-for="s in streams" :key="s.id" :class="{ off: !s.active }">
          <strong>{{ s.code }}</strong> {{ s.label }} <span class="muted">· {{ t(`secs.cycle.${s.cycle_code}`) }}</span>
          <div v-if="canWrite" class="actions"><button type="button" class="btn" :disabled="busy" @click="toggleStream(s)">{{ s.active ? t('secs.disable') : t('secs.enable') }}</button></div>
        </li>
      </ul>
      <p v-if="streams.length === 0" class="muted">{{ t('secs.noStreams') }}</p>
      <form v-if="canWrite" class="grid" @submit.prevent="openStream">
        <div class="field">
          <label for="st-ref">{{ t('secs.openStream') }}</label>
          <select id="st-ref" v-model="streamRef">
            <option value="">—</option>
            <option v-for="c in openableCatalogue" :key="c.id" :value="c.id">{{ c.code }} · {{ c.label }} ({{ t(`secs.cycle.${c.cycle_code}`) }})</option>
          </select>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy || streamRef === ''">{{ t('secs.open') }}</button>
      </form>
    </section>

    <section v-if="tab === 'classes'" class="card" aria-labelledby="secs-classes">
      <h2 id="secs-classes">{{ t('secs.tab.classes') }}</h2>
      <p class="muted">{{ t('secs.classesHelp') }}</p>
      <ul class="rows">
        <li v-for="c in classes" :key="c.id">
          <strong>{{ c.label }}</strong> <span class="muted">· {{ levelLabel(c.level_id) }}<template v-if="c.stream_id"> · {{ streamLabel(c.stream_id) }}</template><template v-if="c.capacity"> · {{ t('secs.capacityShort', { n: c.capacity }) }}</template></span>
        </li>
      </ul>
      <p v-if="classes.length === 0" class="muted">{{ t('secs.noClasses') }}</p>
      <form v-if="canClasses && year !== ''" class="grid" @submit.prevent="addClass">
        <h3>{{ t('secs.addClass') }}</h3>
        <div class="field"><label for="cl-label">{{ t('secs.label') }}</label><input id="cl-label" v-model="classForm.label" maxlength="120" required /></div>
        <div class="field">
<label for="cl-level">{{ t('secs.level') }}</label>
          <select id="cl-level" v-model="classForm.level_id" required><option v-for="l in activeLevels" :key="l.id" :value="l.id">{{ l.label }} ({{ l.section_code.toUpperCase() }})</option></select>
</div>
        <div v-if="needsStream || streamChoices.length > 0" class="field">
          <label for="cl-stream">{{ t('secs.stream') }}<template v-if="needsStream"> *</template></label>
          <select id="cl-stream" v-model="classForm.stream_id" :required="needsStream"><option value="">—</option><option v-for="s in streamChoices" :key="s.id" :value="s.id">{{ s.code }} · {{ s.label }}</option></select>
          <p v-if="needsStream" class="muted">{{ t('secs.streamRequired') }}</p>
        </div>
        <div class="field"><label for="cl-cap">{{ t('secs.capacity') }}</label><input id="cl-cap" v-model="classForm.capacity" type="number" min="1" max="500" inputmode="numeric" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secs.add') }}</button>
      </form>
    </section>

    <section v-if="tab === 'curriculum'" class="card" aria-labelledby="secs-curriculum">
      <h2 id="secs-curriculum">{{ t('secs.tab.curriculum') }}</h2>
      <p class="muted">{{ t('secs.curriculumHelp') }}</p>
      <p v-if="frozen" class="notice" role="status">{{ t('secs.frozen') }}</p>

      <h3>{{ t('secs.subjects') }}</h3>
      <ul class="chips">
        <li v-for="s in subjects" :key="s.id" class="tag" :class="{ off: !s.active }">{{ s.code }} · {{ s.label }}</li>
      </ul>
      <form v-if="canWrite" class="grid" @submit.prevent="addSubject">
        <div class="field"><label for="sb-code">{{ t('secs.code') }}</label><input id="sb-code" v-model="subjectForm.code" maxlength="20" required /></div>
        <div class="field"><label for="sb-label">{{ t('secs.label') }}</label><input id="sb-label" v-model="subjectForm.label" maxlength="160" required /></div>
        <div class="field"><label for="sb-en">{{ t('secs.labelEn') }}</label><input id="sb-en" v-model="subjectForm.label_en" maxlength="160" /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('secs.addSubject') }}</button>
      </form>

      <h3>{{ t('secs.coefficients') }}</h3>
      <label class="check"><input v-model="history" type="checkbox" /> {{ t('secs.showHistory') }}</label>
      <p v-if="coefficients.length === 0" class="muted">{{ t('secs.noCoefficients') }}</p>
      <div v-for="g in groups" :key="g.key" class="group">
        <h4>{{ g.level }}<template v-if="g.streamId"> · {{ streamLabel(g.streamId) }}</template> <span class="muted">— {{ t('secs.officialTotal', { total: officialTotal(g.lines.filter((l) => l.superseded_at === null)) }) }}</span></h4>
        <ul class="rows">
          <li v-for="c in g.lines" :key="c.id" :class="{ off: c.superseded_at !== null }">
            <strong>{{ c.subject_label }}</strong>
            <span class="muted"> · {{ t('secs.coefShort', { coef: c.coefficient, hours: c.weekly_hours }) }} · v{{ c.version_no }} · {{ c.valid_from }}</span>
            <span v-if="!c.counts_in_average" class="tag">{{ t('secs.outOfAverage') }}</span>
            <span v-if="c.locked" class="tag">{{ t('secs.locked') }}</span>
            <span v-if="c.confirmation === 'to_confirm'" class="tag tag--warning">{{ t('secs.toConfirm') }}</span>
            <div class="muted small">{{ t('secs.changedBy', { who: c.changed_by, reason: c.change_reason }) }}</div>
            <button v-if="canWrite && !c.locked && !frozen && c.superseded_at === null" type="button" class="btn" @click="editCoefficient(c)">{{ t('secs.newVersion') }}</button>
          </li>
        </ul>
      </div>
      <form v-if="canWrite && !frozen && year !== ''" class="grid" @submit.prevent="saveCoefficient">
        <h4>{{ t('secs.setCoefficient') }}</h4>
        <div class="field">
<label for="cf-level">{{ t('secs.level') }}</label>
          <select id="cf-level" v-model="coefForm.level_code" required><option v-for="l in activeLevels" :key="l.id" :value="l.code">{{ l.label }}</option></select>
</div>
        <div class="field">
<label for="cf-stream">{{ t('secs.stream') }}</label>
          <select id="cf-stream" v-model="coefForm.stream_id"><option value="">{{ t('secs.allStreams') }}</option><option v-for="s in streams" :key="s.id" :value="s.id">{{ s.code }}</option></select>
</div>
        <div class="field">
<label for="cf-subject">{{ t('secs.subject') }}</label>
          <select id="cf-subject" v-model="coefForm.subject_ref" required><option v-for="s in subjects.filter((x) => x.active)" :key="s.id" :value="s.id">{{ s.label }}</option></select>
</div>
        <div class="field"><label for="cf-coef">{{ t('secs.coefficient') }}</label><input id="cf-coef" v-model="coefForm.coefficient" type="number" min="0" max="99" step="0.01" inputmode="decimal" required /></div>
        <div class="field"><label for="cf-hours">{{ t('secs.weeklyHours') }}</label><input id="cf-hours" v-model="coefForm.weekly_hours" type="number" min="0" max="60" step="0.25" inputmode="decimal" required /></div>
        <div class="field">
<label for="cf-role">{{ t('secs.role') }}</label>
          <select id="cf-role" v-model="coefForm.subject_role"><option v-for="r in SUBJECT_ROLES" :key="r" :value="r">{{ t(`secs.roles.${r}`) }}</option></select>
</div>
        <div class="field">
<label for="cf-origin">{{ t('secs.origin') }}</label>
          <select id="cf-origin" v-model="coefForm.origin"><option v-for="o in ORIGINS" :key="o" :value="o">{{ t(`secs.origins.${o}`) }}</option></select>
</div>
        <div v-if="coefForm.origin === 'official'" class="field"><label for="cf-source">{{ t('secs.sourceRef') }}</label><input id="cf-source" v-model="coefForm.source_ref" maxlength="120" required /></div>
        <div class="field"><label for="cf-from">{{ t('secs.validFrom') }}</label><input id="cf-from" v-model="coefForm.valid_from" type="date" required /></div>
        <div class="field"><label for="cf-reason">{{ t('secs.reason') }}</label><input id="cf-reason" v-model="coefForm.reason" maxlength="255" required /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secs.saveVersion') }}</button>
      </form>
      <div v-if="canManage && year !== ''" class="grid">
        <h4>{{ t('secs.yearTools') }}</h4>
        <div v-if="!frozen" class="field">
          <label for="fz-reason">{{ t('secs.freezeReason') }}</label><input id="fz-reason" v-model="freezeReason" maxlength="255" />
          <button type="button" class="btn" :disabled="busy || freezeReason.trim() === ''" @click="freezeYear">{{ t('secs.freeze') }}</button>
        </div>
        <div class="field">
          <label for="cp-to">{{ t('secs.copyTo') }}</label>
          <select id="cp-to" v-model="copyForm.to"><option value="">—</option><option v-for="y in years.filter((x) => x.id !== year)" :key="y.id" :value="y.id">{{ y.label }}</option></select>
          <label for="cp-reason">{{ t('secs.reason') }}</label><input id="cp-reason" v-model="copyForm.reason" maxlength="255" />
          <button type="button" class="btn" :disabled="busy || copyForm.to === '' || copyForm.reason.trim() === ''" @click="copyYear">{{ t('secs.copy') }}</button>
        </div>
      </div>
    </section>

    <section v-if="tab === 'rooms'" class="card" aria-labelledby="secs-rooms">
      <h2 id="secs-rooms">{{ t('secs.tab.rooms') }}</h2>
      <p class="muted">{{ t('secs.roomsHelp') }}</p>
      <ul class="rows">
        <li v-for="r in rooms" :key="r.id">
<strong>{{ r.label }}</strong> <span class="muted">· {{ t(`secs.roomKinds.${r.room_kind}`) }}<template v-if="r.capacity"> · {{ t('secs.capacityShort', { n: r.capacity }) }}</template></span>
          <div v-if="r.equipment" class="muted small">{{ r.equipment }}</div>
</li>
      </ul>
      <form v-if="canWrite" class="grid" @submit.prevent="saveRoom">
        <div class="field"><label for="rm-id">{{ t('secs.roomId') }}</label><input id="rm-id" v-model="roomForm.room_id" maxlength="40" required /></div>
        <div class="field"><label for="rm-label">{{ t('secs.label') }}</label><input id="rm-label" v-model="roomForm.label" maxlength="120" required /></div>
        <div class="field">
<label for="rm-kind">{{ t('secs.roomKind') }}</label>
          <select id="rm-kind" v-model="roomForm.room_kind"><option v-for="k in ROOM_KINDS" :key="k" :value="k">{{ t(`secs.roomKinds.${k}`) }}</option></select>
</div>
        <div class="field"><label for="rm-cap">{{ t('secs.capacity') }}</label><input id="rm-cap" v-model="roomForm.capacity" type="number" min="1" max="2000" inputmode="numeric" /></div>
        <div class="field"><label for="rm-eq">{{ t('secs.equipment') }}</label><input id="rm-eq" v-model="roomForm.equipment" maxlength="2000" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secs.save') }}</button>
      </form>
    </section>

    <section v-if="tab === 'rules'" class="card" aria-labelledby="secs-rules">
      <h2 id="secs-rules">{{ t('secs.tab.rules') }}</h2>
      <p class="muted">{{ t('secs.rulesHelp') }}</p>
      <ul class="rows">
        <li v-for="(rule, key) in rules" :key="key">
          <strong>{{ t(`secs.rule.${key}` as MessageKey) }}</strong>
          <span class="tag" :class="{ 'tag--warning': rule.source !== 'establishment' }">{{ t(`secs.ruleSource.${rule.source}`) }}</span>
          <div v-if="rule.version_no" class="muted small">{{ t('secs.ruleVersion', { n: rule.version_no, from: rule.valid_from ?? '', reason: rule.change_reason ?? '' }) }}</div>
          <form v-if="canManage && ruleDrafts[key]" class="grid" @submit.prevent="saveRule(String(key))">
            <label :for="`rl-${key}`">{{ t('secs.ruleValue', { shape: rule.shape }) }}</label>
            <textarea :id="`rl-${key}`" v-model="ruleDrafts[key].text" rows="2" spellcheck="false" />
            <p v-if="ruleDrafts[key].invalid" class="error-text">{{ t('secs.invalidJson') }}</p>
            <label :for="`rr-${key}`">{{ t('secs.reason') }}</label>
            <input :id="`rr-${key}`" v-model="ruleDrafts[key].reason" maxlength="255" />
            <button type="submit" class="btn" :disabled="busy || ruleDrafts[key].reason.trim() === ''">{{ t('secs.saveVersion') }}</button>
          </form>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
.tab { border: 1px solid var(--border, #ccc); background: transparent; border-radius: 999px; padding: 6px 12px; min-height: 44px; }
.tab[aria-pressed='true'] { background: var(--accent, #1d4ed8); color: #fff; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.grid { display: grid; gap: 8px; margin-top: 12px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.chips { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
.check { display: flex; gap: 8px; align-items: center; }
.off { opacity: 0.6; }
.small { font-size: 0.875rem; }
.group { margin-top: 12px; }
textarea, input, select { max-width: 100%; }
</style>
