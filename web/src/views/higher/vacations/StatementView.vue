<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatHetd, formatHours, vacations, type IssuedDocument, type PayInput, type Statement } from '@/api/higher-vacations';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { VP } from './access';
import PayForm from './PayForm.vue';
import StatementSummary from './StatementSummary.vue';
import { useHv, vk } from './i18n';

// État de vacation détaillé : séances (payables ou refusées, RG-ENS-1/4), visa de l'école, approbation de la direction, rejet,
// paiement (NOT-14 envoyée par le serveur), DOC-25 et DOC-26. Les boutons suivent le statut et les permissions ; le serveur tranche.
const props = defineProps<{ id: string }>();
const { t, locale } = useHv();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const statement = ref<Statement | null>(null);
const reason = ref('');
const issued = ref<IssuedDocument | null>(null);
const { name: personName } = useHigherNames(() => [statement.value?.person_id]);
const writable = computed(() => !higher.readOnly);
const status = computed(() => statement.value?.status);
const can = (perms: readonly string[]): boolean => writable.value && ctx.can(...perms);

async function refresh(): Promise<void> {
  const res = await load.run(() => vacations.statement(props.id));
  if (res) statement.value = res;
}

async function step(fn: () => Promise<Statement>): Promise<void> {
  const res = await act.run(fn);
  if (res) await refresh();
}

const validateSchool = (): Promise<void> => step(() => vacations.validateSchool(props.id));
const approve = (): Promise<void> => step(() => vacations.approve(props.id));
const reject = (): Promise<void> => step(() => vacations.reject(props.id, reason.value.trim()));
const pay = (input: PayInput): Promise<void> => step(() => vacations.pay(props.id, input));

async function issue(kind: 'statement' | 'payslip'): Promise<void> {
  const res = await act.run(() => (kind === 'statement' ? vacations.statementDocument(props.id) : vacations.payslip(props.id)));
  if (res) issued.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <HigherFrame :title="t('hv.detail.title', { month: statement?.period_month ?? '' })" :lede="statement ? personName(statement.person_id) : ''" section="curriculum">
    <template #actions><router-link v-if="ctx.can(...VP.read) && $router.hasRoute('higher-vacations')" :to="{ name: 'higher-vacations' }">{{ t('hv.back') }}</router-link></template>
    <ErrorNotice :error="load.error.value" />
    <ErrorNotice :error="act.error.value" />
    <template v-if="statement">
      <StatementSummary :statement="statement" />

      <div class="hi-actions" data-test="actions">
        <button v-if="status === 'draft' && can(VP.validateSchool)" type="button" class="btn btn--primary" :disabled="act.busy.value" @click="validateSchool">{{ t('hv.validateSchool') }}</button>
        <button v-if="status === 'school_validated' && can(VP.approve)" type="button" class="btn btn--primary" :disabled="act.busy.value" @click="approve">{{ t('hv.approve') }}</button>
        <button v-if="status !== 'rejected' && can(VP.statementDocument)" type="button" class="btn" :disabled="act.busy.value" @click="issue('statement')">{{ t('hv.doc25') }}</button>
        <button v-if="status === 'paid' && can(VP.pay)" type="button" class="btn" :disabled="act.busy.value" @click="issue('payslip')">{{ t('hv.doc26') }}</button>
      </div>
      <p v-if="issued" class="notice notice--success" role="status" data-test="issued">{{ t('hv.docIssued', { number: issued.number ?? issued.id }) }}</p>

      <PayForm v-if="status === 'approved' && can(VP.pay)" :busy="act.busy.value" @pay="pay" />

      <form v-if="(status === 'draft' || status === 'school_validated' || status === 'approved') && can(VP.reject)" class="hi-form" @submit.prevent="reject">
        <div class="field"><label for="hv-reason">{{ t('hv.rejectReason') }}</label><input id="hv-reason" v-model="reason" maxlength="500" /></div>
        <div class="field"><button type="submit" class="btn" :disabled="act.busy.value || reason.trim() === ''">{{ t('hv.reject') }}</button></div>
      </form>

      <section class="hi-section">
        <h2>{{ t('hv.detail.lines') }}</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th scope="col">{{ t('hv.line.date') }}</th><th scope="col">{{ t('hv.line.element') }}</th><th scope="col">{{ t('hv.line.level') }}</th><th scope="col">{{ t('hv.line.kind') }}</th><th scope="col" class="num">{{ t('hv.hours') }}</th><th scope="col" class="num">{{ t('hv.hetd') }}</th><th scope="col">{{ t('hv.line.payable') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="l in statement.lines ?? []" :key="l.id" :data-test="l.payable ? 'line' : 'line-refused'">
                <td>{{ formatDate(l.held_on, locale) }}</td>
                <td>{{ l.element_code }} · {{ l.element_title }}</td>
                <td>{{ l.level_label ?? '' }}</td>
                <td>{{ t(vk('hv.kind.', l.session_kind)) }}</td>
                <td class="num">{{ formatHours(l.minutes) }}</td>
                <td class="num">{{ formatHetd(l.hetd_milli, locale) }}</td>
                <td>{{ l.payable ? t('hv.yes') : `${t('hv.line.refused')} : ${l.refusal ?? ''}` }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </HigherFrame>
</template>
