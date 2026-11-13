<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from '@/views/admin/useAction';
import { listRates, putRate, type Rate } from '@/api/staff';
import type { useStaffDirectory } from './useStaffDirectory';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import { formatMinutes } from '@/lib/hr';

// Taux horaires (XAF par heure d'équivalent TD) et plafonds mensuels par catégorie.
defineProps<{ dir: ReturnType<typeof useStaffDirectory> }>();
const { t, locale } = useI18n();
const ctx = useContextStore();

const rows = ref<Rate[]>([]);
const loaded = ref(false);
const list = useAction();
const save = useAction();
const touched = ref(false);
const form = reactive({ category: '', hourly_rate: 0, monthly_cap_minutes: 0 });
const canManage = computed(() => ctx.can('vacations.manage'));

async function load(): Promise<void> {
  const res = await list.run(listRates);
  if (res) rows.value = res;
  loaded.value = true;
}
function edit(r: Rate): void {
  Object.assign(form, { category: r.category, hourly_rate: r.hourly_rate, monthly_cap_minutes: r.monthly_cap_minutes ?? 0 });
}
async function submit(): Promise<void> {
  touched.value = true;
  if (form.category.trim() === '' || !(form.hourly_rate >= 1) || !(form.monthly_cap_minutes >= 1)) return;
  const res = await save.run(() => putRate({ category: form.category.trim(), hourly_rate: Number(form.hourly_rate), monthly_cap_minutes: Number(form.monthly_cap_minutes) }));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { category: '', hourly_rate: 0, monthly_cap_minutes: 0 });
  await load();
}
onMounted(load);
</script>

<template>
  <div data-test="rates">
    <div class="card">
      <ErrorNotice v-if="list.error.value" :error="list.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0 && !list.error.value" class="muted">{{ t('vac.rates.empty') }}</p>
      <div v-else-if="rows.length > 0" class="table-wrap">
        <table>
          <thead><tr><th>{{ t('staff.col.category') }}</th><th class="num">{{ t('vac.col.rate') }}</th><th class="num">{{ t('vac.col.monthlyCap') }}</th><th v-if="canManage" /></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id">
              <th scope="row">{{ r.category }}</th>
              <td class="num">{{ formatXaf(r.hourly_rate, locale) }}</td>
              <td class="num">{{ r.monthly_cap_minutes === null ? '—' : formatMinutes(r.monthly_cap_minutes) }}</td>
              <td v-if="canManage"><button type="button" class="btn btn--quiet" @click="edit(r)">{{ t('hr.edit') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <form v-if="canManage" class="card" novalidate @submit.prevent="submit">
      <h2>{{ t('vac.rates.set') }}</h2>
      <ErrorNotice v-if="save.error.value" :error="save.error.value" />
      <div class="grid grid--kpi">
        <label class="field"><span class="label">{{ t('staff.col.category') }}</span><input v-model="form.category" type="text" :aria-invalid="touched && form.category.trim() === ''" /></label>
        <label class="field"><span class="label">{{ t('vac.col.rate') }}</span><input v-model.number="form.hourly_rate" type="number" min="1" :aria-invalid="touched && !(form.hourly_rate >= 1)" /></label>
        <label class="field"><span class="label">{{ t('vac.rates.capMinutes') }}</span><input v-model.number="form.monthly_cap_minutes" type="number" min="1" :aria-invalid="touched && !(form.monthly_cap_minutes >= 1)" /></label>
      </div>
      <p v-if="touched && (form.category.trim() === '' || !(form.hourly_rate >= 1) || !(form.monthly_cap_minutes >= 1))" class="error" role="alert">{{ t('vac.rates.invalid') }}</p>
      <button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('vac.rates.save') }}</button>
    </form>
  </div>
</template>
