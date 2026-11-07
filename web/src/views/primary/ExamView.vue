<script setup lang="ts">
// Examen de fin de cycle (CEP, FSLC) préparé par l'école : session (centre, sous-centre), candidats du niveau d'examen, numéros,
// fiche d'inscription aux champs paramétrés, liste exportée, résultats OFFICIELS saisis ou importés tels que publiés (aucune note
// inventée), publication aux familles.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import { primaryP2, type ExamCandidate, type ExamCatalogue, type ExamSession, type ExamSheet } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import { usePicker } from './usePicker';
import { useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const picker = usePicker();
const catalogue = ref<ExamCatalogue | null>(null);
const sessions = ref<ExamSession[]>([]);
const sessionId = ref('');
const candidates = ref<ExamCandidate[]>([]);
const outcomes = reactive<Record<string, string>>({});
const sheet = ref<ExamSheet | null>(null);
const sheetValues = reactive<Record<string, string>>({});
const csv = ref('');
const notice = ref<string | null>(null);
const draft = reactive({ exam_code: '', level: '', centre: '', sub_centre: '', closes: '', written: '' });
const canManage = computed(() => can(...P.examManage));
const session = computed(() => sessions.value.find((s) => s.id === sessionId.value) ?? null);
const kindLabel = (code: string): string => {
  const k = catalogue.value?.kinds[code];
  return k ? (locale.value === 'en' ? k.en : k.fr) : code;
};

async function loadSessions(): Promise<void> {
  sessions.value = await primaryP2.examSessions(picker.year.value?.id);
  if (!sessions.value.some((s) => s.id === sessionId.value)) sessionId.value = sessions.value[0]?.id ?? '';
}

async function loadCandidates(): Promise<void> {
  candidates.value = [];
  sheet.value = null;
  if (sessionId.value === '') return;
  const list = await run(() => primaryP2.candidates(sessionId.value));
  candidates.value = list ?? [];
  for (const c of candidates.value) outcomes[c.id] = c.outcome;
}

async function create(): Promise<void> {
  const year = picker.year.value;
  if (!year) return;
  const created = await run(() => primaryP2.createExamSession({
    academic_year_id: year.id, exam_code: draft.exam_code, level_structure_id: draft.level,
    centre_label: draft.centre.trim() || null, sub_centre_label: draft.sub_centre.trim() || null,
    registration_closes_on: draft.closes || null, written_on: draft.written || null,
  }));
  if (created) {
    await loadSessions();
    sessionId.value = created.id;
  }
}

async function act(action: () => Promise<unknown>, message: (r: unknown) => string): Promise<void> {
  const done = await run(action);
  if (done !== undefined) {
    notice.value = message(done);
    await loadSessions();
    await loadCandidates();
  }
}

const enrol = (): Promise<void> => act(() => primaryP2.enrolLevel(sessionId.value), (r) => t('pri.exam.enrolled', r as { registered: number; existing: number }));
const numbers = (): Promise<void> => act(() => primaryP2.assignNumbers(sessionId.value), (r) => t('pri.exam.numbered', r as { assigned: number }));
const status = (next: 'draft' | 'open' | 'closed'): Promise<void> => act(() => primaryP2.examStatus(sessionId.value, next), () => t('pri.exam.statusChanged'));
const saveResults = (): Promise<void> => act(
  () => primaryP2.recordResults(sessionId.value, candidates.value.filter((c) => c.registration_status === 'registered').map((c) => ({ candidate_id: c.id, outcome: outcomes[c.id] ?? 'pending' }))),
  (r) => t('pri.exam.recorded', { recorded: (r as { recorded: number }).recorded, rejected: (r as { rejected: unknown[] }).rejected.length }),
);
const importCsv = (): Promise<void> => act(() => primaryP2.importResults(sessionId.value, csv.value), (r) => {
  const res = r as { recorded: number; rejected: { line: number; reason: string }[] };
  return t('pri.exam.recorded', { recorded: res.recorded, rejected: res.rejected.length }) + res.rejected.map((x) => ` · ${t('pri.exam.line', { line: x.line })} ${x.reason}`).join('');
});
const publish = (): Promise<void> => act(() => primaryP2.publishResults(sessionId.value), (r) => t('pri.exam.published', r as { notified: number }));
const withdraw = (c: ExamCandidate): Promise<void> => act(() => primaryP2.withdraw(c.id, t('pri.exam.withdrawReason')), () => t('pri.exam.withdrawn', { name: c.name }));

async function openSheet(c: ExamCandidate): Promise<void> {
  sheet.value = (await run(() => primaryP2.examSheet(c.id))) ?? null;
  for (const key of Object.keys(sheetValues)) delete sheetValues[key];
  for (const f of sheet.value?.fields ?? []) sheetValues[f.code] = f.value ?? '';
}

async function saveSheet(): Promise<void> {
  const current = sheet.value;
  if (!current) return;
  const fields: Record<string, string | null> = {};
  for (const [k, v] of Object.entries(sheetValues)) fields[k] = v.trim() === '' ? null : v.trim();
  const done = await run(() => primaryP2.updateCandidate(current.candidate.id, { fields }));
  if (done) await openSheet({ ...done, name: '' });
}

function printSheet(): void {
  window.print();
}

watch(sessionId, () => void loadCandidates());
onMounted(async () => {
  await run(async () => {
    await picker.load();
    catalogue.value = await primaryP2.examCatalogue();
    draft.exam_code = Object.keys(catalogue.value.kinds)[0] ?? '';
    draft.level = picker.levels.value[picker.levels.value.length - 1]?.id ?? '';
    await loadSessions();
  });
  await loadCandidates();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.exam.title') }}</h1>
      <p class="lede">{{ t('pri.exam.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>

    <section class="card">
      <div v-if="sessions.length" class="field">
        <label for="ex-session">{{ t('pri.exam.session') }}</label>
        <select id="ex-session" v-model="sessionId"><option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.label }} · {{ picker.levelName(s.level_structure_id) }}</option></select>
      </div>
      <p v-else class="muted">{{ t('pri.exam.none') }}</p>
      <form v-if="canManage && catalogue" class="form" @submit.prevent="create">
        <h2>{{ t('pri.exam.create') }}</h2>
        <div class="grid2">
          <div class="field"><label for="ex-code">{{ t('pri.exam.exam') }}</label><select id="ex-code" v-model="draft.exam_code"><option v-for="(k, code) in catalogue.kinds" :key="code" :value="code">{{ kindLabel(String(code)) }}</option></select></div>
          <div class="field"><label for="ex-level">{{ t('pri.exam.level') }}</label><select id="ex-level" v-model="draft.level"><option v-for="l in picker.levels.value" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
          <div class="field"><label for="ex-centre">{{ t('pri.exam.centre') }}</label><input id="ex-centre" v-model="draft.centre" type="text" maxlength="160" /></div>
          <div class="field"><label for="ex-sub">{{ t('pri.exam.subCentre') }}</label><input id="ex-sub" v-model="draft.sub_centre" type="text" maxlength="160" /></div>
          <div class="field"><label for="ex-closes">{{ t('pri.exam.closes') }}</label><input id="ex-closes" v-model="draft.closes" type="date" /></div>
          <div class="field"><label for="ex-written">{{ t('pri.exam.written') }}</label><input id="ex-written" v-model="draft.written" type="date" /></div>
        </div>
        <button type="submit" class="btn" :disabled="busy || draft.level === ''">{{ t('pri.exam.createButton') }}</button>
      </form>
    </section>

    <template v-if="session">
      <section class="card" aria-labelledby="ex-head">
        <h2 id="ex-head">{{ session.label }}</h2>
        <p>
          <span class="tag">{{ t(`pri.exam.status.${session.status}` as MessageKey) }}</span>
          <template v-if="session.sub_centre_label"> · {{ t('pri.exam.subCentre') }} : {{ session.sub_centre_label }}</template>
          <template v-if="session.written_on"> · {{ t('pri.exam.written') }} : {{ session.written_on }}</template>
        </p>
        <p class="muted">{{ t('pri.exam.stats', { registered: session.stats.registered, passed: session.stats.passed, failed: session.stats.failed, absent: session.stats.absent }) }}<template v-if="session.stats.pass_rate !== null"> · {{ t('pri.exam.rate', { rate: session.stats.pass_rate }) }}</template></p>
        <div v-if="canManage" class="actions">
          <button v-if="session.status !== 'closed'" type="button" class="btn" :disabled="busy" @click="enrol">{{ t('pri.exam.enrol') }}</button>
          <button type="button" class="btn" :disabled="busy" @click="numbers">{{ t('pri.exam.numbers') }}</button>
          <button v-if="session.status === 'draft'" type="button" class="btn" :disabled="busy" @click="status('open')">{{ t('pri.exam.openRegistrations') }}</button>
          <button v-if="session.status === 'open'" type="button" class="btn" :disabled="busy" @click="status('closed')">{{ t('pri.exam.closeRegistrations') }}</button>
          <button v-if="session.status === 'closed'" type="button" class="btn btn--quiet" :disabled="busy" @click="status('open')">{{ t('pri.exam.reopenRegistrations') }}</button>
        </div>
        <ExportButton :path="`/primary/exam-sessions/${session.id}/export`" name="candidats-examen" :caption="t('pri.exam.export')" />
      </section>

      <section class="card" aria-labelledby="ex-cands">
        <h2 id="ex-cands">{{ t('pri.exam.candidates') }}</h2>
        <p v-if="candidates.length === 0" class="muted">{{ t('pri.exam.noCandidate') }}</p>
        <ul class="rows">
          <li v-for="c in candidates" :key="c.id">
            <span><strong>{{ c.name }}</strong><template v-if="c.candidate_number"> · {{ c.candidate_number }}</template></span>
            <span v-if="c.registration_status === 'withdrawn'" class="tag">{{ t('pri.exam.withdrawnTag') }}</span>
            <template v-else>
              <label class="sr-only" :for="`ex-out-${c.id}`">{{ t('pri.exam.outcome') }}</label>
              <select v-if="canManage && session.status === 'closed'" :id="`ex-out-${c.id}`" v-model="outcomes[c.id]">
                <option v-for="o in catalogue?.outcomes ?? []" :key="o" :value="o">{{ t(`pri.exam.outcomes.${o}` as MessageKey) }}</option>
              </select>
              <span v-else class="tag">{{ t(`pri.exam.outcomes.${c.outcome}` as MessageKey) }}</span>
              <button type="button" class="btn btn--quiet" @click="openSheet(c)">{{ t('pri.exam.sheet') }}</button>
              <button v-if="canManage && session.status !== 'closed'" type="button" class="btn btn--quiet" @click="withdraw(c)">{{ t('pri.exam.withdraw') }}</button>
            </template>
          </li>
        </ul>
        <template v-if="canManage && session.status === 'closed'">
          <button type="button" class="btn btn--primary" :disabled="busy" @click="saveResults">{{ t('pri.exam.saveResults') }}</button>
          <div class="field">
            <label for="ex-csv">{{ t('pri.exam.import') }}</label>
            <textarea id="ex-csv" v-model="csv" rows="4" :placeholder="t('pri.exam.importHint')" />
          </div>
          <div class="actions">
            <button type="button" class="btn" :disabled="busy || csv.trim() === ''" @click="importCsv">{{ t('pri.exam.importButton') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="publish">{{ t('pri.exam.publish') }}</button>
          </div>
        </template>
      </section>

      <section v-if="sheet" class="card print-area" aria-labelledby="ex-sheet">
        <h2 id="ex-sheet">{{ t('pri.exam.sheetTitle') }} · {{ sheet.session.label }}</h2>
        <p><strong>{{ sheet.learner.last_name }} {{ sheet.learner.first_name }}</strong><template v-if="sheet.learner.birth_date"> · {{ t('pri.exam.born', { date: sheet.learner.birth_date }) }}</template><template v-if="sheet.class_name"> · {{ sheet.class_name }}</template></p>
        <p>{{ t('pri.exam.number') }} {{ sheet.candidate.candidate_number ?? '—' }} · {{ t('pri.exam.centre') }} {{ sheet.session.centre_label ?? '—' }} · {{ t('pri.exam.subCentre') }} {{ sheet.candidate.sub_centre_label ?? sheet.session.sub_centre_label ?? '—' }}</p>
        <form class="form" @submit.prevent="saveSheet">
          <div v-for="f in sheet.fields" :key="f.code" class="field">
            <label :for="`ex-f-${f.code}`">{{ locale === 'en' ? f.en : f.fr }}<template v-if="f.required"> *</template></label>
            <input :id="`ex-f-${f.code}`" v-model="sheetValues[f.code]" type="text" maxlength="160" :disabled="!canManage" />
          </div>
          <p v-if="sheet.missing_required.length" class="notice">{{ t('pri.exam.missing', { count: sheet.missing_required.length }) }}</p>
          <div class="actions">
            <button v-if="canManage" type="submit" class="btn" :disabled="busy">{{ t('pri.exam.saveSheet') }}</button>
            <button type="button" class="btn btn--quiet" @click="printSheet">{{ t('pri.exam.print') }}</button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; }
.form { display: grid; gap: 6px; margin-top: 12px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0; }
.rows { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.rows li { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; border-bottom: 1px solid var(--border, #dcdcd8); padding: 6px 0; }
textarea { width: 100%; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
