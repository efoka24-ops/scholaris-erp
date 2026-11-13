<script setup lang="ts">
// Inscriptions du secondaire (T824 à T827, T978) : inscription (API-01) avec garant d'un mineur, liste des élèves et matricule
// national (API-02, liste à régulariser RG-INS-1), préinscriptions et dossier du primaire, réinscription des élèves autorisés par le
// conseil, transferts, import Excel ou CSV (simulation puis application). Alerte d'âge et exclu définitif : décision motivée du chef.
// Aucun contrôle de paiement (RG-INS-4). Le matricule n'apparaît jamais dans une adresse.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import type { AcademicYear } from '@/api/admin';
import { secondarySetup, type SchoolClass } from '@/api/secondarySetup';
import {
  fileFormat, secondaryEnrolment, templateCsv, toBase64,
  type Candidate, type Dossier, type ImportReport, type Preregistration, type StudentRow, type Transfer,
} from '@/api/secondaryEnrolment';
import { ApiError } from '@/api/http';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import { dossierFromForm, emptyEnrolForm, isMinor, type EnrolForm } from './enrolmentModel';

type Tab = 'enrol' | 'students' | 'preregistrations' | 'reenrol' | 'transfers' | 'import';
const TABS: Tab[] = ['enrol', 'students', 'preregistrations', 'reenrol', 'transfers', 'import'];
const AGE_ALERT = 'https://api.scholaris.cm/errors/admission-age-alert';
const EXCLUDED = 'https://api.scholaris.cm/errors/final-exclusion';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
const canManage = computed(() => can('secondary.enrol.manage'));
const canDecide = computed(() => can('secondary.enrol.validate'));

const tab = ref<Tab>('students');
const done = ref<MessageKey | null>(null);
const years = ref<AcademicYear[]>([]);
const year = ref('');
const classes = ref<SchoolClass[]>([]);
const students = ref<StudentRow[]>([]);
const pendingOnly = ref(false);
const classFilter = ref('');
const dossier = ref<Dossier | null>(null);
const nationalDraft = ref('');
const form = reactive<EnrolForm>(emptyEnrolForm());
const decisionNeeded = ref<'age' | 'exclusion' | null>(null);
const preregs = ref<Preregistration[]>([]);
const preForm = reactive({ last_name: '', first_name: '', birth_date: '', target_level_code: '', channel: 'desk', guardian_name: '', guardian_phone: '' });
const convertClass = ref('');
const primary = reactive({ json: '', signature: '', level: '' });
const fromYear = ref('');
const candidates = ref<Candidate[]>([]);
const reenrolClass = ref('');
const reenrolPicked = reactive<Record<string, boolean>>({});
const reenrolResult = ref<{ enrolled: string[]; refused: { person_id: string; reason: string }[] } | null>(null);
const transfers = ref<Transfer[]>([]);
const outForm = reactive({ person_id: '', other_school_label: '', transfer_date: new Date().toISOString().slice(0, 10), file_ref: '' });
const importFile = ref<{ name: string; format: 'csv' | 'xlsx'; content: string } | null>(null);
const importReport = ref<ImportReport | null>(null);
const entryDate = ref(new Date().toISOString().slice(0, 10));

const levelCodes = computed(() => [...new Set(classes.value.map((c) => c.level_code))]);
const className = (structureId: string): string => classes.value.find((c) => c.structure_id === structureId)?.label ?? '—';
const minor = computed(() => isMinor(form.birth_date, form.entry_date));

async function loadYearData(): Promise<void> {
  if (year.value === '') return;
  classes.value = (await run(() => secondarySetup.classes(year.value))) ?? [];
  await loadStudents();
}
async function loadStudents(): Promise<void> {
  students.value = (await run(() => secondaryEnrolment.students({ academic_year_id: year.value, class_structure_id: classFilter.value || undefined, pending: pendingOnly.value }))) ?? [];
}
async function load(): Promise<void> {
  years.value = (await run(() => secondarySetup.years())) ?? [];
  if (year.value === '' && years.value.length > 0) year.value = years.value.find((y) => y.status === 'open')?.id ?? years.value[0]?.id ?? '';
  await loadYearData();
}

