<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { generateStatement, getStatement, listStatements, payStatement, statementAction, type Statement } from '@/api/staff';
import type { useStaffDirectory } from './useStaffDirectory';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, formatXaf, todayIso } from '@/lib/format';
import { currentMonth, formatHetd, formatMinutes } from '@/lib/hr';

// Relevés mensuels : générer à partir des heures validées, soumettre, approuver, payer (aucun calcul de paie).
const props = defineProps<{ dir: ReturnType<typeof useStaffDirectory> }>();
const { t, locale } = useI18n();
const ctx = useContextStore();

const rows = ref<Statement[]>([]);
const loaded = ref(false);
const filters = reactive({ period: '', status: '' });
const list = useAction();
const act = useAction();
const gen = useAction();
const detail = ref<Statement | null>(null);
const paying = ref<string | null>(null);
const pay = reactive({ paid_on: todayIso(), reference: '' });
const payTouched = ref(false);
const form = reactive({ staff_id: '', period: currentMonth() });
const touched = ref(false);

const canManage = computed(() => ctx.can('vacations.manage'));
const canValidate = computed(() => ctx.can('vacations.validate'));
const periodBad = computed(() => touched.value && !/^\d{4}-(0[1-9]|1[0-2])$/.test(form.period));
const statusLabel = (s: string): string => t(`vac.statement.${s}` as MessageKey);
const tone = (s: string): 'success' | 'neutral' => (s === 'validated' || s === 'paid' ? 'success' : 'neutral');

async function load(): Promise<void> {
  const res = await list.run(() => listStatements({ 'filter[period]': filters.period, 'filter[status]': filters.status }));
  if (res) rows.value = res;
  loaded.value = true;
}
function replace(s: Statement): void {
  rows.value = rows.value.map((x) => (x.id === s.id ? { ...x, ...s } : x));
}
async function generate(): Promise<void> {
  touched.value = true;
  if (form.staff_id === '' || periodBad.value || form.period === '') return;
  const res = await gen.run(() => generateStatement(form.staff_id, form.period));
  if (!res) return;
  touched.value = false;
  await load();
}
async function run(s: Statement, action: 'submit' | 'approve' | 'reject'): Promise<void> {
  const res = await act.run(() => statementAction(s.id, action));
  if (res) replace(res);
}
async function open(s: Statement): Promise<void> {
  if (detail.value?.id === s.id) {
    detail.value = null;
    return;
  }
  const res = await act.run(() => getStatement(s.id));
  if (res) detail.value = res;
}
function startPay(s: Statement): void {
  paying.value = s.id;
  payTouched.value = false;
  pay.reference = '';
}
async function confirmPay(s: Statement): Promise<void> {
  payTouched.value = true;
  if (pay.paid_on === '' || pay.reference.trim() === '') return;
  const res = await act.run(() => payStatement(s.id, pay.paid_on, pay.reference.trim()));
  if (res) {
    replace(res);
    paying.value = null;
  }
}

onMounted(load);
</script>

