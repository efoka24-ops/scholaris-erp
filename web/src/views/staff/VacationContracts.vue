<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { contractAction, createContract, listContracts, type Contract } from '@/api/staff';
import type { useStaffDirectory } from './useStaffDirectory';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import { formatMinutes } from '@/lib/hr';

// Contrats de vacation : brouillon, soumission, validation à deux personnes, résiliation.
const props = defineProps<{ dir: ReturnType<typeof useStaffDirectory> }>();
const { t, locale } = useI18n();
const ctx = useContextStore();

const rows = ref<Contract[]>([]);
const loaded = ref(false);
const status = ref('');
const list = useAction();
const act = useAction();
const create = useAction();
const touched = ref(false);
const form = reactive({ staff_id: '', starts_on: '', ends_on: '', hourly_rate: 0, max_minutes: 0 });

const canManage = computed(() => ctx.can('vacations.manage'));
const canValidate = computed(() => ctx.can('vacations.validate'));
const statusLabel = (s: string): string => t(`vac.contract.${s}` as MessageKey);
const tone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'active' ? 'success' : s === 'rejected' ? 'warning' : 'neutral');
const errors = computed(() => ({
  staff: touched.value && form.staff_id === '',
  dates: touched.value && (form.starts_on === '' || form.ends_on === '' || form.ends_on < form.starts_on),
  rate: touched.value && !(form.hourly_rate >= 1),
  max: touched.value && !(form.max_minutes >= 1),
}));

async function load(): Promise<void> {
  const res = await list.run(() => listContracts({ 'filter[status]': status.value }));
  if (res) rows.value = res;
  loaded.value = true;
}
async function submit(): Promise<void> {
  touched.value = true;
  if (Object.values(errors.value).some(Boolean)) return;
  const res = await create.run(() => createContract({ staff_id: form.staff_id, starts_on: form.starts_on, ends_on: form.ends_on, hourly_rate: Number(form.hourly_rate), max_minutes: Number(form.max_minutes) }));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { starts_on: '', ends_on: '', hourly_rate: 0, max_minutes: 0 });
  await load();
}
async function run(c: Contract, action: 'submit' | 'approve' | 'reject' | 'terminate'): Promise<void> {
  const res = await act.run(() => contractAction(c.id, action));
  if (res) rows.value = rows.value.map((x) => (x.id === c.id ? { ...x, ...res } : x));
}

onMounted(load);
</script>

<template>
  <div data-test="contracts">
    <div class="card">
      <label class="field">
        <span class="label">{{ t('staff.filter.status') }}</span>
        <select v-model="status" @change="load">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="s in ['draft', 'pending_approval', 'active', 'rejected', 'terminated']" :key="s" :value="s">{{ statusLabel(s) }}</option>
        </select>
      </label>
      <ErrorNotice v-if="list.error.value || act.error.value" :error="list.error.value ?? act.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0" class="muted">{{ t('vac.contracts.empty') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('vac.col.reference') }}</th><th>{{ t('vac.col.staff') }}</th><th>{{ t('vac.col.period') }}</th>
              <th class="num">{{ t('vac.col.rate') }}</th><th class="num">{{ t('vac.col.cap') }}</th><th>{{ t('staff.col.status') }}</th><th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in rows" :key="c.id">
              <th scope="row">{{ c.reference }}</th>
              <td>{{ props.dir.nameOf(c.staff_id) }}</td>
              <td>{{ formatDate(c.starts_on, locale) }} → {{ formatDate(c.ends_on, locale) }}</td>
              <td class="num">{{ formatXaf(c.hourly_rate, locale) }}</td>
              <td class="num">{{ formatMinutes(c.max_minutes) }}</td>
              <td><StatusTag :tone="tone(c.status)">{{ statusLabel(c.status) }}</StatusTag></td>
              <td class="row">
                <button v-if="canManage && (c.status === 'draft' || c.status === 'rejected')" type="button" class="btn btn--quiet" data-action="submit" :disabled="act.busy.value" @click="run(c, 'submit')">{{ t('vac.action.submit') }}</button>
                <button v-if="canValidate && c.status === 'pending_approval'" type="button" class="btn btn--quiet" data-action="approve" :disabled="act.busy.value" @click="run(c, 'approve')">{{ t('vac.action.approve') }}</button>
                <button v-if="canValidate && c.status === 'pending_approval'" type="button" class="btn btn--quiet" data-action="reject" :disabled="act.busy.value" @click="run(c, 'reject')">{{ t('vac.action.reject') }}</button>
                <button v-if="canManage && c.status === 'active'" type="button" class="btn btn--quiet" data-action="terminate" :disabled="act.busy.value" @click="run(c, 'terminate')">{{ t('vac.action.terminate') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <form v-if="canManage" class="card" data-test="contract-form" novalidate @submit.prevent="submit">
      <h2>{{ t('vac.contract.create') }}</h2>
      <ErrorNotice v-if="create.error.value" :error="create.error.value" />
      <div class="grid grid--kpi">
        <label class="field">
          <span class="label">{{ t('vac.col.staff') }}</span>
          <select v-model="form.staff_id" :aria-invalid="errors.staff">
            <option value="" disabled>{{ t('vac.choose') }}</option>
            <option v-for="s in props.dir.staff.value" :key="s.id" :value="s.id">{{ props.dir.nameOf(s.id) }}</option>
          </select>
          <span v-if="errors.staff" class="error" role="alert">{{ t('vac.staffRequired') }}</span>
        </label>
        <label class="field"><span class="label">{{ t('vac.contract.starts') }}</span><input v-model="form.starts_on" type="date" :aria-invalid="errors.dates" /></label>
        <label class="field"><span class="label">{{ t('vac.contract.ends') }}</span><input v-model="form.ends_on" type="date" :aria-invalid="errors.dates" /></label>
        <label class="field"><span class="label">{{ t('vac.col.rate') }}</span><input v-model.number="form.hourly_rate" type="number" min="1" :aria-invalid="errors.rate" /></label>
        <label class="field"><span class="label">{{ t('vac.contract.maxMinutes') }}</span><input v-model.number="form.max_minutes" type="number" min="1" :aria-invalid="errors.max" /></label>
      </div>
      <p v-if="errors.dates" class="error" role="alert">{{ t('vac.contract.datesInvalid') }}</p>
      <p v-if="errors.rate || errors.max" class="error" role="alert">{{ t('vac.contract.numbersInvalid') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="create.busy.value">{{ t('vac.contract.submit') }}</button>
    </form>
  </div>
</template>
