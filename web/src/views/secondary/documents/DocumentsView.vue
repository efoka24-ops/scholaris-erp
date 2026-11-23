<script setup lang="ts">
// Documents du secondaire (T833) : catalogue DOC-nn avec QR et disponibilité selon les modules, émission (numéro, QR, contenu figé,
// FR/EN) à partir d'une inscription, d'une classe ou d'une facture, documents valides d'un élève avec lien de vérification publique.
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  issuable, requiredFields, secondaryDocumentPickers, secondaryDocuments, unavailableKey,
  type DocumentCandidate, type DocumentKindInfo, type IssuedSecondaryDocument, type SecondaryClassRow,
} from '@/api/secondaryDocuments';
import { useI18n } from '@/i18n';
import { useAction } from '../useSec';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();
const kinds = ref<DocumentKindInfo[]>([]);
const classes = ref<SecondaryClassRow[]>([]);
const candidates = ref<DocumentCandidate[]>([]);
const issued = ref<IssuedSecondaryDocument | null>(null);
const pupilDocs = ref<IssuedSecondaryDocument[]>([]);
const form = ref({ kind: '', classId: '', enrollmentId: '', invoiceId: '', destination: '', reason: '', effectiveOn: '', lang: '' as '' | 'fr' | 'en' });

const issuableKinds = computed(() => kinds.value.filter(issuable));
const current = computed(() => kinds.value.find((k) => k.kind === form.value.kind) ?? null);
const fields = computed(() => (current.value ? requiredFields(current.value) : []));
const needs = (f: string): boolean => (fields.value as string[]).includes(f);
const label = (k: DocumentKindInfo): string => (locale.value === 'en' ? k.label_en : k.label_fr);
const shownCandidates = computed(() => candidates.value.filter((c) => (form.value.kind === 'exeat' ? c.status !== 'active' && c.status !== 'conditional' : c.status === 'active' || c.status === 'conditional')));
const ready = computed(() => {
  const f = form.value;
  if (!current.value) return false;
  if (needs('class_id') && f.classId === '') return false;
  if (needs('invoice_id') && f.invoiceId.trim() === '') return false;
  if (needs('enrollment_id') && f.enrollmentId === '') return false;
  return !needs('destination') || (f.destination.trim() !== '' && f.reason.trim() !== '' && f.effectiveOn !== '');
});
/** DOC-21 à 24 : réservés à l'enseignement technique (capacité du profil, en plus du module). */
const technicalOnly = (k: DocumentKindInfo): boolean => (k as DocumentKindInfo & { required_teaching_kind?: string | null }).required_teaching_kind === 'technical';
const verifyLink = (token: string): string => `/verification/${encodeURIComponent(token)}`;

watch(() => form.value.classId, async (classId) => {
  form.value.enrollmentId = '';
  candidates.value = classId === '' ? [] : ((await run(() => secondaryDocumentPickers.candidates(classId))) ?? []);
});
watch(() => form.value.enrollmentId, async (id) => {
  const c = candidates.value.find((x) => x.enrollment_id === id);
  pupilDocs.value = c ? ((await run(() => secondaryDocuments.issuedFor(c.person_id))) ?? []) : [];
});

async function issue(): Promise<void> {
  if (!ready.value || !current.value) return;
  const f = form.value;
  const kind = current.value.kind;
  const doc = await run(() => secondaryDocuments.issue({
    kind,
    ...(needs('enrollment_id') ? { enrollment_id: f.enrollmentId } : {}),
    ...(needs('class_id') ? { class_id: f.classId } : {}),
    ...(needs('invoice_id') ? { invoice_id: f.invoiceId.trim() } : {}),
    ...(needs('destination') ? { destination: f.destination.trim(), reason: f.reason.trim(), effective_on: f.effectiveOn } : {}),
    ...(f.lang !== '' ? { locale: f.lang } : {}),
  }));
  if (doc) {
    issued.value = doc;
    const c = candidates.value.find((x) => x.enrollment_id === f.enrollmentId);
    if (c) pupilDocs.value = (await run(() => secondaryDocuments.issuedFor(c.person_id))) ?? pupilDocs.value;
  }
}

