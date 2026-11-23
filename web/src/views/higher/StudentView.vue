<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { enrolments, PROGRESSION_DECISIONS, type ProgressionDecision, type ProgressionRecord, type StudentRecord } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, splitCodes, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Dossier étudiant (EX-INS-06) : matricule unique, parcours multiples (RG-ORG-6), consentement de partage avec le garant
// (RG-INS-7), dérogation à la limite d'inscriptions (RG-INS-5), décisions de passage. Design : Inscription.html (consentements).
const props = defineProps<{ personId: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const student = ref<StudentRecord | null>(null);
const decisions = ref<ProgressionRecord[]>([]);
const derogation = reactive({ level_id: '', academic_year_id: '', reason: '', tried: false, done: false });
const dec = reactive({ level_id: '', academic_year_id: '', decision: 'admitted' as ProgressionDecision, acquired: '', missing: '', tried: false });

const canEnrol = computed(() => ctx.can(...P.enrol) && !higher.readOnly);
const canDerogate = computed(() => ctx.can(...P.derogate) && !higher.readOnly);
const canDecide = computed(() => ctx.can(...P.enrolmentValidate) && !higher.readOnly);

async function fetchAll(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'groups', 'years');
  const res = await load.run(async () => ({ s: await enrolments.student(props.personId), d: await enrolments.decisions({ 'filter[person_id]': props.personId }).catch(() => [] as ProgressionRecord[]) }));
  if (!res) return;
  student.value = res.s;
  decisions.value = res.d;
}

async function setConsent(share: boolean): Promise<void> {
  const res = await act.run(() => enrolments.consent(props.personId, share));
  if (res) student.value = res;
}

async function derogate(): Promise<void> {
  derogation.tried = true;
  if (!derogation.level_id || !derogation.academic_year_id || !derogation.reason.trim()) return;
  const res = await act.run(() => enrolments.derogate({ person_id: props.personId, level_id: derogation.level_id, academic_year_id: derogation.academic_year_id, reason: derogation.reason.trim() }));
  if (res !== undefined) Object.assign(derogation, { reason: '', tried: false, done: true });
}

async function record(): Promise<void> {
  dec.tried = true;
  if (!dec.level_id || !dec.academic_year_id) return;
  const res = await act.run(() =>
    enrolments.recordDecision({ person_id: props.personId, level_id: dec.level_id, academic_year_id: dec.academic_year_id, decision: dec.decision, acquired_units: splitCodes(dec.acquired), missing_units: splitCodes(dec.missing) }),
  );
  if (!res) return;
  Object.assign(dec, { acquired: '', missing: '', tried: false });
  decisions.value = await enrolments.decisions({ 'filter[person_id]': props.personId }).catch(() => decisions.value);
}

onMounted(async () => {
  await higher.ensure();
  await fetchAll();
});
</script>

