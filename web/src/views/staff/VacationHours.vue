<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { declareHour, listHours, rejectHour, signHour, validateHour, type Hour } from '@/api/staff';
import type { useStaffDirectory } from './useStaffDirectory';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { currentMonth, formatMinutes } from '@/lib/hr';

// Saisie et validation des heures : déclarer, émarger (l'enseignant), valider ou rejeter (vacations.validate, jamais le déclarant).
const props = defineProps<{ dir: ReturnType<typeof useStaffDirectory> }>();
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const rows = ref<Hour[]>([]);
const loaded = ref(false);
const filters = reactive({ status: '', period: currentMonth(), staff_id: '' });
const list = useAction();
const act = useAction();
const declare = useAction();
const rejecting = ref<string | null>(null);
const reason = ref('');
const reasonTouched = ref(false);

const canValidate = computed(() => ctx.can('vacations.validate'));
const canManage = computed(() => ctx.can('vacations.manage'));
const form = reactive({ staff_id: '', held_on: todayIso(), minutes: 60, session_kind: 'td', content: '', signed: false });
const touched = ref(false);
const query = computed(() => ({ 'filter[status]': filters.status, 'filter[period]': filters.period, 'filter[staff_id]': filters.staff_id }));
const formOwn = computed(() => props.dir.isOwn(form.staff_id));
const periodBad = computed(() => filters.period !== '' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(filters.period));

const statusLabel = (s: string): string => t(`vac.hour.${s}` as MessageKey);
const tone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'validated' ? 'success' : s === 'rejected' ? 'warning' : 'neutral');
const mine = (h: Hour): boolean => props.dir.isOwn(h.staff_id);

async function load(): Promise<void> {
  if (periodBad.value) return;
  const res = await list.run(() => listHours(query.value));
  if (res) rows.value = res;
  loaded.value = true;
}

function replace(h: Hour): void {
  rows.value = rows.value.map((x) => (x.id === h.id ? { ...x, ...h } : x));
}

async function submitDeclare(): Promise<void> {
  touched.value = true;
  if (form.staff_id === '' || form.held_on === '' || !(form.minutes >= 1 && form.minutes <= 720)) return;
  const body: Record<string, unknown> = { staff_id: form.staff_id, held_on: form.held_on, minutes: Number(form.minutes), session_kind: form.session_kind };
  if (form.content.trim()) body.content = form.content.trim();
  if (form.signed && formOwn.value) body.signed = true;
  const res = await declare.run(() => declareHour(body));
  if (!res) return;
  touched.value = false;
  form.content = '';
  form.signed = false;
  await load();
}

async function sign(h: Hour): Promise<void> {
  const res = await act.run(() => signHour(h.id, h.content ? { content: h.content } : {}));
  if (res) replace(res);
}
async function validate(h: Hour): Promise<void> {
  const res = await act.run(() => validateHour(h.id));
  if (res) replace(res);
}
function startReject(h: Hour): void {
  rejecting.value = h.id;
  reason.value = '';
  reasonTouched.value = false;
}
async function confirmReject(h: Hour): Promise<void> {
  reasonTouched.value = true;
  if (reason.value.trim() === '') return;
  const res = await act.run(() => rejectHour(h.id, reason.value.trim()));
  if (res) {
    replace(res);
    rejecting.value = null;
  }
}

// La fiche de l'enseignant est présélectionnée dès que la liste du personnel est chargée.
watch(
  () => props.dir.staff.value,
  () => {
    if (form.staff_id === '' && !canManage.value && props.dir.ownIds.value.length > 0) form.staff_id = props.dir.ownIds.value[0] ?? '';
  },
  { immediate: true },
);
onMounted(load);
</script>

