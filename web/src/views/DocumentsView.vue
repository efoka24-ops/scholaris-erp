<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ExportButton from '@/components/ExportButton.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { IssuedDocument } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Registre des documents émis (numéro, titulaire, statut, vérification publique) et demandes en ligne.
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const view = ref<'all' | 'bulletins' | 'certificates' | 'revoked'>('all');
const docs = ref<IssuedDocument[]>([]);
const types = ref<Array<{ id: string; code: string; label?: string; name?: string }>>([]);
const requestType = ref('');
const note = ref('');
const loading = ref(false);
const error = ref<unknown>(null);
const info = ref<string | null>(null);

const canList = computed(() => ctx.can('documents.read'));
const canRequest = computed(() => ctx.can('documents.request'));

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    if (canList.value) docs.value = await http.all<IssuedDocument>('/documents', { 'filter[view]': view.value === 'all' ? '' : view.value }, {}, 5);
    if (canRequest.value) types.value = await http.all('/document-types', {}, {}, 2);
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function requestDocument(): Promise<void> {
  if (!auth.me?.person_id || !requestType.value) return;
  error.value = null;
  info.value = null;
  try {
    await http.post('/document-requests', { type: requestType.value, subject_person_id: auth.me.person_id, note: note.value.trim() || undefined });
    info.value = t('documents.requested');
    note.value = '';
  } catch (e) {
    error.value = e;
  }
}

async function preview(id: string): Promise<void> {
  error.value = null;
  try {
    const blob = await http.get<Blob>(`/documents/${id}/preview`, undefined, { expect: 'blob' });
    window.open(URL.createObjectURL(blob), '_blank', 'noopener');
  } catch (e) {
    error.value = e;
  }
}

const tone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'valid' ? 'success' : s === 'revoked' ? 'warning' : 'neutral');

watch(view, load);
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('documents.eyebrow') }}</p>
      <h1>{{ t('documents.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="info" class="notice notice--success" role="status">{{ info }}</p>

    <section v-if="canRequest" class="card narrow" aria-labelledby="req-title">
      <h2 id="req-title">{{ t('documents.request') }}</h2>
      <div class="field">
        <label for="d-type">{{ t('documents.type') }}</label>
        <select id="d-type" v-model="requestType">
          <option value="" disabled>{{ t('grades.choose') }}</option>
          <option v-for="ty in types" :key="ty.id" :value="ty.code">{{ ty.label ?? ty.name ?? ty.code }}</option>
        </select>
      </div>
      <div class="field"><label for="d-note">{{ t('documents.note') }}</label><textarea id="d-note" v-model="note" maxlength="500" /></div>
      <button type="button" class="btn btn--primary" :disabled="!requestType || !auth.me?.person_id" @click="requestDocument">{{ t('documents.requestGo') }}</button>
    </section>

    <section v-if="canList" aria-labelledby="reg-title">
      <h2 id="reg-title">{{ t('documents.registry') }}</h2>
      <div class="filters" role="group" :aria-label="t('documents.filters')">
        <button v-for="v in ['all', 'bulletins', 'certificates', 'revoked'] as const" :key="v" type="button" :aria-pressed="view === v" @click="view = v">{{ t(`documents.view.${v}`) }}</button>
      </div>
      <ExportButton path="/documents/export" :query="{ 'filter[view]': view === 'all' ? '' : view }" name="documents" />
      <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('documents.number') }}</th><th scope="col">{{ t('documents.holder') }}</th><th scope="col">{{ t('documents.issued') }}</th><th scope="col">{{ t('billing.status') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
          <tbody>
            <tr v-if="!loading && docs.length === 0"><td colspan="5" class="muted">{{ t('documents.none') }}</td></tr>
            <tr v-for="d in docs" :key="d.id">
              <th scope="row">{{ d.number }}</th>
              <td>{{ d.holder_name }}</td>
              <td>{{ formatDateTime(d.issued_at, locale) }}<small v-if="d.issued_via" class="muted"> · {{ d.issued_via }}</small></td>
              <td><StatusTag :tone="tone(d.status)">{{ d.status }}</StatusTag></td>
              <td><button type="button" class="btn" @click="preview(d.id)">{{ t('documents.preview') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 560px;
  margin-bottom: 1.5rem;
}
.filters {
  display: flex;
  flex-wrap: wrap;
  margin: 0.5rem 0 1rem;
}
.filters button {
  min-height: var(--tap);
  padding: 0 1rem;
  font: inherit;
  font-weight: 700;
  background: var(--surface);
  border: 1px solid var(--ink);
  cursor: pointer;
}
.filters button[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
}
</style>