<template>
  <div data-test="statements">
    <div class="card">
      <form class="row" @submit.prevent="load">
        <label class="field"><span class="label">{{ t('vac.filter.period') }}</span><input v-model="filters.period" type="month" @change="load" /></label>
        <label class="field">
          <span class="label">{{ t('staff.filter.status') }}</span>
          <select v-model="filters.status" @change="load">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="s in ['draft', 'pending_approval', 'validated', 'paid']" :key="s" :value="s">{{ statusLabel(s) }}</option>
          </select>
        </label>
      </form>
      <ErrorNotice v-if="list.error.value || act.error.value" :error="list.error.value ?? act.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0" class="muted">{{ t('vac.statements.empty') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('vac.col.staff') }}</th><th>{{ t('vac.col.period') }}</th><th class="num">{{ t('vac.col.duration') }}</th><th class="num">{{ t('vac.col.hetd') }}</th>
              <th class="num">{{ t('vac.col.gross') }}</th><th>{{ t('staff.col.status') }}</th><th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="s in rows" :key="s.id">
              <tr>
                <th scope="row">{{ props.dir.nameOf(s.staff_id) }}</th>
                <td>{{ s.period }}</td>
                <td class="num">{{ formatMinutes(s.minutes_total) }}</td>
                <td class="num">{{ formatHetd(s.hetd_milli, locale) }}</td>
                <td class="num">{{ formatXaf(s.gross_amount, locale) }}</td>
                <td>
                  <StatusTag :tone="tone(s.status)">{{ statusLabel(s.status) }}</StatusTag>
                  <span v-if="s.paid_on" class="muted"> {{ formatDate(s.paid_on, locale) }} · {{ s.payment_reference }}</span>
                </td>
                <td class="row">
                  <button type="button" class="btn btn--quiet" data-action="detail" @click="open(s)">{{ t('vac.action.detail') }}</button>
                  <button v-if="canManage && s.status === 'draft'" type="button" class="btn btn--quiet" data-action="submit" :disabled="act.busy.value" @click="run(s, 'submit')">{{ t('vac.action.submit') }}</button>
                  <button v-if="canValidate && s.status === 'pending_approval'" type="button" class="btn btn--quiet" data-action="approve" :disabled="act.busy.value" @click="run(s, 'approve')">{{ t('vac.action.approve') }}</button>
                  <button v-if="canValidate && s.status === 'pending_approval'" type="button" class="btn btn--quiet" data-action="reject" :disabled="act.busy.value" @click="run(s, 'reject')">{{ t('vac.action.reject') }}</button>
                  <button v-if="canManage && s.status === 'validated'" type="button" class="btn btn--quiet" data-action="pay" @click="startPay(s)">{{ t('vac.action.pay') }}</button>
                </td>
              </tr>
              <tr v-if="paying === s.id">
                <td colspan="7">
                  <form class="row" novalidate @submit.prevent="confirmPay(s)">
                    <label class="field"><span class="label">{{ t('vac.pay.date') }}</span><input v-model="pay.paid_on" type="date" /></label>
                    <label class="field spacer">
                      <span class="label">{{ t('vac.pay.reference') }}</span>
                      <input v-model="pay.reference" type="text" maxlength="80" :aria-invalid="payTouched && pay.reference.trim() === ''" />
                      <span v-if="payTouched && pay.reference.trim() === ''" class="error" role="alert">{{ t('vac.pay.required') }}</span>
                    </label>
                    <button type="submit" class="btn btn--ink" :disabled="act.busy.value">{{ t('vac.pay.confirm') }}</button>
                    <button type="button" class="btn btn--quiet" @click="paying = null">{{ t('hr.cancel') }}</button>
                  </form>
                </td>
              </tr>
              <tr v-if="detail?.id === s.id">
                <td colspan="7">
                  <p v-if="!detail.lines || detail.lines.length === 0" class="muted">{{ t('vac.hours.empty') }}</p>
                  <ul v-else>
                    <li v-for="h in detail.lines" :key="h.id">{{ formatDate(h.held_on, locale) }} · {{ formatMinutes(h.minutes) }} · {{ t(`hr.session.${h.session_kind}` as MessageKey) }}{{ h.content ? ' · ' + h.content : '' }}</li>
                  </ul>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <form v-if="canManage" class="card" data-test="statement-form" novalidate @submit.prevent="generate">
      <h2>{{ t('vac.statement.generate') }}</h2>
      <ErrorNotice v-if="gen.error.value" :error="gen.error.value" />
      <div class="row">
        <label class="field">
          <span class="label">{{ t('vac.col.staff') }}</span>
          <select v-model="form.staff_id" :aria-invalid="touched && form.staff_id === ''">
            <option value="" disabled>{{ t('vac.choose') }}</option>
            <option v-for="s in props.dir.staff.value" :key="s.id" :value="s.id">{{ props.dir.nameOf(s.id) }}</option>
          </select>
          <span v-if="touched && form.staff_id === ''" class="error" role="alert">{{ t('vac.staffRequired') }}</span>
        </label>
        <label class="field"><span class="label">{{ t('vac.col.period') }}</span><input v-model="form.period" type="month" :aria-invalid="periodBad" /></label>
        <button type="submit" class="btn btn--primary" :disabled="gen.busy.value">{{ t('vac.statement.generateSubmit') }}</button>
      </div>
    </form>
  </div>
</template>
