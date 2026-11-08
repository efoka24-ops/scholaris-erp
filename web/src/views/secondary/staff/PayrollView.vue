<script setup lang="ts">
// Paie simplifiée du privé (T912, module payroll_simple) : statuts éligibles (chef d'établissement, jamais un fonctionnaire), bulletin
// préparé par l'intendant (net = brut − retenues saisies), validé par une autre personne, payé avec émission de DOC-30.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n, type MessageKey } from '@/i18n';
import { useAuthStore } from '@/stores/auth';
import { useAction, usePermissions } from '../useSec';
import { hoursOf, netOf, PAYABLE_STATUSES, secondaryStaff, type Deduction, type EmploymentStatus, type MonthlyHours, type Payslip, type StaffProfile } from './staffApi';

const { t, locale } = useI18n();
const auth = useAuthStore();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canPrepare = computed(() => can('secondary.billing.manage'));
const canValidate = computed(() => can('secondary.settings.manage'));
const profiles = ref<StaffProfile[]>([]);
const slips = ref<Payslip[]>([]);
const eligible = ref<EmploymentStatus[]>([]);
const notice = ref('');
const form = reactive({ profileId: '', month: new Date().toISOString().slice(0, 7), gross: '', label: '', amount: '' });
const deductions = ref<Deduction[]>([]);
const money = (amount: number): string => `${new Intl.NumberFormat('fr-FR').format(amount)} FCFA`;
const gross = computed(() => (/^\d+$/.test(form.gross) ? Number(form.gross) : NaN));
const net = computed(() => (Number.isNaN(gross.value) ? NaN : netOf(gross.value, deductions.value)));
const eligibleProfiles = computed(() => profiles.value.filter((p) => p.id !== null && p.employment_status !== null && eligible.value.includes(p.employment_status)));
const nameOf = (slip: Payslip): string => profiles.value.find((p) => p.id === slip.staff_profile_id)?.full_name ?? slip.person_id;
const ready = computed(() => form.profileId !== '' && /^\d{4}-(0[1-9]|1[0-2])$/.test(form.month) && !Number.isNaN(net.value) && net.value >= 0);

const hoursMonth = ref(new Date().toISOString().slice(0, 7));
const monthly = ref<MonthlyHours[]>([]);
const hoursForm = reactive({ profileId: '', hours: '', note: '' });
const nonTeaching = computed(() => profiles.value.filter((p) => p.id !== null && !p.teaches));
const profileName = (id: string): string => profiles.value.find((p) => p.id === id)?.full_name ?? id;

