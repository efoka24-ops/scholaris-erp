<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { saveBlob } from '@/api/exports';
import { secondarySetup, type SchoolClass } from '@/api/secondarySetup';
import { useAction, usePermissions } from '../useSec';
import { secondaryOfficialExams, type ExamCandidate, type ExamSession, type ExamSummary } from './secondaryOfficialExams';

const { t } = useI18n();
const STATUS_KEYS = { blocked: 'secx.status.blocked', incomplete: 'secx.status.incomplete', eligible: 'secx.status.eligible' } as const;
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canManage = computed(() => can('secondary.exams.manage', 'secondary.exams.lists'));
const canValidate = computed(() => can('secondary.exams.validate'));
const canRead = computed(() => can('secondary.exams.read', 'secondary.exams.manage', 'secondary.exams.lists', 'secondary.exams.validate'));
const sessions = ref<ExamSession[]>([]);
const classes = ref<SchoolClass[]>([]);
const years = ref<{ id: string; label: string }[]>([]);
const sessionId = ref('');
const candidates = ref<ExamCandidate[]>([]);
const photoSources = ref<Record<string, string>>({});
const summary = ref<ExamSummary | null>(null);
const notice = ref('');
const newSession = reactive({ exam_code: '', exam_label: '', academic_year_id: '', class_structure_id: '', registration_deadline: '', exam_on: '', required_pieces: '', passing_codes: '' });
const personId = ref('');
const csvFile = ref<File | null>(null);
const selected = computed(() => sessions.value.find((s) => s.id === sessionId.value) ?? null);

async function load(): Promise<void> {
  const result = await run(async () => {
    years.value = await secondarySetup.years();
    classes.value = newSession.academic_year_id ? await secondarySetup.classes(newSession.academic_year_id) : [];
    sessions.value = await secondaryOfficialExams.sessions();
    if (!sessionId.value) sessionId.value = sessions.value[0]?.id ?? '';
    await loadSession();
  });
  if (result === undefined) return;
}

async function loadSession(): Promise<void> {
  if (!sessionId.value) {
    Object.values(photoSources.value).forEach(URL.revokeObjectURL);
    photoSources.value = {};
    candidates.value = [];
    summary.value = null;
    return;
  }
  await run(async () => {
    candidates.value = await secondaryOfficialExams.candidates(sessionId.value);
    Object.values(photoSources.value).forEach(URL.revokeObjectURL);
    photoSources.value = {};
    await Promise.all(candidates.value.filter((candidate) => candidate.photo_url).map(async (candidate) => {
      try {
        const blob = await secondaryOfficialExams.photoBlob(candidate.photo_url!);
        photoSources.value[candidate.id] = URL.createObjectURL(blob);
      } catch {
        // Une photo expirée sera régénérée au prochain chargement de la liste.
      }
    }));
    summary.value = await secondaryOfficialExams.summary(sessionId.value);
  });
}

async function createSession(): Promise<void> {
  const body = {
    exam_code: newSession.exam_code.trim(), exam_label: newSession.exam_label.trim(), academic_year_id: newSession.academic_year_id,
    class_structure_id: newSession.class_structure_id, registration_deadline: newSession.registration_deadline || null,
    exam_on: newSession.exam_on || null, required_pieces: newSession.required_pieces.split(',').map((v) => v.trim()).filter(Boolean),
    passing_codes: newSession.passing_codes.split(',').map((v) => v.trim()).filter(Boolean),
  };
  const created = await run(() => secondaryOfficialExams.createSession(body));
  if (created) {
    notice.value = t('secx.created');
    sessionId.value = created.id;
    await load();
  }
}

async function updateSessionStatus(): Promise<void> {
  if (!selected.value || selected.value.status !== 'open') return;
  const updated = await run(() => secondaryOfficialExams.updateSession(selected.value!.id, { status: 'closed' }));
  if (updated) {
    notice.value = t('secx.closedNotice');
    await load();
  }
}

async function loadClassesForYear(): Promise<void> {
  if (!newSession.academic_year_id) {
    classes.value = [];
    newSession.class_structure_id = '';
    return;
  }
  classes.value = (await run(() => secondarySetup.classes(newSession.academic_year_id))) ?? [];
  if (!classes.value.some((schoolClass) => schoolClass.structure_id === newSession.class_structure_id)) newSession.class_structure_id = '';
}

async function generate(): Promise<void> {
  const result = await run(() => secondaryOfficialExams.generate(sessionId.value));
  if (result) {
    notice.value = t('secx.generated', { added: result.added, listed: result.already_listed });
    await loadSession();
  }
}

async function addCandidate(): Promise<void> {
  if (!personId.value.trim()) return;
  const result = await run(() => secondaryOfficialExams.addCandidate(sessionId.value, personId.value.trim()));
  if (result) {
    personId.value = '';
    await loadSession();
  }
}

