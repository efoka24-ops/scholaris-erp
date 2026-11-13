<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { admissions, DECISIONS, enrolments, PHONE, type Application, type ApplicationDocument, type Campaign, type CampaignStatistics, type Decision } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { ulid } from '@/lib/ulid';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import { useWorkflow, WF } from '@/lib/useWorkflow';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Fiche candidature (EX-ADM-02 à 07) : pièces (déposer, vérifier, refuser), prérequis, frais de dossier (mobile money ou espèces),
// note, décision motivée (directeur d'école), dépassement des places avec motif (RG-ADM-3), retrait, création de l'inscription.
// Design : InscriptionPieces.html (pièces, frais et paiement).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const load = useHigherAction();
const act = useHigherAction();

const app = ref<Application | null>(null);
const campaign = ref<Campaign | null>(null);
const stats = ref<CampaignStatistics | null>(null);
const refs = reactive<Record<string, string>>({});
const rejecting = ref<ApplicationDocument | null>(null);
const rejectReason = ref('');
const pay = reactive({ channel: 'orange_money' as 'orange_money' | 'mtn_momo' | 'cash', phone: '' });
let payKey = ulid();
const score = ref('');
const decision = reactive({ decision: 'admitted' as Decision, reason: '', over: '' });
const decisionTried = ref(false);
const overRequired = ref(false);
const withdrawReason = ref('');

const canManage = computed(() => ctx.can(...P.applicationManage) && !higher.readOnly);
const workflow = useWorkflow();
// Décision d'admission : la fonction désignée par le circuit de l'établissement (directeur d'école sans réglage).
const canDecide = computed(() => workflow.canAct(WF.admission, 'decision', P.admissionDecide) && !higher.readOnly);
const canEnrol = computed(() => ctx.can(...P.enrol) && !higher.readOnly);
const verified = computed(() => app.value?.documents?.filter((d) => d.status === 'verified').length ?? 0);
const holdingSeat = computed(() => ['admitted', 'conditional', 'enrolled'].includes(app.value?.status ?? ''));
const seatsFull = computed(() => !!stats.value && stats.value.seats_left <= 0 && !holdingSeat.value && (decision.decision === 'admitted' || decision.decision === 'conditional'));
const showOver = computed(() => seatsFull.value || overRequired.value);
const phoneBad = computed(() => pay.channel !== 'cash' && pay.phone.trim() !== '' && !PHONE.test(pay.phone.trim()));
const scoreValue = computed(() => {
  const n = Number(score.value.replace(',', '.'));
  return score.value.trim() !== '' && Number.isFinite(n) && n >= 0 && n <= 20 ? Math.round(n * 100) : null;
});

async function fetchAll(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const res = await load.run(async () => {
    const a = await admissions.application(props.id);
    const [c, s] = await Promise.all([admissions.campaign(a.campaign_id).catch(() => null), admissions.statistics(a.campaign_id).catch(() => null)]);
    return { a, c, s };
  });
  if (!res) return;
  app.value = res.a;
  campaign.value = res.c;
  stats.value = res.s;
  if (res.a.score_hundredths !== null) score.value = (res.a.score_hundredths / 100).toFixed(2);
}

async function apply(fn: () => Promise<Application>): Promise<boolean> {
  const res = await act.run(fn);
  if (res) {
    app.value = { ...app.value, ...res } as Application;
    return true;
  }
  return false;
}

async function attach(d: ApplicationDocument): Promise<void> {
  const ref = (refs[d.id] ?? '').trim();
  if (!ref) return;
  if (await apply(() => admissions.attachDocument(props.id, { requirement_code: d.requirement_code, document_ref: ref }))) refs[d.id] = '';
}
const verify = (d: ApplicationDocument): Promise<boolean> => apply(() => admissions.verifyDocument(props.id, d.id));
async function confirmReject(): Promise<void> {
  const d = rejecting.value;
  if (!d || !rejectReason.value.trim()) return;
  if (await apply(() => admissions.rejectDocument(props.id, d.id, rejectReason.value.trim()))) {
    rejecting.value = null;
    rejectReason.value = '';
  }
}
const submit = (): Promise<boolean> => apply(() => admissions.submit(props.id));

async function payFee(): Promise<void> {
  if (pay.channel !== 'cash' && !PHONE.test(pay.phone.trim())) return;
  const body = pay.channel === 'cash' ? { channel: 'cash' } : { channel: pay.channel, payer_phone: pay.phone.trim() };
  if (await apply(() => admissions.payFee(props.id, body, payKey))) payKey = ulid();
}
const refreshFee = (): Promise<boolean> => apply(() => admissions.payFee(props.id, {}, ulid()));

