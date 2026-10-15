<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { billing, documents, parseXaf, PAYMENT_CHANNELS, PHONE, SCHOLARSHIP_KINDS, type Holds, type IssuedDoc, type PaymentChannel, type PaymentResult, type Scholarship, type ScholarshipKind, type StudentAccount } from '@/api/higher';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { ulid } from '@/lib/ulid';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Compte de scolarité (EX-FIN-02 à 06) : échéancier, encaissement au guichet (caisse ouverte) ou mobile money, reçus, bourses et
// remises (validation par un second comptable), blocages d'impayés (jamais cours ni composition), attestation de paiement DOC-29 et
// quitus DOC-30 (refusé si le solde n'est pas nul, RG-FIN-6). Design : Frais.html (encaissement, journal du jour).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();
const auth = useAuthStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const account = ref<StudentAccount | null>(null);
const scholarships = ref<Scholarship[]>([]);
const holds = ref<Holds | null>(null);
const payment = ref<PaymentResult['payment'] | null>(null);
const issued = ref<IssuedDoc | null>(null);
const pay = reactive({ amount: '', channel: 'cash' as PaymentChannel, phone: '', ext: '', tried: false });
let payKey = ulid(); // conservée tant que l'encaissement n'a pas abouti : un double appui ne crée jamais deux reçus
const sch = reactive({ kind: 'merit' as ScholarshipKind, calc: 'percent' as 'percent' | 'fixed', rate: '', reason: '', tried: false });

const canCash = computed(() => ctx.can(...P.billingCash) && !higher.readOnly);
const canManage = computed(() => ctx.can(...P.billingManage) && !higher.readOnly);
const canCollect = computed(() => (pay.channel === 'cash' ? canCash.value : canCash.value || canManage.value));
const canIssue = computed(() => ctx.can(...P.documentIssue) && !higher.readOnly);
const canQuitus = computed(() => ctx.can(...P.quitus) && !higher.readOnly);
const amountValue = computed(() => parseXaf(pay.amount));
const mobile = computed(() => pay.channel === 'orange_money' || pay.channel === 'mtn_momo');
const phoneBad = computed(() => pay.tried && mobile.value && !pay.ext.trim() && !PHONE.test(pay.phone.trim()));
const balance = computed(() => holds.value?.balance_xaf ?? account.value?.balance_xaf ?? 0);
const BLOCKABLE = ['results', 'documents', 'reinscription'];
const NEVER = ['courses', 'exams'];

async function fetchAll(): Promise<void> {
  await higher.loadRefs('schools', 'programs', 'levels', 'years');
  const res = await load.run(async () => {
    const a = await billing.account(props.id);
    const [s, h] = await Promise.all([billing.scholarships(props.id).catch(() => [] as Scholarship[]), billing.holds(a.person_id).catch(() => null)]);
    return { a, s, h };
  });
  if (!res) return;
  account.value = res.a;
  scholarships.value = res.s;
  holds.value = res.h;
}

async function collect(): Promise<void> {
  pay.tried = true;
  if (amountValue.value === null || phoneBad.value) return;
  const res = await act.run(() =>
    billing.pay(props.id, { amount_xaf: amountValue.value as number, channel: pay.channel, ...(mobile.value && pay.phone.trim() ? { payer_phone: pay.phone.trim() } : {}), ...(pay.ext.trim() ? { external_reference: pay.ext.trim() } : {}) }, payKey),
  );
  if (!res) return;
  payKey = ulid();
  payment.value = res.payment;
  Object.assign(pay, { amount: '', ext: '', tried: false });
  await fetchAll();
}

async function requestScholarship(): Promise<void> {
  sch.tried = true;
  if (!sch.reason.trim()) return;
  const rate = sch.rate.trim() === '' ? undefined : Number(sch.rate);
  const res = await act.run(() => billing.requestScholarship(props.id, { scholarship_kind: sch.kind, calc_mode: sch.calc, ...(rate !== undefined ? { rate_value: rate } : {}), reason: sch.reason.trim() }));
  if (!res) return;
  Object.assign(sch, { rate: '', reason: '', tried: false });
  scholarships.value = await billing.scholarships(props.id).catch(() => scholarships.value);
}

