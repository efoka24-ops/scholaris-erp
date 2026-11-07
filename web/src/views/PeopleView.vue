<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { ApiError, http } from '@/api/http';
import type { Enrollment, Person } from '@/api/types';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { useVocabulary } from '@/composables/useVocabulary';
import { formatDate } from '@/lib/format';

// Fiches personnes (recherche, création avec détection de doublons) et inscriptions de l'établissement.
const { t, locale } = useI18n();
const ctx = useContextStore();
const router = useRouter();
const { term } = useVocabulary();

const tab = ref<'people' | 'enrollments'>('people');
const q = ref('');
const people = ref<Person[]>([]);
const cursor = ref<string | null>(null);
const enrollments = ref<Enrollment[]>([]);
const enrCursor = ref<string | null>(null);
const statusFilter = ref('');
const loading = ref(false);
const error = ref<unknown>(null);

const showForm = ref(false);
const form = reactive({ first_name: '', last_name: '', birth_date: '', phone: '', email: '' });
const duplicates = ref<Array<{ id?: string; person?: Person; criteria?: number; summary?: string }>>([]);
const canManage = computed(() => ctx.can('people.manage'));

async function searchPeople(append = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const { items, nextCursor } = await http.page<Person>('/people', { 'filter[q]': q.value.trim(), cursor: append ? cursor.value : null });
    people.value = append ? [...people.value, ...items] : items;
    cursor.value = nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function loadEnrollments(append = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const { items, nextCursor } = await http.page<Enrollment>('/enrollments', { 'filter[status]': statusFilter.value, cursor: append ? enrCursor.value : null });
    enrollments.value = append ? [...enrollments.value, ...items] : items;
    enrCursor.value = nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function create(confirmDifferent = false): Promise<void> {
  error.value = null;
  loading.value = true;
  try {
    const body: Record<string, unknown> = { first_name: form.first_name.trim(), last_name: form.last_name.trim() };
    if (form.birth_date) body.birth_date = form.birth_date;
    if (form.phone) body.phone = form.phone.trim();
    if (form.email) body.email = form.email.trim();
    if (confirmDifferent) body.confirm_different = true;
    const res = await http.post<{ data: Person }>('/people', body);
    duplicates.value = [];
    await router.push({ name: 'person', params: { id: res.data.id } });
  } catch (e) {
    if (e instanceof ApiError && e.status === 409 && Array.isArray(e.problem?.duplicates)) {
      duplicates.value = e.problem.duplicates as typeof duplicates.value;
    } else {
      error.value = e;
    }
  } finally {
    loading.value = false;
  }
}

const dupPerson = (d: (typeof duplicates.value)[number]): Person | undefined => d.person ?? (d as unknown as Person);

watch(tab, (v) => {
  if (v === 'enrollments' && enrollments.value.length === 0) void loadEnrollments();
});
onMounted(() => searchPeople());
</script>

<template>
  <div class="page">
    <header class="page-head row row--between">
      <div>
        <p class="eyebrow">{{ t('people.eyebrow') }}</p>
        <h1>{{ t('people.title') }}</h1>
        <router-link v-if="ctx.can('people.manage')" class="btn btn--primary" :to="{ name: 'enroll' }">{{ t('enroll.title') }}</router-link>
      </div>
      <button v-if="canManage" type="button" class="btn btn--primary" :aria-expanded="showForm" @click="showForm = !showForm">{{ t('people.new') }}</button>
    </header>

    <ErrorNotice :error="error" />

    <form v-if="showForm" class="card" novalidate @submit.prevent="create(false)">
      <h2>{{ t('people.new') }}</h2>
      <div class="grid grid--kpi">
        <div class="field"><label for="p-ln">{{ t('people.lastName') }}</label><input id="p-ln" v-model="form.last_name" type="text" required autocomplete="off" /></div>
        <div class="field"><label for="p-fn">{{ t('people.firstName') }}</label><input id="p-fn" v-model="form.first_name" type="text" required autocomplete="off" /></div>
        <div class="field"><label for="p-bd">{{ t('people.birthDate') }}</label><input id="p-bd" v-model="form.birth_date" type="date" /></div>
        <div class="field"><label for="p-ph">{{ t('people.phone') }}</label><input id="p-ph" v-model="form.phone" type="tel" /></div>
        <div class="field"><label for="p-em">{{ t('people.email') }}</label><input id="p-em" v-model="form.email" type="email" /></div>
      </div>
      <div v-if="duplicates.length" class="notice notice--warning" role="alert">
        <strong>{{ t('people.duplicateTitle') }}</strong>
        <ul>
          <li v-for="(d, i) in duplicates" :key="i">
            <template v-if="dupPerson(d)">{{ dupPerson(d)?.last_name }} {{ dupPerson(d)?.first_name }}</template>
            <template v-if="d.criteria"> · {{ t('people.criteria', { count: d.criteria }) }}</template>
            <router-link v-if="dupPerson(d)?.id" class="btn" :to="{ name: 'person', params: { id: dupPerson(d)?.id } }">{{ t('people.sameOne') }}</router-link>
          </li>
        </ul>
        <button type="button" class="btn" :disabled="loading" @click="create(true)">{{ t('people.differentOne') }}</button>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="loading || !form.first_name.trim() || !form.last_name.trim()">{{ t('people.create') }}</button>
    </form>

    <div class="tabs" role="tablist">
      <button role="tab" type="button" :aria-selected="tab === 'people'" @click="tab = 'people'">{{ t('people.tabPeople') }}</button>
      <button role="tab" type="button" :aria-selected="tab === 'enrollments'" @click="tab = 'enrollments'">{{ t('people.tabEnrollments') }}</button>
    </div>

    <section v-if="tab === 'people'">
      <form class="row" role="search" @submit.prevent="searchPeople()">
        <div class="field grow">
          <label for="p-q">{{ t('people.search') }}</label>
          <input id="p-q" v-model="q" type="search" :placeholder="t('people.searchHint')" />
        </div>
        <button type="submit" class="btn btn--ink go">{{ t('people.searchGo') }}</button>
      </form>
      <ExportButton path="/people/export" :query="{ 'filter[q]': q.trim() }" name="personnes" />
      <p v-if="!loading && people.length === 0" class="card muted">{{ t('people.none') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('people.name') }}</th><th scope="col">{{ t('people.birthDate') }}</th><th scope="col">{{ t('people.phone') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
          <tbody>
            <tr v-for="p in people" :key="p.id">
              <th scope="row">{{ p.last_name.toUpperCase() }} {{ p.first_name }}</th>
              <td>{{ formatDate(p.birth_date, locale) }}</td>
              <td>{{ p.phone }}</td>
              <td><router-link class="btn" :to="{ name: 'person', params: { id: p.id } }">{{ t('people.open') }}</router-link></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button v-if="cursor" type="button" class="btn" :disabled="loading" @click="searchPeople(true)">{{ t('common.more') }}</button>
    </section>

    <section v-else>
      <div class="field">
        <label for="e-status">{{ t('people.status') }}</label>
        <select id="e-status" v-model="statusFilter" @change="loadEnrollments()">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="s in ['draft', 'active', 'conditional', 'suspended', 'withdrawn', 'transferred', 'excluded', 'graduated']" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <ExportButton path="/enrollments/export" :query="{ 'filter[status]': statusFilter }" name="inscriptions" />
      <p v-if="!loading && enrollments.length === 0" class="card muted">{{ t('people.noEnrollments', { learners: term('learner') }) }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('people.number') }}</th><th scope="col">{{ t('people.status') }}</th><th scope="col">{{ t('people.completeness') }}</th><th scope="col">{{ t('people.docsDue') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
          <tbody>
            <tr v-for="e in enrollments" :key="e.id">
              <th scope="row">{{ e.internal_number ?? '—' }}</th>
              <td><StatusTag :tone="e.status === 'active' ? 'success' : e.status === 'draft' || e.status === 'conditional' ? 'warning' : 'neutral'">{{ e.status }}</StatusTag></td>
              <td>{{ typeof e.completeness === 'number' ? `${e.completeness} %` : '—' }}</td>
              <td>{{ formatDate(e.documents_due_on, locale) }}</td>
              <td><router-link class="btn" :to="{ name: 'person', params: { id: e.person_id } }">{{ t('people.open') }}</router-link></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button v-if="enrCursor" type="button" class="btn" :disabled="loading" @click="loadEnrollments(true)">{{ t('common.more') }}</button>
    </section>
  </div>
</template>

<style scoped>
.tabs {
  display: flex;
  border-bottom: 1px solid var(--ink);
  margin: 1rem 0;
}
.tabs button {
  min-height: var(--tap);
  padding: 0 1rem;
  font: inherit;
  font-weight: 700;
  background: transparent;
  border: 1px solid transparent;
  border-bottom: 0;
  cursor: pointer;
}
.tabs button[aria-selected='true'] {
  background: var(--surface);
  border-color: var(--ink);
  border-bottom: 3px solid var(--accent);
}
.grow {
  flex: 1;
  min-width: 220px;
}
.go {
  align-self: flex-end;
  margin-bottom: 0.9rem;
}
</style>
