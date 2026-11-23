<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CONDITIONS, FOLLOWUP_KINDS, PARTIES, internships, type Followup, type FollowupKind, type Internship, type Party, type Readiness } from '@/api/higher-internships';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import InternshipsNav from './InternshipsNav.vue';
import { S } from './perms';
import { ik, useInternshipsI18n } from './i18n';

// Une convention (EX-STG-02 à 04) : émission DOC-20, signatures, sujet, attestation, mémoire, suivi (DOC-23), conditions RG-STG-1.
const props = defineProps<{ id: string }>();
const { ts, locale } = useInternshipsI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const item = ref<Internship | null>(null);
const readiness = ref<Readiness | null>(null);
const followups = ref<Followup[]>([]);
const signDates = reactive<Record<Party, string>>({ student: '', company: '', school: '' });
const completion = reactive({ received_on: '', reference: '' });
const thesis = reactive({ title: '', file_ref: '' });
const followup = reactive<{ kind: FollowupKind; occurred_on: string; summary: string; score: string }>({ kind: 'visit', occurred_on: '', summary: '', score: '' });
const reason = ref('');

const writable = computed(() => !higher.readOnly);
const canManage = computed(() => ctx.can(...S.manage) && writable.value);
const canSign = (party: Party): boolean => writable.value && (party === 'school' ? ctx.can(...S.signSchool) : ctx.can(...S.manage));
const running = computed(() => item.value?.status === 'signed' || item.value?.status === 'completed');

async function refresh(): Promise<void> {
  const res = await load.run(() => Promise.all([internships.get(props.id), internships.readiness(props.id), internships.followups(props.id)]));
  if (!res) return;
  [item.value, readiness.value, followups.value] = res;
}

async function apply(fn: () => Promise<unknown>): Promise<void> {
  if ((await act.run(fn)) !== undefined) await refresh();
}

const issue = () => apply(() => internships.issueConvention(props.id));
const sign = (party: Party) => (signDates[party] ? apply(() => internships.sign(props.id, party, signDates[party])) : Promise.resolve());
const validateSubject = () => apply(() => internships.validateSubject(props.id));
const complete = () => (completion.received_on ? apply(() => internships.complete(props.id, completion.received_on, completion.reference || undefined)) : Promise.resolve());
const deposit = () => (thesis.title && thesis.file_ref ? apply(() => internships.depositThesis(props.id, thesis.title, thesis.file_ref)) : Promise.resolve());
const cancel = () => (reason.value.trim() ? apply(() => internships.cancel(props.id, reason.value.trim())) : Promise.resolve());

