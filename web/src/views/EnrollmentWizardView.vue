<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError, http } from '@/api/http';
import { formatXaf } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Inscription en 5 étapes (maquettes Inscription.html et InscriptionPieces.html) :
// 1 Identité, 2 Parents et garant, 3 Filière, 4 Pièces, 5 Frais et paiement.
// Section, régime (jour/soir) et places autorisées n'ont pas encore de champ côté serveur : non affichés plutôt qu'inventés.
const { t, locale } = useI18n();
const ctx = useContextStore();
const router = useRouter();

type Step = 1 | 2 | 3 | 4 | 5;
const step = ref<Step>(1);
const busy = ref(false);
const error = ref<unknown>(null);

const learner = reactive({ id: '', last_name: '', first_name: '', birth_date: '', birth_place: '', gender: '', nationality: '', phone: '', email: '' });
interface Duplicate {
  person: { id: string; first_name?: string; last_name?: string; birth_date?: string | null };
  label: string;
  history?: string[];
}
const duplicate = ref<Duplicate | null>(null);

interface Relative {
  id: string;
  name: string;
  kind: string;
}
const relatives = ref<Relative[]>([]);
const relative = reactive({ first_name: '', last_name: '', phone: '', kind: 'parent' });
const RELATION_KINDS = ['parent', 'guardian', 'financial_guarantor'] as const;

interface Named {
  id: string;
  name?: string;
  label?: string;
  capacity?: number | null;
}
const years = ref<Named[]>([]);
const structures = ref<Named[]>([]);
const yearId = ref('');
const structureId = ref('');
const enrollmentId = ref('');

const DOCUMENT_KINDS = ['birth_certificate', 'id_photo', 'previous_report', 'medical_certificate', 'vaccination_card', 'guardian_id', 'residence_proof'] as const;
const received = ref<Set<string>>(new Set());

interface InvoiceLine {
  label: string;
  net_xaf: number;
}
interface InvoiceView {
  id: string;
  net_xaf: number;
  lines?: InvoiceLine[];
}
const invoice = ref<InvoiceView | null>(null);
const familyReference = ref('');
const done = ref<'validated' | 'conditional' | null>(null);

const canPay = computed(() => ctx.can('billing.manage'));
const stepNames = computed(() => [t('enroll.step1'), t('enroll.step2'), t('enroll.step3'), t('enroll.step4'), t('enroll.step5')]);
const percent = computed(() => {
  const identity = [learner.last_name, learner.first_name, learner.birth_date, learner.birth_place, learner.gender, learner.nationality, learner.phone].filter((v) => v !== '').length;
  const parts = identity / 7 + (relatives.value.length > 0 ? 1 : 0) + (structureId.value ? 1 : 0) + received.value.size / DOCUMENT_KINDS.length;
  return Math.round((parts / 4) * 100);
});

function label(n: Named): string {
  return n.name ?? n.label ?? n.id;
}

async function guarded<T>(work: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;
  try {
    return await work();
  } catch (e) {
    error.value = e;
    return undefined;
  } finally {
    busy.value = false;
  }
}

function personBody(extra: Record<string, unknown> = {}): Record<string, unknown> {
  const body: Record<string, unknown> = {
    last_name: learner.last_name.trim(),
    first_name: learner.first_name.trim(),
    ...extra,
  };
  for (const key of ['birth_date', 'birth_place', 'gender', 'nationality', 'phone', 'email'] as const) {
    if (learner[key].trim() !== '') body[key] = learner[key].trim();
  }
  return body;
}

async function createLearner(confirmDifferent = false): Promise<void> {
  const created = await guarded(async () => {
    try {
      return (await http.post<{ data: { id: string } }>('/people', personBody(confirmDifferent ? { confirm_different: true } : {}))).data;
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && Array.isArray(e.problem?.duplicates)) {
        duplicate.value = (e.problem.duplicates as Duplicate[])[0] ?? null;
        error.value = null;
        return undefined;
      }
      throw e;
    }
  });
  if (created) {
    learner.id = created.id;
    duplicate.value = null;
    step.value = 2;
  }
}

async function resumeExisting(): Promise<void> {
  if (!duplicate.value) return;
  learner.id = duplicate.value.person.id;
  duplicate.value = null;
  step.value = 2;
}

async function submitIdentity(): Promise<void> {
  if (learner.id) {
    step.value = 2;
    return;
  }
  await createLearner(false);
}