async function saveScore(): Promise<void> {
  const v = scoreValue.value;
  if (v === null) return;
  await apply(() => admissions.score(props.id, v));
}

async function decide(): Promise<void> {
  decisionTried.value = true;
  if (!decision.reason.trim() || (showOver.value && !decision.over.trim())) return;
  const ok = await apply(() => admissions.decide(props.id, { decision: decision.decision, reason: decision.reason.trim(), ...(showOver.value ? { over_capacity_reason: decision.over.trim() } : {}) }));
  if (ok) {
    decisionTried.value = false;
    overRequired.value = false;
    await fetchAll();
  } else if (act.error.value instanceof ApiError && act.error.value.status === 409 && (decision.decision === 'admitted' || decision.decision === 'conditional')) {
    overRequired.value = true; // places prises : le motif de dépassement devient obligatoire
  }
}

async function withdraw(): Promise<void> {
  if (!withdrawReason.value.trim()) return;
  await apply(() => admissions.withdraw(props.id, withdrawReason.value.trim()));
}

async function enrol(): Promise<void> {
  const res = await act.run(() => enrolments.create({ application_id: props.id }));
  if (res) await router.push({ name: 'higher-enrolment', params: { id: res.id } });
}

onMounted(async () => {
  await higher.ensure();
  await fetchAll();
});
</script>