async function loadMonthly(): Promise<void> {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(hoursMonth.value)) return;
  monthly.value = (await run(() => secondaryStaff.monthlyHours(hoursMonth.value))) ?? [];
}
async function load(): Promise<void> {
  profiles.value = (await run(() => secondaryStaff.profiles())) ?? [];
  eligible.value = (await run(() => secondaryStaff.payrollRules()))?.eligible_statuses ?? [];
  slips.value = (await run(() => secondaryStaff.payslips())) ?? [];
  await loadMonthly();
}
async function declareHours(): Promise<void> {
  const value = Number(hoursForm.hours.replace(',', '.'));
  if (hoursForm.profileId === '' || !Number.isFinite(value) || value < 0) return;
  const saved = await run(() => secondaryStaff.declareMonthlyHours({ staff_profile_id: hoursForm.profileId, month_ref: hoursMonth.value, minutes: Math.round(value * 60), note: hoursForm.note.trim() || null }));
  if (saved) { notice.value = t('secpay.hoursDeclared'); hoursForm.hours = ''; hoursForm.note = ''; await loadMonthly(); }
}
async function validateHours(row: MonthlyHours): Promise<void> {
  if (await run(() => secondaryStaff.validateMonthlyHours(row.id))) { notice.value = t('secpay.hoursValidated'); await loadMonthly(); }
}
async function downloadPdf(slip: Payslip): Promise<void> {
  const blob = await run(() => secondaryStaff.payslipPdf(slip.id));
  if (!blob) return;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `bulletin-${slip.month_ref}-${slip.document_number ?? slip.id}.pdf`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
async function saveRules(): Promise<void> {
  const saved = await run(() => secondaryStaff.savePayrollRules(eligible.value));
  if (saved) { eligible.value = saved.eligible_statuses; notice.value = t('secpay.rulesSaved'); }
}
function addDeduction(): void {
  const label = form.label.trim();
  if (label === '' || !/^\d+$/.test(form.amount)) return;
  deductions.value.push({ label, amount: Number(form.amount) });
  form.label = '';
  form.amount = '';
}
async function create(): Promise<void> {
  if (!ready.value) return;
  const created = await run(() => secondaryStaff.createPayslip({ staff_profile_id: form.profileId, month_ref: form.month, gross_amount: gross.value, deductions: deductions.value }));
  if (created) {
    notice.value = t('secpay.created');
    form.gross = '';
    deductions.value = [];
    await load();
  }
}
async function validate(slip: Payslip): Promise<void> {
  if (await run(() => secondaryStaff.validate(slip.id))) { notice.value = t('secpay.validated'); await load(); }
}
async function pay(slip: Payslip): Promise<void> {
  const paid = await run(() => secondaryStaff.pay(slip.id, locale.value));
  if (paid) { notice.value = t('secpay.paid', { number: paid.document_number ?? '' }); await load(); }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secpay.title') }}</h1>
      <p class="lede">{{ t('secpay.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <section class="card" aria-labelledby="spy-rules">
      <h2 id="spy-rules">{{ t('secpay.rules') }}</h2>
      <p class="muted">{{ t('secpay.rulesHelp') }}</p>
      <fieldset :disabled="!canValidate">
        <legend class="sr-only">{{ t('secpay.rules') }}</legend>
        <label v-for="s in PAYABLE_STATUSES" :key="s" class="check-row">
          <input v-model="eligible" type="checkbox" :value="s" /> {{ t(`secst.status.${s}` as MessageKey) }}
        </label>
      </fieldset>
      <button v-if="canValidate" type="button" class="btn" :disabled="busy || eligible.length === 0" @click="saveRules">{{ t('secpay.saveRules') }}</button>
    </section>

    <section class="card" aria-labelledby="spy-hours">
      <h2 id="spy-hours">{{ t('secpay.hours') }}</h2>
      <p class="muted">{{ t('secpay.hoursHelp') }}</p>
      <div class="field">
        <label for="spy-hours-month">{{ t('secpay.month') }}</label>
        <input id="spy-hours-month" v-model="hoursMonth" type="month" @change="loadMonthly" />
      </div>
      <form v-if="canPrepare" class="stack" @submit.prevent="declareHours">
        <div class="field">
          <label for="spy-hours-member">{{ t('secpay.hoursMember') }}</label>
          <select id="spy-hours-member" v-model="hoursForm.profileId" required>
            <option value="">{{ t('secpay.chooseMember') }}</option>
            <option v-for="p in nonTeaching" :key="p.id ?? p.staff_id" :value="p.id ?? ''">{{ p.full_name }}<template v-if="p.function"> · {{ p.function }}</template></option>
          </select>
        </div>
        <div class="field">
          <label for="spy-hours-value">{{ t('secpay.hoursValue') }}</label>
          <input id="spy-hours-value" v-model="hoursForm.hours" type="text" inputmode="decimal" required />
        </div>
        <div class="field">
          <label for="spy-hours-note">{{ t('secpay.note') }}</label>
          <input id="spy-hours-note" v-model="hoursForm.note" type="text" maxlength="255" />
        </div>
        <div><button type="submit" class="btn" :disabled="busy || hoursForm.profileId === ''">{{ t('secpay.declareHours') }}</button></div>
      </form>
      <ul class="rows">
        <li v-for="row in monthly" :key="row.id">
          {{ profileName(row.staff_profile_id) }} · {{ hoursOf(row.minutes) }} h · {{ t(`secpay.hoursState.${row.status}` as MessageKey) }}
          <button v-if="row.status === 'declared' && canValidate && row.declared_by !== auth.me?.id" type="button" class="btn" :disabled="busy" @click="validateHours(row)">{{ t('secpay.validate') }}</button>
        </li>
        <li v-if="monthly.length === 0" class="muted">{{ t('secpay.noHours') }}</li>
      </ul>
    </section>

    <section v-if="canPrepare" class="card" aria-labelledby="spy-new">
      <h2 id="spy-new">{{ t('secpay.new') }}</h2>
      <form class="stack" @submit.prevent="create">
        <div class="field">
          <label for="spy-member">{{ t('secpay.member') }}</label>
          <select id="spy-member" v-model="form.profileId" required>
            <option value="">{{ t('secpay.chooseMember') }}</option>
            <option v-for="p in eligibleProfiles" :key="p.id ?? p.staff_id" :value="p.id ?? ''">{{ p.full_name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="spy-month">{{ t('secpay.month') }}</label>
          <input id="spy-month" v-model="form.month" type="month" required />
        </div>
        <div class="field">
          <label for="spy-gross">{{ t('secpay.gross') }}</label>
          <input id="spy-gross" v-model="form.gross" type="text" inputmode="numeric" pattern="[0-9]*" required />
        </div>
        <div class="field">
          <label for="spy-label">{{ t('secpay.deductionLabel') }}</label>
          <input id="spy-label" v-model="form.label" type="text" maxlength="100" />
          <label for="spy-amount">{{ t('secpay.deductionAmount') }}</label>
          <input id="spy-amount" v-model="form.amount" type="text" inputmode="numeric" pattern="[0-9]*" />
          <div><button type="button" class="btn" @click="addDeduction">{{ t('secpay.addDeduction') }}</button></div>
        </div>
        <ul v-if="deductions.length > 0" class="rows">
          <li v-for="(line, index) in deductions" :key="`${line.label}-${index}`">
            {{ line.label }} : {{ money(line.amount) }}
            <button type="button" class="btn btn--link" @click="deductions.splice(index, 1)">{{ t('secpay.removeDeduction') }}</button>
          </li>
        </ul>
        <p>{{ t('secpay.net') }} : <strong>{{ Number.isNaN(net) ? '—' : money(net) }}</strong></p>
        <div><button type="submit" class="btn btn--primary" :disabled="busy || !ready">{{ t('secpay.create') }}</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="spy-list">
      <h2 id="spy-list">{{ t('secpay.list') }}</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secpay.member') }}</th><th>{{ t('secpay.month') }}</th><th>{{ t('secpay.gross') }}</th><th>{{ t('secpay.deductions') }}</th><th>{{ t('secpay.net') }}</th><th>{{ t('secpay.state') }}</th><th>{{ t('secpay.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="slip in slips" :key="slip.id">
              <td>{{ nameOf(slip) }}</td><td>{{ slip.month_ref }}</td><td>{{ money(slip.gross_amount) }}</td><td>{{ money(slip.deductions_amount) }}</td>
              <td>{{ money(slip.net_amount) }}</td><td>{{ t(`secpay.state.${slip.status}` as MessageKey) }}</td>
              <td>
                <template v-if="slip.status === 'draft' && canValidate">
                  <span v-if="slip.created_by === auth.me?.id" class="muted">{{ t('secpay.ownSlip') }}</span>
                  <button v-else type="button" class="btn" :disabled="busy" @click="validate(slip)">{{ t('secpay.validate') }}</button>
                </template>
                <button v-if="slip.status === 'validated' && canPrepare" type="button" class="btn" :disabled="busy" @click="pay(slip)">{{ t('secpay.pay') }}</button>
                <span v-if="slip.document_number">{{ t('secpay.document', { number: slip.document_number }) }}</span>
                <button v-if="slip.document_id && (canPrepare || canValidate)" type="button" class="btn" :disabled="busy" @click="downloadPdf(slip)">{{ t('secpay.pdf') }}</button>
              </td>
            </tr>
            <tr v-if="slips.length === 0"><td colspan="7">{{ t('secpay.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 10px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.table-wrap { overflow-x: auto; }
.check-row { display: flex; gap: 8px; align-items: center; }
</style>
