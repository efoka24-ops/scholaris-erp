<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { ApiError, http } from '@/api/http';
import type { Consent, Enrollment, Person } from '@/api/types';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate } from '@/lib/format';
import { documentStatusLabel, enrollmentStatusLabel } from '@/lib/peopleStatus';
import {
  BILLING_KINDS,
  RELATION_KINDS,
  RESPONSIBLE_KINDS,
  childrenOf,
  guardiansOf,
  remainsAfter,
  subjectLinks,
  type RelatedPerson,
  type RelationRow,
  type SubjectLink,
} from '@/lib/personRelations';
import { useContextStore } from '@/stores/context';

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
  resetForm();
  notice.value = '';
  guarantorInfo.value = false;
  familyError.value = null;
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

// GAR-FORM : ajouter, changer ou retirer un lien (POST et DELETE /people/{id}/relations, people.manage côté serveur). Changer un
// lien = enregistrer le nouveau type puis retirer l'ancien. Billing lit les liens au moment de chaque opération : les factures déjà
// émises restent celles de l'élève, les relances et l'affectation des paiements par référence famille suivent les liens actuels.
const ctx = useContextStore();
const canManage = computed(() => ctx.can('people.manage'));
const links = computed(() => subjectLinks(relations.value));
const kindLabel = (k: string): string => ((RELATION_KINDS as string[]).includes(k) ? t(`people.kind.${k}` as MessageKey) : k);
const isLearner = computed(() => enrollments.value.length > 0 || links.value.length > 0);
const noResponsible = computed(() => isLearner.value && !links.value.some((l) => RESPONSIBLE_KINDS.includes(l.kind)));
const busy = ref(false);
const notice = ref('');
const guarantorInfo = ref(false);
const familyError = ref<unknown>(null);
const changeKinds = reactive<Record<string, string>>({});

interface Candidate {
  person: Person;
  label?: string;
}
const form = reactive({ mode: 'existing' as 'existing' | 'new', q: '', kind: 'parent', last_name: '', first_name: '', phone: '', replaceGuarantors: false });
const results = ref<Person[]>([]);
const searched = ref(false);
const chosen = ref<{ id: string; name: string } | null>(null);
const duplicates = ref<Candidate[]>([]);
const personName = (p: Pick<Person, 'first_name' | 'last_name'>): string => [(p.last_name ?? '').toUpperCase(), p.first_name ?? ''].filter((x) => x !== '').join(' ');
// Garants financiers actuels qu'on peut remplacer d'un coup (cas de l'oncle qui paie désormais à la place du père).
const currentGuarantors = computed(() => links.value.filter((l) => l.kind === 'financial_guarantor' && l.personId !== chosen.value?.id));

function resetForm(): void {
  Object.assign(form, { mode: 'existing', q: '', kind: 'parent', last_name: '', first_name: '', phone: '', replaceGuarantors: false });
  results.value = [];
  searched.value = false;
  chosen.value = null;
  duplicates.value = [];
}

async function reloadRelations(): Promise<void> {
  relations.value = (await http.get<{ data: RelationRow[] }>(`/people/${props.id}/relations`)).data;
  for (const key of Object.keys(changeKinds)) delete changeKinds[key];
}

async function guarded(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  familyError.value = null;
  notice.value = '';
  guarantorInfo.value = false;
  try {
    await action();
  } catch (e) {
    familyError.value = e;
  } finally {
    busy.value = false;
  }
}

async function search(): Promise<void> {
  const q = form.q.trim();
  if (q === '') return;
  await guarded(async () => {
    const { items } = await http.page<Person>('/people', { 'filter[q]': q });
    results.value = items.filter((p) => p.id !== props.id);
    searched.value = true;
  });
}

function choose(p: Person): void {
  chosen.value = { id: p.id, name: personName(p) };
  duplicates.value = [];
}

/** Personne à lier : celle choisie, ou une nouvelle (nom, prénom, téléphone). null si un doublon probable attend un choix. */
async function relatedPersonId(confirmDifferent: boolean): Promise<string | null> {
  if (form.mode === 'existing' || chosen.value) return chosen.value?.id ?? null;
  const body: Record<string, unknown> = { last_name: form.last_name.trim(), first_name: form.first_name.trim() };
  if (form.phone.trim() !== '') body.phone = form.phone.trim();
  if (confirmDifferent) body.confirm_different = true;
  try {
    const created = (await http.post<{ data: Person }>('/people', body)).data;
    // Si l'enregistrement du lien échoue ensuite, on réutilise cette personne au lieu d'en créer une seconde.
    chosen.value = { id: created.id, name: personName(created) };
    return created.id;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409 && Array.isArray(e.problem?.duplicates)) {
      duplicates.value = e.problem.duplicates as Candidate[];
      return null;
    }
    throw e;
  }
}

