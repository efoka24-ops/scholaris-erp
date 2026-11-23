<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { enrolments, STATUS_TARGETS, STUDY_MODES, type Enrolment, type EnrolmentEvent, type EnrolmentStatus, type IssuedDoc, type LearningContract, type StudyMode } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Fiche d'inscription (EX-INS-03 à 08) : validation (frais payés ou réserve financière décidée par le directeur, RG-INS-2),
// statuts, changement de filière/groupe/mode (frais à recalculer), contrat pédagogique (UE optionnelles « N parmi M », dettes,
// capitalisées), carte d'étudiant DOC-05 et fiche DOC-04, journal. Design : InscriptionPieces.html (étapes et récapitulatif).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const enrolment = ref<Enrolment | null>(null);
const contract = ref<LearningContract | null>(null);
const events = ref<EnrolmentEvent[]>([]);
const issued = ref<IssuedDoc | null>(null);
const recalc = ref(false);
const chosen = ref<string[]>([]);
const hold = reactive({ open: false, reason: '', tried: false });
const statusForm = reactive({ status: 'suspended' as EnrolmentStatus, reason: '', tried: false });
const change = reactive({ level_id: '', group_id: '', study_mode: '' as StudyMode | '', reason: '', tried: false });

const canEnrol = computed(() => ctx.can(...P.enrol) && !higher.readOnly);
const canValidate = computed(() => ctx.can(...P.enrolmentValidate) && !higher.readOnly);
const validated = computed(() => !!enrolment.value && enrolment.value.status !== 'pending');
const changeGroups = computed(() => higher.groups.filter((g) => g.level_id === (change.level_id || enrolment.value?.level_id)));
const groupsOfChoice = computed(() => {
  const map = new Map<string, { count: number; options: LearningContract['choices'] }>();
  for (const c of contract.value?.choices ?? []) {
    const entry = map.get(c.choice_group) ?? { count: c.choice_count ?? 1, options: [] };
    entry.options.push(c);
    map.set(c.choice_group, entry);
  }
  return [...map.entries()].map(([group, v]) => ({ group, ...v, picked: v.options.filter((o) => chosen.value.includes(o.curriculum_unit_id)).length }));
});
const choicesBad = computed(() => groupsOfChoice.value.filter((g) => g.picked !== g.count));

async function fetchAll(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'groups', 'years');
  const res = await load.run(async () => {
    const e = await enrolments.show(props.id);
    const [c, ev] = await Promise.all([enrolments.contract(props.id).catch(() => null), enrolments.events(props.id).catch(() => [] as EnrolmentEvent[])]);
    return { e, c, ev };
  });
  if (!res) return;
  enrolment.value = res.e;
  contract.value = res.c;
  events.value = res.ev;
  chosen.value = (res.c?.items ?? []).filter((i) => i.origin === 'optional' && i.curriculum_unit_id).map((i) => i.curriculum_unit_id as string);
}

async function refresh(e?: Enrolment): Promise<void> {
  if (e) enrolment.value = e;
  events.value = await enrolments.events(props.id).catch(() => events.value);
  contract.value = await enrolments.contract(props.id).catch(() => contract.value);
}

async function validate(): Promise<void> {
  const res = await act.run(() => enrolments.validate(props.id));
  if (res) await refresh(res);
}
async function validateHold(): Promise<void> {
  hold.tried = true;
  if (!hold.reason.trim()) return;
  const res = await act.run(() => enrolments.validate(props.id, { financial_hold: true, reason: hold.reason.trim() }));
  if (res) {
    Object.assign(hold, { open: false, reason: '', tried: false });
    await refresh(res);
  }
}
async function setStatus(): Promise<void> {
  statusForm.tried = true;
  if (!statusForm.reason.trim()) return;
  const res = await act.run(() => enrolments.setStatus(props.id, { status: statusForm.status, reason: statusForm.reason.trim() }));
  if (res) {
    Object.assign(statusForm, { reason: '', tried: false });
    await refresh(res);
  }
}
async function applyChange(): Promise<void> {
  change.tried = true;
  if (!change.reason.trim() || (!change.level_id && !change.group_id && !change.study_mode)) return;
  const res = await act.run(() =>
    enrolments.change(props.id, {
      reason: change.reason.trim(),
      ...(change.level_id ? { level_id: change.level_id } : {}),
      ...(change.group_id ? { group_id: change.group_id } : {}),
      ...(change.study_mode ? { study_mode: change.study_mode } : {}),
    }),
  );
  if (res) {
    recalc.value = !!res.fees_recalculation_required;
    Object.assign(change, { level_id: '', group_id: '', study_mode: '', reason: '', tried: false });
    await refresh(res);
  }
}
async function saveChoices(finalize: boolean): Promise<void> {
  if (choicesBad.value.length) return;
  const res = await act.run(() => enrolments.chooseUnits(props.id, { optional_unit_ids: chosen.value, ...(finalize ? { finalize: true } : {}) }));
  if (res) contract.value = res;
}
async function issue(kind: 'card' | 'form'): Promise<void> {
  const res = await act.run(() => (kind === 'card' ? enrolments.studentCard(props.id) : enrolments.enrolmentForm(props.id)));
  if (res) {
    issued.value = res;
    await refresh();
  }
}

