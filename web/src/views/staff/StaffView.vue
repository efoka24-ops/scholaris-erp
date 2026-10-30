<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { http } from '@/api/http';
import {
  addAssignment, addQualification, deleteAssignment, deleteQualification, getStaff, leaveBalances, listAssignments, listQualifications,
  putLeaveBalance, updateAssignment, updateStaff, LEAVE_TYPES,
  type Assignment, type LeaveBalance, type LeaveType, type Qualification, type StaffMember,
} from '@/api/staff';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { formatMinutes } from '@/lib/hr';

// Fiche du personnel : identité et statut, qualifications, affectations, soldes de congé — design : Dashboard.html, Frais.html (cartes et tableaux).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const ctx = useContextStore();

const member = ref<StaffMember | null>(null);
const quals = ref<Qualification[]>([]);
const assigns = ref<Assignment[]>([]);
const balances = ref<LeaveBalance[]>([]);
const structures = ref<Array<{ id: string; name?: string; label?: string }>>([]);
const year = new Date().getFullYear();

const main = useAction();
const statusAct = useAction();
const qualAct = useAction();
const assignAct = useAction();
const balAct = useAction();

const canManage = computed(() => ctx.can('staff.manage'));
const qualForm = reactive({ title: '', institution: '', obtained_on: '' });
const assignForm = reactive({ structure_id: '', subject_label: '', session_kind: 'td', planned_minutes: 0 });
const balForm = reactive({ leave_type: 'annual' as LeaveType, entitled_days: 0 });
const qualTouched = ref(false);

const name = computed(() => member.value?.full_name || [member.value?.first_name, member.value?.last_name].filter(Boolean).join(' ') || member.value?.person_id || '');
const structureName = (id: string | null): string => {
  const s = structures.value.find((x) => x.id === id);
  return s?.name ?? s?.label ?? id ?? '—';
};
const sessionLabel = (k: string): string => t(`hr.session.${k}` as MessageKey);
const leaveLabel = (k: string): string => t(`leave.type.${k}` as MessageKey);

async function loadAll(): Promise<void> {
  const m = await main.run(async () => {
    const [s, q, a] = await Promise.all([getStaff(props.id), listQualifications(props.id), listAssignments(props.id)]);
    return { s, q, a };
  });
  if (!m) return;
  member.value = m.s;
  quals.value = m.q;
  assigns.value = m.a;
  try {
    balances.value = await leaveBalances(props.id, year);
  } catch {
    balances.value = []; // soldes non visibles pour ce profil : la fiche reste utilisable
  }
  if (canManage.value) {
    try {
      structures.value = await http.all('/structures', {}, {}, 5);
    } catch {
      structures.value = [];
    }
  }
}

async function setStatus(status: string): Promise<void> {
  const res = await statusAct.run(() => updateStaff(props.id, { status }));
  if (res) member.value = { ...member.value, ...res } as StaffMember;
}

async function submitQual(): Promise<void> {
  qualTouched.value = true;
  if (qualForm.title.trim() === '') return;
  const body: Record<string, unknown> = { title: qualForm.title.trim() };
  if (qualForm.institution.trim()) body.institution = qualForm.institution.trim();
  if (qualForm.obtained_on) body.obtained_on = qualForm.obtained_on;
  const res = await qualAct.run(() => addQualification(props.id, body));
  if (!res) return;
  quals.value = [...quals.value, res];
  Object.assign(qualForm, { title: '', institution: '', obtained_on: '' });
  qualTouched.value = false;
}

async function removeQual(qid: string): Promise<void> {
  const ok = await qualAct.run(async () => {
    await deleteQualification(props.id, qid);
    return true;
  });
  if (ok) quals.value = quals.value.filter((q) => q.id !== qid);
}

async function submitAssign(): Promise<void> {
  const body: Record<string, unknown> = { session_kind: assignForm.session_kind, planned_minutes: Number(assignForm.planned_minutes) || 0 };
  if (assignForm.structure_id) body.structure_id = assignForm.structure_id;
  if (assignForm.subject_label.trim()) body.subject_label = assignForm.subject_label.trim();
  const res = await assignAct.run(() => addAssignment(props.id, body));
  if (!res) return;
  assigns.value = [...assigns.value, res];
  Object.assign(assignForm, { structure_id: '', subject_label: '', planned_minutes: 0 });
}

async function endAssign(a: Assignment): Promise<void> {
  const res = await assignAct.run(() => updateAssignment(props.id, a.id, { status: 'ended' }));
  if (res) assigns.value = assigns.value.map((x) => (x.id === a.id ? res : x));
}