// FIX-R32 : le dossier ouvert sous la liste se referme (bouton Fermer) et n'est plus ouvert d'office après une inscription.
function closeDossier(): void {
  dossier.value = null;
  nationalDraft.value = '';
  Object.assign(guarantorForm, { phone: '', last_name: '', first_name: '' });
}
async function openDossier(personId: string): Promise<void> {
  dossier.value = (await run(() => secondaryEnrolment.student(personId))) ?? null;
  nationalDraft.value = '';
}
async function saveNationalId(): Promise<void> {
  if (!dossier.value || nationalDraft.value.trim() === '') return;
  const updated = await run(() => secondaryEnrolment.setNationalId(dossier.value?.person_id ?? '', nationalDraft.value.trim()));
  if (updated) {
    dossier.value = updated;
    nationalDraft.value = '';
    done.value = 'sece.nationalIdSaved';
    await loadStudents();
  }
}

// C-118 : garant financier rattaché depuis la fiche de l'élève (parent retrouvé par son téléphone, sinon créé avec son nom).
const guarantorForm = reactive({ phone: '', last_name: '', first_name: '' });
async function attachGuarantor(): Promise<void> {
  if (!dossier.value || guarantorForm.phone.trim() === '') return;
  const input: { phone: string; last_name?: string; first_name?: string } = { phone: guarantorForm.phone.trim() };
  if (guarantorForm.last_name.trim() !== '') input.last_name = guarantorForm.last_name.trim();
  if (guarantorForm.first_name.trim() !== '') input.first_name = guarantorForm.first_name.trim();
  const updated = await run(() => secondaryEnrolment.attachGuarantor(dossier.value?.person_id ?? '', input));
  if (updated) {
    dossier.value = updated;
    Object.assign(guarantorForm, { phone: '', last_name: '', first_name: '' });
    done.value = 'sece.guarantorAttached';
  }
}

async function enrol(): Promise<void> {
  decisionNeeded.value = null;
  // Le message de l'inscription précédente ne doit pas masquer l'échec de celle-ci (FIX-R32).
  done.value = null;
  const result = await run(() => secondaryEnrolment.enrol(dossierFromForm(form)));
  if (result) {
    Object.assign(form, emptyEnrolForm());
    closeDossier();
    done.value = 'sece.enrolled';
    await loadStudents();
    return;
  }
  if (error.value instanceof ApiError) {
    const type = (error.value.problem as { type?: string } | undefined)?.type;
    if (type === AGE_ALERT) decisionNeeded.value = 'age';
    if (type === EXCLUDED) decisionNeeded.value = 'exclusion';
  }
}