async function addLink(confirmDifferent = false): Promise<void> {
  const kind = form.kind;
  const replaced = kind === 'financial_guarantor' && form.replaceGuarantors ? currentGuarantors.value : [];
  if (replaced.length > 0 && !window.confirm(t('people.familyReplaceConfirm', { names: replaced.map((l) => l.name).join(', ') }))) return;
  await guarded(async () => {
    const relatedId = await relatedPersonId(confirmDifferent);
    if (relatedId === null) return;
    await http.post(`/people/${props.id}/relations`, { related_person_id: relatedId, kind });
    for (const l of replaced) await http.delete(`/people/${props.id}/relations/${l.id}`);
    resetForm();
    await reloadRelations();
    notice.value = t('people.familyAdded');
    guarantorInfo.value = BILLING_KINDS.includes(kind);
  });
}

/** Avertissements quand le lien retiré (ou changé en `newKind`) était le dernier responsable ou le dernier destinataire des relances. */
function warnings(link: SubjectLink, newKind: string | null): string[] {
  const out: string[] = [];
  if (RESPONSIBLE_KINDS.includes(link.kind) && !remainsAfter(links.value, link.id, RESPONSIBLE_KINDS) && !(newKind !== null && RESPONSIBLE_KINDS.includes(newKind))) {
    out.push(t('people.familyLastResponsible'));
  }
  if (BILLING_KINDS.includes(link.kind) && !remainsAfter(links.value, link.id, BILLING_KINDS) && !(newKind !== null && BILLING_KINDS.includes(newKind))) {
    out.push(t('people.familyLastPayer'));
  }
  return out;
}

async function removeLink(link: SubjectLink): Promise<void> {
  const message = [t('people.familyRemoveConfirm', { kind: kindLabel(link.kind), name: link.name }), ...warnings(link, null)].join('\n\n');
  if (!window.confirm(message)) return;
  await guarded(async () => {
    await http.delete(`/people/${props.id}/relations/${link.id}`);
    await reloadRelations();
    notice.value = t('people.familyRemoved');
    guarantorInfo.value = BILLING_KINDS.includes(link.kind);
  });
}

