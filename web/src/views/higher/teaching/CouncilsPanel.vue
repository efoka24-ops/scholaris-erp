<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { attendance, COUNCIL_DECISIONS, type Council, type CouncilDecision } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import PersonPicker from '../PersonPicker.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Conseil de discipline (RG-PRE-2) : convocation (≥ 3 membres), séance consignée (PV, décision), signature du PV (empreinte).
// Une exclusion définitive signée clôt l'inscription ; « exclusion_pending » signale qu'il reste à la reporter.
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Council[]>([]);
const touched = ref(false);
const form = reactive({ person_id: null as string | null, reason: '', incident_ref: '', hearing_date: '', members: '' });
const hearingFor = ref<Council | null>(null);
const hearing = reactive({ minutes_text: '', decision: 'none' as CouncilDecision, decision_reason: '', exclusion_from: '', exclusion_to: '' });
const hearingTried = ref(false);
const signing = ref<Council | null>(null);

const canManage = computed(() => ctx.can(...TP.council) && !higher.readOnly);
const memberList = (): string[] => form.members.split(/[,;\n]+/).map((s) => s.trim()).filter(Boolean);
const membersBad = computed(() => touched.value && memberList().length < 3);
const tone = (s: string): string => (s === 'signed' ? 'success' : 'neutral');

async function load(): Promise<void> {
  const res = await list.run(() => attendance.councils(higher.scope()));
  if (res) items.value = res;
}

async function convene(): Promise<void> {
  touched.value = true;
  if (!form.person_id || !form.reason.trim() || !form.hearing_date || membersBad.value) return;
  const person = form.person_id;
  const members = memberList();
  const res = await act.run(() => attendance.convene({ person_id: person, reason: form.reason.trim(), incident_ref: form.incident_ref.trim() || null, hearing_date: form.hearing_date, members }));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { reason: '', incident_ref: '', hearing_date: '', members: '' });
  await load();
}

async function record(): Promise<void> {
  hearingTried.value = true;
  const c = hearingFor.value;
  if (!c || !hearing.minutes_text.trim() || !hearing.decision_reason.trim()) return;
  const temp = hearing.decision === 'temporary_exclusion';
  const res = await act.run(() =>
    attendance.hearing(c.id, { minutes_text: hearing.minutes_text.trim(), decision: hearing.decision, decision_reason: hearing.decision_reason.trim(), ...(temp ? { exclusion_from: hearing.exclusion_from || null, exclusion_to: hearing.exclusion_to || null } : {}) }),
  );
  if (!res) return;
  hearingFor.value = null;
  hearingTried.value = false;
  await load();
}

async function sign(): Promise<void> {
  const c = signing.value;
  if (!c) return;
  const res = await act.run(() => attendance.signCouncil(c.id));
  signing.value = null;
  if (res) await load();
}

onMounted(load);
</script>