async function addFollowup(): Promise<void> {
  if (!followup.occurred_on || !followup.summary.trim()) return;
  const score = followup.score.trim() === '' ? undefined : Number(followup.score.replace(',', '.'));
  await apply(() => internships.addFollowup(props.id, { kind: followup.kind, occurred_on: followup.occurred_on, summary: followup.summary.trim(), ...(score === undefined ? {} : { score }) }));
  followup.summary = '';
  followup.score = '';
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <HigherFrame :title="ts('hs.d.title')" :lede="item?.subject ?? ''" section="portal" :selector="false">
    <InternshipsNav />
    <ErrorNotice :error="load.error.value" />
    <ErrorNotice :error="act.error.value" />
    <template v-if="item">
      <section class="card">
        <p><span class="tag" :class="`tag--${toneOf(item.status) ?? 'neutral'}`" data-test="convention-status">{{ ts(ik('hs.state.', item.status)) }}</span></p>
        <p class="hi-small">{{ ts('hs.c.period', { from: formatDate(item.starts_on, locale), to: formatDate(item.ends_on, locale) }) }} · {{ ts('hs.c.tutor') }} : {{ item.company_tutor_name }} · {{ ts('hs.c.supervisor') }} : {{ item.supervisor_name }}</p>
        <div class="hi-actions">
          <button v-if="canManage && item.status === 'draft'" type="button" class="btn btn--primary" data-test="issue-convention" @click="issue">{{ ts('hs.d.issue') }}</button>
          <button v-if="canManage && !item.subject_validated_at && item.status !== 'cancelled'" type="button" class="btn btn--quiet" @click="validateSubject">{{ ts('hs.d.validateSubject') }}</button>
          <span v-if="item.subject_validated_at" class="tag tag--success">{{ ts('hs.d.subjectValidated') }}</span>
        </div>
      </section>

      <section class="card">
        <h2>{{ ts('hs.d.signatures') }}</h2>
        <ul class="hi-list">
          <li v-for="p in PARTIES" :key="p" :data-test="`signature-${p}`">
            <strong class="hi-grow">{{ ts(ik('hs.party.', p)) }}</strong>
            <span v-if="item.signatures[p]" class="tag tag--success">{{ ts('hs.d.signedOn', { date: formatDate(item.signatures[p], locale) }) }}</span>
            <template v-else-if="item.status === 'pending_signatures' && canSign(p)">
              <input v-model="signDates[p]" type="date" :aria-label="ts(ik('hs.party.', p))">
              <button type="button" class="btn btn--quiet" :data-test="`sign-${p}`" @click="sign(p)">{{ ts('hs.d.sign') }}</button>
            </template>
          </li>
        </ul>
      </section>

      <section v-if="readiness" class="card" data-test="readiness">
        <h2>{{ ts('hs.d.readiness') }}</h2>
        <ul class="hi-list">
          <li v-for="c in CONDITIONS" :key="c"><span class="hi-grow">{{ ts(ik('hs.cond.', c)) }}</span><span class="tag" :class="readiness.conditions[c] ? 'tag--success' : 'tag--warning'">{{ readiness.conditions[c] ? '✓' : '—' }}</span></li>
        </ul>
      </section>

      <section v-if="running && canManage" class="card hi-form">
        <template v-if="item.status === 'signed'">
          <div class="field"><label for="cp-on">{{ ts('hs.d.completion') }}</label><input id="cp-on" v-model="completion.received_on" type="date"></div>
          <button type="button" class="btn btn--quiet" data-test="complete" @click="complete">{{ ts('hs.d.complete') }}</button>
        </template>
        <p v-if="item.thesis_deposited_at" class="hi-small">{{ ts('hs.d.deposited', { date: formatDate(item.thesis_deposited_at, locale) }) }} · {{ item.thesis_title }}</p>
        <template v-else>
          <div class="field"><label for="th-t">{{ ts('hs.d.thesisTitle') }}</label><input id="th-t" v-model="thesis.title" maxlength="255"></div>
          <div class="field"><label for="th-r">{{ ts('hs.d.thesisRef') }}</label><input id="th-r" v-model="thesis.file_ref" maxlength="255"></div>
          <button type="button" class="btn btn--quiet" data-test="deposit" @click="deposit">{{ ts('hs.d.deposit') }}</button>
        </template>
      </section>

      <section class="card">
        <h2>{{ ts('hs.d.followups') }}</h2>
        <ul class="hi-list">
          <li v-for="f in followups" :key="f.id" data-test="followup"><span class="hi-grow"><strong>{{ ts(ik('hs.kind.', f.kind)) }}</strong> · {{ formatDate(f.occurred_on, locale) }} — {{ f.summary }}</span><span v-if="f.score !== null">{{ f.score }}/20</span></li>
          <li v-if="followups.length === 0" class="muted">{{ ts('hs.none') }}</li>
        </ul>
        <form v-if="running && canManage" class="hi-form" @submit.prevent="addFollowup">
          <div class="field"><label for="fu-k">{{ ts('hs.d.kind') }}</label><select id="fu-k" v-model="followup.kind"><option v-for="k in FOLLOWUP_KINDS" :key="k" :value="k">{{ ts(ik('hs.kind.', k)) }}</option></select></div>
          <div class="field"><label for="fu-d">{{ ts('hs.d.date') }}</label><input id="fu-d" v-model="followup.occurred_on" type="date"></div>
          <div class="field"><label for="fu-s">{{ ts('hs.d.summary') }}</label><textarea id="fu-s" v-model="followup.summary" maxlength="4000" /></div>
          <div v-if="followup.kind === 'evaluation'" class="field"><label for="fu-n">{{ ts('hs.d.score') }}</label><input id="fu-n" v-model="followup.score" inputmode="decimal"></div>
          <button type="submit" class="btn btn--quiet" data-test="add-followup">{{ ts('hs.d.addFollowup') }}</button>
        </form>
      </section>

      <section v-if="canManage && ['draft', 'pending_signatures', 'signed'].includes(item.status)" class="card hi-form">
        <div class="field"><label for="cc-r">{{ ts('hs.reason') }}</label><input id="cc-r" v-model="reason" maxlength="500"></div>
        <button type="button" class="btn btn--quiet" @click="cancel">{{ ts('hs.d.cancelConvention') }}</button>
      </section>
    </template>
  </HigherFrame>
</template>
