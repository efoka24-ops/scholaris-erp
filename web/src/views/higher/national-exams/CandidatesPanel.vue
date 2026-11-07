<script setup lang="ts">
import { reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { nationalExams, type Candidate, type NationalSession } from '@/api/higher-nationalexams';
import { useHigherNames } from '../useHigherNames';
import { useHigherAction } from '../useHigherAction';
import { useNationalI18n, xk } from './i18n';

// Liste des candidats (EX-EXN-02 à 06) : état du dossier, contrôles (assiduité, frais, pièces), retrait, émission de DOC-12 et DOC-17.
const props = defineProps<{ session: NationalSession; candidates: Candidate[]; canManage: boolean }>();
const emit = defineEmits<{ (e: 'changed'): void }>();
const { tx } = useNationalI18n();
const names = useHigherNames(() => props.candidates.map((c) => c.person_id));
const save = useHigherAction();
const issue = useHigherAction();

const openId = ref<string | null>(null);
const draft = reactive({ fee_receipt: '', pieces: [] as string[], withdraw: '' });
const info = ref('');

function open(c: Candidate): void {
  openId.value = openId.value === c.id ? null : c.id;
  Object.assign(draft, { fee_receipt: c.fee_receipt ?? '', pieces: [...c.pieces_received], withdraw: '' });
  info.value = '';
}

async function saveDossier(c: Candidate): Promise<void> {
  const res = await save.run(() => nationalExams.updateCandidate(c.id, { fee_receipt: draft.fee_receipt.trim() || null, pieces_received: draft.pieces }));
  if (!res) return;
  info.value = tx('hx.saved');
  emit('changed');
}

async function withdraw(c: Candidate): Promise<void> {
  if (draft.withdraw.trim() === '') return;
  const res = await save.run(() => nationalExams.withdraw(c.id, draft.withdraw.trim()));
  if (!res) return;
  openId.value = null;
  emit('changed');
}

async function issueDocument(c: Candidate, code: 'DOC-12' | 'DOC-17'): Promise<void> {
  const res = await issue.run(() => nationalExams.issueDocument(c.id, code));
  if (res) info.value = tx('hx.issued');
}
</script>

<template>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th scope="col">{{ tx('hx.candidate') }}</th><th scope="col">{{ tx('hx.status') }}</th><th scope="col" class="num">{{ tx('hx.presence') }}</th>
          <th scope="col">{{ tx('hx.dossier') }}</th><th scope="col">{{ tx('hx.result') }}</th><th scope="col">{{ tx('hx.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="c in candidates" :key="c.id">
          <tr data-test="candidate-row">
            <th scope="row">{{ names.name(c.person_id) }}<br /><small class="muted">{{ c.matricule ?? '—' }}<template v-if="c.candidate_number"> · {{ c.candidate_number }}</template></small></th>
            <td><span class="tag tag--neutral" :data-state="c.status">{{ tx(xk('hx.state.', c.status)) }}</span></td>
            <td class="num">{{ c.presence_percent }} %<br /><small :class="c.attendance_ok ? 'muted' : 'hx-warn'">{{ c.attendance_ok ? tx('hx.attendanceOk') : tx('hx.attendanceKo') }}</small></td>
            <td>
              <small>{{ c.fee_receipt ? tx('hx.feePaid') : tx('hx.feeMissing') }}<br />{{ tx('hx.piecesCount', { received: c.pieces_received.length, required: session.required_pieces.length }) }}</small>
            </td>
            <td>{{ c.result_code ? tx(xk('hx.result.', c.result_code)) : '—' }}<template v-if="c.result_mark !== null"> ({{ c.result_mark }})</template></td>
            <td><button type="button" class="btn btn--quiet" :aria-expanded="openId === c.id" @click="open(c)">{{ tx('hx.open') }}</button></td>
          </tr>
          <tr v-if="openId === c.id" data-test="candidate-detail">
            <td colspan="6">
              <form class="hi-form" @submit.prevent="saveDossier(c)">
                <div class="field"><label :for="`fee-${c.id}`">{{ tx('hx.feeReceipt') }}</label><input :id="`fee-${c.id}`" v-model="draft.fee_receipt" type="text" :disabled="!canManage" /></div>
                <fieldset class="field">
                  <legend>{{ tx('hx.piecesReceived') }}</legend>
                  <p v-if="session.required_pieces.length === 0" class="muted">{{ tx('hx.noPieces') }}</p>
                  <label v-for="p in session.required_pieces" :key="p" class="hx-check"><input v-model="draft.pieces" type="checkbox" :value="p" :disabled="!canManage" /> {{ p }}</label>
                </fieldset>
                <p v-if="c.withdrawn_reason" class="muted">{{ c.withdrawn_reason }}</p>
                <div v-if="canManage && c.status !== 'withdrawn'" class="hx-actions">
                  <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ tx('hx.saveDossier') }}</button>
                  <input v-model="draft.withdraw" type="text" :aria-label="tx('hx.withdrawReason')" :placeholder="tx('hx.withdrawReason')" />
                  <button type="button" class="btn" :disabled="save.busy.value || draft.withdraw.trim() === ''" @click="withdraw(c)">{{ tx('hx.withdraw') }}</button>
                </div>
                <div v-if="c.status !== 'withdrawn'" class="hx-actions">
                  <button type="button" class="btn btn--quiet" :disabled="issue.busy.value" @click="issueDocument(c, 'DOC-12')">{{ tx('hx.doc12') }}</button>
                  <button type="button" class="btn btn--quiet" :disabled="issue.busy.value" @click="issueDocument(c, 'DOC-17')">{{ tx('hx.doc17') }}</button>
                </div>
                <p v-if="info" class="notice" role="status" data-test="candidate-info">{{ info }}</p>
                <ErrorNotice :error="save.error.value ?? issue.error.value" />
              </form>
            </td>
          </tr>
        </template>
        <tr v-if="candidates.length === 0"><td colspan="6" class="muted">{{ tx('hx.noCandidate') }}</td></tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.hx-warn { color: var(--danger, #b3261e); }
.hx-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.hx-check { display: block; }
</style>
