<script setup lang="ts">
// Examens (EXA) côté école et contrôle : session nationale (CEP, FSLC, entrée en 6e, Common Entrance), liste générée depuis la classe
// d'examen, contrôle d'état civil (un candidat sans acte vérifié est bloqué, lui seul : RG-EXA-1), circuit directeur > inspection >
// délégation avec gel (ajout ultérieur motivé : RG-EXA-2), fiches DOC-14, listes DOC-13/15, relevé DOC-17, attestations DOC-16
// (jamais un diplôme : RG-EXA-3), dossier signé vers le secondaire. Le serveur reste juge des droits.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicExams, type ExamCandidate, type ExamSession, type ExamSessionDetail, type ExamSummary } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { currentYear, useAction } from './usePs';

const { t, locale } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const sessions = ref<ExamSession[]>([]);
const sessionId = ref('');
const detail = ref<ExamSessionDetail | null>(null);
const candidates = ref<ExamCandidate[]>([]);
const summary = ref<ExamSummary | null>(null);
const done = ref('');
const yearId = ref('');
const yearLabel = ref('');
const newSession = ref({ exam_code: 'cep', written_on: '' });
const addition = ref({ person_id: '', structure_id: '', reason: '' });

const canWrite = computed(() => ctx.can('public_primary.exams.write'));
const canValidate = computed(() => ctx.can('public_primary.exams.validate'));
const canOpen = computed(() => ctx.can('public_primary.exams.consolidate') && !canWrite.value);
const canAttest = computed(() => ctx.can('public_primary.exams.attest', 'public_primary.exams.import'));
const canSign = computed(() => ctx.can('public_primary.exams.attest'));
const list = computed(() => detail.value?.list ?? null);
const editable = computed(() => list.value === null || list.value.state === 'draft' || list.value.state === 'returned');
const frozen = computed(() => list.value?.frozen_at != null);
const lang = computed(() => (locale.value === 'en' ? 'en' : 'fr'));

const STATE_KEYS: Record<string, MessageKey> = {
  proposed: 'pp.exa.stateProposed',
  validated: 'pp.exa.stateValidated',
  blocked: 'pp.exa.stateBlocked',
  withdrawn: 'pp.exa.stateWithdrawn',
};
const LIST_KEYS: Record<string, MessageKey> = {
  draft: 'pp.exa.listDraft',
  submitted: 'pp.exa.listSubmitted',
  inspected: 'pp.exa.listInspected',
  validated: 'pp.exa.listValidated',
  returned: 'pp.exa.listReturned',
};
const OUTCOME_KEYS: Record<string, MessageKey> = { passed: 'pp.exa.passed', failed: 'pp.exa.failed', absent: 'pp.exa.absent' };

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    yearId.value = year?.id ?? '';
    yearLabel.value = year?.label ?? '';
    sessions.value = await publicExams.sessions();
    if (sessions.value.length > 0 && sessionId.value === '') {
      sessionId.value = sessions.value[0]?.id ?? '';
      await load();
    }
  });
});

async function load(): Promise<void> {
  if (!sessionId.value) return;
  await run(async () => {
    detail.value = await publicExams.session(sessionId.value);
    candidates.value = await publicExams.candidates(sessionId.value);
    summary.value = (await publicExams.results(sessionId.value)).summary;
  });
}

async function act(action: () => Promise<unknown>, message: MessageKey): Promise<void> {
  done.value = '';
  const result = await run(action);
  if (result !== undefined) {
    done.value = t(message);
    await load();
  }
}

async function openSession(): Promise<void> {
  await run(async () => {
    const created = await publicExams.openSession({ exam_code: newSession.value.exam_code, year_label: yearLabel.value, ...(newSession.value.written_on ? { written_on: newSession.value.written_on } : {}) });
    sessions.value = [...sessions.value, created];
    sessionId.value = created.id;
  });
  await load();
}