async function removeCandidate(candidate: ExamCandidate): Promise<void> {
  const result = await run(() => secondaryOfficialExams.removeCandidate(candidate.id));
  if (result?.removed) {
    notice.value = t('secx.removed', { name: candidate.full_name });
    await loadSession();
  }
}

async function removePhoto(candidate: ExamCandidate): Promise<void> {
  const result = await run(() => secondaryOfficialExams.removePhoto(candidate.id));
  if (result) await loadSession();
}

async function saveCandidate(candidate: ExamCandidate, field: 'pieces_received' | 'selected_subjects', value: string): Promise<void> {
  const result = await run(() => secondaryOfficialExams.updateCandidate(candidate.id, { [field]: value.split(',').map((v) => v.trim()).filter(Boolean) }));
  if (result) await loadSession();
}

async function uploadPhoto(candidate: ExamCandidate, event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const result = await run(() => secondaryOfficialExams.uploadPhoto(candidate.id, file));
  if (result) await loadSession();
}

async function exportCsv(): Promise<void> {
  const blob = await run(() => secondaryOfficialExams.exportCsv(sessionId.value));
  if (blob) saveBlob(blob, `candidats-${sessionId.value}.csv`);
}

async function importResults(): Promise<void> {
  if (!csvFile.value) return;
  const text = await csvFile.value.text();
  const result = await run(() => secondaryOfficialExams.importResults(sessionId.value, text));
  if (result) {
    summary.value = result.summary;
    notice.value = t('secx.imported', { imported: result.imported, rejected: result.rejected.length });
    await loadSession();
  }
}

onMounted(load);
onBeforeUnmount(() => Object.values(photoSources.value).forEach(URL.revokeObjectURL));
</script>