onMounted(async () => {
  await higher.ensure();
  await fetchAll();
});
</script>

<template>
  <HigherFrame :title="enrolment ? t('hi.ins.sheet', { matricule: enrolment.matricule ?? t('hi.ins.matriculePending') }) : t('hi.ins.title')" section="enrolment" :selector="false">
    <template #actions>
      <router-link :to="{ name: 'higher-enrolments' }" class="btn btn--quiet">← {{ t('hi.backToList') }}</router-link>
      <router-link v-if="enrolment" :to="{ name: 'higher-student', params: { personId: enrolment.person_id } }" class="btn">{{ t('hi.ins.studentFile') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <template v-if="enrolment">
      <div class="row">
        <span class="tag" :class="`tag--${toneOf(enrolment.status) ?? 'neutral'}`" data-test="status">{{ t(k('hi.ins.st.', enrolment.status)) }}</span>
        <span>{{ higher.programName(enrolment.program_id) }} · {{ higher.levelName(enrolment.level_id) }}<template v-if="enrolment.group_id"> · {{ higher.groupName(enrolment.group_id) }}</template> · {{ higher.yearName(enrolment.academic_year_id) }}</span>
        <span class="muted">{{ t(k('hi.mode.', enrolment.study_mode)) }} · {{ t('hi.ins.attempt', { n: enrolment.attempt_number }) }}</span>
      </div>
      <p v-if="enrolment.financial_hold" class="notice notice--warning" data-test="hold">{{ t('hi.ins.holdActive', { reason: enrolment.financial_hold_reason ?? '' }) }}</p>
      <p v-if="enrolment.refund_percent !== null" class="notice">{{ t('hi.ins.refund', { percent: enrolment.refund_percent, amount: formatXaf(enrolment.refund_xaf ?? 0, locale) }) }}</p>
      <p v-if="recalc" class="notice notice--warning" role="status" data-test="recalc">{{ t('hi.ins.recalc') }}</p>
      <ErrorNotice :error="act.error.value" />

      <div class="hi-two hi-section">
        <div>
          <section v-if="enrolment.status === 'pending' || enrolment.status === 'financial_hold'" class="card card--strong" data-test="validate">
            <h2>{{ t('hi.ins.validate') }}</h2>
            <p class="muted hi-small">{{ t('hi.ins.validateHint') }}</p>
            <div class="hi-actions">
              <button v-if="canEnrol || canValidate" type="button" class="btn btn--primary" :disabled="act.busy.value" @click="validate">{{ enrolment.status === 'financial_hold' ? t('hi.ins.liftHold') : t('hi.ins.validate') }}</button>
              <button v-if="canValidate && enrolment.status === 'pending'" type="button" class="btn btn--quiet" @click="hold.open = !hold.open">{{ t('hi.ins.hold') }}</button>
            </div>
            <div v-if="hold.open" class="hi-section">
              <div class="field"><label for="hold-reason">{{ t('hi.ins.holdReason') }}</label><textarea id="hold-reason" v-model="hold.reason" :aria-invalid="hold.tried && !hold.reason.trim()" /><span v-if="hold.tried && !hold.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
              <button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="validateHold">{{ t('hi.confirm') }}</button>
            </div>
          </section>

          <section class="card hi-section" aria-labelledby="docs-title">
            <h2 id="docs-title">{{ t('hi.ins.card') }} · {{ t('hi.ins.form') }}</h2>
            <p v-if="!validated" class="muted">{{ t('hi.ins.docsNeedValidation') }}</p>
            <div class="hi-actions">
              <button type="button" class="btn" :disabled="!validated || !canEnrol || act.busy.value" @click="issue('card')">{{ t('hi.ins.issue') }} : {{ t('hi.ins.card') }}</button>
              <button type="button" class="btn" :disabled="!validated || !canEnrol || act.busy.value" @click="issue('form')">{{ t('hi.ins.issue') }} : {{ t('hi.ins.form') }}</button>
            </div>
            <p v-if="issued" class="notice notice--success hi-section" role="status">{{ t('hi.ins.issued', { number: issued.number }) }} · <router-link :to="{ name: 'verify', params: { token: issued.verification_token } }">{{ t('hi.doc.verifyLink') }}</router-link></p>
          </section>

          <section v-if="canEnrol && validated" class="card hi-section">
            <h2>{{ t('hi.ins.changeStatus') }}</h2>
            <div class="hi-form">
              <div class="field"><label for="st-new">{{ t('hi.ins.newStatus') }}</label><select id="st-new" v-model="statusForm.status"><option v-for="s in STATUS_TARGETS" :key="s" :value="s">{{ t(k('hi.ins.st.', s)) }}</option></select></div>
              <div class="field"><label for="st-reason">{{ t('hi.reason') }}</label><input id="st-reason" v-model="statusForm.reason" type="text" :aria-invalid="statusForm.tried && !statusForm.reason.trim()" /><span v-if="statusForm.tried && !statusForm.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
            </div>
            <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="setStatus">{{ t('hi.confirm') }}</button>
          </section>

          <section v-if="canEnrol && ['pending', 'enrolled', 'financial_hold', 'suspended'].includes(enrolment.status)" class="card hi-section" data-test="change">
            <h2>{{ t('hi.ins.change') }}</h2>
            <p class="notice notice--warning">{{ t('hi.ins.changeWarn') }}</p>
            <div class="hi-form">
              <div class="field"><label for="ch-level">{{ t('hi.ins.newLevel') }}</label><select id="ch-level" v-model="change.level_id"><option value="">{{ t('hi.ins.unchanged') }}</option><option v-for="l in higher.levels" :key="l.id" :value="l.id">{{ l.code }} · {{ higher.programName(l.program_id) }}</option></select></div>
              <div class="field"><label for="ch-group">{{ t('hi.group') }}</label><select id="ch-group" v-model="change.group_id"><option value="">{{ t('hi.ins.unchanged') }}</option><option v-for="g in changeGroups" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
              <div class="field"><label for="ch-mode">{{ t('hi.ins.mode') }}</label><select id="ch-mode" v-model="change.study_mode"><option value="">{{ t('hi.ins.unchanged') }}</option><option v-for="m in STUDY_MODES" :key="m" :value="m">{{ t(k('hi.mode.', m)) }}</option></select></div>
              <div class="field hi-wide"><label for="ch-reason">{{ t('hi.reason') }}</label><input id="ch-reason" v-model="change.reason" type="text" :aria-invalid="change.tried && !change.reason.trim()" /><span v-if="change.tried && !change.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
            </div>
            <button type="button" class="btn btn--quiet" :disabled="act.busy.value" @click="applyChange">{{ t('hi.confirm') }}</button>
          </section>
        </div>

        <div>
          <section v-if="contract" class="card" aria-labelledby="ct-title" data-test="contract">
            <div class="row row--between">
              <h2 id="ct-title">{{ t('hi.ins.contract') }}</h2>
              <span class="tag" :class="contract.status === 'final' ? 'tag--success' : 'tag--neutral'">{{ contract.status === 'final' ? t('hi.ins.contractFinal', { date: formatDate(contract.finalized_at, locale) }) : t('hi.ins.contractDraft') }}</span>
            </div>
            <p><strong>{{ t('hi.ins.creditsToTake', { credits: contract.credits_to_take }) }}</strong></p>
            <div class="table-wrap">
              <table>
                <thead><tr><th scope="col">{{ t('hi.cur.unit') }}</th><th scope="col">{{ t('hi.label') }}</th><th scope="col" class="num">{{ t('hi.cur.credits') }}</th><th scope="col">{{ t('hi.app.kind') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(i, n) in contract.items" :key="n">
                    <th scope="row">{{ i.unit_code }}</th><td>{{ i.unit_title }}</td><td class="num">{{ i.credits }}</td>
                    <td><span class="tag" :class="i.origin === 'debt' ? 'tag--warning' : i.origin === 'capitalized' ? 'tag--success' : 'tag--neutral'">{{ t(k('hi.ins.origin.', i.origin)) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <template v-if="groupsOfChoice.length && contract.status !== 'final'">
              <fieldset v-for="g in groupsOfChoice" :key="g.group" class="field hi-section">
                <legend class="label">{{ t('hi.ins.choices', { count: g.count, total: g.options.length, group: g.group }) }}</legend>
                <label v-for="o in g.options" :key="o.curriculum_unit_id" class="check"><input v-model="chosen" type="checkbox" :value="o.curriculum_unit_id" :disabled="!canEnrol" /> {{ o.code }} · {{ o.title }} ({{ o.credits }})</label>
                <span v-if="g.picked !== g.count" class="error">{{ t('hi.ins.choicesBad', { group: g.group, count: g.count }) }}</span>
              </fieldset>
            </template>
            <div v-if="canEnrol && contract.status !== 'final'" class="hi-actions hi-section">
              <button v-if="groupsOfChoice.length" type="button" class="btn btn--quiet" :disabled="act.busy.value || choicesBad.length > 0" @click="saveChoices(false)">{{ t('hi.ins.saveChoices') }}</button>
              <button type="button" class="btn btn--primary" :disabled="act.busy.value || choicesBad.length > 0" @click="saveChoices(true)">{{ t('hi.ins.finalize') }}</button>
            </div>
          </section>

          <section class="card hi-section" aria-labelledby="ev-title">
            <h2 id="ev-title">{{ t('hi.ins.events') }}</h2>
            <ul class="hi-list hi-small">
              <li v-for="(e, n) in events" :key="n"><span class="hi-grow"><strong>{{ e.event_type }}</strong><template v-if="e.from_value || e.to_value"> · {{ e.from_value ?? '—' }} → {{ e.to_value ?? '—' }}</template><template v-if="e.reason"> · {{ e.reason }}</template></span><span class="muted">{{ formatDateTime(e.occurred_at, locale) }}</span></li>
              <li v-if="events.length === 0" class="muted">{{ t('hi.none') }}</li>
            </ul>
          </section>
        </div>
      </div>
    </template>
  </HigherFrame>
</template>
