<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { candidate, fileProblem, type FollowDocument, type FollowUp, type OpenCampaign } from '@/api/higher-apply';
import { DIPLOMAS, MENTIONS, MOBILE_CHANNELS, PHONE, type Diploma, type Mention } from '@/api/higher';
import { formatDate, formatXaf } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { useRoute } from 'vue-router';
import HigherFrame from '../HigherFrame.vue';
import { useHigherAction } from '../useHigherAction';
import { hk, useApplyI18n } from './i18n';

// Espace du candidat connecté (mobile d'abord) : ses candidatures, le formulaire de dossier, l'envoi de ses pièces, le paiement
// des frais de dossier par mobile money, le résultat et la convocation. Le serveur ne rend que SES dossiers (404 pour les autres).
const { ta, locale } = useApplyI18n();
const route = useRoute();
const load = useHigherAction();
const act = useHigherAction();

const applications = ref<FollowUp[]>([]);
const campaigns = ref<OpenCampaign[]>([]);
const notice = ref('');
const fileError = ref<Record<string, string>>({});
const sending = ref('');
const withdrawing = ref<string | null>(null);
const withdrawReason = ref('');
const keys = new Map<string, string>();
const pay = reactive<{ channel: (typeof MOBILE_CHANNELS)[number]; phone: string; tried: boolean }>({ channel: 'orange_money', phone: '', tried: false });
const draft = reactive({ campaign_id: '', application_kind: 'new' as 'new' | 'transfer', origin_institution: '', diploma: 'bac' as Diploma, diploma_series: '', diploma_year: '', diploma_mention: '' as Mention | '', tried: false });

const startable = computed(() => campaigns.value.filter((c) => !applications.value.some((a) => a.campaign.id === c.id)));
const chosenCampaign = computed(() => campaigns.value.find((c) => c.id === draft.campaign_id) ?? null);
const phoneBad = computed(() => !PHONE.test(pay.phone.replace(/\s/g, '')));
const closed = (a: FollowUp): boolean => ['withdrawn', 'enrolled', 'rejected'].includes(a.status);

async function refresh(): Promise<void> {
  const [mine, open] = await Promise.all([load.run(() => candidate.mine()), load.run(() => candidate.openCampaigns())]);
  if (mine) applications.value = mine;
  if (open) campaigns.value = open;
  const wanted = typeof route.query.campagne === 'string' ? route.query.campagne : '';
  if (wanted && startable.value.some((c) => c.id === wanted)) draft.campaign_id = wanted;
}
onMounted(refresh);

async function createDraft(): Promise<void> {
  draft.tried = true;
  if (!draft.campaign_id || (draft.application_kind === 'transfer' && draft.origin_institution.trim() === '')) return;
  const year = draft.diploma_year.trim() === '' ? undefined : Number(draft.diploma_year);
  if (year !== undefined && (!Number.isInteger(year) || year < 1950 || year > 2100)) return;
  const res = await act.run(() =>
    candidate.create({
      campaign_id: draft.campaign_id,
      application_kind: draft.application_kind,
      ...(draft.application_kind === 'transfer' ? { origin_institution: draft.origin_institution.trim() } : {}),
      diploma: draft.diploma,
      ...(draft.diploma_series.trim() ? { diploma_series: draft.diploma_series.trim() } : {}),
      ...(year !== undefined ? { diploma_year: year } : {}),
      ...(draft.diploma_mention ? { diploma_mention: draft.diploma_mention } : {}),
    }),
  );
  if (!res) return;
  draft.tried = false;
  draft.campaign_id = '';
  await refresh();
}

async function upload(a: FollowUp, d: FollowDocument, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  const problem = fileProblem(file);
  if (problem) {
    fileError.value = { ...fileError.value, [d.id]: ta(problem === 'size' ? 'ha.docs.tooBig' : 'ha.docs.badType') };
    input.value = '';
    return;
  }
  fileError.value = { ...fileError.value, [d.id]: '' };
  sending.value = d.id;
  const res = await act.run(() => candidate.upload(a.id, d.id, file));
  sending.value = '';
  input.value = '';
  if (res !== undefined) {
    notice.value = ta('ha.docs.sent');
    await refresh();
  }
}