<template>
  <ErrorNotice :error="list.error.value" />
  <ErrorNotice :error="act.error.value" />
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">{{ t('ht.dis.hearingDate') }}</th><th scope="col">{{ t('ht.rep.student') }}</th><th scope="col">{{ t('hi.reason') }}</th><th scope="col">{{ t('ht.dis.decision') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
      <tbody>
        <tr v-for="c in items" :key="c.id">
          <th scope="row">{{ formatDate(c.hearing_date, locale) }}</th>
          <td class="hi-mono">{{ c.person_id }}</td>
          <td>{{ c.reason }}</td>
          <td>{{ c.decision ? t(hk('ht.dis.kind.', c.decision)) : '—' }}</td>
          <td>
            <span class="tag" :class="`tag--${tone(c.status)}`">{{ t(hk('ht.dis.state.', c.status)) }}</span>
            <span v-if="c.exclusion_pending" class="tag tag--warning" data-test="exclusion-pending">{{ t('ht.dis.pending') }}</span>
          </td>
          <td class="hi-actions">
            <template v-if="canManage">
              <button v-if="c.status === 'convened' || c.status === 'held'" type="button" class="hi-linkbtn" @click="hearingFor = c">{{ t('ht.dis.hearing') }}</button>
              <button v-if="c.status === 'held'" type="button" class="hi-linkbtn" @click="signing = c">{{ t('ht.dis.sign') }}</button>
            </template>
          </td>
        </tr>
        <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
      </tbody>
    </table>
  </div>

  <section v-if="signing" class="card card--strong hi-section" role="dialog" aria-labelledby="cs-title" data-test="sign-council">
    <h2 id="cs-title">{{ t('ht.dis.sign') }} · {{ signing.person_id }}</h2>
    <p>{{ t('ht.dis.signText') }}</p>
    <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="sign">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="signing = null">{{ t('hi.back') }}</button></div>
  </section>

  <section v-if="hearingFor" class="card card--strong hi-section hi-form" role="dialog" aria-labelledby="ch-title" data-test="hearing">
    <h2 id="ch-title" class="hi-wide">{{ t('ht.dis.hearing') }} · {{ hearingFor.person_id }}</h2>
    <div class="field hi-wide"><label for="ch-min">{{ t('ht.dis.minutes') }}</label><textarea id="ch-min" v-model="hearing.minutes_text" rows="6" :aria-invalid="hearingTried && !hearing.minutes_text.trim()" /></div>
    <div class="field"><label for="ch-dec">{{ t('ht.dis.decision') }}</label><select id="ch-dec" v-model="hearing.decision"><option v-for="d in COUNCIL_DECISIONS" :key="d" :value="d">{{ t(hk('ht.dis.kind.', d)) }}</option></select></div>
    <template v-if="hearing.decision === 'temporary_exclusion'">
      <div class="field"><label for="ch-from">{{ t('ht.dis.exclusionFrom') }}</label><input id="ch-from" v-model="hearing.exclusion_from" type="date" /></div>
      <div class="field"><label for="ch-to">{{ t('ht.dis.exclusionTo') }}</label><input id="ch-to" v-model="hearing.exclusion_to" type="date" /></div>
    </template>
    <div class="field hi-wide"><label for="ch-reason">{{ t('ht.dis.decisionReason') }}</label><textarea id="ch-reason" v-model="hearing.decision_reason" :aria-invalid="hearingTried && !hearing.decision_reason.trim()" /><span v-if="hearingTried && (!hearing.decision_reason.trim() || !hearing.minutes_text.trim())" class="error">{{ t('hi.required') }}</span></div>
    <div class="hi-actions hi-wide"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="record">{{ t('hi.save') }}</button><button type="button" class="btn btn--quiet" @click="hearingFor = null">{{ t('hi.back') }}</button></div>
  </section>

  <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="convene">
    <h2 class="hi-wide">{{ t('ht.dis.convene') }}</h2>
    <div class="hi-wide"><PersonPicker id="cc-person" v-model="form.person_id" :label="t('ht.rep.student')" /><span v-if="touched && !form.person_id" class="error">{{ t('hi.required') }}</span></div>
    <div class="field"><label for="cc-date">{{ t('ht.dis.hearingDate') }}</label><input id="cc-date" v-model="form.hearing_date" type="date" :aria-invalid="touched && !form.hearing_date" /></div>
    <div class="field"><label for="cc-inc">{{ t('ht.dis.incident') }}</label><input id="cc-inc" v-model="form.incident_ref" type="text" /></div>
    <div class="field hi-wide"><label for="cc-members">{{ t('ht.dis.members') }}</label><input id="cc-members" v-model="form.members" type="text" aria-describedby="cc-members-hint" :aria-invalid="membersBad" /><span id="cc-members-hint" class="hint">{{ t('ht.dis.membersHint') }}</span><span v-if="membersBad" class="error">{{ t('ht.dis.membersBad') }}</span></div>
    <div class="field hi-wide"><label for="cc-reason">{{ t('hi.reason') }}</label><textarea id="cc-reason" v-model="form.reason" :aria-invalid="touched && !form.reason.trim()" /><span v-if="touched && !form.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
    <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('ht.dis.convene') }}</button></div>
  </form>
</template>
