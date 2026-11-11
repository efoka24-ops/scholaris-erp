<script setup lang="ts">
// Services annexes du secondaire (T972, T973) : cantine et transport, internat, infirmerie. Une rubrique par module utilisable ; un
// module coupé reste consultable sans aucune action d'écriture (EX-SEC-04). Données de santé : infirmier seul, chaque lecture
// journalisée par le serveur. Mobile d'abord : une colonne, champs pleine largeur.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { secondaryProfile, type ModuleState, type SecondaryModule } from '@/api/secondaryProfile';
import {
  INFIRMARY_OUTCOMES, INFIRMARY_REASONS, parseStops, secondaryServices,
  type AccessLogEntry, type Exemption, type HealthRecord, type InfirmaryVisit, type ServiceGroup, type ServiceKind, type ServiceOffer, type ServiceSubscription,
} from '@/api/secondaryServices';
import { useI18n } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import StudentSelect from '../StudentSelect.vue';
import { useStudentDirectory } from '../studentDirectory';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();
// Élèves choisis par leur nom (FIX-SEC) : annuaire des élèves, jamais l'identifiant interne à saisir.
const directory = useStudentDirectory();

const GROUPS: { group: ServiceGroup; module: SecondaryModule; kinds: ServiceKind[] }[] = [
  { group: 'canteen-transport', module: 'canteen_transport', kinds: ['canteen', 'transport'] },
  { group: 'boarding', module: 'boarding', kinds: ['boarding'] },
];
const SERVICE_READ = ['secondary.services.manage', 'secondary.billing.manage', 'secondary.settings.manage', 'secondary.finance.read'];

const states = ref<Partial<Record<SecondaryModule, ModuleState>>>({});
const profileKnown = ref(false);
const notice = ref('');
const offers = ref<Record<ServiceGroup, ServiceOffer[]>>({ 'canteen-transport': [], boarding: [] });
const subscriptions = ref<Record<ServiceGroup, ServiceSubscription[]>>({ 'canteen-transport': [], boarding: [] });
const offerForm = ref<Record<ServiceGroup, { kind: ServiceKind; label: string; stops: string; capacity: number | ''; feeCode: string; amount: number }>>({
  'canteen-transport': { kind: 'canteen', label: '', stops: '', capacity: '', feeCode: '', amount: 0 },
  boarding: { kind: 'boarding', label: '', stops: '', capacity: '', feeCode: '', amount: 0 },
});
const subForm = ref<Record<ServiceGroup, { offerId: string; personId: string; startsOn: string; endsOn: string; stop: string; invoice: boolean }>>({
  'canteen-transport': { offerId: '', personId: '', startsOn: '', endsOn: '', stop: '', invoice: false },
  boarding: { offerId: '', personId: '', startsOn: '', endsOn: '', stop: '', invoice: false },
});

const isNurse = computed(() => can('secondary.infirmary.manage'));
const canLog = computed(() => can('secondary.infirmary.manage', 'secondary.settings.manage'));
const canServices = computed(() => can(...SERVICE_READ));
const canWriteServices = computed(() => can('secondary.services.manage'));
const canWriteOffers = computed(() => can('secondary.services.manage', 'secondary.settings.manage'));
const visits = ref<InfirmaryVisit[]>([]);
const openedVisit = ref<InfirmaryVisit | null>(null);
const exemptions = ref<Exemption[]>([]);
const log = ref<AccessLogEntry[]>([]);
const visitForm = ref({ personId: '', reason: 'illness' as (typeof INFIRMARY_REASONS)[number], outcome: 'back_to_class' as (typeof INFIRMARY_OUTCOMES)[number], parentCalled: false, notes: '', exemptUntil: '' });
const recordPerson = ref('');
const record = ref<HealthRecord | null>(null);
const money = (n: number): string => `${new Intl.NumberFormat('fr-FR').format(n)} FCFA`;

function usable(module: SecondaryModule): boolean {
  if (!profileKnown.value) return true;
  const state = states.value[module];
  return state === 'active' || state === 'read_only';
}
function readOnly(module: SecondaryModule): boolean {
  return states.value[module] === 'read_only';
}
const visibleGroups = computed(() => (canServices.value ? GROUPS.filter((g) => usable(g.module)) : []));
const showInfirmary = computed(() => usable('infirmary') && (isNurse.value || canLog.value));