<template>
  <div data-test="hours">
    <div class="card">
      <div class="row row--between">
        <form class="row" @submit.prevent="load">
          <label class="field">
            <span class="label">{{ t('vac.filter.period') }}</span>
            <input v-model="filters.period" type="month" :aria-invalid="periodBad" @change="load" />
          </label>
          <label class="field">
            <span class="label">{{ t('staff.filter.status') }}</span>
            <select v-model="filters.status" @change="load">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="s in ['declared', 'validated', 'rejected']" :key="s" :value="s">{{ statusLabel(s) }}</option>
            </select>
          </label>
          <label v-if="dir.staff.value.length > 1" class="field">
            <span class="label">{{ t('vac.col.staff') }}</span>
            <select v-model="filters.staff_id" @change="load">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="s in dir.staff.value" :key="s.id" :value="s.id">{{ dir.nameOf(s.id) }}</option>
            </select>
          </label>
        </form>
        <ExportButton path="/vacation-hours/export" :query="query" name="heures" />
      </div>
      <ErrorNotice v-if="list.error.value || act.error.value" :error="list.error.value ?? act.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0" class="muted">{{ t('vac.hours.empty') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('vac.col.date') }}</th><th>{{ t('vac.col.staff') }}</th><th class="num">{{ t('vac.col.duration') }}</th><th>{{ t('teacher.col.kind') }}</th>
              <th>{{ t('vac.col.content') }}</th><th>{{ t('staff.col.status') }}</th><th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="h in rows" :key="h.id">
              <tr>
                <td>{{ formatDate(h.held_on, locale) }}</td>
                <td>{{ dir.nameOf(h.staff_id) }}</td>
                <td class="num">{{ formatMinutes(h.minutes) }}</td>
                <td>{{ t(`hr.session.${h.session_kind}` as MessageKey) }}</td>
                <td>{{ h.content ?? '—' }}</td>
                <td>
                  <StatusTag :tone="tone(h.status)">{{ statusLabel(h.status) }}</StatusTag>
                  <StatusTag v-if="h.status === 'declared' && h.teacher_signed_at" tone="success">{{ t('vac.hour.signed') }}</StatusTag>
                  <span v-if="h.rejection_reason" class="muted"> {{ h.rejection_reason }}</span>
                </td>
                <td class="row">
                  <button v-if="h.status === 'declared' && !h.teacher_signed_at && mine(h)" type="button" class="btn btn--quiet" data-action="sign" :disabled="act.busy.value" @click="sign(h)">{{ t('vac.action.sign') }}</button>
                  <button v-if="h.status === 'declared' && canValidate && h.declared_by !== auth.me?.id" type="button" class="btn btn--quiet" data-action="validate" :disabled="act.busy.value" @click="validate(h)">{{ t('vac.action.validate') }}</button>
                  <button v-if="h.status === 'declared' && canValidate" type="button" class="btn btn--quiet" data-action="reject" @click="startReject(h)">{{ t('vac.action.reject') }}</button>
                </td>
              </tr>
              <tr v-if="rejecting === h.id">
                <td colspan="7">
                  <form class="row" novalidate @submit.prevent="confirmReject(h)">
                    <label class="field spacer">
                      <span class="label">{{ t('vac.reject.reason') }}</span>
                      <input v-model="reason" type="text" :aria-invalid="reasonTouched && reason.trim() === ''" />
                      <span v-if="reasonTouched && reason.trim() === ''" class="error" role="alert">{{ t('vac.reject.required') }}</span>
                    </label>
                    <button type="submit" class="btn btn--ink" :disabled="act.busy.value">{{ t('vac.reject.confirm') }}</button>
                    <button type="button" class="btn btn--quiet" @click="rejecting = null">{{ t('hr.cancel') }}</button>
                  </form>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <form class="card" data-test="declare-form" novalidate @submit.prevent="submitDeclare">
      <h2>{{ t('vac.declare') }}</h2>
      <ErrorNotice v-if="declare.error.value" :error="declare.error.value" />
      <div class="grid grid--kpi">
        <label class="field">
          <span class="label">{{ t('vac.col.staff') }}</span>
          <select v-model="form.staff_id" :aria-invalid="touched && form.staff_id === ''">
            <option value="" disabled>{{ t('vac.choose') }}</option>
            <option v-for="s in dir.staff.value" :key="s.id" :value="s.id">{{ dir.nameOf(s.id) }}</option>
          </select>
          <span v-if="touched && form.staff_id === ''" class="error" role="alert">{{ t('vac.staffRequired') }}</span>
        </label>
        <label class="field"><span class="label">{{ t('vac.col.date') }}</span><input v-model="form.held_on" type="date" /></label>
        <label class="field">
          <span class="label">{{ t('vac.minutes') }}</span>
          <input v-model.number="form.minutes" type="number" min="1" max="720" :aria-invalid="touched && !(form.minutes >= 1 && form.minutes <= 720)" />
          <span v-if="touched && !(form.minutes >= 1 && form.minutes <= 720)" class="error" role="alert">{{ t('vac.minutesRange') }}</span>
        </label>
        <label class="field">
          <span class="label">{{ t('teacher.col.kind') }}</span>
          <select v-model="form.session_kind"><option v-for="k in ['cm', 'td', 'tp', 'other']" :key="k" :value="k">{{ t(`hr.session.${k}` as MessageKey) }}</option></select>
        </label>
      </div>
      <label class="field"><span class="label">{{ t('vac.col.content') }}</span><input v-model="form.content" type="text" maxlength="500" /></label>
      <label v-if="formOwn" class="check"><input v-model="form.signed" type="checkbox" /> {{ t('vac.signNow') }}</label>
      <button type="submit" class="btn btn--primary" :disabled="declare.busy.value">{{ t('vac.declare.submit') }}</button>
    </form>
  </div>
</template>