onMounted(async () => {
  await run(async () => {
    kinds.value = await secondaryDocuments.kinds();
    form.value.kind = issuableKinds.value[0]?.kind ?? '';
  });
  classes.value = (await run(() => secondaryDocumentPickers.classes())) ?? [];
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sec.docs.title') }}</h1>
      <p class="lede">{{ t('sec.docs.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card" aria-labelledby="sd-new">
      <h2 id="sd-new">{{ t('sec.docs.issueTitle') }}</h2>
      <p v-if="issuableKinds.length === 0" class="muted">{{ t('sec.docs.noneIssuable') }}</p>
      <form v-else class="stack" @submit.prevent="issue">
        <div class="field">
          <label for="sd-kind">{{ t('sec.docs.kind') }}</label>
          <select id="sd-kind" v-model="form.kind">
            <option v-for="k in issuableKinds" :key="k.kind" :value="k.kind">{{ k.doc }} · {{ label(k) }}</option>
          </select>
        </div>
        <div v-if="needs('class_id') || needs('enrollment_id')" class="field">
          <label for="sd-class">{{ t('sec.docs.class') }}</label>
          <select id="sd-class" v-model="form.classId">
            <option value="">{{ t('sec.docs.chooseClass') }}</option>
            <option v-for="c in classes" :key="c.id" :value="c.structure_id">{{ c.label }}</option>
          </select>
        </div>
        <div v-if="needs('enrollment_id')" class="field">
          <label for="sd-pupil">{{ t('sec.docs.pupil') }}</label>
          <select id="sd-pupil" v-model="form.enrollmentId" :disabled="form.classId === ''">
            <option value="">{{ t('sec.docs.choosePupil') }}</option>
            <option v-for="c in shownCandidates" :key="c.enrollment_id" :value="c.enrollment_id">{{ c.name }}<template v-if="c.internal_number"> · {{ c.internal_number }}</template></option>
          </select>
          <p v-if="form.kind === 'exeat'" class="muted">{{ t('sec.docs.exeatHelp') }}</p>
        </div>
        <div v-if="needs('invoice_id')" class="field">
          <label for="sd-invoice">{{ t('sec.docs.invoice') }}</label>
          <input id="sd-invoice" v-model="form.invoiceId" type="text" maxlength="40" autocomplete="off" />
          <p class="muted">{{ t('sec.docs.invoiceHelp') }}</p>
        </div>
        <template v-if="needs('destination')">
          <div class="field">
            <label for="sd-dest">{{ t('sec.docs.destination') }}</label>
            <input id="sd-dest" v-model="form.destination" type="text" maxlength="160" />
          </div>
          <div class="field">
            <label for="sd-reason">{{ t('sec.docs.reason') }}</label>
            <input id="sd-reason" v-model="form.reason" type="text" maxlength="255" />
          </div>
          <div class="field">
            <label for="sd-date">{{ t('sec.docs.effectiveOn') }}</label>
            <input id="sd-date" v-model="form.effectiveOn" type="date" />
          </div>
        </template>
        <div class="field">
          <label for="sd-lang">{{ t('sec.docs.language') }}</label>
          <select id="sd-lang" v-model="form.lang">
            <option value="">{{ t('sec.docs.languageSection') }}</option>
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </div>
        <p class="muted">{{ t('sec.docs.noDebt') }}</p>
        <div><button type="submit" class="btn btn--primary" :disabled="busy || !ready">{{ t('sec.docs.issue') }}</button></div>
      </form>
      <div v-if="issued" class="notice notice--success" role="status">
        <p><strong>{{ t('sec.docs.issued', { number: issued.number }) }}</strong></p>
        <p v-if="issued.qr"><a :href="verifyLink(issued.verification_token)" target="_blank" rel="noopener">{{ t('sec.docs.verify') }}</a></p>
      </div>
    </section>

    <section v-if="pupilDocs.length > 0" class="card" aria-labelledby="sd-pupil-docs">
      <h2 id="sd-pupil-docs">{{ t('sec.docs.pupilDocs') }}</h2>
      <ul class="rows">
        <li v-for="d in pupilDocs" :key="d.id">
          <strong>{{ d.doc }}</strong> · {{ d.number }}
          <a v-if="d.qr" :href="verifyLink(d.verification_token)" target="_blank" rel="noopener">{{ t('sec.docs.verify') }}</a>
        </li>
      </ul>
    </section>

    <section class="card" aria-labelledby="sd-catalogue">
      <h2 id="sd-catalogue">{{ t('sec.docs.catalogue') }}</h2>
      <ul class="rows">
        <li v-for="k in kinds" :key="k.kind">
          <strong>{{ k.doc }}</strong> · {{ label(k) }}
          <span v-if="k.qr" class="tag">{{ t('sec.docs.qr') }}</span>
          <span v-if="technicalOnly(k) && k.unavailable_reason !== 'teaching_kind_not_offered'" class="tag">{{ t('sec.docs.notTechnical') }}</span>
          <span v-if="!k.available" class="tag tag--warning">{{ t(unavailableKey(k)) }}</span>
          <span v-else-if="k.source === 'socle'" class="muted"> · {{ t('sec.docs.bySocle') }}</span>
          <span v-else-if="!k.direct" class="muted"> · {{ t('sec.docs.byFlow') }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 10px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
</style>
