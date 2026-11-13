<script setup lang="ts">
// Personnel du secondaire (T911) : statut d'emploi des membres actifs (dossier du personnel du socle) et heures du mois : appel validé,
// validées ou rejetées par le censeur, signées au cahier de textes. Une heure sans appel ou sans séance signée n'est pas payable.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import { EMPLOYMENT_STATUSES, hoursOf, secondaryStaff, STAFF_CATEGORIES, type EmploymentStatus, type StaffCategory, type StaffHours, type StaffProfile } from './staffApi';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canManage = computed(() => can('secondary.settings.manage', 'staff.manage'));
const profiles = ref<StaffProfile[]>([]);
const hours = ref<StaffHours[]>([]);
const month = ref(new Date().toISOString().slice(0, 7));
const notice = ref('');
const category = ref<'' | StaffCategory>('');
const form = reactive({ personId: '', status: 'private_employee' as EmploymentStatus, staffCategory: '' as '' | StaffCategory, grade: '', payrollRef: '' });
const shown = computed(() => (category.value === '' ? profiles.value : profiles.value.filter((p) => p.staff_category === category.value)));
const categoryLabel = (c: StaffCategory | null): string => (c ? t(`secst.category.${c}` as MessageKey) : t('secst.notSet'));
const statusLabel = (status: EmploymentStatus | null): string => (status ? t(`secst.status.${status}` as MessageKey) : t('secst.notSet'));

function pick(personId: string): void {
  const profile = profiles.value.find((p) => p.person_id === personId);
  form.status = profile?.employment_status ?? 'private_employee';
  form.staffCategory = profile?.staff_category ?? '';
  form.grade = profile?.grade_label ?? '';
  form.payrollRef = profile?.payroll_ref ?? '';
}
async function loadHours(): Promise<void> {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month.value)) return;
  hours.value = (await run(() => secondaryStaff.hours(month.value))) ?? [];
}
async function load(): Promise<void> {
  profiles.value = (await run(() => secondaryStaff.profiles())) ?? [];
  await loadHours();
}
async function save(): Promise<void> {
  if (form.personId === '') return;
  const saved = await run(() => secondaryStaff.saveProfile({
    person_id: form.personId,
    employment_status: form.status,
    staff_category: form.staffCategory === '' ? null : form.staffCategory,
    grade_label: form.grade.trim() || null,
    payroll_ref: form.payrollRef.trim() || null,
  }));
  if (saved) {
    notice.value = t('secst.saved');
    form.personId = '';
    await load();
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secst.title') }}</h1>
      <p class="lede">{{ t('secst.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <section class="card" aria-labelledby="sst-profiles">
      <h2 id="sst-profiles">{{ t('secst.profiles') }}</h2>
      <form v-if="canManage && profiles.length > 0" class="stack" @submit.prevent="save">
        <div class="field">
          <label for="sst-member">{{ t('secst.member') }}</label>
          <select id="sst-member" v-model="form.personId" required @change="pick(form.personId)">
            <option value="">{{ t('secst.chooseMember') }}</option>
            <option v-for="p in profiles" :key="p.staff_id" :value="p.person_id">{{ p.full_name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sst-status">{{ t('secst.status') }}</label>
          <select id="sst-status" v-model="form.status">
            <option v-for="s in EMPLOYMENT_STATUSES" :key="s" :value="s">{{ statusLabel(s) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sst-category">{{ t('secst.category') }}</label>
          <select id="sst-category" v-model="form.staffCategory">
            <option value="">{{ t('secst.notSet') }}</option>
            <option v-for="c in STAFF_CATEGORIES" :key="c" :value="c">{{ categoryLabel(c) }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sst-grade">{{ t('secst.grade') }}</label>
          <input id="sst-grade" v-model="form.grade" type="text" maxlength="80" />
        </div>
        <div class="field">
          <label for="sst-ref">{{ t('secst.payrollRef') }}</label>
          <input id="sst-ref" v-model="form.payrollRef" type="text" maxlength="40" autocomplete="off" />
        </div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy || form.personId === ''">{{ t('secst.save') }}</button></div>
      </form>
      <div class="field">
        <label for="sst-filter">{{ t('secst.category') }}</label>
        <select id="sst-filter" v-model="category">
          <option value="">{{ t('secst.allCategories') }}</option>
          <option v-for="c in STAFF_CATEGORIES" :key="c" :value="c">{{ categoryLabel(c) }}</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secst.member') }}</th><th>{{ t('secst.function') }}</th><th>{{ t('secst.category') }}</th><th>{{ t('secst.status') }}</th><th>{{ t('secst.grade') }}</th><th>{{ t('secst.payrollRef') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in shown" :key="p.staff_id">
              <td>{{ p.full_name }}</td><td>{{ p.function || '—' }}</td><td>{{ categoryLabel(p.staff_category) }}</td><td>{{ statusLabel(p.employment_status) }}</td><td>{{ p.grade_label || '—' }}</td><td>{{ p.payroll_ref || '—' }}</td>
            </tr>
            <tr v-if="shown.length === 0"><td colspan="6">{{ t('secst.empty') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card" aria-labelledby="sst-hours">
      <h2 id="sst-hours">{{ t('secst.hours') }}</h2>
      <div class="field">
        <label for="sst-month">{{ t('secst.month') }}</label>
        <input id="sst-month" v-model="month" type="month" @change="loadHours" />
      </div>
      <p class="muted">{{ t('secst.hoursHelp') }}</p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ t('secst.member') }}</th><th>{{ t('secst.status') }}</th><th>{{ t('secst.declared') }}</th><th>{{ t('secst.validated') }}</th><th>{{ t('secst.rejected') }}</th><th>{{ t('secst.logged') }}</th></tr></thead>
          <tbody>
            <tr v-for="h in hours" :key="h.staff_id">
              <td>{{ h.full_name }}</td><td>{{ statusLabel(h.employment_status) }}</td><td>{{ hoursOf(h.declared_minutes) }}</td>
              <td>{{ hoursOf(h.validated_minutes) }}</td><td>{{ hoursOf(h.rejected_minutes) }}</td><td>{{ hoursOf(h.logged_minutes) }}</td>
            </tr>
            <tr v-if="hours.length === 0"><td colspan="6">{{ t('secst.noHours') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 10px; }
.table-wrap { overflow-x: auto; }
</style>