<template>
  <main class="exam-page">
    <header class="page-head">
      <div><p class="eyebrow">{{ t('secx.eyebrow') }}</p><h1>{{ t('secx.title') }}</h1><p class="subtitle">{{ t('secx.subtitle') }}</p></div>
      <button v-if="selected && canRead" class="button secondary" :disabled="busy" @click="exportCsv">{{ t('secx.export') }}</button>
    </header>
    <ErrorNotice v-if="error" :error="error" />
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>

    <section v-if="canManage" class="panel create-panel">
      <div class="section-title"><div><p class="eyebrow">{{ t('secx.newEyebrow') }}</p><h2>{{ t('secx.newTitle') }}</h2></div><span class="tag">{{ t('secx.configurable') }}</span></div>
      <form class="form-grid" @submit.prevent="createSession">
        <label>{{ t('secx.code') }}<input v-model="newSession.exam_code" required maxlength="40" :placeholder="t('secx.codePlaceholder')" /></label>
        <label>{{ t('secx.label') }}<input v-model="newSession.exam_label" required maxlength="160" :placeholder="t('secx.labelPlaceholder')" /></label>
        <label>{{ t('secx.year') }}<select v-model="newSession.academic_year_id" required @change="loadClassesForYear"><option value="" disabled>{{ t('secx.chooseYear') }}</option><option v-for="year in years" :key="year.id" :value="year.id">{{ year.label ?? year.id }}</option></select></label>
        <label>{{ t('secx.class') }}<select v-model="newSession.class_structure_id" required><option value="" disabled>{{ t('secx.chooseClass') }}</option><option v-for="schoolClass in classes" :key="schoolClass.structure_id" :value="schoolClass.structure_id">{{ schoolClass.label }}</option></select></label>
        <label>{{ t('secx.deadline') }}<input v-model="newSession.registration_deadline" type="date" /></label>
        <label>{{ t('secx.examOn') }}<input v-model="newSession.exam_on" type="date" /></label>
        <label>{{ t('secx.pieces') }} <small>{{ t('secx.commaSeparated') }}</small><input v-model="newSession.required_pieces" :placeholder="t('secx.piecesPlaceholder')" /></label>
        <label>{{ t('secx.passingCodes') }} <small>{{ t('secx.commaSeparated') }}</small><input v-model="newSession.passing_codes" :placeholder="t('secx.passingPlaceholder')" /></label>
        <button class="button primary" :disabled="busy">{{ t('secx.create') }}</button>
      </form>
    </section>

    <section class="panel session-panel">
      <div class="section-title"><div><p class="eyebrow">{{ t('secx.workEyebrow') }}</p><h2>{{ t('secx.workTitle') }}</h2></div><span v-if="selected" class="tag" :class="selected.status">{{ selected.status === 'open' ? t('secx.open') : t('secx.closed') }}</span></div>
      <label class="session-select">{{ t('secx.session') }}<select v-model="sessionId" @change="loadSession"><option value="">{{ t('secx.chooseSession') }}</option><option v-for="session in sessions" :key="session.id" :value="session.id">{{ session.exam_label }} · {{ session.academic_year_id }}</option></select></label>
      <template v-if="selected">
        <div v-if="canManage && selected.status === 'open'" class="session-actions"><button class="button secondary" :disabled="busy" @click="updateSessionStatus">{{ t('secx.close') }}</button></div>
        <div class="summary-grid" v-if="summary">
          <article><span>{{ t('secx.candidates') }}</span><strong>{{ summary.candidate_count }}</strong></article><article><span>{{ t('secx.results') }}</span><strong>{{ summary.results_count }}</strong></article>
          <article><span>{{ t('secx.passed') }}</span><strong>{{ summary.passed_count }}</strong></article><article><span>{{ t('secx.rate') }}</span><strong>{{ summary.success_rate === null ? '—' : `${summary.success_rate}%` }}</strong></article>
        </div>
        <div v-if="summary?.comparison" class="comparison"><div><span>{{ t('secx.exam') }}</span><strong>{{ summary.comparison.exam_average ?? '—' }}</strong></div><span class="versus">{{ t('secx.versus') }}</span><div><span>{{ t('secx.schoolAverage') }}</span><strong>{{ summary.comparison.school_average ?? '—' }}</strong></div><p>{{ t('secx.gap', { value: summary.comparison.difference ?? '—' }) }}</p></div>
        <div v-if="canManage" class="toolbar"><button class="button secondary" :disabled="busy || selected.status !== 'open'" @click="generate">{{ t('secx.generate') }}</button><form class="inline-form" @submit.prevent="addCandidate"><input v-model="personId" :aria-label="t('secx.personId')" :placeholder="t('secx.personId')" /><button class="button secondary" :disabled="busy || selected.status !== 'open'">{{ t('secx.add') }}</button></form></div>
        <div v-if="canValidate" class="import-box"><label>{{ t('secx.importLabel') }} <input type="file" accept=".csv,text/csv" @change="csvFile = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label><p>{{ t('secx.importHelp') }}</p><button class="button primary" :disabled="busy || !csvFile || selected.status !== 'open'" @click="importResults">{{ t('secx.import') }}</button></div>
        <div class="candidate-list">
          <article v-for="candidate in candidates" :key="candidate.id" class="candidate-card">
            <div class="candidate-main"><div class="avatar"><img v-if="photoSources[candidate.id]" :src="photoSources[candidate.id]" :alt="t('secx.photoAlt')" /><span v-else>{{ candidate.full_name.slice(0, 1) }}</span></div><div class="identity"><strong>{{ candidate.full_name }}</strong><span>{{ candidate.class_label }} · {{ t('secx.nationalId', { value: candidate.national_id ?? t('secx.pending') }) }}</span><span class="checks">{{ t('secx.fees', { value: candidate.fee_status === 'seen' ? t('secx.feesSeen') : t('secx.feesNotSeen') }) }} · {{ t('secx.missingPieces', { value: candidate.missing_pieces.length || t('secx.none') }) }}</span></div><span class="status-pill" :class="candidate.status">{{ t(STATUS_KEYS[candidate.status]) }}</span></div>
            <p v-if="candidate.status === 'blocked'" class="blocked-message">{{ candidate.status_message }}</p>
            <div class="candidate-details" v-if="canManage && selected.status === 'open'"><label>{{ t('secx.piecesReceived') }}<input :value="candidate.pieces_received.join(', ')" @change="saveCandidate(candidate, 'pieces_received', ($event.target as HTMLInputElement).value)" :placeholder="t('secx.piecesReceivedPlaceholder')" /></label><label>{{ t('secx.optionalSubjects') }}<input :value="candidate.selected_subjects.join(', ')" @change="saveCandidate(candidate, 'selected_subjects', ($event.target as HTMLInputElement).value)" :placeholder="t('secx.optionalPlaceholder')" /></label><label class="file-label">{{ t('secx.photo') }}<input type="file" accept="image/jpeg,image/png" @change="uploadPhoto(candidate, $event)" /></label><button v-if="candidate.photo_url" class="button secondary" :disabled="busy" @click="removePhoto(candidate)">{{ t('secx.removePhoto') }}</button><button class="button secondary" :disabled="busy" @click="removeCandidate(candidate)">{{ t('secx.remove') }}</button></div>
            <div class="result-line" v-if="candidate.result_code"><span>{{ t('secx.result', { value: candidate.result_code }) }}</span><strong v-if="candidate.result_mark !== null">{{ candidate.result_mark }}</strong></div>
          </article>
          <p v-if="!candidates.length" class="empty">{{ t('secx.empty') }}</p>
        </div>
      </template>
      <p v-else class="empty">{{ t('secx.noSession') }}</p>
    </section>
  </main>
