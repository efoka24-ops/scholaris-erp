<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { useStaffDirectory } from './useStaffDirectory';
import { leaveAction, leaveBalances, leaveCalendar, LEAVE_TYPES, listLeaves, requestLeave, type LeaveBalance, type LeaveRequest, type LeaveType } from '@/api/staff';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { addDaysIso } from '@/lib/hr';
import { formatDate, todayIso } from '@/lib/format';

// Congés : demande, approbation, refus, annulation, calendrier d'absence et soldes — design : Dashboard.html, Frais.html (tableaux et formulaires).
const { t, locale } = useI18n();
const ctx = useContextStore();
const dir = useStaffDirectory();

const requests = ref<LeaveRequest[]>([]);
const calendar = ref<LeaveRequest[]>([]);
const balances = ref<LeaveBalance[]>([]);
const loaded = ref(false);
const status = ref('');
const span = reactive({ from: todayIso(), to: addDaysIso(todayIso(), 30) });
const year = new Date().getFullYear();
const balanceStaff = ref('');

const list = useAction();
const act = useAction();
const req = useAction();
const cal = useAction();
const bal = useAction();

const canRequest = computed(() => ctx.can('staff.leave.request'));
const canApprove = computed(() => ctx.can('staff.leave.approve'));
const form = reactive({ leave_type: 'annual' as LeaveType, starts_on: '', ends_on: '', reason: '' });
const touched = ref(false);
const errors = computed(() => ({
  dates: touched.value && (form.starts_on === '' || form.ends_on === '' || form.ends_on < form.starts_on),
}));

const typeLabel = (k: string): string => t(`leave.type.${k}` as MessageKey);
const statusLabel = (k: string): string => t(`leave.status.${k}` as MessageKey);
const tone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'approved' ? 'success' : s === 'rejected' ? 'warning' : 'neutral');
const own = (r: LeaveRequest): boolean => dir.isOwn(r.staff_id);
const cancellable = (r: LeaveRequest): boolean => own(r) && (r.status === 'pending' || (r.status === 'approved' && r.starts_on > todayIso()));

async function loadRequests(): Promise<void> {
  const res = await list.run(() => listLeaves({ 'filter[status]': status.value }));
  if (res) requests.value = res;
  loaded.value = true;
}
async function loadCalendar(): Promise<void> {
  if (span.from === '' || span.to === '' || span.to < span.from) return;
  const res = await cal.run(() => leaveCalendar(span.from, span.to));
  if (res) calendar.value = res;
}
async function loadBalances(): Promise<void> {
  const res = await bal.run(() => leaveBalances(balanceStaff.value || null, year));
  if (res) balances.value = res;
}
async function submit(): Promise<void> {
  touched.value = true;
  if (errors.value.dates) return;
  const body: Record<string, unknown> = { leave_type: form.leave_type, starts_on: form.starts_on, ends_on: form.ends_on };
  if (form.reason.trim()) body.reason = form.reason.trim();
  const res = await req.run(() => requestLeave(body));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { starts_on: '', ends_on: '', reason: '' });
  await Promise.all([loadRequests(), loadCalendar(), loadBalances()]);
}
async function decide(r: LeaveRequest, action: 'approve' | 'reject' | 'cancel'): Promise<void> {
  const res = await act.run(() => leaveAction(r.id, action));
  if (!res) return;
  requests.value = requests.value.map((x) => (x.id === r.id ? { ...x, ...res } : x));
  await Promise.all([loadCalendar(), loadBalances()]);
}