async function loadPreregs(): Promise<void> {
  preregs.value = (await run(() => secondaryEnrolment.preregistrations())) ?? [];
}
async function addPrereg(): Promise<void> {
  const body: Record<string, unknown> = { last_name: preForm.last_name.trim(), first_name: preForm.first_name.trim(), target_level_code: preForm.target_level_code, channel: preForm.channel };
  if (preForm.birth_date !== '') body.birth_date = preForm.birth_date;
  if (preForm.guardian_name.trim() !== '') body.guardian_name = preForm.guardian_name.trim();
  if (preForm.guardian_phone.trim() !== '') body.guardian_phone = preForm.guardian_phone.trim();
  if (await run(() => secondaryEnrolment.createPreregistration(body))) {
    Object.assign(preForm, { last_name: '', first_name: '', birth_date: '', guardian_name: '', guardian_phone: '' });
    await loadPreregs();
  }
}
async function decide(p: Preregistration, status: 'accepted' | 'refused'): Promise<void> {
  const reason = status === 'refused' ? (window.prompt(t('sece.refusalReason')) ?? '').trim() : '';
  if (status === 'refused' && reason === '') return;
  if (await run(() => secondaryEnrolment.updatePreregistration(p.id, reason === '' ? { status } : { status, decision_reason: reason }))) await loadPreregs();
}
async function convert(p: Preregistration): Promise<void> {
  if (convertClass.value === '') return;
  const guardians = p.guardian_name ? [{ kind: 'parent' as const, identity: { last_name: p.guardian_name, first_name: t('sece.parentDefault'), phone: p.guardian_phone ?? undefined } }] : undefined;
  const result = await run(() => secondaryEnrolment.convert(p.id, { class_id: convertClass.value, entry_date: entryDate.value, guardians }));
  if (result) {
    closeDossier();
    done.value = 'sece.enrolled';
    await loadPreregs();
  }
}
async function importPrimary(): Promise<void> {
  const result = await run(() => secondaryEnrolment.primaryFile(primary.json.trim(), primary.signature.trim(), primary.level));
  if (result) {
    done.value = result.duplicate ? 'sece.primaryDuplicate' : 'sece.primaryImported';
    Object.assign(primary, { json: '', signature: '' });
    await loadPreregs();
  }
}

async function loadCandidates(): Promise<void> {
  if (fromYear.value === '') return;
  candidates.value = (await run(() => secondaryEnrolment.candidates(fromYear.value, year.value))) ?? [];
  for (const c of candidates.value) reenrolPicked[c.person_id] = c.admitted && c.reenrolled_class_id === null;
}
async function reenrol(): Promise<void> {
  const picked = candidates.value.filter((c) => reenrolPicked[c.person_id]);
  if (picked.length === 0 || reenrolClass.value === '') return;
  reenrolResult.value = (await run(() => secondaryEnrolment.reenrol({
    from_academic_year_id: fromYear.value, class_id: reenrolClass.value, entry_date: entryDate.value,
    students: picked.map((c) => ({ person_id: c.person_id, repeat: !c.admitted })),
  }))) ?? null;
  await loadCandidates();
}

async function loadTransfers(): Promise<void> {
  transfers.value = (await run(() => secondaryEnrolment.transfers())) ?? [];
}
async function transferOut(): Promise<void> {
  if (!window.confirm(t('sece.transferConfirm'))) return;
  const result = await run(() => secondaryEnrolment.transferOut({ ...outForm, file_ref: outForm.file_ref.trim() || undefined }));
  if (result) {
    Object.assign(outForm, { person_id: '', other_school_label: '', file_ref: '' });
    await loadTransfers();
    await loadStudents();
  }
}

