<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Consent, Enrollment, Person } from '@/api/types';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';

// Fiche personne : identité, inscriptions (tous établissements du client), pièces et complétude, consentements.
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();

interface DocumentRow {
  id: string;
  kind: string;
  status: string;
  expires_on: string | null;
}
const person = ref<Person | null>(null);
const enrollments = ref<Enrollment[]>([]);
const documents = ref<DocumentRow[]>([]);
const completeness = ref<{ required: number; received: number; missing: number } | null>(null);
const consents = ref<Consent[]>([]);
const error = ref<unknown>(null);

async function load(): Promise<void> {
  error.value = null;
  try {
    person.value = (await http.get<{ data: Person }>(`/people/${props.id}`)).data;
  } catch (e) {
    error.value = e;
    return;
  }
  // Les sections secondaires peuvent être refusées selon le rôle : leur absence n'empêche pas d'afficher la fiche.
  const results = await Promise.allSettled([
    http.get<{ data: Enrollment[] }>(`/people/${props.id}/enrollments`),
    http.get<{ data: DocumentRow[]; meta?: { completeness?: { required: number; received: number; missing: number } } }>(`/people/${props.id}/documents`),
    http.get<{ data: Consent[] }>('/consents', { 'filter[person_id]': props.id }),
  ]);
  if (results[0].status === 'fulfilled') enrollments.value = results[0].value.data;
  if (results[1].status === 'fulfilled') {
    documents.value = results[1].value.data;
    completeness.value = results[1].value.meta?.completeness ?? null;
  }
  if (results[2].status === 'fulfilled') consents.value = results[2].value.data;
}

const docTone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'received' ? 'success' : s === 'expiring_soon' || s === 'expired' || s === 'missing' ? 'warning' : 'neutral');

onMounted(load);
</script>

<template>
  <div class="page narrow">
    <p><router-link :to="{ name: 'people' }">← {{ t('people.title') }}</router-link></p>
    <ErrorNotice :error="error" />
    <template v-if="person">
      <header class="page-head">
        <p class="eyebrow">{{ t('people.record') }}</p>
        <h1>{{ person.last_name.toUpperCase() }} {{ person.first_name }}</h1>
      </header>

      <section class="card" aria-labelledby="id-title">
        <h2 id="id-title">{{ t('people.identity') }}</h2>
        <dl class="facts">
          <div><dt class="eyebrow">{{ t('people.birthDate') }}</dt><dd>{{ formatDate(person.birth_date, locale) || '—' }}</dd></div>
          <div><dt class="eyebrow">{{ t('people.birthPlace') }}</dt><dd>{{ person.birth_place ?? '—' }}</dd></div>
          <div><dt class="eyebrow">{{ t('people.phone') }}</dt><dd>{{ person.phone ?? '—' }}</dd></div>
          <div><dt class="eyebrow">{{ t('people.email') }}</dt><dd>{{ person.email ?? '—' }}</dd></div>
          <div><dt class="eyebrow">{{ t('people.nationalId') }}</dt><dd>{{ person.national_id ?? '—' }}</dd></div>
        </dl>
      </section>

      <section class="card" aria-labelledby="enr-title">
        <h2 id="enr-title">{{ t('people.tabEnrollments') }}</h2>
        <p v-if="enrollments.length === 0" class="muted">{{ t('people.noneForPerson') }}</p>
        <ul v-else class="plain">
          <li v-for="e in enrollments" :key="e.id" class="row row--between">
            <span>{{ e.internal_number ?? e.id }}</span>
            <StatusTag :tone="e.status === 'active' ? 'success' : 'warning'">{{ e.status }}</StatusTag>
          </li>
        </ul>
      </section>

      <section class="card" aria-labelledby="doc-title">
        <h2 id="doc-title">{{ t('people.documents') }}</h2>
        <p v-if="completeness" class="progress">
          {{ t('people.completeness') }} : <strong>{{ completeness.received }} / {{ completeness.required }}</strong>
          <progress :value="completeness.received" :max="Math.max(completeness.required, 1)" :aria-label="t('people.completeness')" />
        </p>
        <ul class="plain">
          <li v-for="d in documents" :key="d.id" class="row row--between">
            <span>{{ d.kind }}<small v-if="d.expires_on" class="muted"> · {{ t('people.expires', { date: formatDate(d.expires_on, locale) }) }}</small></span>
            <StatusTag :tone="docTone(d.status)">{{ d.status }}</StatusTag>
          </li>
        </ul>
      </section>

      <section class="card" aria-labelledby="con-title">
        <h2 id="con-title">{{ t('people.consents') }}</h2>
        <p v-if="consents.length === 0" class="muted">{{ t('people.noConsents') }}</p>
        <ul v-else class="plain">
          <li v-for="c in consents" :key="c.id" class="row row--between">
            <span>{{ c.purpose }}</span>
            <StatusTag :tone="c.revoked_at ? 'neutral' : 'success'">{{ c.revoked_at ? t('people.revoked') : t('people.given') }}</StatusTag>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 820px;
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75rem;
  margin: 0;
}
.facts dd {
  margin: 0;
  font-weight: 600;
}
.plain {
  list-style: none;
  padding: 0;
  margin: 0;
}
.plain li {
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border);
}
progress {
  width: 100%;
  height: 12px;
  display: block;
  margin-top: 0.25rem;
}
</style>