onMounted(async () => {
  await dir.load();
  await Promise.all([loadRequests(), loadCalendar(), loadBalances()]);
});
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('staff.eyebrow') }}</p>
      <h1>{{ t('leave.title') }}</h1>
      <p class="lede">{{ t('leave.lede') }}</p>
    </header>

    <form v-if="canRequest" class="card" data-test="leave-form" novalidate @submit.prevent="submit">
      <h2>{{ t('leave.request') }}</h2>
      <ErrorNotice v-if="req.error.value" :error="req.error.value" />
      <div class="grid grid--kpi">
        <label class="field">
          <span class="label">{{ t('leave.col.type') }}</span>
          <select v-model="form.leave_type"><option v-for="k in LEAVE_TYPES" :key="k" :value="k">{{ typeLabel(k) }}</option></select>
        </label>
        <label class="field"><span class="label">{{ t('leave.col.from') }}</span><input v-model="form.starts_on" type="date" :aria-invalid="errors.dates" /></label>
        <label class="field"><span class="label">{{ t('leave.col.to') }}</span><input v-model="form.ends_on" type="date" :aria-invalid="errors.dates" /></label>
      </div>
      <p v-if="errors.dates" class="error" role="alert">{{ t('leave.datesInvalid') }}</p>
      <label class="field"><span class="label">{{ t('leave.col.reason') }}</span><input v-model="form.reason" type="text" /></label>
      <button type="submit" class="btn btn--primary" :disabled="req.busy.value">{{ t('leave.request.submit') }}</button>
    </form>

    <div class="card" data-test="requests">
      <h2>{{ t('leave.requests') }}</h2>
      <label class="field">
        <span class="label">{{ t('staff.filter.status') }}</span>
        <select v-model="status" @change="loadRequests">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="s in ['pending', 'approved', 'rejected', 'cancelled']" :key="s" :value="s">{{ statusLabel(s) }}</option>
        </select>
      </label>
      <ErrorNotice v-if="list.error.value || act.error.value" :error="list.error.value ?? act.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="requests.length === 0" class="muted">{{ t('leave.empty') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('vac.col.staff') }}</th><th>{{ t('leave.col.type') }}</th><th>{{ t('leave.col.from') }}</th><th>{{ t('leave.col.to') }}</th>
              <th class="num">{{ t('leave.col.days') }}</th><th>{{ t('staff.col.status') }}</th><th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in requests" :key="r.id">
              <th scope="row">{{ dir.nameOf(r.staff_id) }}</th>
              <td>{{ typeLabel(r.leave_type) }}</td>
              <td>{{ formatDate(r.starts_on, locale) }}</td>
              <td>{{ formatDate(r.ends_on, locale) }}</td>
              <td class="num">{{ r.days ?? '—' }}</td>
              <td><StatusTag :tone="tone(r.status)">{{ statusLabel(r.status) }}</StatusTag></td>
              <td class="row">
                <button v-if="canApprove && r.status === 'pending' && !own(r)" type="button" class="btn btn--quiet" data-action="approve" :disabled="act.busy.value" @click="decide(r, 'approve')">{{ t('vac.action.approve') }}</button>
                <button v-if="canApprove && r.status === 'pending' && !own(r)" type="button" class="btn btn--quiet" data-action="reject" :disabled="act.busy.value" @click="decide(r, 'reject')">{{ t('leave.action.refuse') }}</button>
                <button v-if="cancellable(r)" type="button" class="btn btn--quiet" data-action="cancel" :disabled="act.busy.value" @click="decide(r, 'cancel')">{{ t('leave.action.cancel') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" data-test="calendar">
      <h2>{{ t('leave.calendar') }}</h2>
      <form class="row" @submit.prevent="loadCalendar">
        <label class="field"><span class="label">{{ t('leave.col.from') }}</span><input v-model="span.from" type="date" @change="loadCalendar" /></label>
        <label class="field"><span class="label">{{ t('leave.col.to') }}</span><input v-model="span.to" type="date" @change="loadCalendar" /></label>
      </form>
      <ErrorNotice v-if="cal.error.value" :error="cal.error.value" />
      <p v-if="calendar.length === 0" class="muted">{{ t('leave.calendar.empty') }}</p>
      <ul v-else>
        <li v-for="r in calendar" :key="r.id">
          <strong>{{ dir.nameOf(r.staff_id) }}</strong> · {{ typeLabel(r.leave_type) }} · {{ formatDate(r.starts_on, locale) }} → {{ formatDate(r.ends_on, locale) }}
          <StatusTag :tone="tone(r.status)">{{ statusLabel(r.status) }}</StatusTag>
        </li>
      </ul>
    </div>

    <div class="card" data-test="balances">
      <h2>{{ t('staff.balances', { year }) }}</h2>
      <label v-if="dir.staff.value.length > 1" class="field">
        <span class="label">{{ t('vac.col.staff') }}</span>
        <select v-model="balanceStaff" @change="loadBalances">
          <option value="">{{ t('leave.mine') }}</option>
          <option v-for="s in dir.staff.value" :key="s.id" :value="s.id">{{ dir.nameOf(s.id) }}</option>
        </select>
      </label>
      <ErrorNotice v-if="bal.error.value" :error="bal.error.value" />
      <p v-if="balances.length === 0" class="muted">{{ t('leave.balances.empty') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>{{ t('leave.col.type') }}</th><th class="num">{{ t('leave.bal.entitled') }}</th><th class="num">{{ t('leave.bal.used') }}</th><th class="num">{{ t('leave.bal.pending') }}</th><th class="num">{{ t('leave.bal.remaining') }}</th></tr></thead>
          <tbody>
            <tr v-for="b in balances" :key="b.leave_type">
              <th scope="row">{{ typeLabel(b.leave_type) }}</th>
              <td class="num">{{ b.entitled_days ?? '—' }}</td><td class="num">{{ b.used_days }}</td><td class="num">{{ b.pending_days }}</td><td class="num">{{ b.remaining_days ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>