async function loadProfile(): Promise<void> {
  try {
    const profile = await secondaryProfile.get();
    states.value = Object.fromEntries(profile.modules.map((m) => [m.code, m.state]));
    profileKnown.value = true;
  } catch {
    profileKnown.value = false;
  }
}

async function loadGroup(group: ServiceGroup): Promise<void> {
  offers.value[group] = (await run(() => secondaryServices.offers(group))) ?? [];
  subscriptions.value[group] = (await run(() => secondaryServices.subscriptions(group, { 'filter[status]': 'active' }))) ?? [];
}

async function loadInfirmary(): Promise<void> {
  if (isNurse.value) {
    visits.value = (await run(() => secondaryServices.visits({ per_page: 50 }))) ?? [];
    exemptions.value = (await run(() => secondaryServices.exemptions()))?.exemptions ?? [];
  }
  if (canLog.value) log.value = (await run(() => secondaryServices.accessLog({ per_page: 50 }))) ?? [];
}

async function load(): Promise<void> {
  await loadProfile();
  if (visibleGroups.value.length > 0 || showInfirmary.value) await directory.load();
  for (const g of visibleGroups.value) await loadGroup(g.group);
  if (showInfirmary.value) await loadInfirmary();
}

async function createOffer(group: ServiceGroup): Promise<void> {
  const f = offerForm.value[group];
  if (f.label.trim() === '' || !Number.isInteger(Number(f.amount))) return;
  const created = await run(() => secondaryServices.createOffer(group, {
    service_kind: f.kind, label: f.label.trim(), amount_xaf: Number(f.amount),
    ...(f.kind === 'transport' ? { stops: parseStops(f.stops) } : {}),
    ...(f.capacity !== '' ? { capacity: Number(f.capacity) } : {}),
    ...(f.feeCode.trim() !== '' ? { fee_code: f.feeCode.trim() } : {}),
  }));
  if (created) {
    offerForm.value[group] = { ...f, label: '', stops: '', capacity: '', feeCode: '', amount: 0 };
    notice.value = t('secsv.saved');
    await loadGroup(group);
  }
}
async function toggleOffer(group: ServiceGroup, offer: ServiceOffer): Promise<void> {
  if (await run(() => secondaryServices.updateOffer(group, offer.id, { active: !offer.active }))) await loadGroup(group);
}
function stopsOf(group: ServiceGroup): string[] {
  return offers.value[group].find((o) => o.id === subForm.value[group].offerId)?.stops ?? [];
}
async function subscribe(group: ServiceGroup): Promise<void> {
  const f = subForm.value[group];
  if (f.offerId === '' || f.personId.trim() === '' || f.startsOn === '') return;
  const created = await run(() => secondaryServices.subscribe(group, {
    offer_id: f.offerId, person_id: f.personId.trim(), starts_on: f.startsOn, invoice: f.invoice,
    ...(f.endsOn !== '' ? { ends_on: f.endsOn } : {}),
    ...(f.stop !== '' ? { stop_label: f.stop } : {}),
  }));
  if (created) {
    subForm.value[group] = { offerId: f.offerId, personId: '', startsOn: f.startsOn, endsOn: '', stop: '', invoice: false };
    notice.value = t('secsv.saved');
    await loadGroup(group);
  }
}
async function endSubscription(group: ServiceGroup, s: ServiceSubscription): Promise<void> {
  const endsOn = (window.prompt(t('secsv.endDatePrompt')) ?? '').trim();
  const reason = endsOn === '' ? '' : (window.prompt(t('secsv.reasonPrompt')) ?? '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(endsOn) || reason === '') return;
  if (await run(() => secondaryServices.endSubscription(group, s.id, endsOn, reason))) await loadGroup(group);
}

async function recordVisit(): Promise<void> {
  const f = visitForm.value;
  if (f.personId.trim() === '') return;
  const created = await run(() => secondaryServices.recordVisit({
    person_id: f.personId.trim(), reason_code: f.reason, outcome: f.outcome, parent_called: f.parentCalled,
    ...(f.notes.trim() !== '' ? { notes: f.notes.trim() } : {}),
    ...(f.exemptUntil !== '' ? { pe_exempt_until: f.exemptUntil } : {}),
  }));
  if (created) {
    visitForm.value = { personId: '', reason: 'illness', outcome: 'back_to_class', parentCalled: false, notes: '', exemptUntil: '' };
    notice.value = t('secsv.saved');
    await loadInfirmary();
  }
}
async function showVisit(v: InfirmaryVisit): Promise<void> {
  openedVisit.value = (await run(() => secondaryServices.visit(v.id))) ?? null;
}
async function openRecord(): Promise<void> {
  if (recordPerson.value.trim() === '') return;
  record.value = (await run(() => secondaryServices.record(recordPerson.value.trim()))) ?? null;
}
async function saveRecord(): Promise<void> {
  const r = record.value;
  if (!r) return;
  const clean = (v: string | null): string | null => (v === null || v.trim() === '' ? null : v.trim());
  const saved = await run(() => secondaryServices.saveRecord(r.person_id, {
    allergies: clean(r.allergies), conditions: clean(r.conditions), treatments: clean(r.treatments), emergency_instructions: clean(r.emergency_instructions),
  }));
  if (saved) {
    record.value = saved;
    notice.value = t('secsv.saved');
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secsv.title') }}</h1>
      <p class="lede">{{ t('secsv.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
    <p v-if="visibleGroups.length === 0 && !showInfirmary" class="muted">{{ t('secsv.none') }}</p>

    <section v-for="g in visibleGroups" :key="g.group" class="card" :aria-labelledby="`sv-${g.group}`">
      <h2 :id="`sv-${g.group}`">{{ t(`secsv.group.${g.group}`) }}</h2>
      <p v-if="readOnly(g.module)" class="notice notice--warning" role="status">{{ t('secsv.readOnly') }}</p>

      <h3>{{ t('secsv.offers') }}</h3>
      <p v-if="offers[g.group].length === 0" class="muted">{{ t('secsv.noOffers') }}</p>
      <ul v-else class="rows">
        <li v-for="o in offers[g.group]" :key="o.id">
          <strong>{{ o.label }}</strong> · {{ t(`secsv.kind.${o.service_kind}`) }} · {{ money(o.amount_xaf) }}
          <span v-if="!o.active" class="tag">{{ t('secsv.closed') }}</span>
          <div class="muted">
            {{ t('secsv.subscribers', { count: o.subscribers }) }}<template v-if="o.capacity !== null"> / {{ o.capacity }}</template>
            <template v-if="o.stops.length > 0"> · {{ o.stops.join(' → ') }}</template>
          </div>
          <div v-if="canWriteOffers && !readOnly(g.module)" class="actions">
            <button type="button" class="btn" :disabled="busy" @click="toggleOffer(g.group, o)">{{ o.active ? t('secsv.close') : t('secsv.reopen') }}</button>
          </div>
        </li>
      </ul>

      <form v-if="canWriteOffers && !readOnly(g.module)" class="stack" @submit.prevent="createOffer(g.group)">
        <h3>{{ t('secsv.offerNew') }}</h3>
        <div v-if="g.kinds.length > 1" class="field">
          <label :for="`sv-kind-${g.group}`">{{ t('secsv.kindLabel') }}</label>
          <select :id="`sv-kind-${g.group}`" v-model="offerForm[g.group].kind">
            <option v-for="k in g.kinds" :key="k" :value="k">{{ t(`secsv.kind.${k}`) }}</option>
          </select>
        </div>
        <div class="field"><label :for="`sv-label-${g.group}`">{{ t('secsv.label') }}</label><input :id="`sv-label-${g.group}`" v-model="offerForm[g.group].label" type="text" maxlength="160" /></div>
        <div v-if="offerForm[g.group].kind === 'transport'" class="field">
          <label :for="`sv-stops-${g.group}`">{{ t('secsv.stops') }}</label>
          <textarea :id="`sv-stops-${g.group}`" v-model="offerForm[g.group].stops" rows="4"></textarea>
        </div>
        <div class="field"><label :for="`sv-cap-${g.group}`">{{ t('secsv.capacity') }}</label><input :id="`sv-cap-${g.group}`" v-model.number="offerForm[g.group].capacity" type="number" min="1" step="1" inputmode="numeric" /></div>
        <div class="field"><label :for="`sv-fee-${g.group}`">{{ t('secsv.feeCode') }}</label><input :id="`sv-fee-${g.group}`" v-model="offerForm[g.group].feeCode" type="text" maxlength="40" autocomplete="off" /></div>
        <div class="field"><label :for="`sv-amt-${g.group}`">{{ t('secsv.amount') }}</label><input :id="`sv-amt-${g.group}`" v-model.number="offerForm[g.group].amount" type="number" min="0" step="1" inputmode="numeric" /></div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsv.create') }}</button></div>
      </form>

      <h3>{{ t('secsv.subscriptions') }}</h3>
      <p v-if="subscriptions[g.group].length === 0" class="muted">{{ t('secsv.noSubscriptions') }}</p>
      <ul v-else class="rows">
        <li v-for="s in subscriptions[g.group]" :key="s.id">
          <strong>{{ s.student_name ?? directory.nameOf(s.person_id) }}</strong> · {{ s.offer_label }}<template v-if="s.stop_label"> · {{ s.stop_label }}</template>
          <span v-if="s.invoice_id" class="tag">{{ t('secsv.invoiced') }}</span>
          <div class="muted">{{ s.starts_on }}<template v-if="s.ends_on"> → {{ s.ends_on }}</template></div>
          <div v-if="canWriteServices && !readOnly(g.module)" class="actions">
            <button type="button" class="btn" :disabled="busy" @click="endSubscription(g.group, s)">{{ t('secsv.end') }}</button>
          </div>
        </li>
      </ul>

      <form v-if="canWriteServices && !readOnly(g.module)" class="stack" @submit.prevent="subscribe(g.group)">
        <h3>{{ t('secsv.subscribe') }}</h3>
        <div class="field">
          <label :for="`sv-offer-${g.group}`">{{ t('secsv.offer') }}</label>
          <select :id="`sv-offer-${g.group}`" v-model="subForm[g.group].offerId">
            <option v-for="o in offers[g.group].filter((x) => x.active)" :key="o.id" :value="o.id">{{ o.label }}</option>
          </select>
        </div>
        <StudentSelect :id="`sv-person-${g.group}`" v-model="subForm[g.group].personId" :label="t('secsv.personId')" :students="directory.students.value" :loaded="directory.loaded.value" />
        <div v-if="stopsOf(g.group).length > 0" class="field">
          <label :for="`sv-stop-${g.group}`">{{ t('secsv.stop') }}</label>
          <select :id="`sv-stop-${g.group}`" v-model="subForm[g.group].stop">
            <option v-for="stop in stopsOf(g.group)" :key="stop" :value="stop">{{ stop }}</option>
          </select>
        </div>
        <div class="field"><label :for="`sv-start-${g.group}`">{{ t('secsv.startsOn') }}</label><input :id="`sv-start-${g.group}`" v-model="subForm[g.group].startsOn" type="date" /></div>
        <div class="field"><label :for="`sv-end-${g.group}`">{{ t('secsv.endsOn') }}</label><input :id="`sv-end-${g.group}`" v-model="subForm[g.group].endsOn" type="date" /></div>
        <label class="check"><input v-model="subForm[g.group].invoice" type="checkbox" /> {{ t('secsv.invoice') }}</label>
        <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsv.subscribe') }}</button></div>
      </form>
    </section>

    <section v-if="showInfirmary" class="card" aria-labelledby="sv-infirmary">
      <h2 id="sv-infirmary">{{ t('secsv.infirmary') }}</h2>
      <p class="muted">{{ t('secsv.infirmaryPrivacy') }}</p>
      <p v-if="readOnly('infirmary')" class="notice notice--warning" role="status">{{ t('secsv.readOnly') }}</p>

      <template v-if="isNurse">
        <h3>{{ t('secsv.exemptions') }}</h3>
        <p v-if="exemptions.length === 0" class="muted">{{ t('secsv.noExemptions') }}</p>
        <ul v-else class="rows">
          <li v-for="e in exemptions" :key="e.person_id">{{ directory.nameOf(e.person_id) }} · {{ t('secsv.exemptTo', { date: e.exempt_until }) }}</li>
        </ul>

        <form v-if="!readOnly('infirmary')" class="stack" @submit.prevent="recordVisit">
          <h3>{{ t('secsv.visitNew') }}</h3>
          <StudentSelect id="sv-v-person" v-model="visitForm.personId" :label="t('secsv.personId')" :students="directory.students.value" :loaded="directory.loaded.value" />
          <div class="field">
            <label for="sv-v-reason">{{ t('secsv.reason') }}</label>
            <select id="sv-v-reason" v-model="visitForm.reason">
              <option v-for="r in INFIRMARY_REASONS" :key="r" :value="r">{{ t(`secsv.reason.${r}`) }}</option>
            </select>
          </div>
          <div class="field">
            <label for="sv-v-outcome">{{ t('secsv.outcome') }}</label>
            <select id="sv-v-outcome" v-model="visitForm.outcome">
              <option v-for="o in INFIRMARY_OUTCOMES" :key="o" :value="o">{{ t(`secsv.outcome.${o}`) }}</option>
            </select>
          </div>
          <label class="check"><input v-model="visitForm.parentCalled" type="checkbox" /> {{ t('secsv.parentCalled') }}</label>
          <div class="field"><label for="sv-v-notes">{{ t('secsv.notes') }}</label><textarea id="sv-v-notes" v-model="visitForm.notes" rows="3" maxlength="2000"></textarea></div>
          <div class="field"><label for="sv-v-exempt">{{ t('secsv.exemptUntil') }}</label><input id="sv-v-exempt" v-model="visitForm.exemptUntil" type="date" /></div>
          <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsv.record') }}</button></div>
        </form>

        <h3>{{ t('secsv.visits') }}</h3>
        <p v-if="visits.length === 0" class="muted">{{ t('secsv.noVisits') }}</p>
        <ul v-else class="rows">
          <li v-for="v in visits" :key="v.id">
            <strong>{{ v.student_name ?? directory.nameOf(v.person_id) }}</strong> · {{ v.visited_at.slice(0, 16).replace('T', ' ') }} · {{ t(`secsv.reason.${v.reason_code}`) }} · {{ t(`secsv.outcome.${v.outcome}`) }}
            <span v-if="v.parent_called" class="tag">{{ t('secsv.parentCalled') }}</span>
            <div class="actions"><button type="button" class="btn" :disabled="busy" @click="showVisit(v)">{{ t('secsv.showNotes') }}</button></div>
            <p v-if="openedVisit && openedVisit.id === v.id" class="muted">{{ openedVisit.notes ?? '—' }}</p>
          </li>
        </ul>

        <h3>{{ t('secsv.healthRecord') }}</h3>
        <form class="stack" @submit.prevent="openRecord">
          <StudentSelect id="sv-r-person" v-model="recordPerson" :label="t('secsv.personId')" :students="directory.students.value" :loaded="directory.loaded.value" />
          <div><button type="submit" class="btn" :disabled="busy">{{ t('secsv.open') }}</button></div>
        </form>
        <form v-if="record" class="stack" @submit.prevent="saveRecord">
          <div class="field"><label for="sv-r-allergies">{{ t('secsv.allergies') }}</label><textarea id="sv-r-allergies" v-model="record.allergies" rows="2" :readonly="readOnly('infirmary')"></textarea></div>
          <div class="field"><label for="sv-r-conditions">{{ t('secsv.conditions') }}</label><textarea id="sv-r-conditions" v-model="record.conditions" rows="2" :readonly="readOnly('infirmary')"></textarea></div>
          <div class="field"><label for="sv-r-treatments">{{ t('secsv.treatments') }}</label><textarea id="sv-r-treatments" v-model="record.treatments" rows="2" :readonly="readOnly('infirmary')"></textarea></div>
          <div class="field"><label for="sv-r-emergency">{{ t('secsv.emergency') }}</label><textarea id="sv-r-emergency" v-model="record.emergency_instructions" rows="2" :readonly="readOnly('infirmary')"></textarea></div>
          <div v-if="!readOnly('infirmary')"><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secsv.saveRecord') }}</button></div>
        </form>
      </template>

      <template v-if="canLog">
        <h3>{{ t('secsv.accessLog') }}</h3>
        <p v-if="log.length === 0" class="muted">{{ t('secsv.noLog') }}</p>
        <ul v-else class="rows">
          <li v-for="l in log" :key="l.id">{{ l.created_at.slice(0, 16).replace('T', ' ') }} · {{ l.user_id }} · {{ t(`secsv.access.${l.access_kind}` as 'secsv.access.read_record') }}<template v-if="l.person_id"> · {{ directory.nameOf(l.person_id) }}</template></li>
        </ul>
      </template>
    </section>
  </div>
</template>