async function addRelative(): Promise<void> {
  if (relative.first_name.trim() === '' || relative.last_name.trim() === '') return;
  const added = await guarded(async () => {
    const body: Record<string, unknown> = { first_name: relative.first_name.trim(), last_name: relative.last_name.trim(), confirm_different: true };
    if (relative.phone.trim() !== '') body.phone = relative.phone.trim();
    const person = (await http.post<{ data: { id: string } }>('/people', body)).data;
    await http.post(`/people/${learner.id}/relations`, { related_person_id: person.id, kind: relative.kind });
    return { id: person.id, name: `${relative.first_name.trim()} ${relative.last_name.trim()}`, kind: relative.kind } satisfies Relative;
  });
  if (added) {
    relatives.value.push(added);
    relative.first_name = '';
    relative.last_name = '';
    relative.phone = '';
  }
}

async function loadChoices(): Promise<void> {
  await guarded(async () => {
    years.value = await http.all<Named>('/academic-years', {}, {}, 3);
    structures.value = await http.all<Named>('/structures', {}, {}, 5);
  });
}

async function chooseTrack(): Promise<void> {
  if (!structureId.value || !yearId.value) return;
  const row = await guarded(async () => {
    if (enrollmentId.value) {
      await http.patch(`/enrollments/${enrollmentId.value}`, { structure_id: structureId.value, academic_year_id: yearId.value });
      return { id: enrollmentId.value };
    }
    return (await http.post<{ data: { id: string } }>('/enrollments', { person_id: learner.id, structure_id: structureId.value, academic_year_id: yearId.value })).data;
  });
  if (row) {
    enrollmentId.value = row.id;
    await refreshDocuments();
    step.value = 4;
  }
}

async function refreshDocuments(): Promise<void> {
  const list = await guarded(() => http.get<{ data: Array<{ kind: string }> }>(`/people/${learner.id}/documents`));
  received.value = new Set((list?.data ?? []).map((d) => d.kind));
}

async function upload(kind: string, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  const form = new FormData();
  form.append('kind', kind);
  form.append('file', file);
  await guarded(() => http.post(`/people/${learner.id}/documents`, undefined, { form }));
  input.value = '';
  await refreshDocuments();
}

async function loadInvoice(): Promise<void> {
  step.value = 5;
  invoice.value = null;
  if (!canPay.value) return;
  const guarantor = relatives.value.find((r) => r.kind === 'financial_guarantor') ?? relatives.value[0];
  await guarded(async () => {
    try {
      invoice.value = (await http.post<{ data: InvoiceView }>('/invoices', { enrollment_id: enrollmentId.value })).data;
    } catch (e) {
      if (!(e instanceof ApiError && e.status === 422)) throw e;
      error.value = e;
    }
    if (guarantor && invoice.value) {
      familyReference.value = (await http.post<{ data: { reference: string } }>('/payment-references', { guarantor_id: guarantor.id })).data.reference;
    }
  });
}

async function validate(): Promise<void> {
  const row = await guarded(() => http.post<{ data: { status: string } }>(`/enrollments/${enrollmentId.value}/validate`));
  if (row) done.value = row.data.status === 'conditional' ? 'conditional' : 'validated';
}

onMounted(() => void loadChoices());
</script>