</template>

<style scoped>
.exam-page{max-width:1180px;margin:0 auto;padding:32px 28px 56px;color:#1b2b3a}.page-head,.section-title,.candidate-main,.toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px}.page-head{margin-bottom:24px}.page-head h1{margin:4px 0;font-size:clamp(1.8rem,4vw,2.5rem);letter-spacing:-.04em}.subtitle{margin:8px 0 0;color:#667789}.eyebrow{margin:0;color:#477865;font-size:.72rem;font-weight:800;letter-spacing:.13em}.panel{margin-top:20px;padding:24px;border:1px solid #e3e9e5;border-radius:20px;background:#fff;box-shadow:0 12px 34px #263e3210}.section-title{margin-bottom:20px}.section-title h2{margin:4px 0;font-size:1.28rem}.tag,.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 12px;background:#edf5f0;color:#416d55;font-size:.78rem;font-weight:700}.tag.closed{background:#f1f2f4;color:#697783}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:end;gap:14px}.form-grid label,.candidate-details label,.session-select,.import-box label{display:grid;gap:7px;color:#526373;font-size:.83rem;font-weight:700}.form-grid small{font-weight:400;color:#87939d}.form-grid input,.form-grid select,.candidate-details input,.session-select select,.inline-form input,.import-box input{width:100%;min-height:42px;padding:9px 11px;border:1px solid #dce5df;border-radius:10px;background:#fbfcfb;color:#213342;font:inherit}.button{min-height:42px;padding:0 16px;border:0;border-radius:10px;font:inherit;font-weight:750;cursor:pointer}.button:disabled{opacity:.55;cursor:not-allowed}.button.primary{background:#23694f;color:white}.button.secondary{background:#edf4f0;color:#285d48}.form-grid>.button{align-self:end}.session-select{max-width:500px}.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}.summary-grid article{display:grid;gap:8px;padding:16px;border-radius:14px;background:#f5f8f6}.summary-grid span,.comparison span{color:#667789;font-size:.82rem}.summary-grid strong{font-size:1.55rem}.comparison{display:flex;align-items:center;gap:20px;margin:12px 0 22px;padding:16px 20px;border:1px solid #e4ece7;border-radius:14px}.comparison div{display:grid;gap:4px}.comparison strong{font-size:1.2rem}.comparison .versus{font-size:.7rem;font-weight:900;color:#8a9890}.comparison p{margin-left:auto;color:#647368}.toolbar{justify-content:flex-start;flex-wrap:wrap;margin:18px 0}.inline-form{display:flex;gap:8px;max-width:440px;flex:1}.import-box{margin:16px 0;padding:16px;border:1px dashed #cbd9d0;border-radius:14px;background:#fafcfb}.import-box p{color:#6b7b72;font-size:.82rem}.candidate-list{display:grid;gap:12px;margin-top:18px}.candidate-card{padding:16px;border:1px solid #e6ece8;border-radius:15px}.candidate-main{justify-content:flex-start}.avatar{display:grid;place-items:center;flex:0 0 42px;height:42px;border-radius:50%;background:#e5f1ea;color:#2d6c50;font-weight:800}.identity{display:grid;gap:4px;min-width:0;flex:1}.identity>span{color:#74818b;font-size:.8rem}.checks{color:#586e60!important}.status-pill{margin-left:auto}.status-pill.blocked{background:#fff1ed;color:#ab4b39}.status-pill.incomplete{background:#fff7e6;color:#9b6b16}.status-pill.eligible{background:#eaf5ee;color:#2c7550}.blocked-message{margin:12px 0 0;padding:9px 12px;border-left:3px solid #cb705d;background:#fff7f4;color:#8d4134}.candidate-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:15px}.candidate-details label{font-size:.77rem}.result-line{display:flex;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:1px solid #edf0ed}.empty{padding:24px;text-align:center;color:#718079}.notice{padding:11px 14px;border-radius:10px;background:#eff8f2;color:#2c6c4a}@media(max-width:850px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.exam-page{padding:20px 14px 40px}.page-head{align-items:flex-start;flex-direction:column}.panel{padding:17px;border-radius:16px}.form-grid,.candidate-details{grid-template-columns:1fr}.summary-grid{gap:8px}.summary-grid article{padding:12px}.toolbar{align-items:stretch;flex-direction:column}.inline-form{max-width:none}.comparison{gap:10px;flex-wrap:wrap}.comparison p{margin-left:0;width:100%}.candidate-main{align-items:flex-start;flex-wrap:wrap}.status-pill{margin-left:60px}}
</style>