async function changeLink(link: SubjectLink): Promise<void> {
  const kind = changeKinds[link.id] ?? link.kind;
  if (kind === link.kind) return;
  const extra = warnings(link, kind);
  if (extra.length > 0 && !window.confirm([t('people.familyChangeConfirm', { name: link.name, from: kindLabel(link.kind), to: kindLabel(kind) }), ...extra].join('\n\n'))) return;
  await guarded(async () => {
    await http.post(`/people/${props.id}/relations`, { related_person_id: link.personId, kind });
    await http.delete(`/people/${props.id}/relations/${link.id}`);
    await reloadRelations();
    notice.value = t('people.familyChanged');
    guarantorInfo.value = BILLING_KINDS.includes(kind) || BILLING_KINDS.includes(link.kind);
  });
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

        <p v-if="noResponsible" class="notice notice--warning" data-test="family-no-responsible">{{ t('people.familyNoResponsible') }}</p>
        <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
        <p v-if="guarantorInfo" class="notice" data-test="family-guarantor-info">{{ t('people.familyGuarantorInfo') }}</p>
        <ErrorNotice :error="familyError" />
        <p v-if="!canManage" class="muted" data-test="family-readonly">{{ t('people.familyReadOnly') }}</p>

        <template v-if="canManage">
          <div v-if="links.length > 0" class="family" data-test="family-manage">
            <h3 class="eyebrow">{{ t('people.familyManage') }}</h3>
            <ul class="plain">
              <li v-for="l in links" :key="l.id" class="manage-row" :data-test="`link-${l.id}`">
                <span class="family-name">{{ l.name }}</span>
                <select
                  :value="changeKinds[l.id] ?? l.kind"
                  :aria-label="t('people.familyChangeAria', { name: l.name, kind: kindLabel(l.kind) })"
                  :disabled="busy"
                  @change="changeKinds[l.id] = ($event.target as HTMLSelectElement).value"
                >
                  <option v-for="k in RELATION_KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
                </select>
                <button type="button" class="btn btn--quiet" data-test="link-change" :disabled="busy || (changeKinds[l.id] ?? l.kind) === l.kind" @click="changeLink(l)">
                  {{ t('people.familyChange') }}
                </button>
                <button type="button" class="btn btn--quiet" data-test="link-remove" :disabled="busy" :aria-label="t('people.familyRemoveAria', { name: l.name, kind: kindLabel(l.kind) })" @click="removeLink(l)">
                  {{ t('people.familyRemove') }}
                </button>
              </li>
            </ul>
          </div>

          <form class="family add-form" data-test="family-add" @submit.prevent="addLink()">
            <fieldset :disabled="busy">
              <legend class="eyebrow">{{ t('people.familyAdd') }}</legend>
              <div class="field">
                <label for="rel-kind">{{ t('people.familyKind') }}</label>
                <select id="rel-kind" v-model="form.kind" data-test="add-kind">
                  <option v-for="k in RELATION_KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
                </select>
              </div>
              <div class="row" role="radiogroup" :aria-label="t('people.familyWho')">
                <label><input v-model="form.mode" type="radio" value="existing" data-test="mode-existing" /> {{ t('people.familyModeExisting') }}</label>
                <label><input v-model="form.mode" type="radio" value="new" data-test="mode-new" /> {{ t('people.familyModeNew') }}</label>
              </div>

              <template v-if="form.mode === 'existing'">
                <div class="field">
                  <label for="rel-q">{{ t('people.familySearch') }}</label>
                  <div class="row">
                    <input id="rel-q" v-model="form.q" type="text" data-test="add-q" @keydown.enter.prevent="search" />
                    <button type="button" class="btn btn--quiet" data-test="add-search" @click="search">{{ t('people.searchGo') }}</button>
                  </div>
                </div>
                <p v-if="searched && results.length === 0" class="muted">{{ t('people.familyNoResult') }}</p>
                <ul v-if="results.length > 0" class="plain" data-test="add-results">
                  <li v-for="p in results" :key="p.id" class="row row--between">
                    <span>{{ personName(p) }}<small class="muted"> · {{ p.phone ?? '—' }}</small></span>
                    <button type="button" class="btn btn--quiet" @click="choose(p)">{{ t('people.familyChoose') }}</button>
                  </li>
                </ul>
              </template>
              <template v-else-if="!chosen">
                <div class="field">
                  <label for="rel-last">{{ t('people.lastName') }}</label>
                  <input id="rel-last" v-model="form.last_name" type="text" required maxlength="100" data-test="add-last" />
                </div>
                <div class="field">
                  <label for="rel-first">{{ t('people.firstName') }}</label>
                  <input id="rel-first" v-model="form.first_name" type="text" required maxlength="100" data-test="add-first" />
                </div>
                <div class="field">
                  <label for="rel-phone">{{ t('people.phone') }}</label>
                  <input id="rel-phone" v-model="form.phone" type="tel" maxlength="25" data-test="add-phone" />
                </div>
              </template>

              <section v-if="duplicates.length > 0" class="notice notice--warning" role="alert" data-test="add-duplicates">
                <p>{{ t('people.familyDuplicate') }}</p>
                <ul class="plain">
                  <li v-for="d in duplicates" :key="d.person.id" class="row row--between">
                    <span>{{ personName(d.person) }}<small class="muted"> · {{ d.person.phone ?? '—' }}<template v-if="d.label"> · {{ d.label }}</template></small></span>
                    <button type="button" class="btn btn--quiet" @click="choose(d.person)">{{ t('people.familyChoose') }}</button>
                  </li>
                </ul>
                <button type="button" class="btn btn--quiet" data-test="add-create-anyway" @click="addLink(true)">{{ t('people.familyCreateAnyway') }}</button>
              </section>

              <p v-if="chosen" data-test="add-chosen"><strong>{{ t('people.familyChosen', { name: chosen.name }) }}</strong></p>
              <label v-if="form.kind === 'financial_guarantor' && currentGuarantors.length > 0" class="row">
                <input v-model="form.replaceGuarantors" type="checkbox" data-test="add-replace" />
                {{ t('people.familyReplace', { names: currentGuarantors.map((l) => l.name).join(', ') }) }}
              </label>
              <p v-if="form.kind === 'financial_guarantor'" class="muted">{{ t('people.familyGuarantorInfo') }}</p>
              <button type="submit" class="btn btn--primary" data-test="add-submit" :disabled="form.mode === 'existing' && !chosen">{{ t('people.familySubmit') }}</button>
            </fieldset>
          </form>
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
.manage-row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1.4fr) auto auto;
  gap: 0.5rem;
  align-items: center;
}
.add-form fieldset {
  border: 0;
  padding: 0;
  margin: 0;
}
.add-form legend {
  margin-bottom: 0.5rem;
}
@media (max-width: 600px) {
  .family-row {
    grid-template-columns: 1fr auto;
  }
  .manage-row {
    grid-template-columns: 1fr 1fr;
  }
}
progress {
  width: 100%;
  height: 12px;
  display: block;
  margin-top: 0.25rem;
}
</style>