<template>
  <HigherFrame :title="app ? t('hi.app.sheet', { ref: app.reference }) : t('hi.app.title')" section="admissions" :selector="false">
    <template #actions>
      <router-link :to="{ name: 'higher-applications' }" class="btn btn--quiet">← {{ t('hi.backToList') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <template v-if="app">
      <div class="row">
        <span class="tag" :class="`tag--${toneOf(app.status) ?? 'neutral'}`" data-test="status">{{ t(k('hi.app.st.', app.status)) }}</span>
        <span>{{ higher.programName(app.program_id) }} · {{ higher.levelName(app.level_id) }} · {{ higher.yearName(app.academic_year_id) }}</span>
        <span class="muted">{{ t(k('hi.app.channel.', app.channel)) }} · {{ t(k('hi.app.kind.', app.application_kind)) }}</span>
      </div>
      <dl class="hi-kv hi-section">
        <dt>{{ t('hi.app.diploma') }}</dt><dd>{{ t(k('hi.app.diploma.', app.diploma)) }}<template v-if="app.diploma_series"> · {{ app.diploma_series }}</template><template v-if="app.diploma_year"> · {{ app.diploma_year }}</template><template v-if="app.diploma_mention"> · {{ t(k('hi.app.mention.', app.diploma_mention)) }}</template></dd>
        <template v-if="app.origin_institution"><dt>{{ t('hi.app.origin') }}</dt><dd>{{ app.origin_institution }}</dd></template>
        <template v-if="app.decision_reason"><dt>{{ t('hi.reason') }}</dt><dd>{{ app.decision_reason }}<template v-if="app.conditional_until"> · {{ formatDate(app.conditional_until, locale) }}</template></dd></template>
      </dl>
      <ErrorNotice :error="act.error.value" />

      <div class="hi-two hi-section">
        <section class="card" aria-labelledby="pieces-title">
          <div class="row row--between"><h2 id="pieces-title">{{ t('hi.app.pieces') }}</h2><span class="tag">{{ t('hi.app.piecesCount', { done: verified, total: app.documents?.length ?? 0 }) }}</span></div>
          <ul class="hi-list">
            <li v-for="d in app.documents ?? []" :key="d.id" :data-doc="d.requirement_code">
              <span class="tag" :class="`tag--${toneOf(d.status) ?? 'neutral'}`">{{ t(k('hi.app.doc.', d.status)) }}</span>
              <span class="hi-grow"><strong>{{ d.label }}</strong> <span v-if="d.blocking" class="muted hi-small">· {{ t('hi.app.blocking') }}</span><span v-if="d.proves_prerequisite" class="muted hi-small"> · {{ t('hi.app.provesPrereq') }}</span><br /><span v-if="d.document_ref" class="hi-mono">{{ d.document_ref }}</span><span v-if="d.rejection_reason" class="error"> {{ d.rejection_reason }}</span></span>
              <template v-if="canManage">
                <button v-if="d.status === 'submitted'" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="verify(d)">{{ t('hi.app.verify') }}</button>
                <button v-if="d.status === 'submitted' || d.status === 'verified'" type="button" class="hi-linkbtn" @click="rejecting = d">{{ t('hi.app.reject') }}</button>
                <span class="row">
                  <label :for="`ref-${d.id}`" class="sr-only">{{ t('hi.app.docRef') }} · {{ d.label }}</label>
                  <input :id="`ref-${d.id}`" v-model="refs[d.id]" type="text" :placeholder="t('hi.app.docRef')" class="hi-ref" />
                  <button type="button" class="btn btn--quiet" :disabled="act.busy.value || !(refs[d.id] ?? '').trim()" @click="attach(d)">{{ d.document_ref ? t('hi.app.replace') : t('hi.app.attach') }}</button>
                </span>
              </template>
            </li>
          </ul>
          <div v-if="rejecting" class="card card--strong hi-section" role="dialog" aria-labelledby="reject-title">
            <h3 id="reject-title">{{ t('hi.app.reject') }} · {{ rejecting.label }}</h3>
            <div class="field"><label for="reject-reason">{{ t('hi.app.rejectReason') }}</label><textarea id="reject-reason" v-model="rejectReason" /></div>
            <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="!rejectReason.trim() || act.busy.value" @click="confirmReject">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="rejecting = null">{{ t('hi.back') }}</button></div>
          </div>
          <div v-if="canManage && app.status === 'draft'" class="hi-section">
            <button type="button" class="btn btn--primary" :disabled="act.busy.value" @click="submit">{{ t('hi.app.submit') }}</button>
            <p class="muted hi-small">{{ t('hi.app.submitHint') }}</p>
          </div>

          <h3 class="hi-section">{{ t('hi.app.prereq') }}</h3>
          <ul v-if="campaign && (campaign.prerequisites.diplomas?.length || campaign.prerequisites.min_mention || campaign.prerequisites.min_year)" class="hi-list hi-small">
            <li v-if="campaign.prerequisites.diplomas?.length">{{ t('hi.app.prereqDiplomas', { list: campaign.prerequisites.diplomas.map((d) => t(k('hi.app.diploma.', d))).join(', ') }) }}</li>
            <li v-if="campaign.prerequisites.min_mention">{{ t('hi.app.prereqMention', { mention: t(k('hi.app.mention.', campaign.prerequisites.min_mention)) }) }}</li>
            <li v-if="campaign.prerequisites.min_year">{{ t('hi.app.prereqYear', { year: campaign.prerequisites.min_year }) }}</li>
          </ul>
          <p v-else class="muted hi-small">{{ t('hi.app.prereqNone') }}</p>
        </section>

        <div>
          <section class="card" aria-labelledby="fee-title" data-test="fee">
            <h2 id="fee-title">{{ t('hi.app.fees') }}</h2>
            <p>
              <span class="tag" :class="`tag--${toneOf(app.fee.status) ?? 'neutral'}`">{{ t(k('hi.app.fee.', app.fee.status)) }}</span>
              <strong v-if="campaign"> {{ formatXaf(campaign.application_fee_xaf, locale) }}</strong>
              <span v-if="app.fee.receipt_number" class="muted"> · {{ t('hi.app.feeReceipt', { number: app.fee.receipt_number }) }}</span>
            </p>
            <p v-if="app.fee.refundable" class="notice">{{ t('hi.app.feeRefundable') }}</p>
            <template v-if="canManage && app.fee.status === 'unpaid' && app.status !== 'draft'">
              <fieldset class="field">
                <legend class="label">{{ t('hi.app.payChannel') }}</legend>
                <label class="check"><input v-model="pay.channel" type="radio" value="orange_money" /> {{ t('hi.fin.channel.orange_money') }}</label>
                <label class="check"><input v-model="pay.channel" type="radio" value="mtn_momo" /> {{ t('hi.fin.channel.mtn_momo') }}</label>
                <label class="check"><input v-model="pay.channel" type="radio" value="cash" /> {{ t('hi.app.payCash') }}</label>
              </fieldset>
              <div v-if="pay.channel !== 'cash'" class="field"><label for="pay-phone">{{ t('hi.app.payPhone') }}</label><input id="pay-phone" v-model="pay.phone" type="tel" inputmode="tel" :aria-invalid="phoneBad" /><span v-if="phoneBad" class="error">{{ t('hi.app.payPhoneBad') }}</span></div>
              <p v-else class="muted hi-small">{{ t('hi.fin.cashNeedsSession') }}</p>
              <button type="button" class="btn btn--primary" :disabled="act.busy.value || (pay.channel !== 'cash' && !PHONE.test(pay.phone.trim()))" @click="payFee">{{ t('hi.app.pay') }}</button>
            </template>
            <button v-if="app.fee.status === 'pending'" type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="refreshFee">{{ t('hi.app.refresh') }}</button>
          </section>

          <section v-if="canManage && ['complete', 'admitted', 'conditional', 'waitlisted'].includes(app.status)" class="card hi-section">
            <div class="field"><label for="score">{{ t('hi.adm.score') }}</label><input id="score" v-model="score" type="text" inputmode="decimal" :aria-invalid="score !== '' && scoreValue === null" /><span v-if="score !== '' && scoreValue === null" class="error">{{ t('hi.app.scoreBad') }}</span></div>
            <button type="button" class="btn btn--quiet" :disabled="scoreValue === null || act.busy.value" @click="saveScore">{{ t('hi.app.scoreAction') }}</button>
          </section>

          <section v-if="canDecide && ['complete', 'admitted', 'conditional', 'waitlisted'].includes(app.status)" class="card card--strong hi-section" aria-labelledby="dec-title" data-test="decision">
            <h2 id="dec-title">{{ t('hi.app.decision') }}</h2>
            <p class="muted hi-small">{{ t('hi.app.decisionHint') }}</p>
            <fieldset class="field">
              <legend class="sr-only">{{ t('hi.app.decision') }}</legend>
              <label v-for="d in DECISIONS" :key="d" class="check"><input v-model="decision.decision" type="radio" :value="d" /> {{ t(k('hi.app.dec.', d)) }}</label>
            </fieldset>
            <div class="field"><label for="dec-reason">{{ t('hi.reason') }}</label><textarea id="dec-reason" v-model="decision.reason" :aria-invalid="decisionTried && !decision.reason.trim()" /><span v-if="decisionTried && !decision.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
            <div v-if="showOver" class="notice notice--warning" data-test="over-capacity">
              <p><strong>{{ t('hi.app.over') }}</strong> — {{ t('hi.app.overHint') }}</p>
              <div class="field"><label for="dec-over">{{ t('hi.app.overReason') }}</label><textarea id="dec-over" v-model="decision.over" :aria-invalid="decisionTried && !decision.over.trim()" /><span v-if="decisionTried && !decision.over.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
            </div>
            <button type="button" class="btn btn--primary" :disabled="act.busy.value" @click="decide">{{ t('hi.app.decide') }}</button>
          </section>

          <section v-if="app.decisions?.length" class="card hi-section">
            <h3>{{ t('hi.app.history') }}</h3>
            <ul class="hi-list hi-small">
              <li v-for="d in app.decisions" :key="d.id"><span class="tag" :class="`tag--${toneOf(d.decision) ?? 'neutral'}`">{{ t(k('hi.app.dec.', d.decision)) }}</span><span class="hi-grow">{{ d.reason }}<template v-if="d.over_capacity_reason"> · {{ t('hi.app.over') }} : {{ d.over_capacity_reason }}</template></span><span class="muted">{{ formatDateTime(d.decided_at, locale) }}</span></li>
            </ul>
          </section>

          <section v-if="canEnrol && (app.status === 'admitted' || app.status === 'conditional') && !app.enrolment_id" class="card hi-section">
            <button type="button" class="btn btn--primary" :disabled="act.busy.value" @click="enrol">{{ t('hi.app.enrol') }}</button>
          </section>
          <p v-if="app.enrolment_id" class="hi-section"><router-link :to="{ name: 'higher-enrolment', params: { id: app.enrolment_id } }">{{ t('hi.app.enrolled') }} →</router-link></p>

          <section v-if="canManage && !['withdrawn', 'enrolled', 'rejected'].includes(app.status)" class="card hi-section">
            <h3>{{ t('hi.app.withdraw') }}</h3>
            <p class="muted hi-small">{{ t('hi.app.withdrawHint') }}</p>
            <div class="field"><label for="wd-reason">{{ t('hi.reason') }}</label><input id="wd-reason" v-model="withdrawReason" type="text" /></div>
            <button type="button" class="btn btn--quiet" :disabled="!withdrawReason.trim() || act.busy.value" @click="withdraw">{{ t('hi.app.withdraw') }}</button>
          </section>
        </div>
      </div>
    </template>
  </HigherFrame>
</template>

<style scoped>
.hi-ref {
  width: 200px;
}
</style>