async function removeAssign(a: Assignment): Promise<void> {
  const ok = await assignAct.run(async () => {
    await deleteAssignment(props.id, a.id);
    return true;
  });
  if (ok) assigns.value = assigns.value.filter((x) => x.id !== a.id);
}

async function submitBalance(): Promise<void> {
  const res = await balAct.run(() => putLeaveBalance({ staff_id: props.id, leave_type: balForm.leave_type, leave_year: year, entitled_days: Number(balForm.entitled_days) }));
  if (res) balances.value = res;
}

onMounted(loadAll);
</script>

<template>
  <section class="page">
    <router-link :to="{ name: 'staff' }">← {{ t('staff.back') }}</router-link>
    <ErrorNotice v-if="main.error.value" :error="main.error.value" />
    <p v-else-if="!member" class="muted" role="status">{{ t('common.loading') }}</p>

    <template v-else>
      <header class="page-head">
        <p class="eyebrow">{{ t('staff.eyebrow') }}</p>
        <h1>{{ name }}</h1>
        <p class="lede">
          {{ t(`staff.kind.${member.kind}` as MessageKey) }} ·
          <StatusTag :tone="member.status === 'active' ? 'success' : member.status === 'suspended' ? 'warning' : 'neutral'">{{ t(`staff.status.${member.status}` as MessageKey) }}</StatusTag>
        </p>
      </header>

      <div class="card" data-test="identity">
        <h2>{{ t('staff.identity') }}</h2>
        <dl class="dl">
          <div><dt>{{ t('staff.col.grade') }}</dt><dd>{{ member.grade ?? '—' }}</dd></div>
          <div><dt>{{ t('staff.col.category') }}</dt><dd>{{ member.category ?? '—' }}</dd></div>
          <div><dt>{{ t('staff.col.number') }}</dt><dd>{{ member.employee_number ?? '—' }}</dd></div>
          <div><dt>{{ t('staff.col.hired') }}</dt><dd>{{ formatDate(member.hired_on, locale) || '—' }}</dd></div>
          <div v-if="member.left_on"><dt>{{ t('staff.col.left') }}</dt><dd>{{ formatDate(member.left_on, locale) }}</dd></div>
          <div><dt>{{ t('staff.col.phone') }}</dt><dd>{{ member.phone ?? '—' }}</dd></div>
          <div><dt>{{ t('staff.col.email') }}</dt><dd>{{ member.email ?? '—' }}</dd></div>
        </dl>
        <template v-if="canManage">
          <ErrorNotice v-if="statusAct.error.value" :error="statusAct.error.value" />
          <div class="row">
            <button v-if="member.status !== 'active'" type="button" class="btn" :disabled="statusAct.busy.value" data-action="activate" @click="setStatus('active')">{{ t('staff.action.activate') }}</button>
            <button v-if="member.status === 'active'" type="button" class="btn" :disabled="statusAct.busy.value" data-action="suspend" @click="setStatus('suspended')">{{ t('staff.action.suspend') }}</button>
            <button v-if="member.status !== 'left'" type="button" class="btn" :disabled="statusAct.busy.value" data-action="leave" @click="setStatus('left')">{{ t('staff.action.left') }}</button>
          </div>
        </template>
      </div>

      <div class="card" data-test="qualifications">
        <h2>{{ t('staff.qualifications') }}</h2>
        <ErrorNotice v-if="qualAct.error.value" :error="qualAct.error.value" />
        <p v-if="quals.length === 0" class="muted">{{ t('staff.qualifications.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead><tr><th>{{ t('staff.qual.title') }}</th><th>{{ t('staff.qual.institution') }}</th><th>{{ t('staff.qual.obtained') }}</th><th v-if="canManage" /></tr></thead>
            <tbody>
              <tr v-for="q in quals" :key="q.id">
                <th scope="row">{{ q.title }}</th><td>{{ q.institution ?? '—' }}</td><td>{{ formatDate(q.obtained_on, locale) }}</td>
                <td v-if="canManage"><button type="button" class="btn btn--quiet" @click="removeQual(q.id)">{{ t('hr.delete') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <form v-if="canManage" class="row" novalidate style="margin-top: 12px" @submit.prevent="submitQual">
          <label class="field"><span class="label">{{ t('staff.qual.title') }}</span><input v-model="qualForm.title" type="text" :aria-invalid="qualTouched && qualForm.title.trim() === ''" /></label>
          <label class="field"><span class="label">{{ t('staff.qual.institution') }}</span><input v-model="qualForm.institution" type="text" /></label>
          <label class="field"><span class="label">{{ t('staff.qual.obtained') }}</span><input v-model="qualForm.obtained_on" type="date" /></label>
          <button type="submit" class="btn" :disabled="qualAct.busy.value">{{ t('staff.qual.add') }}</button>
        </form>
      </div>

      <div class="card" data-test="assignments">
        <h2>{{ t('staff.assignments') }}</h2>
        <ErrorNotice v-if="assignAct.error.value" :error="assignAct.error.value" />
        <p v-if="assigns.length === 0" class="muted">{{ t('staff.assignments.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('teacher.col.class') }}</th><th>{{ t('teacher.col.subject') }}</th><th>{{ t('teacher.col.kind') }}</th>
                <th class="num">{{ t('staff.assign.planned') }}</th><th class="num">{{ t('staff.assign.validated') }}</th><th>{{ t('staff.col.status') }}</th><th v-if="canManage" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in assigns" :key="a.id">
                <td>{{ structureName(a.structure_id) }}</td><td>{{ a.subject_label ?? '—' }}</td><td>{{ sessionLabel(a.session_kind) }}</td>
                <td class="num">{{ formatMinutes(a.planned_minutes ?? 0) }}</td><td class="num">{{ formatMinutes(a.validated_minutes ?? 0) }}</td>
                <td><StatusTag :tone="a.status === 'active' ? 'success' : 'neutral'">{{ t(`staff.assign.${a.status}` as MessageKey) }}</StatusTag></td>
                <td v-if="canManage" class="row">
                  <button v-if="a.status === 'active'" type="button" class="btn btn--quiet" @click="endAssign(a)">{{ t('staff.assign.end') }}</button>
                  <button type="button" class="btn btn--quiet" @click="removeAssign(a)">{{ t('hr.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <form v-if="canManage" class="row" style="margin-top: 12px" @submit.prevent="submitAssign">
          <label class="field">
            <span class="label">{{ t('teacher.col.class') }}</span>
            <select v-model="assignForm.structure_id">
              <option value="">—</option>
              <option v-for="s in structures" :key="s.id" :value="s.id">{{ s.name ?? s.label ?? s.id }}</option>
            </select>
          </label>
          <label class="field"><span class="label">{{ t('teacher.col.subject') }}</span><input v-model="assignForm.subject_label" type="text" /></label>
          <label class="field">
            <span class="label">{{ t('teacher.col.kind') }}</span>
            <select v-model="assignForm.session_kind"><option v-for="k in ['cm', 'td', 'tp', 'other']" :key="k" :value="k">{{ sessionLabel(k) }}</option></select>
          </label>
          <label class="field"><span class="label">{{ t('staff.assign.plannedMinutes') }}</span><input v-model.number="assignForm.planned_minutes" type="number" min="0" /></label>
          <button type="submit" class="btn" :disabled="assignAct.busy.value">{{ t('staff.assign.add') }}</button>
        </form>
      </div>

      <div class="card" data-test="balances">
        <h2>{{ t('staff.balances', { year }) }}</h2>
        <ErrorNotice v-if="balAct.error.value" :error="balAct.error.value" />
        <p v-if="balances.length === 0" class="muted">{{ t('leave.balances.empty') }}</p>
        <div v-else class="table-wrap">
          <table>
            <thead><tr><th>{{ t('leave.col.type') }}</th><th class="num">{{ t('leave.bal.entitled') }}</th><th class="num">{{ t('leave.bal.used') }}</th><th class="num">{{ t('leave.bal.pending') }}</th><th class="num">{{ t('leave.bal.remaining') }}</th></tr></thead>
            <tbody>
              <tr v-for="b in balances" :key="b.leave_type">
                <th scope="row">{{ leaveLabel(b.leave_type) }}</th>
                <td class="num">{{ b.entitled_days ?? '—' }}</td><td class="num">{{ b.used_days }}</td><td class="num">{{ b.pending_days }}</td><td class="num">{{ b.remaining_days ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <form v-if="canManage" class="row" style="margin-top: 12px" @submit.prevent="submitBalance">
          <label class="field">
            <span class="label">{{ t('leave.col.type') }}</span>
            <select v-model="balForm.leave_type"><option v-for="k in LEAVE_TYPES" :key="k" :value="k">{{ leaveLabel(k) }}</option></select>
          </label>
          <label class="field"><span class="label">{{ t('leave.bal.entitled') }}</span><input v-model.number="balForm.entitled_days" type="number" min="0" max="366" /></label>
          <button type="submit" class="btn" :disabled="balAct.busy.value">{{ t('leave.bal.set') }}</button>
        </form>
      </div>
    </template>
  </section>
</template>

<style scoped>
.dl {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px 24px;
  margin: 0 0 16px;
}
.dl dt {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.dl dd {
  margin: 2px 0 0;
  font-weight: 600;
}
</style>