const generate = (): Promise<void> => act(() => publicExams.generate(sessionId.value, yearId.value), 'pp.exa.generated');
const submit = (): Promise<void> => act(() => publicExams.submit(sessionId.value), 'pp.exa.submitted');
const alerts = (): Promise<void> => act(() => publicExams.birthActAlerts(sessionId.value), 'pp.exa.alertsSent');

async function validate(): Promise<void> {
  done.value = '';
  const result = await run(() => publicExams.validate(sessionId.value));
  if (result) {
    done.value = t('pp.exa.validatedCount', { validated: result.validated, blocked: result.blocked.length });
    await load();
  }
}

async function sendBack(): Promise<void> {
  const reason = window.prompt(t('pp.exa.returnReason')) ?? '';
  if (reason.trim() !== '') await act(() => publicExams.sendBack(sessionId.value, reason), 'pp.exa.returned');
}

async function withdraw(c: ExamCandidate): Promise<void> {
  const reason = window.prompt(t('pp.exa.withdrawReason')) ?? '';
  if (reason.trim() !== '') await act(() => publicExams.withdraw(c.id, reason), 'pp.exa.withdrawn');
}

async function addCandidate(): Promise<void> {
  const body = {
    person_id: addition.value.person_id.trim(),
    ...(addition.value.structure_id ? { structure_id: addition.value.structure_id } : {}),
    ...(addition.value.reason ? { reason: addition.value.reason } : {}),
  };
  await act(() => publicExams.add(sessionId.value, body), 'pp.exa.added');
}

async function doc(action: () => Promise<{ document: { id: string } }>): Promise<void> {
  await run(async () => openDocument((await action()).document.id));
}

const listDoc = (): Promise<void> => doc(() => publicExams.listDocument(sessionId.value, lang.value));
const resultsDoc = (): Promise<void> => doc(() => publicExams.resultsDocument(sessionId.value, lang.value));
const sheetDoc = (c: ExamCandidate): Promise<void> => doc(() => publicExams.sheet(c.id, lang.value));
const prepare = (): Promise<void> => act(() => publicExams.attestations(sessionId.value, lang.value), 'pp.exa.attestationsPrepared');
const validateCand = (c: ExamCandidate): Promise<void> => act(() => publicExams.validateCandidate(c.id), 'pp.exa.candidateValidated');
const signCand = (c: ExamCandidate): Promise<void> => act(() => publicExams.sign(c.id), 'pp.exa.signed');