<template>
  <div class="page enroll">
    <header class="page-head">
      <p class="eyebrow">{{ t('enroll.eyebrow') }}</p>
      <h1>{{ t('enroll.title') }}</h1>
      <p v-if="learner.id" class="lede">{{ learner.first_name }} {{ learner.last_name }} · {{ t('enroll.complete', { percent }) }}</p>
    </header>

    <ol class="steps" :aria-label="t('enroll.steps')">
      <li v-for="(name, i) in stepNames" :key="name" :class="{ 'steps__on': step === i + 1, 'steps__done': step > i + 1 }" :aria-current="step === i + 1 ? 'step' : undefined">
        <span class="steps__n">{{ step > i + 1 ? '✓' : i + 1 }}</span>{{ name }}
      </li>
    </ol>

    <ErrorNotice :error="error" />

    <section v-if="done" class="card" role="status">
      <h2>{{ done === 'validated' ? t('enroll.validated') : t('enroll.conditional') }}</h2>
      <p>{{ done === 'validated' ? t('enroll.validatedText') : t('enroll.conditionalText') }}</p>
      <router-link class="btn btn--primary" :to="{ name: 'people' }">{{ t('enroll.backToPeople') }}</router-link>
    </section>

    <template v-else>
      <!-- Étape 1 -->
      <form v-if="step === 1" class="card form" novalidate @submit.prevent="submitIdentity">
        <section v-if="duplicate" class="notice notice--warning" role="alert" aria-labelledby="dup-title">
          <h2 id="dup-title">{{ t('enroll.dupTitle') }}</h2>
          <p>
            <b>{{ duplicate.person.first_name }} {{ duplicate.person.last_name }}</b><template v-if="duplicate.person.birth_date"> · {{ t('enroll.bornOn', { date: duplicate.person.birth_date }) }}</template>
            <br /><span v-for="h in duplicate.history ?? []" :key="h">{{ h }}<br /></span>
            <span class="tag">{{ duplicate.label }}</span>
          </p>
          <div class="row">
            <button type="button" class="btn btn--primary" @click="resumeExisting">{{ t('enroll.dupSame') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="createLearner(true)">{{ t('enroll.dupDifferent') }}</button>
          </div>
          <p class="muted small">{{ t('enroll.dupHint') }}</p>
        </section>

        <div class="grid2">
          <div class="field"><label for="en-last">{{ t('enroll.lastName') }}</label><input id="en-last" v-model="learner.last_name" type="text" required autocomplete="family-name" /></div>
          <div class="field"><label for="en-first">{{ t('enroll.firstName') }}</label><input id="en-first" v-model="learner.first_name" type="text" required autocomplete="given-name" /></div>
          <div class="field"><label for="en-birth">{{ t('enroll.birthDate') }}</label><input id="en-birth" v-model="learner.birth_date" type="date" /></div>
          <div class="field"><label for="en-place">{{ t('enroll.birthPlace') }}</label><input id="en-place" v-model="learner.birth_place" type="text" /></div>
          <div class="field">
            <label for="en-gender">{{ t('enroll.gender') }}</label>
            <select id="en-gender" v-model="learner.gender">
              <option value="">—</option>
              <option value="female">{{ t('enroll.female') }}</option>
              <option value="male">{{ t('enroll.male') }}</option>
            </select>
          </div>
          <div class="field"><label for="en-nat">{{ t('enroll.nationality') }}</label><input id="en-nat" v-model="learner.nationality" type="text" /></div>
          <div class="field"><label for="en-phone">{{ t('enroll.phone') }}</label><input id="en-phone" v-model="learner.phone" type="tel" inputmode="tel" autocomplete="tel" /></div>
          <div class="field"><label for="en-mail">{{ t('enroll.email') }}</label><input id="en-mail" v-model="learner.email" type="email" autocomplete="email" /></div>
        </div>
        <button type="submit" class="btn btn--primary btn--large" :disabled="busy || learner.last_name.trim() === '' || learner.first_name.trim() === ''">{{ t('enroll.next2') }}</button>
      </form>

      <!-- Étape 2 -->
      <section v-else-if="step === 2" class="card form">
        <h2>{{ t('enroll.step2') }}</h2>
        <p class="muted">{{ t('enroll.step2Hint') }}</p>
        <ul v-if="relatives.length" class="relatives">
          <li v-for="r in relatives" :key="r.id"><b>{{ r.name }}</b> · {{ t(`enroll.kind.${r.kind}` as never) }}</li>
        </ul>
        <form class="grid2" novalidate @submit.prevent="addRelative">
          <div class="field"><label for="rl-last">{{ t('enroll.lastName') }}</label><input id="rl-last" v-model="relative.last_name" type="text" /></div>
          <div class="field"><label for="rl-first">{{ t('enroll.firstName') }}</label><input id="rl-first" v-model="relative.first_name" type="text" /></div>
          <div class="field"><label for="rl-phone">{{ t('enroll.phone') }}</label><input id="rl-phone" v-model="relative.phone" type="tel" inputmode="tel" /></div>
          <div class="field">
            <label for="rl-kind">{{ t('enroll.relation') }}</label>
            <select id="rl-kind" v-model="relative.kind"><option v-for="k in RELATION_KINDS" :key="k" :value="k">{{ t(`enroll.kind.${k}` as never) }}</option></select>
          </div>
          <div><button type="submit" class="btn" :disabled="busy || relative.first_name.trim() === '' || relative.last_name.trim() === ''">{{ t('enroll.addRelative') }}</button></div>
        </form>
        <div class="row">
          <button type="button" class="btn" @click="step = 1">{{ t('enroll.back') }}</button>
          <button type="button" class="btn btn--primary btn--large" @click="step = 3">{{ t('enroll.next3') }}</button>
        </div>
      </section>

      <!-- Étape 3 -->
      <section v-else-if="step === 3" class="card form">
        <h2>{{ t('enroll.step3') }}</h2>
        <div class="grid2">
          <div class="field">
            <label for="tr-year">{{ t('enroll.year') }}</label>
            <select id="tr-year" v-model="yearId"><option value="" disabled>{{ t('grades.choose') }}</option><option v-for="y in years" :key="y.id" :value="y.id">{{ label(y) }}</option></select>
          </div>
          <div class="field">
            <label for="tr-structure">{{ t('enroll.track') }}</label>
            <select id="tr-structure" v-model="structureId">
              <option value="" disabled>{{ t('grades.choose') }}</option>
              <option v-for="s in structures" :key="s.id" :value="s.id">{{ label(s) }}<template v-if="s.capacity"> · {{ t('enroll.capacity', { count: s.capacity }) }}</template></option>
            </select>
          </div>
        </div>
        <p class="muted small">{{ t('enroll.noSectionYet') }}</p>
        <div class="row">
          <button type="button" class="btn" @click="step = 2">{{ t('enroll.back') }}</button>
          <button type="button" class="btn btn--primary btn--large" :disabled="busy || !yearId || !structureId" @click="chooseTrack">{{ t('enroll.next4') }}</button>
        </div>
      </section>

      <!-- Étape 4 -->
      <section v-else-if="step === 4" class="card form">
        <h2>{{ t('enroll.step4') }} <span class="tag">{{ received.size }} / {{ DOCUMENT_KINDS.length }}</span></h2>
        <ul class="docs">
          <li v-for="k in DOCUMENT_KINDS" :key="k">
            <span class="tag" :class="received.has(k) ? 'tag--ok' : 'tag--warn'">{{ received.has(k) ? t('enroll.received') : t('enroll.missing') }}</span>
            <span class="grow">{{ t(`enroll.doc.${k}` as never) }}</span>
            <label class="btn btn--quiet">
              {{ received.has(k) ? t('enroll.replace') : t('enroll.scan') }}
              <input type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" :aria-label="`${t('enroll.scan')} · ${t(`enroll.doc.${k}` as never)}`" @change="upload(k, $event)" />
            </label>
          </li>
        </ul>
        <p class="muted small">{{ t('enroll.docsHint') }}</p>
        <div class="row">
          <button type="button" class="btn" @click="step = 3">{{ t('enroll.back') }}</button>
          <button type="button" class="btn btn--primary btn--large" @click="loadInvoice">{{ t('enroll.next5') }}</button>
        </div>
      </section>

      <!-- Étape 5 -->
      <section v-else class="card form">
        <h2>{{ t('enroll.step5') }}</h2>
        <template v-if="invoice">
          <table class="lines">
            <tbody>
              <tr v-for="(l, i) in invoice.lines ?? []" :key="i"><th scope="row">{{ l.label }}</th><td class="num">{{ formatXaf(l.net_xaf, locale) }}</td></tr>
            </tbody>
            <tfoot><tr><th scope="row">{{ t('enroll.total') }}</th><td class="num">{{ formatXaf(invoice.net_xaf, locale) }}</td></tr></tfoot>
          </table>
          <p v-if="familyReference">{{ t('enroll.reference') }} <b class="mono">{{ familyReference }}</b><br /><span class="muted small">{{ t('enroll.referenceHint') }}</span></p>
          <router-link class="btn" :to="{ name: 'billing' }">{{ t('enroll.payCounter') }}</router-link>
        </template>
        <p v-else-if="!canPay" class="muted">{{ t('enroll.noBilling') }}</p>
        <p v-else class="muted">{{ t('enroll.noInvoice') }}</p>
        <div class="row">
          <button type="button" class="btn" @click="step = 4">{{ t('enroll.back') }}</button>
          <button type="button" class="btn btn--primary btn--large" :disabled="busy" @click="validate">{{ invoice ? t('enroll.validate') : t('enroll.validateNoPay') }}</button>
          <button type="button" class="btn btn--quiet" @click="router.push({ name: 'people' })">{{ t('enroll.saveDraft') }}</button>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.enroll {
  max-width: 820px;
}
.steps {
  list-style: none;
  display: flex;
  gap: 4px;
  padding: 0;
  margin: 0 0 16px;
  flex-wrap: wrap;
}
.steps li {
  flex: 1 1 120px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-top: 4px solid var(--border, #dcdcd8);
  font-size: 13px;
  font-weight: 700;
  color: #595959;
}
.steps__on {
  border-top-color: var(--accent, #f26b1d) !important;
  color: var(--ink, #000) !important;
}
.steps__done {
  border-top-color: var(--ink, #000) !important;
  color: var(--ink, #000);
}
.steps__n {
  display: inline-flex;
  width: 22px;
  height: 22px;
  align-items: center;
  justify-content: center;
  background: var(--ink, #000);
  color: #fff;
  font-size: 12px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px 16px;
}
.relatives,
.docs {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.docs li {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid var(--border, #dcdcd8);
  padding: 8px 12px;
}
.grow {
  flex: 1;
}
.tag--ok {
  border-color: #0a7a3e;
  color: #0a7a3e;
}
.tag--warn {
  border-color: #b34700;
  color: #b34700;
}
.lines {
  width: 100%;
  border-collapse: collapse;
}
.lines th,
.lines td {
  text-align: left;
  padding: 8px 0;
  border-top: 1px solid var(--border, #dcdcd8);
}
.lines .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.mono {
  font-family: monospace;
}
.small {
  font-size: 13px;
}
</style>