async function downloadTemplate(): Promise<void> {
  const template = await run(() => secondaryEnrolment.importTemplate());
  if (!template) return;
  const url = URL.createObjectURL(new Blob([templateCsv(template)], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = 'modele_import_eleves.csv';
  link.click();
  URL.revokeObjectURL(url);
}
async function pickFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  input.value = '';
  importReport.value = null;
  importFile.value = null;
  if (!file) return;
  const format = fileFormat(file.name);
  if (format === null || file.size > 8 * 1024 * 1024) {
    error.value = new Error(t('sece.badFile'));
    return;
  }
  importFile.value = { name: file.name, format, content: format === 'xlsx' ? toBase64(await file.arrayBuffer()) : await file.text() };
}
async function runImport(dryRun: boolean): Promise<void> {
  const file = importFile.value;
  if (!file || year.value === '') return;
  const payload = file.format === 'xlsx' ? { content_base64: file.content } : { content: file.content };
  const report = await run(() => secondaryEnrolment.importFile({ format: file.format, ...payload, academic_year_id: year.value, entry_date: entryDate.value, dry_run: dryRun }));
  if (report) {
    importReport.value = report;
    if (!dryRun) {
      done.value = 'sece.importApplied';
      importFile.value = null;
      await loadStudents();
    }
  }
}

watch(year, loadYearData);
watch([pendingOnly, classFilter], loadStudents);
watch(tab, async (value) => {
  done.value = null;
  if (value === 'preregistrations') await loadPreregs();
  if (value === 'transfers') await loadTransfers();
});
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('sece.title') }}</h1>
      <p class="lede">{{ t('sece.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ t(done) }}</p>

    <div class="field">
      <label for="se-year">{{ t('secs.year') }}</label>
      <select id="se-year" v-model="year"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
    </div>

    <nav class="tabs" :aria-label="t('sece.title')">
      <button v-for="item in TABS" :key="item" type="button" class="tab" :aria-pressed="tab === item" @click="tab = item">{{ t(`sece.tab.${item}`) }}</button>
    </nav>

    <section v-if="tab === 'students'" class="card" aria-labelledby="se-students">
      <h2 id="se-students">{{ t('sece.tab.students') }}</h2>
      <div class="row">
        <div class="field">
<label for="se-class">{{ t('secs.tab.classes') }}</label>
          <select id="se-class" v-model="classFilter"><option value="">{{ t('sece.allClasses') }}</option><option v-for="c in classes" :key="c.id" :value="c.structure_id">{{ c.label }}</option></select>
</div>
        <label class="check"><input v-model="pendingOnly" type="checkbox" /> {{ t('sece.pendingOnly') }}</label>
      </div>
      <p v-if="students.length === 0" class="muted">{{ t('sece.noStudents') }}</p>
      <ul v-else class="rows">
        <li v-for="s in students" :key="s.id">
          <button type="button" class="linkish" @click="openDossier(s.person_id)"><strong>{{ s.last_name }} {{ s.first_name }}</strong></button>
          <span class="muted"> · {{ className(s.class_structure_id) }} · {{ t(`sece.kind.${s.entry_kind}`) }}</span>
          <span class="tag" :class="{ 'tag--warning': s.national_id_status !== 'recorded' }">{{ t(`sece.nid.${s.national_id_status}`) }}</span>
          <span v-if="s.age_alert" class="tag tag--warning">{{ t('sece.ageAlert') }}</span>
          <span v-if="s.repeater" class="tag">{{ t('sece.repeater') }}</span>
        </li>
      </ul>
      <article v-if="dossier" class="dossier" aria-labelledby="se-dossier">
        <div class="dossier-head">
          <h3 id="se-dossier">{{ dossier.last_name }} {{ dossier.first_name }}</h3>
          <button type="button" class="btn" @click="closeDossier">{{ t('sece.closeDossier') }}</button>
        </div>
        <p class="muted">{{ dossier.birth_date ?? '—' }} · {{ t(`sece.nid.${dossier.national_id_status}`) }}<template v-if="dossier.national_id"> · {{ dossier.national_id }}</template></p>
        <p v-if="dossier.final_exclusion_on" class="notice">{{ t('sece.excludedOn', { date: dossier.final_exclusion_on }) }}</p>
        <form v-if="canManage" class="row" @submit.prevent="saveNationalId">
          <label for="se-nid" class="sr-only">{{ t('sece.nationalId') }}</label>
          <input id="se-nid" v-model="nationalDraft" maxlength="40" autocomplete="off" :placeholder="t('sece.nationalId')" />
          <button type="submit" class="btn" :disabled="busy || nationalDraft.trim() === ''">{{ t('sece.recordNationalId') }}</button>
        </form>
        <ul class="rows small">
          <li v-for="r in dossier.records" :key="r.id">{{ r.entry_date }} · {{ r.level_code }} · {{ t(`sece.kind.${r.entry_kind}`) }}<template v-if="r.age_decision_reason"> · {{ t('sece.decision', { reason: r.age_decision_reason }) }}</template></li>
          <li v-for="tr in dossier.transfers" :key="tr.id">{{ tr.transfer_date }} · {{ t(`sece.direction.${tr.direction}`) }} · {{ tr.other_school_label }}</li>
        </ul>
        <h4 id="se-guarantor">{{ t('sece.guarantors') }}</h4>
        <ul v-if="(dossier.financial_guarantors ?? []).length > 0" class="rows small">
          <li v-for="g in dossier.financial_guarantors" :key="g.person_id">{{ g.last_name }} {{ g.first_name }}<template v-if="g.phone"> · {{ g.phone }}</template><template v-if="g.payment_reference"> · {{ t('sece.paymentReference', { reference: g.payment_reference }) }}</template></li>
        </ul>
        <p v-else class="muted">{{ t('sece.noGuarantor') }}</p>
        <form v-if="canManage" class="grid" aria-labelledby="se-guarantor" @submit.prevent="attachGuarantor">
          <p class="muted">{{ t('sece.guarantorHelp') }}</p>
          <div class="field"><label for="se-g-phone">{{ t('sece.phone') }}</label><input id="se-g-phone" v-model="guarantorForm.phone" type="tel" maxlength="20" inputmode="tel" required /></div>
          <div class="field"><label for="se-g-last">{{ t('sece.lastName') }}</label><input id="se-g-last" v-model="guarantorForm.last_name" maxlength="100" /></div>
          <div class="field"><label for="se-g-first">{{ t('sece.firstName') }}</label><input id="se-g-first" v-model="guarantorForm.first_name" maxlength="100" /></div>
          <button type="submit" class="btn" :disabled="busy || guarantorForm.phone.trim() === ''">{{ t('sece.attachGuarantor') }}</button>
        </form>
      </article>
    </section>

    <section v-if="tab === 'enrol' && canManage" class="card" aria-labelledby="se-enrol">
      <h2 id="se-enrol">{{ t('sece.tab.enrol') }}</h2>
      <p class="muted">{{ t('sece.enrolHelp') }}</p>
      <form class="grid" @submit.prevent="enrol">
        <!-- FIX-R32 : champs inactifs pendant l'envoi, sinon la remise à zéro du formulaire à la réponse effaçait la saisie suivante. -->
        <fieldset class="grid enrol-fields" :disabled="busy">
        <div class="field"><label for="en-last">{{ t('sece.lastName') }}</label><input id="en-last" v-model="form.last_name" maxlength="120" required autocomplete="off" /></div>
        <div class="field"><label for="en-first">{{ t('sece.firstName') }}</label><input id="en-first" v-model="form.first_name" maxlength="120" required autocomplete="off" /></div>
        <div class="field"><label for="en-birth">{{ t('sece.birthDate') }}</label><input id="en-birth" v-model="form.birth_date" type="date" required /></div>
        <div class="field">
<label for="en-gender">{{ t('sece.gender') }}</label>
          <select id="en-gender" v-model="form.gender"><option value="">—</option><option value="female">{{ t('sece.female') }}</option><option value="male">{{ t('sece.male') }}</option></select>
</div>
        <div class="field">
<label for="en-class">{{ t('secs.tab.classes') }}</label>
          <select id="en-class" v-model="form.class_id" required><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select>
</div>
        <div class="field"><label for="en-date">{{ t('sece.entryDate') }}</label><input id="en-date" v-model="form.entry_date" type="date" required /></div>
        <fieldset class="grid">
          <legend>{{ t('sece.guardian') }}<template v-if="minor"> *</template></legend>
          <div class="field"><label for="en-g-last">{{ t('sece.lastName') }}</label><input id="en-g-last" v-model="form.guardian_last_name" maxlength="120" :required="minor" /></div>
          <div class="field"><label for="en-g-first">{{ t('sece.firstName') }}</label><input id="en-g-first" v-model="form.guardian_first_name" maxlength="120" :required="minor" /></div>
          <div class="field"><label for="en-g-phone">{{ t('sece.phone') }}</label><input id="en-g-phone" v-model="form.guardian_phone" type="tel" maxlength="20" inputmode="tel" /></div>
          <label class="check"><input id="en-g-payer" v-model="form.guardian_financial" type="checkbox" :disabled="form.guardian_phone.trim() === ''" /> {{ t('sece.financialGuarantor') }}</label>
        </fieldset>
        <div class="field"><label for="en-nid">{{ t('sece.nationalIdOptional') }}</label><input id="en-nid" v-model="form.national_id" maxlength="40" autocomplete="off" /></div>
        <label class="check"><input v-model="form.repeater" type="checkbox" /> {{ t('sece.repeater') }}</label>
        <div class="field"><label for="en-docs">{{ t('sece.documents') }}</label><input id="en-docs" v-model="form.documents" maxlength="400" :placeholder="t('sece.documentsHint')" /></div>
        <div v-if="decisionNeeded" class="notice" role="alert">
          <p>{{ decisionNeeded === 'age' ? t('sece.ageDecisionNeeded') : t('sece.exclusionDecisionNeeded') }}</p>
          <template v-if="canDecide">
            <label for="en-decision">{{ t('sece.decisionReason') }}</label>
            <input v-if="decisionNeeded === 'age'" id="en-decision" v-model="form.age_decision_reason" maxlength="255" required />
            <input v-else id="en-decision" v-model="form.exclusion_decision_reason" maxlength="255" required />
          </template>
          <p v-else class="muted">{{ t('sece.headOnly') }}</p>
        </div>
        <p class="muted">{{ t('sece.noFeeCheck') }}</p>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sece.enrol') }}</button>
        </fieldset>
      </form>
    </section>

    <section v-if="tab === 'preregistrations'" class="card" aria-labelledby="se-pre">
      <h2 id="se-pre">{{ t('sece.tab.preregistrations') }}</h2>
      <ul class="rows">
        <li v-for="p in preregs" :key="p.id">
          <strong>{{ p.last_name }} {{ p.first_name }}</strong> <span class="muted">· {{ p.target_level_code }} · {{ t(`sece.channel.${p.channel}`) }}</span>
          <span class="tag">{{ t(`sece.preStatus.${p.status}`) }}</span>
          <div v-if="canManage && p.status === 'pending'" class="actions">
            <button type="button" class="btn" :disabled="busy" @click="decide(p, 'accepted')">{{ t('sece.accept') }}</button>
            <button type="button" class="btn" :disabled="busy" @click="decide(p, 'refused')">{{ t('sece.refuse') }}</button>
          </div>
          <div v-if="canManage && p.status === 'accepted'" class="row">
            <label :for="`cv-${p.id}`" class="sr-only">{{ t('secs.tab.classes') }}</label>
            <select :id="`cv-${p.id}`" v-model="convertClass"><option value="">—</option><option v-for="c in classes.filter((x) => x.level_code === p.target_level_code)" :key="c.id" :value="c.id">{{ c.label }}</option></select>
            <button type="button" class="btn btn--primary" :disabled="busy || convertClass === ''" @click="convert(p)">{{ t('sece.convert') }}</button>
          </div>
        </li>
      </ul>
      <p v-if="preregs.length === 0" class="muted">{{ t('sece.noPreregs') }}</p>
      <form v-if="canManage" class="grid" @submit.prevent="addPrereg">
        <h3>{{ t('sece.newPrereg') }}</h3>
        <div class="field"><label for="pr-last">{{ t('sece.lastName') }}</label><input id="pr-last" v-model="preForm.last_name" maxlength="120" required /></div>
        <div class="field"><label for="pr-first">{{ t('sece.firstName') }}</label><input id="pr-first" v-model="preForm.first_name" maxlength="120" required /></div>
        <div class="field"><label for="pr-birth">{{ t('sece.birthDate') }}</label><input id="pr-birth" v-model="preForm.birth_date" type="date" /></div>
        <div class="field">
<label for="pr-level">{{ t('secs.level') }}</label>
          <select id="pr-level" v-model="preForm.target_level_code" required><option v-for="code in levelCodes" :key="code" :value="code">{{ code }}</option></select>
</div>
        <div class="field">
<label for="pr-channel">{{ t('sece.channelLabel') }}</label>
          <select id="pr-channel" v-model="preForm.channel"><option value="desk">{{ t('sece.channel.desk') }}</option><option value="online">{{ t('sece.channel.online') }}</option></select>
</div>
        <div class="field"><label for="pr-gname">{{ t('sece.guardian') }}</label><input id="pr-gname" v-model="preForm.guardian_name" maxlength="160" /></div>
        <div class="field"><label for="pr-gphone">{{ t('sece.phone') }}</label><input id="pr-gphone" v-model="preForm.guardian_phone" type="tel" maxlength="20" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secs.add') }}</button>
      </form>
      <form v-if="canManage" class="grid" @submit.prevent="importPrimary">
        <h3>{{ t('sece.primaryTitle') }}</h3>
        <p class="muted">{{ t('sece.primaryHelp') }}</p>
        <label for="pf-json">{{ t('sece.primaryJson') }}</label><textarea id="pf-json" v-model="primary.json" rows="3" spellcheck="false" required />
        <label for="pf-sig">{{ t('sece.primarySignature') }}</label><input id="pf-sig" v-model="primary.signature" maxlength="128" required />
        <label for="pf-level">{{ t('secs.level') }}</label>
        <select id="pf-level" v-model="primary.level" required><option v-for="code in levelCodes" :key="code" :value="code">{{ code }}</option></select>
        <button type="submit" class="btn" :disabled="busy">{{ t('sece.primaryImport') }}</button>
      </form>
    </section>

    <section v-if="tab === 'reenrol'" class="card" aria-labelledby="se-re">
      <h2 id="se-re">{{ t('sece.tab.reenrol') }}</h2>
      <p class="muted">{{ t('sece.reenrolHelp') }}</p>
      <div class="field">
<label for="re-from">{{ t('sece.fromYear') }}</label>
        <select id="re-from" v-model="fromYear" @change="loadCandidates"><option value="">—</option><option v-for="y in years.filter((x) => x.id !== year)" :key="y.id" :value="y.id">{{ y.label }}</option></select>
</div>
      <ul class="rows">
        <li v-for="c in candidates" :key="c.person_id">
          <label class="check"><input v-model="reenrolPicked[c.person_id]" type="checkbox" :disabled="c.reenrolled_class_id !== null" /> {{ c.full_name ?? c.person_id }} · {{ c.level_code }}</label>
          <span class="tag" :class="{ 'tag--warning': !c.admitted }">{{ c.admitted ? t('sece.admitted') : t('sece.notAdmitted') }}</span>
          <span v-if="c.reenrolled_class_id" class="tag">{{ t('sece.alreadyReenrolled') }}</span>
        </li>
      </ul>
      <div v-if="canManage && candidates.length > 0" class="row">
        <label for="re-class" class="sr-only">{{ t('secs.tab.classes') }}</label>
        <select id="re-class" v-model="reenrolClass"><option value="">—</option><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select>
        <button type="button" class="btn btn--primary" :disabled="busy || reenrolClass === ''" @click="reenrol">{{ t('sece.reenrol') }}</button>
      </div>
      <div v-if="reenrolResult" class="notice" role="status">
        <p>{{ t('sece.reenrolDone', { n: reenrolResult.enrolled.length }) }}</p>
        <ul class="rows small"><li v-for="r in reenrolResult.refused" :key="r.person_id">{{ candidates.find((c) => c.person_id === r.person_id)?.full_name ?? r.person_id }} : {{ r.reason }}</li></ul>
      </div>
    </section>

    <section v-if="tab === 'transfers'" class="card" aria-labelledby="se-tr">
      <h2 id="se-tr">{{ t('sece.tab.transfers') }}</h2>
      <ul class="rows">
        <li v-for="tr in transfers" :key="tr.id">
{{ tr.transfer_date }} · {{ t(`sece.direction.${tr.direction}`) }} · {{ tr.other_school_label }}
          <span class="tag" :class="{ 'tag--warning': tr.status === 'to_check' }">{{ t(`sece.transferStatus.${tr.status}`) }}</span>
</li>
      </ul>
      <form v-if="canManage" class="grid" @submit.prevent="transferOut">
        <h3>{{ t('sece.transferOut') }}</h3>
        <div class="field">
<label for="to-person">{{ t('sece.student') }}</label>
          <select id="to-person" v-model="outForm.person_id" required><option v-for="s in students" :key="s.id" :value="s.person_id">{{ s.last_name }} {{ s.first_name }}</option></select>
</div>
        <div class="field"><label for="to-school">{{ t('sece.otherSchool') }}</label><input id="to-school" v-model="outForm.other_school_label" maxlength="160" required /></div>
        <div class="field"><label for="to-date">{{ t('sece.transferDate') }}</label><input id="to-date" v-model="outForm.transfer_date" type="date" required /></div>
        <div class="field"><label for="to-file">{{ t('sece.fileRef') }}</label><input id="to-file" v-model="outForm.file_ref" maxlength="120" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('sece.transferOut') }}</button>
      </form>
      <p class="muted">{{ t('sece.transferInHelp') }}</p>
    </section>

    <section v-if="tab === 'import' && canManage" class="card" aria-labelledby="se-imp">
      <h2 id="se-imp">{{ t('sece.tab.import') }}</h2>
      <p class="muted">{{ t('sece.importHelp') }}</p>
      <button type="button" class="btn" :disabled="busy" @click="downloadTemplate">{{ t('sece.template') }}</button>
      <div class="field"><label for="im-date">{{ t('sece.entryDate') }}</label><input id="im-date" v-model="entryDate" type="date" /></div>
      <div class="field"><label for="im-file">{{ t('sece.chooseFile') }}</label><input id="im-file" type="file" accept=".csv,.xlsx" :disabled="busy" @change="pickFile" /></div>
      <div v-if="importFile" class="actions">
        <button type="button" class="btn" :disabled="busy" @click="runImport(true)">{{ t('sece.simulate') }}</button>
        <button type="button" class="btn btn--primary" :disabled="busy || !importReport || importReport.summary.error > 0 || importReport.summary.valid === 0" @click="runImport(false)">{{ t('sece.apply') }}</button>
      </div>
      <div v-if="importReport" class="notice" role="status">
        <p>{{ t('sece.importSummary', { valid: importReport.summary.valid, error: importReport.summary.error, duplicate: importReport.summary.duplicate, total: importReport.summary.total }) }}</p>
        <p v-if="importReport.unknown_columns.length > 0" class="muted">{{ t('sece.unknownColumns', { columns: importReport.unknown_columns.join(', ') }) }}</p>
        <ul class="rows small">
          <li v-for="l in importReport.lines.filter((x) => x.status !== 'valid')" :key="l.line">{{ t('sece.line', { line: l.line }) }} · {{ l.last_name }} {{ l.first_name }} · {{ t(`sece.importStatus.${l.status}`) }} — {{ l.messages.join(' ') }}</li>
        </ul>
      </div>
    </section>
  </div>
</template>

<style scoped>
.tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
.tab { border: 1px solid var(--border, #ccc); background: transparent; border-radius: 999px; padding: 6px 12px; min-height: 44px; }
.tab[aria-pressed='true'] { background: var(--accent, #1d4ed8); color: #fff; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.grid { display: grid; gap: 8px; margin-top: 12px; border: 0; padding: 0; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.row select, .row input { flex: 1 1 160px; min-width: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.check { display: flex; gap: 8px; align-items: center; }
.small { font-size: 0.875rem; }
.enrol-fields { margin: 0; min-width: 0; }
.dossier-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.dossier { margin-top: 16px; border-top: 1px solid var(--border, #ddd); padding-top: 12px; }
.linkish { background: none; border: 0; padding: 0; text-align: left; cursor: pointer; color: inherit; min-height: 44px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
textarea, input, select { max-width: 100%; }
</style>