async function exportCsv(): Promise<void> {
  await run(async () => {
    const url = URL.createObjectURL(await publicExams.exportList(sessionId.value));
    const a = document.createElement('a');
    a.href = url;
    a.download = `candidats-${sessionId.value}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  });
}

async function transfer(c: ExamCandidate): Promise<void> {
  const target = window.prompt(t('pp.exa.transferTarget')) ?? '';
  await run(async () => {
    const dossier = await publicExams.transfer(c.id, target.trim() || undefined);
    const blob = new Blob([JSON.stringify({ dossier: dossier.dossier, signature: dossier.signature, algorithm: dossier.algorithm })], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `dossier-secondaire-${c.learner_number ?? c.id}.json`;
    a.click();
    URL.revokeObjectURL(url);
    done.value = t('pp.exa.transferDone');
  });
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.exa.title') }}</h1>
      <p class="lede">{{ t('pp.exa.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls">
      <div class="field">
        <label for="ex-session">{{ t('pp.exa.session') }}</label>
        <select id="ex-session" v-model="sessionId" @change="load">
          <option value="">—</option>
          <option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.label }} · {{ s.year_label }}</option>
        </select>
      </div>
      <form v-if="canOpen" class="controls" @submit.prevent="openSession">
        <div class="field">
          <label for="ex-code">{{ t('pp.exa.examCode') }}</label>
          <select id="ex-code" v-model="newSession.exam_code">
            <option value="cep">CEP</option>
            <option value="fslc">FSLC</option>
            <option value="sixth">{{ t('pp.exa.sixth') }}</option>
            <option value="common_entrance">Common Entrance</option>
          </select>
        </div>
        <div class="field">
          <label for="ex-written">{{ t('pp.exa.writtenOn') }}</label>
          <input id="ex-written" v-model="newSession.written_on" type="date" />
        </div>
        <button type="submit" class="btn" :disabled="busy || !yearLabel">{{ t('pp.exa.openSession', { year: yearLabel }) }}</button>
      </form>
    </section>

    <section v-if="detail" class="card" aria-labelledby="ex-list">
      <h2 id="ex-list">{{ detail.label }} · {{ detail.year_label }}</h2>
      <p class="muted">{{ t('pp.exa.levels', { levels: detail.level_codes.join(', ') }) }}</p>
      <p>
        {{ t('pp.exa.listState') }} : <span class="tag">{{ list ? t(LIST_KEYS[list.state] ?? 'pp.exa.listDraft') : t('pp.exa.noList') }}</span>
        <span v-if="list?.current_step"> · {{ t(list.current_step === 'inspection' ? 'pp.exa.stepInspection' : 'pp.exa.stepDelegation') }}</span>
        <span v-if="frozen"> · {{ t('pp.exa.frozen', { n: list?.amendments_count ?? 0 }) }}</span>
      </p>
      <p v-if="list?.return_reason" class="notice">{{ t('pp.exa.returnedBecause', { reason: list.return_reason }) }}</p>
      <div class="actions">
        <button v-if="canWrite && editable && !frozen" type="button" class="btn" :disabled="busy || !yearId" @click="generate">{{ t('pp.exa.generate') }}</button>
        <button v-if="canWrite && editable && candidates.length > 0" type="button" class="btn btn--primary" :disabled="busy" @click="submit">{{ t('pp.exa.submit') }}</button>
        <button v-if="canWrite" type="button" class="btn" :disabled="busy || candidates.length === 0" @click="alerts">{{ t('pp.exa.alerts') }}</button>
        <button v-if="canValidate && list && (list.state === 'submitted' || list.state === 'inspected')" type="button" class="btn btn--primary" :disabled="busy" @click="validate">{{ t('pp.exa.validate') }}</button>
        <button v-if="canValidate && list && (list.state === 'submitted' || list.state === 'inspected')" type="button" class="btn" :disabled="busy" @click="sendBack">{{ t('pp.exa.sendBack') }}</button>
        <button v-if="canWrite && list" type="button" class="btn" :disabled="busy" @click="listDoc">{{ t(detail.kind === 'competition' ? 'pp.exa.doc15' : 'pp.exa.doc13') }}</button>
        <button v-if="list" type="button" class="btn" :disabled="busy" @click="exportCsv">{{ t('pp.exa.export') }}</button>
        <button v-if="canAttest && !canWrite && detail.kind === 'certificate'" type="button" class="btn" :disabled="busy" @click="prepare">{{ t('pp.exa.prepareAttestations') }}</button>
      </div>
      <p class="muted">{{ t('pp.exa.formatNotice') }}</p>
    </section>

    <section v-if="summary && detail" class="card" aria-labelledby="ex-results">
      <h2 id="ex-results">{{ t('pp.exa.results') }}</h2>
      <p v-if="summary.pending > 0 && summary.candidates === 0" class="muted">{{ t('pp.exa.resultsPending') }}</p>
      <p v-else>
        {{ t('pp.exa.summary', { candidates: summary.candidates, present: summary.present, passed: summary.passed }) }}
        · <strong>{{ summary.success_rate === null ? '—' : `${summary.success_rate} %` }}</strong> {{ t('pp.exa.rateFormula') }}
      </p>
      <button v-if="canWrite && summary.candidates > 0" type="button" class="btn" :disabled="busy" @click="resultsDoc">{{ t('pp.exa.doc17') }}</button>
    </section>

    <section v-if="detail" class="card" aria-labelledby="ex-cands">
      <h2 id="ex-cands">{{ t('pp.exa.candidates', { n: candidates.length }) }}</h2>
      <p v-if="candidates.length === 0" class="muted">{{ t('pp.exa.noCandidates') }}</p>
      <ul class="rows">
        <li v-for="c in candidates" :key="c.id" class="row">
          <p>
            <strong>{{ c.last_name.toUpperCase() }} {{ c.first_name }}</strong>
            <span class="muted"> · {{ c.learner_number ?? '—' }} · {{ c.birth_date ? formatDate(c.birth_date) : '—' }}</span>
            · <span class="tag">{{ t(STATE_KEYS[c.state] ?? 'pp.exa.stateProposed') }}</span>
            <span v-if="c.added_after_freeze" class="tag">{{ t('pp.exa.addedAfterFreeze') }}</span>
          </p>
          <p :class="c.birth_act_verified ? 'muted' : 'notice'">
            {{ c.birth_act_verified ? t('pp.exa.actVerified', { ref: c.birth_act_ref ?? '—' }) : t('pp.exa.actMissing') }}
          </p>
          <p v-if="c.candidate_number" class="muted">{{ t('pp.exa.number', { n: c.candidate_number }) }}</p>
          <p v-if="c.outcome">{{ t('pp.exa.outcome') }} : {{ t(OUTCOME_KEYS[c.outcome] ?? 'pp.exa.absent') }}<span v-if="c.attestation_state"> · {{ t(c.attestation_state === 'signed' ? 'pp.exa.attestationSigned' : 'pp.exa.attestationPrepared') }}</span></p>
          <div class="actions">
            <button v-if="canWrite" type="button" class="btn btn--small" :disabled="busy" @click="sheetDoc(c)">{{ t('pp.exa.doc14') }}</button>
            <button v-if="canWrite && c.state !== 'withdrawn' && !c.outcome" type="button" class="btn btn--small" :disabled="busy" @click="withdraw(c)">{{ t('pp.exa.withdraw') }}</button>
            <button v-if="canValidate && (c.state === 'blocked' || (frozen && c.state === 'proposed'))" type="button" class="btn btn--small" :disabled="busy" @click="validateCand(c)">{{ t('pp.exa.validateCandidate') }}</button>
            <button v-if="canSign && c.attestation_state === 'prepared'" type="button" class="btn btn--small" :disabled="busy" @click="signCand(c)">{{ t('pp.exa.sign') }}</button>
            <button v-if="c.attestation_document_id" type="button" class="btn btn--small" :disabled="busy" @click="openDocument(c.attestation_document_id)">{{ t('pp.exa.doc16') }}</button>
            <button v-if="canWrite && c.state !== 'withdrawn'" type="button" class="btn btn--small" :disabled="busy" @click="transfer(c)">{{ t('pp.exa.transfer') }}</button>
          </div>
        </li>
      </ul>
      <form v-if="canWrite && list" class="controls" @submit.prevent="addCandidate">
        <h3>{{ t('pp.exa.add') }}</h3>
        <div class="field">
          <label for="ex-person">{{ t('pp.exa.personId') }}</label>
          <input id="ex-person" v-model="addition.person_id" type="text" required maxlength="40" />
        </div>
        <div class="field">
          <label for="ex-class">{{ t('pp.common.class') }}</label>
          <input id="ex-class" v-model="addition.structure_id" type="text" maxlength="40" />
        </div>
        <div class="field">
          <label for="ex-reason">{{ t('pp.common.reason') }}</label>
          <input id="ex-reason" v-model="addition.reason" type="text" maxlength="500" :required="frozen" />
        </div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.exa.addButton') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.row { border: 1px solid var(--input-border); padding: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
