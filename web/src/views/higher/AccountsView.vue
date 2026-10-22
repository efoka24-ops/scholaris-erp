<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { billing, type PaymentPlan, type Sponsor, type StudentAccount } from '@/api/higher';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import PersonPicker from './PersonPicker.vue';
import YearField from './YearField.vue';
import { useWorkflow, WF } from '@/lib/useWorkflow';
import { P } from './access';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Comptes de scolarité (EX-FIN-02 à 04) : liste, ouverture, échéancier négocié (demande, puis validation par un autre comptable).
// Design : Frais.html (journal, totaux) et InscriptionPieces.html (étape « Frais et paiement »).
const { t, locale } = useI18n();
const ctx = useContextStore();
const auth = useAuthStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const accounts = ref<StudentAccount[]>([]);
const sponsors = ref<Sponsor[]>([]);
const yearFilter = ref('');
const touched = ref(false);
const form = reactive({ person_id: null as string | null, academic_year_id: '', school_id: '', program_id: '', level_id: '', study_mode: '' as '' | 'day' | 'evening', plan_id: '', sponsor_id: '' });
const plan = reactive({ person_id: null as string | null, academic_year_id: '', school_id: '', program_id: '', reason: '', tried: false });
const planTerms = ref<Array<{ due_on: string; weight: number }>>([{ due_on: '', weight: 50 }, { due_on: '', weight: 50 }]);
const created = ref<PaymentPlan | null>(null);
const decideId = ref('');
const decided = ref<PaymentPlan | null>(null);

const canManage = computed(() => ctx.can(...P.billingManage) && !higher.readOnly);
// Validation d'un échéancier : la fonction désignée par le circuit de l'établissement (comptable sans réglage).
const workflow = useWorkflow();
const canApprove = computed(() => workflow.canAct(WF.scholarship, 'approval', P.billingManage) && !higher.readOnly);
const totals = computed(() => accounts.value.reduce((a, x) => ({ net: a.net + x.net_xaf, paid: a.paid + x.paid_xaf, balance: a.balance + x.balance_xaf }), { net: 0, paid: 0, balance: 0 }));
const formLevels = computed(() => higher.levels.filter((l) => l.program_id === form.program_id));