async function decideScholarship(s: Scholarship, approve: boolean): Promise<void> {
  const res = await act.run(() => (approve ? billing.approveScholarship(s.id) : billing.rejectScholarship(s.id)));
  if (res) await fetchAll();
}

async function issue(code: 'DOC-29' | 'DOC-30'): Promise<void> {
  const a = account.value;
  if (!a) return;
  const res = await act.run(() => documents.issue({ code, person_id: a.person_id, school_id: a.school_id, program_id: a.program_id, level_id: a.level_id }));
  if (res) issued.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await fetchAll();
});
</script>

<template>
  <HigherFrame :title="t('hi.fin.accountTitle')" section="finance" :selector="false">
    <template #actions>
      <router-link :to="{ name: 'higher-accounts' }" class="btn btn--quiet">← {{ t('hi.backToList') }}</router-link>
      <router-link v-if="account" :to="{ name: 'higher-student', params: { personId: account.person_id } }" class="btn">{{ t('hi.ins.studentFile') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <template v-if="account">
      <p class="muted"><span class="hi-mono">{{ account.person_id }}</span> · {{ higher.schoolName(account.school_id) }}<template v-if="account.program_id"> · {{ higher.programName(account.program_id) }}</template><template v-if="account.level_id"> · {{ higher.levelName(account.level_id) }}</template> · {{ higher.yearName(account.academic_year_id) }}<template v-if="account.payer_name"> · {{ t('hi.fin.payer') }} : {{ account.payer_name }}</template></p>
      <div class="grid grid--kpi">
        <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.net') }}</h3><p class="kpi__value">{{ formatXaf(account.net_xaf, locale) }}</p><p v-if="account.ledger?.discount_xaf" class="kpi__detail">{{ t('hi.fin.discount') }} : {{ formatXaf(account.ledger.discount_xaf, locale) }}</p></article>
        <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.paid') }}</h3><p class="kpi__value">{{ formatXaf(account.paid_xaf, locale) }}</p></article>
        <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.balance') }}</h3><p class="kpi__value" data-test="balance">{{ formatXaf(account.balance_xaf, locale) }}</p><p class="kpi__detail"><span class="tag" :class="account.up_to_date ? 'tag--success' : 'tag--warning'">{{ account.up_to_date ? t('hi.fin.upToDate') : t('hi.fin.late') }}</span></p></article>
      </div>
      <p v-if="account.ledger?.next_due" class="hi-section">{{ t('hi.fin.nextDue', { label: account.ledger.next_due.label, amount: formatXaf(account.ledger.next_due.remaining_xaf, locale), date: formatDate(account.ledger.next_due.due_on, locale) }) }}</p>
      <p v-if="account.ledger?.overdue_xaf" class="notice notice--warning">{{ t('hi.fin.overdue', { amount: formatXaf(account.ledger.overdue_xaf, locale) }) }}</p>
      <ErrorNotice :error="act.error.value" />

      <div class="hi-two hi-section">
        <div>
          <section class="card" aria-labelledby="inst-title">
            <h2 id="inst-title">{{ t('hi.fin.installments') }}</h2>
            <div class="table-wrap">
              <table>
                <thead><tr><th scope="col">{{ t('hi.label') }}</th><th scope="col">{{ t('hi.date') }}</th><th scope="col" class="num">{{ t('hi.amount') }}</th><th scope="col" class="num">{{ t('hi.fin.remaining') }}</th><th scope="col">{{ t('hi.status') }}</th></tr></thead>
                <tbody>
                  <tr v-for="i in account.installments" :key="i.id">
                    <th scope="row">{{ i.label }}</th><td>{{ formatDate(i.due_on, locale) }}</td><td class="num">{{ formatXaf(i.amount_xaf, locale) }}</td><td class="num">{{ formatXaf(i.remaining_xaf, locale) }}</td>
                    <td><span class="tag" :class="`tag--${toneOf(i.status) ?? 'neutral'}`">{{ t(k('hi.fin.inst.', i.status)) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <template v-if="account.ledger?.lines?.length">
              <h3 class="hi-section">{{ t('hi.fin.lines') }}</h3>
              <ul class="hi-list hi-small">
                <li v-for="l in account.ledger.lines" :key="l.fee_code"><span class="hi-grow">{{ l.label }}</span><span>{{ formatXaf(l.net_xaf, locale) }}<template v-if="l.discount_xaf"> ({{ t('hi.fin.discount') }} −{{ formatXaf(l.discount_xaf, locale) }})</template></span></li>
              </ul>
            </template>
          </section>

          <section class="card hi-section" aria-labelledby="rc-title">
            <h2 id="rc-title">{{ t('hi.fin.receipts') }}</h2>
            <ul class="hi-list">
              <li v-for="r in account.receipts" :key="r.payment_id"><strong class="hi-mono">{{ r.receipt_number ?? t('hi.fin.receiptPending') }}</strong><span class="hi-grow">{{ t(k('hi.fin.channel.', r.channel)) }} · {{ formatDateTime(r.recorded_at, locale) }}</span><span>{{ formatXaf(r.amount_xaf, locale) }}</span></li>
              <li v-if="account.receipts.length === 0" class="muted">{{ t('hi.none') }}</li>
            </ul>
          </section>

          <section class="card hi-section" aria-labelledby="hold-title" data-test="holds">
            <h2 id="hold-title">{{ t('hi.fin.holds') }}</h2>
            <p class="muted hi-small">{{ t('hi.fin.holdsText') }}</p>
            <ul v-if="holds" class="hi-list">
              <li v-for="s in BLOCKABLE" :key="s"><span class="hi-grow">{{ t(k('hi.fin.hold.', s)) }}</span><span class="tag" :class="holds.blocks[s] ? 'tag--warning' : 'tag--success'">{{ holds.blocks[s] ? t('hi.fin.blocked') : t('hi.fin.notBlocked') }}</span></li>
              <li v-for="s in NEVER" :key="s"><span class="hi-grow">{{ t(k('hi.fin.hold.', s)) }}</span><span class="tag tag--success">{{ t('hi.fin.neverBlocked') }}</span></li>
            </ul>
          </section>
        </div>

        <div>
          <form v-if="canCash || canManage" class="card card--strong" novalidate data-test="collect" @submit.prevent="collect">
            <h2>{{ t('hi.fin.collect') }}</h2>
            <div class="field"><label for="pay-amount">{{ t('hi.amount') }}</label><input id="pay-amount" v-model="pay.amount" type="text" inputmode="numeric" :aria-invalid="pay.tried && amountValue === null" /><span v-if="pay.tried && amountValue === null" class="error">{{ t('hi.amountBad') }}</span></div>
            <fieldset class="field">
              <legend class="label">{{ t('hi.app.payChannel') }}</legend>
              <label v-for="c in PAYMENT_CHANNELS" :key="c" class="check"><input v-model="pay.channel" type="radio" :value="c" /> {{ t(k('hi.fin.channel.', c)) }}</label>
            </fieldset>
            <p v-if="pay.channel === 'cash'" class="muted hi-small">{{ t('hi.fin.cashNeedsSession') }}</p>
            <div v-if="mobile" class="field"><label for="pay-phone">{{ t('hi.app.payPhone') }}</label><input id="pay-phone" v-model="pay.phone" type="tel" :aria-invalid="phoneBad" /><span v-if="phoneBad" class="error">{{ t('hi.app.payPhoneBad') }}</span></div>
            <div v-if="pay.channel !== 'cash'" class="field"><label for="pay-ext">{{ t('hi.fin.extRef') }}</label><input id="pay-ext" v-model="pay.ext" type="text" /></div>
            <p class="muted hi-small">{{ t('hi.fin.collectHint') }}</p>
            <button type="submit" class="btn btn--primary btn--block" :disabled="act.busy.value || !canCollect">{{ t('hi.fin.collectBtn') }}</button>
            <p v-if="payment && payment.receipt_number" class="notice notice--success hi-section" role="status">{{ t('hi.fin.paymentDone', { number: payment.receipt_number }) }}</p>
            <p v-else-if="payment" class="notice hi-section" role="status">{{ t('hi.fin.paymentPending') }}</p>
          </form>

          <section class="card hi-section" aria-labelledby="sch-title">
            <h2 id="sch-title">{{ t('hi.fin.scholarships') }}</h2>
            <ul class="hi-list">
              <li v-for="s in scholarships" :key="s.id" :data-scholarship="s.id">
                <span class="tag" :class="`tag--${toneOf(s.status) ?? 'neutral'}`">{{ t(k('hi.fin.sch.st.', s.status)) }}</span>
                <span class="hi-grow">{{ t(k('hi.fin.sch.', s.scholarship_kind)) }} · {{ s.calc_mode === 'percent' ? `${s.rate_value} %` : formatXaf(s.rate_value, locale) }} · {{ formatXaf(s.amount_xaf, locale) }}<br /><span class="muted hi-small">{{ s.reason }}</span></span>
                <template v-if="s.status === 'pending' && canManage">
                  <template v-if="s.requested_by !== auth.me?.id">
                    <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="decideScholarship(s, true)">{{ t('hi.fin.approve') }}</button>
                    <button type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="decideScholarship(s, false)">{{ t('hi.fin.reject') }}</button>
                  </template>
                  <span v-else class="muted hi-small" data-test="own-request">{{ t('hi.fin.ownRequest') }}</span>
                </template>
              </li>
              <li v-if="scholarships.length === 0" class="muted">{{ t('hi.none') }}</li>
            </ul>
            <form v-if="canManage" class="hi-section" novalidate @submit.prevent="requestScholarship">
              <h3>{{ t('hi.fin.scholarshipNew') }}</h3>
              <p class="muted hi-small">{{ t('hi.fin.scholarshipHint') }}</p>
              <div class="hi-form">
                <div class="field"><label for="sch-kind">{{ t('hi.app.kind') }}</label><select id="sch-kind" v-model="sch.kind"><option v-for="x in SCHOLARSHIP_KINDS" :key="x" :value="x">{{ t(k('hi.fin.sch.', x)) }}</option></select></div>
                <div class="field"><label for="sch-calc">{{ t('hi.fin.rate') }}</label><select id="sch-calc" v-model="sch.calc"><option value="percent">{{ t('hi.fin.calc.percent') }}</option><option value="fixed">{{ t('hi.fin.calc.fixed') }}</option></select></div>
                <div class="field"><label for="sch-rate">{{ t('hi.fin.rate') }}</label><input id="sch-rate" v-model="sch.rate" type="number" min="0" aria-describedby="sch-rate-hint" /><span id="sch-rate-hint" class="hint">{{ t('hi.fin.rateHint') }}</span></div>
                <div class="field hi-wide"><label for="sch-reason">{{ t('hi.reason') }}</label><input id="sch-reason" v-model="sch.reason" type="text" :aria-invalid="sch.tried && !sch.reason.trim()" /><span v-if="sch.tried && !sch.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
              </div>
              <button type="submit" class="btn btn--quiet" :disabled="act.busy.value">{{ t('hi.fin.scholarshipNew') }}</button>
            </form>
          </section>

          <section class="card hi-section" aria-labelledby="cert-title" data-test="certificates">
            <h2 id="cert-title">{{ t('hi.fin.certificates') }}</h2>
            <div class="hi-actions">
              <button type="button" class="btn" :disabled="!canIssue || act.busy.value" @click="issue('DOC-29')">{{ t('hi.fin.attestation') }}</button>
              <button type="button" class="btn" :disabled="!canQuitus || balance > 0 || act.busy.value" data-test="quitus" @click="issue('DOC-30')">{{ t('hi.fin.quitus') }}</button>
            </div>
            <p v-if="balance > 0" class="notice notice--warning hi-section" role="status" data-test="quitus-refused">{{ t('hi.fin.quitusRefused', { amount: formatXaf(balance, locale) }) }}</p>
            <p v-if="issued" class="notice notice--success hi-section" role="status">{{ t('hi.ins.issued', { number: issued.number }) }} · <router-link :to="{ name: 'verify', params: { token: issued.verification_token } }">{{ t('hi.doc.verifyLink') }}</router-link></p>
          </section>
        </div>
      </div>
    </template>
  </HigherFrame>
</template>
