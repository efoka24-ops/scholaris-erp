<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Consent, Enrollment, Person } from '@/api/types';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { documentStatusLabel, enrollmentStatusLabel } from '@/lib/peopleStatus';
import { childrenOf, guardiansOf, type RelatedPerson, type RelationRow } from '@/lib/personRelations';

// Fiche personne : identité, parents et garants (ou enfants pour un parent), inscriptions (tous établissements du client), pièces et
// complétude, consentements.
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
// FIX-R32 : liens familiaux lus sur GET /people/{id}/relations (mêmes droits de lecture que la fiche).
const relations = ref<RelationRow[]>([]);
const guardians = computed(() => guardiansOf(relations.value));
const children = computed(() => childrenOf(relations.value));
const kindsLabel = (p: RelatedPerson): string => {
  const kinds = p.kinds.length > 0 ? p.kinds : p.financialGuarantor ? ['financial_guarantor'] : [];
  return kinds.map((k) => (['parent', 'guardian', 'financial_guarantor', 'authorized_pickup', 'emergency'].includes(k) ? t(`people.kind.${k}` as MessageKey) : k)).join(' · ') || '—';
};
const error = ref<unknown>(null);

async function load(): Promise<void> {
  error.value = null;
  relations.value = [];
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
    http.get<{ data: RelationRow[] }>(`/people/${props.id}/relations`),
  ]);
  if (results[0].status === 'fulfilled') enrollments.value = results[0].value.data;
  if (results[1].status === 'fulfilled') {
    documents.value = results[1].value.data;
    completeness.value = results[1].value.meta?.completeness ?? null;
  }
  if (results[2].status === 'fulfilled') consents.value = results[2].value.data;
  if (results[3].status === 'fulfilled') relations.value = results[3].value.data;
}

const docTone = (s: string): 'success' | 'warning' | 'neutral' => (s === 'received' ? 'success' : s === 'expiring_soon' || s === 'expired' || s === 'missing' ? 'warning' : 'neutral');

onMounted(load);
// Le lien vers la fiche d'un parent ou d'un enfant réutilise cette vue : on recharge quand l'identifiant change.
watch(() => props.id, load);
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

      <section class="card" aria-labelledby="fam-title">
        <h2 id="fam-title">{{ t('people.family') }}</h2>
        <p v-if="guardians.length === 0 && children.length === 0" class="muted">{{ t('people.familyNone') }}</p>
        <template v-for="block in [{ key: 'guardians', rows: guardians, title: t('people.familyGuardians') }, { key: 'children', rows: children, title: t('people.familyChildren') }]" :key="block.key">
          <div v-if="block.rows.length > 0" class="family" :data-test="`family-${block.key}`">
            <h3 class="eyebrow">{{ block.title }}</h3>
            <ul class="plain">
              <li v-for="p in block.rows" :key="p.personId" class="family-row">
                <router-link :to="{ name: 'person', params: { id: p.personId } }" class="family-name">{{ p.name }}</router-link>
                <span><span class="sr-only">{{ t('people.familyLink') }} : </span>{{ kindsLabel(p) }}</span>
                <span><span class="sr-only">{{ t('people.phone') }} : </span>{{ p.phone ?? '—' }}</span>
                <StatusTag :tone="p.financialGuarantor ? 'success' : 'neutral'">{{ t('people.familyGuarantor') }} : {{ p.financialGuarantor ? t('people.familyYes') : t('people.familyNo') }}</StatusTag>
              </li>
            </ul>
          </div>
        </template>
      </section>

      <section class="card" aria-labelledby="enr-title">
        <h2 id="enr-title">{{ t('people.tabEnrollments') }}</h2>
        <p v-if="enrollments.length === 0" class="muted">{{ t('people.noneForPerson') }}</p>
        <ul v-else class="plain">
          <li v-for="e in enrollments" :key="e.id" class="row row--between">
            <span>{{ e.internal_number ?? e.id }}</span>
            <StatusTag :tone="e.status === 'active' ? 'success' : 'warning'">{{ enrollmentStatusLabel(e.status, t) }}</StatusTag>
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
            <StatusTag :tone="docTone(d.status)">{{ documentStatusLabel(d.status, t) }}</StatusTag>
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
.family + .family {
  margin-top: 0.75rem;
}
.family h3 {
  margin: 0 0 0.25rem;
}
.family-row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1.4fr) minmax(0, 1.2fr) auto;
  gap: 0.5rem;
  align-items: center;
}
.family-name {
  font-weight: 600;
  overflow-wrap: anywhere;
}
@media (max-width: 600px) {
  .family-row {
    grid-template-columns: 1fr auto;
  }
}
progress {
  width: 100%;
  height: 12px;
  display: block;
  margin-top: 0.25rem;
}
</style>