<template>
  <HigherFrame :title="student?.full_name ? `${t('hi.stu.title')} · ${student.full_name}` : t('hi.stu.title')" section="enrolment" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <template v-if="student">
      <dl class="hi-kv">
        <dt>{{ t('hi.ins.matricule') }}</dt><dd><strong>{{ student.matricule ?? t('hi.ins.matriculePending') }}</strong><span v-if="student.matricule_assigned_at" class="muted"> · {{ formatDate(student.matricule_assigned_at, locale) }}</span></dd>
        <dt>{{ t('hi.stu.guarantor') }}</dt><dd class="hi-mono">{{ student.guarantor_person_id ?? '—' }}</dd>
      </dl>
      <ErrorNotice :error="act.error.value" />

      <section class="hi-section" aria-labelledby="tracks-title">
        <h2 id="tracks-title">{{ t('hi.stu.tracks') }}</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.level') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col" class="num">#</th><th scope="col">{{ t('hi.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="tr in student.tracks" :key="tr.enrolment_id">
                <th scope="row"><router-link :to="{ name: 'higher-enrolment', params: { id: tr.enrolment_id } }">{{ higher.yearName(tr.academic_year_id) }}</router-link></th>
                <td>{{ higher.programName(tr.program_id) }}</td><td>{{ higher.levelName(tr.level_id) }}</td><td>{{ tr.group_id ? higher.groupName(tr.group_id) : '—' }}</td><td class="num">{{ tr.attempt_number }}</td>
                <td><span class="tag" :class="`tag--${toneOf(tr.status) ?? 'neutral'}`">{{ t(k('hi.ins.st.', tr.status)) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="hi-two hi-section">
        <section class="card card--strong" aria-labelledby="consent-title" data-test="consent">
          <h2 id="consent-title">{{ t('hi.stu.consent') }}</h2>
          <p class="muted hi-small">{{ t('hi.stu.consentText') }}</p>
          <label class="check"><input type="checkbox" :checked="student.share_with_guarantor" :disabled="!canEnrol || act.busy.value" @change="setConsent(($event.target as HTMLInputElement).checked)" /> {{ t('hi.stu.consentOn') }}</label>
          <p v-if="student.consent_recorded_at" class="muted hi-small">{{ t('hi.stu.consentAt', { date: formatDateTime(student.consent_recorded_at, locale) }) }}</p>
        </section>

        <section v-if="canDerogate" class="card" aria-labelledby="der-title">
          <h2 id="der-title">{{ t('hi.stu.derogation') }}</h2>
          <p class="muted hi-small">{{ t('hi.stu.derogationText') }}</p>
          <div class="field"><label for="der-level">{{ t('hi.level') }}</label><select id="der-level" v-model="derogation.level_id" :aria-invalid="derogation.tried && !derogation.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in higher.levels" :key="l.id" :value="l.id">{{ l.code }} · {{ higher.programName(l.program_id) }}</option></select></div>
          <YearField id="der-year" v-model="derogation.academic_year_id" :invalid="derogation.tried && !derogation.academic_year_id" />
          <div class="field"><label for="der-reason">{{ t('hi.reason') }}</label><textarea id="der-reason" v-model="derogation.reason" :aria-invalid="derogation.tried && !derogation.reason.trim()" /><span v-if="derogation.tried && !derogation.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
          <button type="button" class="btn btn--primary" :disabled="act.busy.value" @click="derogate">{{ t('hi.stu.grant') }}</button>
          <p v-if="derogation.done" class="notice notice--success hi-section" role="status">{{ t('hi.stu.granted') }}</p>
        </section>
      </div>

      <section class="card hi-section" aria-labelledby="dec-title">
        <h2 id="dec-title">{{ t('hi.stu.decisions') }}</h2>
        <p v-if="decisions.length === 0" class="muted">{{ t('hi.stu.noDecision') }}</p>
        <ul v-else class="hi-list">
          <li v-for="d in decisions" :key="d.id">
            <span class="tag" :class="d.decision === 'admitted' ? 'tag--success' : d.decision === 'excluded' ? 'tag--warning' : 'tag--neutral'">{{ t(k('hi.stu.dec.', d.decision)) }}</span>
            <span class="hi-grow">{{ higher.levelName(d.level_id) }} · {{ higher.yearName(d.academic_year_id) }}<template v-if="d.missing_units?.length"> · {{ d.missing_units.join(', ') }}</template></span>
            <span class="muted hi-small">{{ formatDate(d.recorded_at, locale) }}</span>
          </li>
        </ul>
        <form v-if="canDecide" class="hi-form hi-section" novalidate @submit.prevent="record">
          <h3 class="hi-wide">{{ t('hi.stu.decisionNew') }}</h3>
          <div class="field"><label for="pd-level">{{ t('hi.level') }}</label><select id="pd-level" v-model="dec.level_id" :aria-invalid="dec.tried && !dec.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in higher.levels" :key="l.id" :value="l.id">{{ l.code }} · {{ higher.programName(l.program_id) }}</option></select></div>
          <YearField id="pd-year" v-model="dec.academic_year_id" :invalid="dec.tried && !dec.academic_year_id" />
          <div class="field"><label for="pd-dec">{{ t('hi.app.decision') }}</label><select id="pd-dec" v-model="dec.decision"><option v-for="d in PROGRESSION_DECISIONS" :key="d" :value="d">{{ t(k('hi.stu.dec.', d)) }}</option></select></div>
          <div class="field"><label for="pd-acq">{{ t('hi.stu.acquired') }}</label><input id="pd-acq" v-model="dec.acquired" type="text" /></div>
          <div class="field"><label for="pd-miss">{{ t('hi.stu.missing') }}</label><input id="pd-miss" v-model="dec.missing" type="text" /></div>
          <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.save') }}</button></div>
        </form>
      </section>
    </template>
  </HigherFrame>
</template>