async function view(a: FollowUp, d: FollowDocument): Promise<void> {
  const blob = await act.run(() => candidate.download(a.id, d.id));
  if (!blob) return;
  const url = URL.createObjectURL(blob);
  window.open(url, '_blank', 'noopener');
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

async function submit(a: FollowUp): Promise<void> {
  const res = await act.run(() => candidate.submit(a.id));
  if (res !== undefined) {
    notice.value = ta('ha.submit.done');
    await refresh();
  }
}

async function payFee(a: FollowUp): Promise<void> {
  pay.tried = true;
  if (phoneBad.value) return;
  // Même clé jusqu'au succès : un double appui ne crée jamais deux demandes de paiement.
  const key = keys.get(a.id) ?? ulid();
  keys.set(a.id, key);
  const res = await act.run(() => candidate.payFee(a.id, { channel: pay.channel, payer_phone: pay.phone.replace(/\s/g, '') }, key));
  if (res === undefined) return;
  keys.delete(a.id);
  pay.tried = false;
  notice.value = ta('ha.fee.sent');
  await refresh();
}

async function recheck(a: FollowUp): Promise<void> {
  const res = await act.run(() => candidate.payFee(a.id, {}, ulid()));
  if (res !== undefined) await refresh();
}

async function withdraw(a: FollowUp): Promise<void> {
  if (withdrawReason.value.trim() === '') return;
  const res = await act.run(() => candidate.withdraw(a.id, withdrawReason.value.trim()));
  if (res === undefined) return;
  withdrawing.value = null;
  withdrawReason.value = '';
  await refresh();
}
</script>

<template>
  <HigherFrame :title="ta('ha.my.title')" :lede="ta('ha.my.lede')" section="portal" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="notice" class="notice notice--success" role="status" data-test="notice">{{ notice }}</p>

    <section v-if="startable.length" class="card" aria-labelledby="my-open">
      <h2 id="my-open">{{ ta('ha.my.open') }}</h2>
      <form novalidate data-test="draft-form" @submit.prevent="createDraft">
        <div class="field">
          <label for="dr-campaign">{{ ta('ha.pub.campaigns') }}</label>
          <select id="dr-campaign" v-model="draft.campaign_id" :aria-invalid="draft.tried && !draft.campaign_id">
            <option value="" disabled>—</option>
            <option v-for="c in startable" :key="c.id" :value="c.id">{{ c.program }} · {{ c.level }} ({{ ta('ha.pub.dates', { from: formatDate(c.opens_on, locale), to: formatDate(c.closes_on, locale) }) }})</option>
          </select>
        </div>
        <p v-if="chosenCampaign" class="muted">{{ chosenCampaign.application_fee_xaf > 0 ? ta('ha.pub.fee', { amount: formatXaf(chosenCampaign.application_fee_xaf, locale) }) : ta('ha.pub.feeNone') }}</p>
        <fieldset class="field">
          <legend class="label">{{ ta('ha.form.kind') }}</legend>
          <label class="check"><input v-model="draft.application_kind" type="radio" name="dr-kind" value="new" /> {{ ta('ha.form.kind.new') }}</label>
          <label class="check"><input v-model="draft.application_kind" type="radio" name="dr-kind" value="transfer" /> {{ ta('ha.form.kind.transfer') }}</label>
        </fieldset>
        <div v-if="draft.application_kind === 'transfer'" class="field">
          <label for="dr-origin">{{ ta('ha.form.origin') }}</label>
          <input id="dr-origin" v-model="draft.origin_institution" type="text" :aria-invalid="draft.tried && draft.origin_institution.trim() === ''" />
        </div>
        <div class="field">
          <label for="dr-diploma">{{ ta('ha.form.diploma') }}</label>
          <select id="dr-diploma" v-model="draft.diploma"><option v-for="d in DIPLOMAS" :key="d" :value="d">{{ ta(hk('ha.diploma.', d)) }}</option></select>
        </div>
        <div class="field"><label for="dr-series">{{ ta('ha.form.series') }}</label><input id="dr-series" v-model="draft.diploma_series" type="text" maxlength="20" /></div>
        <div class="field"><label for="dr-year">{{ ta('ha.form.year') }}</label><input id="dr-year" v-model="draft.diploma_year" type="text" inputmode="numeric" maxlength="4" /></div>
        <div class="field">
          <label for="dr-mention">{{ ta('ha.form.mention') }}</label>
          <select id="dr-mention" v-model="draft.diploma_mention"><option value="">{{ ta('ha.form.mentionNone') }}</option><option v-for="m in MENTIONS" :key="m" :value="m">{{ ta(hk('ha.mention.', m)) }}</option></select>
        </div>
        <button type="submit" class="btn btn--primary btn--block" :disabled="act.busy.value">{{ ta('ha.form.create') }}</button>
      </form>
    </section>
    <p v-else-if="applications.length === 0 && !load.busy.value" class="notice" data-test="nothing">{{ ta('ha.my.noOpen') }}</p>

    <section v-for="a in applications" :key="a.id" class="card card--strong ap-app" :aria-labelledby="`app-${a.id}`" :data-test="`application-${a.id}`">
      <h2 :id="`app-${a.id}`">{{ a.campaign.title }}</h2>
      <p class="muted">{{ ta('ha.my.reference', { reference: a.reference }) }}</p>
      <p><strong>{{ ta('ha.my.status') }} :</strong> <span class="tag" data-test="status">{{ ta(hk('ha.status.', a.status)) }}</span></p>

      <section v-if="a.result" aria-labelledby="res-title" data-test="result">
        <h3 id="res-title">{{ ta('ha.res.title') }}</h3>
        <p><strong>{{ ta(hk('ha.status.', a.result.decision)) }}</strong></p>
        <p v-if="a.result.reason">{{ ta('ha.res.reason', { reason: a.result.reason }) }}</p>
        <p v-if="a.result.conditional_until">{{ ta('ha.res.until', { date: formatDate(a.result.conditional_until, locale) }) }}</p>
        <p v-if="a.result.waitlist_rank !== null">{{ ta('ha.res.rank', { rank: a.result.waitlist_rank }) }}</p>
      </section>
      <p v-else-if="a.status === 'complete'" class="muted">{{ ta('ha.res.pending') }}</p>

      <section v-if="a.convocation" aria-labelledby="conv-title" data-test="convocation">
        <h3 id="conv-title">{{ ta('ha.conv.title') }}</h3>
        <ul>
          <li>{{ ta('ha.conv.date', { date: formatDate(a.convocation.contest_date, locale) }) }}</li>
          <li>{{ ta('ha.conv.center', { center: a.convocation.center_name }) }}</li>
          <li>{{ ta('ha.conv.room', { room: a.convocation.room_name }) }}</li>
          <li>{{ ta('ha.conv.seat', { seat: a.convocation.seat_number }) }}</li>
        </ul>
      </section>

      <section aria-labelledby="docs-title">
        <h3 id="docs-title">{{ ta('ha.docs.title') }}</h3>
        <p class="muted">{{ ta('ha.docs.hint') }}</p>
        <p v-if="a.missing_documents > 0" class="notice" data-test="missing">{{ ta('ha.docs.missingCount', { count: a.missing_documents }) }}</p>
        <ul class="ap-docs">
          <li v-for="d in a.documents" :key="d.id" :data-test="`doc-${d.requirement_code}`">
            <strong>{{ d.label }}</strong>
            <span class="tag" :class="d.status === 'rejected' ? 'tag--danger' : d.status === 'verified' ? 'tag--success' : ''">{{ ta(hk('ha.docs.status.', d.status)) }}</span>
            <p v-if="d.rejection_reason" class="error" role="alert">{{ ta('ha.docs.reason', { reason: d.rejection_reason }) }}</p>
            <p v-if="fileError[d.id]" class="error" role="alert">{{ fileError[d.id] }}</p>
            <div v-if="!closed(a) && d.status !== 'verified'" class="field">
              <label :for="`file-${d.id}`">{{ d.has_file ? ta('ha.docs.replace') : ta('ha.docs.choose') }}</label>
              <input :id="`file-${d.id}`" type="file" accept="application/pdf,image/jpeg,image/png" @change="upload(a, d, $event)" />
              <span v-if="sending === d.id" role="status">{{ ta('ha.docs.sending') }}</span>
            </div>
            <button v-if="d.has_file" type="button" class="btn btn--quiet" @click="view(a, d)">{{ ta('ha.docs.view') }}</button>
          </li>
        </ul>
      </section>

      <section v-if="a.status === 'draft'" aria-labelledby="sub-title">
        <h3 id="sub-title">{{ ta('ha.submit.title') }}</h3>
        <p class="muted">{{ ta('ha.submit.text') }}</p>
        <button type="button" class="btn btn--primary btn--block" data-test="submit" :disabled="act.busy.value" @click="submit(a)">{{ ta('ha.submit.go') }}</button>
      </section>

      <section v-else-if="!closed(a)" aria-labelledby="fee-title" data-test="fee">
        <h3 id="fee-title">{{ ta('ha.fee.title') }}</h3>
        <p v-if="a.fee.status === 'none'">{{ ta('ha.fee.none') }}</p>
        <template v-else>
          <p>{{ ta('ha.fee.amount', { amount: formatXaf(a.fee.amount_xaf, locale) }) }} · <strong>{{ ta(hk('ha.fee.', a.fee.status)) }}</strong></p>
          <p v-if="a.fee.receipt_number">{{ ta('ha.fee.receipt', { number: a.fee.receipt_number }) }}</p>
          <form v-if="a.fee.status === 'unpaid'" novalidate data-test="pay-form" @submit.prevent="payFee(a)">
            <fieldset class="field">
              <legend class="label">{{ ta('ha.fee.channel') }}</legend>
              <label v-for="c in MOBILE_CHANNELS" :key="c" class="check"><input v-model="pay.channel" type="radio" name="pay-channel" :value="c" /> {{ ta(hk('ha.fee.', c)) }}</label>
            </fieldset>
            <div class="field">
              <label for="pay-phone">{{ ta('ha.fee.phone') }}</label>
              <input id="pay-phone" v-model="pay.phone" type="tel" inputmode="tel" autocomplete="tel" aria-describedby="pay-hint" :aria-invalid="pay.tried && phoneBad" />
              <span id="pay-hint" class="hint">{{ ta('ha.fee.phoneHint') }}</span>
              <span v-if="pay.tried && phoneBad" class="error">{{ ta('ha.fee.phoneBad') }}</span>
            </div>
            <p class="muted">{{ ta('ha.fee.once') }}</p>
            <button type="submit" class="btn btn--primary btn--block" :disabled="act.busy.value">{{ ta('ha.fee.pay') }}</button>
          </form>
          <button v-else-if="a.fee.status === 'pending'" type="button" class="btn" :disabled="act.busy.value" @click="recheck(a)">{{ ta('ha.fee.refresh') }}</button>
        </template>
      </section>

      <section v-if="!closed(a)">
        <button v-if="withdrawing !== a.id" type="button" class="btn btn--quiet" @click="withdrawing = a.id">{{ ta('ha.withdraw.go') }}</button>
        <div v-if="withdrawing === a.id" class="field">
          <p class="muted">{{ ta('ha.withdraw.warn') }}</p>
          <label for="wd-reason">{{ ta('ha.withdraw.reason') }}</label>
          <textarea id="wd-reason" v-model="withdrawReason" />
          <button type="button" class="btn btn--ink" :disabled="act.busy.value || withdrawReason.trim() === ''" @click="withdraw(a)">{{ ta('ha.withdraw.confirm') }}</button>
          <button type="button" class="btn btn--quiet" @click="withdrawing = null">{{ ta('ha.back') }}</button>
        </div>
      </section>
    </section>
  </HigherFrame>
</template>

<style scoped>
.ap-app {
  margin-top: 1rem;
}
.ap-docs {
  list-style: none;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}
</style>