async function load(): Promise<void> {
  await higher.loadRefs('schools', 'programs', 'levels', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (yearFilter.value) q.academic_year_id = yearFilter.value;
  const res = await list.run(async () => ({ a: await billing.accounts(q), s: await billing.sponsors().catch(() => [] as Sponsor[]) }));
  if (!res) return;
  accounts.value = res.a;
  sponsors.value = res.s;
  const def = higher.scopedSchoolId ?? higher.schools[0]?.id ?? '';
  if (!form.school_id) form.school_id = def;
  if (!plan.school_id) plan.school_id = def;
}

async function open(): Promise<void> {
  touched.value = true;
  if (!form.person_id || !form.academic_year_id || !form.school_id) return;
  const res = await act.run(() =>
    billing.openAccount({
      person_id: form.person_id as string,
      academic_year_id: form.academic_year_id,
      school_id: form.school_id,
      program_id: form.program_id || null,
      level_id: form.level_id || null,
      study_mode: form.study_mode || null,
      plan_id: form.plan_id.trim() || null,
      sponsor_id: form.sponsor_id || null,
    }),
  );
  if (res === undefined) return;
  touched.value = false;
  await load();
}

async function requestPlan(): Promise<void> {
  plan.tried = true;
  if (!plan.person_id || !plan.academic_year_id || !plan.school_id || !plan.reason.trim() || planTerms.value.some((x) => !x.due_on || !(Number(x.weight) > 0))) return;
  const res = await act.run(() =>
    billing.requestPlan({ person_id: plan.person_id as string, academic_year_id: plan.academic_year_id, school_id: plan.school_id, program_id: plan.program_id || null, terms: planTerms.value.map((x) => ({ due_on: x.due_on, weight: Number(x.weight) })), reason: plan.reason.trim() }),
  );
  if (!res) return;
  created.value = res;
  Object.assign(plan, { reason: '', tried: false });
}

async function decide(approve: boolean): Promise<void> {
  const id = decideId.value.trim();
  if (!id) return;
  const res = await act.run(() => (approve ? billing.approvePlan(id) : billing.rejectPlan(id)));
  if (res) decided.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.fin.accountsTitle')" :lede="t('hi.fin.accountsLede')" section="finance" @school-change="load">
    <div class="hi-form"><YearField id="ac-year" v-model="yearFilter" @update:model-value="load" /></div>
    <ErrorNotice :error="list.error.value" />
    <div class="grid grid--kpi">
      <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.net') }}</h3><p class="kpi__value">{{ formatXaf(totals.net, locale) }}</p></article>
      <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.paid') }}</h3><p class="kpi__value">{{ formatXaf(totals.paid, locale) }}</p></article>
      <article class="kpi"><h3 class="eyebrow">{{ t('hi.fin.balance') }}</h3><p class="kpi__value">{{ formatXaf(totals.balance, locale) }}</p></article>
    </div>
    <div class="table-wrap hi-section">
      <table>
        <thead><tr><th scope="col">{{ t('hi.ins.student') }}</th><th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.fin.payer') }}</th><th scope="col" class="num">{{ t('hi.fin.net') }}</th><th scope="col" class="num">{{ t('hi.fin.paid') }}</th><th scope="col" class="num">{{ t('hi.fin.balance') }}</th><th scope="col">{{ t('hi.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="a in accounts" :key="a.id">
            <th scope="row"><router-link :to="{ name: 'higher-account', params: { id: a.id } }" class="hi-mono">{{ a.person_id }}</router-link></th>
            <td>{{ a.program_id ? higher.programName(a.program_id) : higher.schoolName(a.school_id) }}<template v-if="a.level_id"> · {{ higher.levelName(a.level_id) }}</template></td>
            <td>{{ higher.yearName(a.academic_year_id) }}</td><td>{{ a.payer_name ?? '—' }}</td>
            <td class="num">{{ formatXaf(a.net_xaf, locale) }}</td><td class="num">{{ formatXaf(a.paid_xaf, locale) }}</td><td class="num"><strong>{{ formatXaf(a.balance_xaf, locale) }}</strong></td>
            <td><span class="tag" :class="a.up_to_date ? 'tag--success' : 'tag--warning'">{{ a.up_to_date ? t('hi.fin.upToDate') : t('hi.fin.late') }}</span></td>
          </tr>
          <tr v-if="accounts.length === 0"><td colspan="8" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="act.error.value" />
    <div v-if="canManage || canApprove" class="hi-two hi-section">
      <form v-if="canManage" class="card hi-form" novalidate @submit.prevent="open">
        <h2 class="hi-wide">{{ t('hi.fin.openAccount') }}</h2>
        <PersonPicker id="oa-person" v-model="form.person_id" :label="t('hi.ins.student')" class="hi-wide" />
        <span v-if="touched && !form.person_id" class="error hi-wide">{{ t('hi.required') }}</span>
        <YearField id="oa-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
        <div class="field"><label for="oa-school">{{ t('hi.school.label') }}</label><select id="oa-school" v-model="form.school_id"><option v-for="s in higher.schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select></div>
        <div class="field"><label for="oa-prog">{{ t('hi.program') }}</label><select id="oa-prog" v-model="form.program_id" @change="form.level_id = ''"><option value="">—</option><option v-for="p in higher.programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select></div>
        <div class="field"><label for="oa-level">{{ t('hi.level') }}</label><select id="oa-level" v-model="form.level_id"><option value="">—</option><option v-for="l in formLevels" :key="l.id" :value="l.id">{{ l.code }}</option></select></div>
        <div class="field"><label for="oa-mode">{{ t('hi.ins.mode') }}</label><select id="oa-mode" v-model="form.study_mode"><option value="">—</option><option value="day">{{ t('hi.mode.day') }}</option><option value="evening">{{ t('hi.mode.evening') }}</option></select></div>
        <div class="field"><label for="oa-sponsor">{{ t('hi.fin.sponsorPick') }}</label><select id="oa-sponsor" v-model="form.sponsor_id"><option value="">{{ t('hi.fin.sponsorNone') }}</option><option v-for="s in sponsors" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
        <div class="field hi-wide"><label for="oa-plan">{{ t('hi.fin.planId') }}</label><input id="oa-plan" v-model="form.plan_id" type="text" /></div>
        <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.fin.openAccount') }}</button></div>
      </form>

      <div>
        <form v-if="canManage" class="card hi-form" novalidate data-test="plan-request" @submit.prevent="requestPlan">
          <h2 class="hi-wide">{{ t('hi.fin.planNew') }}</h2>
          <p class="muted hi-small hi-wide">{{ t('hi.fin.planHint') }}</p>
          <PersonPicker id="pl-person" v-model="plan.person_id" :label="t('hi.ins.student')" class="hi-wide" />
          <YearField id="pl-year" v-model="plan.academic_year_id" :invalid="plan.tried && !plan.academic_year_id" />
          <div class="field"><label for="pl-school">{{ t('hi.school.label') }}</label><select id="pl-school" v-model="plan.school_id"><option v-for="s in higher.schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select></div>
          <fieldset class="field hi-wide">
            <legend class="label">{{ t('hi.fin.installments') }}</legend>
            <div v-for="(x, n) in planTerms" :key="n" class="row">
              <label :for="`pt-date-${n}`" class="sr-only">{{ t('hi.date') }} {{ n + 1 }}</label><input :id="`pt-date-${n}`" v-model="x.due_on" type="date" />
              <label :for="`pt-w-${n}`" class="sr-only">{{ t('hi.fin.weight') }} {{ n + 1 }}</label><input :id="`pt-w-${n}`" v-model.number="x.weight" type="number" min="1" class="hi-weight" />
              <button type="button" class="hi-linkbtn" @click="planTerms.splice(n, 1)">{{ t('hi.fin.removeFee') }}</button>
            </div>
            <button type="button" class="btn btn--quiet" @click="planTerms.push({ due_on: '', weight: 10 })">{{ t('hi.fin.addTerm') }}</button>
          </fieldset>
          <div class="field hi-wide"><label for="pl-reason">{{ t('hi.reason') }}</label><input id="pl-reason" v-model="plan.reason" type="text" :aria-invalid="plan.tried && !plan.reason.trim()" /></div>
          <div class="field"><button type="submit" class="btn" :disabled="act.busy.value">{{ t('hi.fin.planNew') }}</button></div>
          <p v-if="created" class="notice notice--success hi-wide" role="status">{{ t('hi.fin.planCreated', { id: created.id }) }}</p>
        </form>

        <section v-if="canApprove" class="card hi-section" data-test="plan-decide">
          <h2>{{ t('hi.fin.planDecide') }}</h2>
          <p class="muted hi-small">{{ t('hi.fin.planDecideHint') }}</p>
          <div class="field"><label for="pd-id">{{ t('hi.fin.planId2') }}</label><input id="pd-id" v-model="decideId" type="text" /></div>
          <div class="hi-actions">
            <button type="button" class="btn btn--primary" :disabled="!decideId.trim() || act.busy.value" @click="decide(true)">{{ t('hi.fin.approve') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="!decideId.trim() || act.busy.value" @click="decide(false)">{{ t('hi.fin.reject') }}</button>
          </div>
          <p v-if="decided" class="notice hi-section" role="status">{{ decided.id }} · {{ t(`hi.fin.sch.st.${decided.status}` as 'hi.fin.sch.st.approved') }}<template v-if="decided.requested_by === auth.me?.id"> · {{ t('hi.fin.ownRequest') }}</template></p>
        </section>
      </div>
    </div>
  </HigherFrame>
</template>

<style scoped>
.hi-weight {
  width: 90px;
}
</style>
