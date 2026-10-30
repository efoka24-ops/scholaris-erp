<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useAction } from '@/views/admin/useAction';
import { http } from '@/api/http';
import type { Person } from '@/api/types';
import { createStaff, listStaff, type StaffMember } from '@/api/staff';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';

// Liste du personnel de l'établissement (un enseignant sans droit de lecture ne voit que sa fiche) — design : Dashboard.html, Frais.html (tableaux et filtres).
const { t, locale } = useI18n();
const ctx = useContextStore();

const rows = ref<StaffMember[]>([]);
const loaded = ref(false);
const filters = reactive({ kind: '', status: '' });
const list = useAction();
const create = useAction();
const search = useAction();

const canManage = computed(() => ctx.can('staff.manage'));
const exportQuery = computed(() => ({ 'filter[kind]': filters.kind, 'filter[status]': filters.status }));

const form = reactive({ person_id: '', kind: 'permanent', grade: '', category: '', employee_number: '', hired_on: '' });
const personQuery = ref('');
const found = ref<Person[]>([]);
const touched = ref(false);

const kindLabel = (k: string): string => t(`staff.kind.${k}` as MessageKey);
const statusLabel = (s: string): string => t(`staff.status.${s}` as MessageKey);
const tone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'active' ? 'success' : s === 'suspended' ? 'warning' : 'neutral');
const nameOf = (s: StaffMember): string => s.full_name || [s.first_name, s.last_name].filter(Boolean).join(' ') || s.person_id;

async function load(): Promise<void> {
  const res = await list.run(() => listStaff({ 'filter[kind]': filters.kind, 'filter[status]': filters.status }));
  if (res) rows.value = res;
  loaded.value = true;
}

async function searchPeople(): Promise<void> {
  const q = personQuery.value.trim();
  if (q === '') return;
  const res = await search.run(() => http.page<Person>('/people', { 'filter[q]': q }));
  if (res) {
    found.value = res.items;
    form.person_id = res.items[0]?.id ?? '';
  }
}

async function submit(): Promise<void> {
  touched.value = true;
  if (form.person_id === '') return;
  const body: Record<string, unknown> = { person_id: form.person_id, kind: form.kind };
  for (const k of ['grade', 'category', 'employee_number', 'hired_on'] as const) if (form[k].trim() !== '') body[k] = form[k].trim();
  const res = await create.run(() => createStaff(body));
  if (!res) return;
  Object.assign(form, { person_id: '', grade: '', category: '', employee_number: '', hired_on: '' });
  found.value = [];
  personQuery.value = '';
  touched.value = false;
  await load();
}

onMounted(load);
</script>

<template>
  <section class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('staff.eyebrow') }}</p>
      <h1>{{ t('staff.title') }}</h1>
      <p class="lede">{{ t('staff.lede') }}</p>
    </header>

    <div class="card">
      <div class="row row--between">
        <form class="row" @submit.prevent="load">
          <label class="field">
            <span class="label">{{ t('staff.filter.kind') }}</span>
            <select v-model="filters.kind" @change="load">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="k in ['permanent', 'vacataire', 'invite']" :key="k" :value="k">{{ kindLabel(k) }}</option>
            </select>
          </label>
          <label class="field">
            <span class="label">{{ t('staff.filter.status') }}</span>
            <select v-model="filters.status" @change="load">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="s in ['active', 'suspended', 'left']" :key="s" :value="s">{{ statusLabel(s) }}</option>
            </select>
          </label>
        </form>
        <ExportButton path="/staff/export" :query="exportQuery" name="personnel" />
      </div>

      <ErrorNotice v-if="list.error.value" :error="list.error.value" />
      <p v-if="!loaded" class="muted" role="status">{{ t('common.loading') }}</p>
      <p v-else-if="rows.length === 0 && !list.error.value" class="muted">{{ t('staff.empty') }}</p>
      <div v-else-if="rows.length > 0" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ t('staff.col.name') }}</th><th>{{ t('staff.col.kind') }}</th><th>{{ t('staff.col.status') }}</th>
              <th>{{ t('staff.col.grade') }}</th><th>{{ t('staff.col.category') }}</th><th>{{ t('staff.col.hired') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in rows" :key="s.id">
              <th scope="row"><router-link :to="{ name: 'staff-member', params: { id: s.id } }">{{ nameOf(s) }}</router-link></th>
              <td>{{ kindLabel(s.kind) }}</td>
              <td><StatusTag :tone="tone(s.status)">{{ statusLabel(s.status) }}</StatusTag></td>
              <td>{{ s.grade ?? '—' }}</td>
              <td>{{ s.category ?? '—' }}</td>
              <td>{{ formatDate(s.hired_on, locale) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <form v-if="canManage" class="card" data-test="create-form" novalidate @submit.prevent="submit">
      <h2>{{ t('staff.create') }}</h2>
      <ErrorNotice v-if="create.error.value || search.error.value" :error="create.error.value ?? search.error.value" />
      <div class="row">
        <label class="field spacer">
          <span class="label">{{ t('staff.create.person') }}</span>
          <input v-model="personQuery" type="search" :placeholder="t('staff.create.personHint')" @keydown.enter.prevent="searchPeople" />
        </label>
        <button type="button" class="btn" :disabled="search.busy.value" @click="searchPeople">{{ t('staff.create.search') }}</button>
      </div>
      <label v-if="found.length > 0" class="field">
        <span class="label">{{ t('staff.create.pick') }}</span>
        <select v-model="form.person_id">
          <option v-for="p in found" :key="p.id" :value="p.id">{{ p.first_name }} {{ p.last_name }}</option>
        </select>
      </label>
      <p v-if="touched && form.person_id === ''" class="error" role="alert">{{ t('staff.create.personRequired') }}</p>
      <div class="grid grid--kpi">
        <label class="field">
          <span class="label">{{ t('staff.col.kind') }}</span>
          <select v-model="form.kind"><option v-for="k in ['permanent', 'vacataire', 'invite']" :key="k" :value="k">{{ kindLabel(k) }}</option></select>
        </label>
        <label class="field"><span class="label">{{ t('staff.col.grade') }}</span><input v-model="form.grade" type="text" /></label>
        <label class="field"><span class="label">{{ t('staff.col.category') }}</span><input v-model="form.category" type="text" /></label>
        <label class="field"><span class="label">{{ t('staff.col.number') }}</span><input v-model="form.employee_number" type="text" /></label>
        <label class="field"><span class="label">{{ t('staff.col.hired') }}</span><input v-model="form.hired_on" type="date" /></label>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="create.busy.value">{{ t('staff.create.submit') }}</button>
    </form>
  </section>
</template>
