<script setup lang="ts">
// Services aux familles (cantine, garderie, transport) : forfaits, abonnement des élèves inscrits, facturation par cycle (une facture par
// abonnement et par cycle) et présence du jour. Mobile d'abord ; le serveur juge les droits (primary.services.read / manage).
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  primaryServices, SERVICE_CYCLES,
  type ServiceAttendance, type ServiceCandidate, type ServicePlan, type ServicePlans, type ServiceSubscription,
} from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { formatXaf, todayIso } from '@/lib/format';
import { P } from './access';
import { capacityValue, serviceFees, type CatalogueFee } from './fixes';
import { useAction, usePermissions } from './usePri';

const { t, locale } = useI18n();
const { can } = usePermissions();
const load = useAction();
const act = useAction();
const catalogue = ref<ServicePlans>({ kinds: {}, plans: [] });
const plan = ref<ServicePlan | null>(null);
const subscriptions = ref<ServiceSubscription[]>([]);
const candidates = ref<ServiceCandidate[]>([]);
const attendance = ref<ServiceAttendance | null>(null);
const marks = reactive<Record<string, boolean | null>>({});
const notice = ref('');
const tried = ref(false);
const canManage = computed(() => can(...P.servicesManage));
// Frais du catalogue proposés pour le forfait (null : catalogue illisible, saisie libre du code).
const fees = ref<CatalogueFee[] | null>(null);
const newPlan = reactive({ service_kind: '', label: '', fee_code: '', billing_cycle: 'term' as (typeof SERVICE_CYCLES)[number], capacity: '' as string | number, route_label: '' });
const subscribe = reactive({ person_id: '', starts_on: todayIso() });
const billing = reactive({ cycle_key: '', due_on: todayIso(), result: '' });
const day = ref(todayIso());
const ending = ref<string | null>(null);
const endsOn = ref(todayIso());
const freeCandidates = computed(() => candidates.value.filter((c) => !c.subscribed));
const kindLabel = (code: string): string => {
  const k = catalogue.value.kinds[code];
  return k ? (locale.value === 'en' ? k.en : k.fr) : code;
};
const isFull = (p: ServicePlan): boolean => p.capacity !== null && (p.subscribers ?? 0) >= p.capacity;

async function loadPlans(): Promise<void> {
  const res = await load.run(() => primaryServices.plans());
  if (res) {
    catalogue.value = res;
    newPlan.service_kind = newPlan.service_kind || (Object.keys(res.kinds)[0] ?? '');
    if (plan.value) plan.value = res.plans.find((p) => p.id === plan.value?.id) ?? null;
  }
}

async function loadPlan(): Promise<void> {
  subscriptions.value = [];
  candidates.value = [];
  attendance.value = null;
  if (!plan.value) return;
  const id = plan.value.id;
  const res = await load.run(async () => ({
    subs: await primaryServices.subscriptions(id),
    cands: canManage.value ? await primaryServices.candidates(id) : [],
  }));
  if (res) {
    subscriptions.value = res.subs;
    candidates.value = res.cands;
  }
  await loadAttendance();
}

async function loadAttendance(): Promise<void> {
  if (!plan.value || !day.value) return;
  const id = plan.value.id;
  const res = await load.run(() => primaryServices.attendance(id, day.value));
  if (!res) return;
  attendance.value = res;
  for (const key of Object.keys(marks)) delete marks[key];
  for (const item of res.items) marks[item.subscription_id] = item.present;
}

function open(p: ServicePlan): void {
  plan.value = p;
  notice.value = '';
  billing.result = '';
  void loadPlan();
}

async function createPlan(): Promise<void> {
  tried.value = true;
  if (!newPlan.label.trim() || !newPlan.fee_code.trim() || !newPlan.service_kind) return;
  if (fees.value && fees.value.length > 0 && !fees.value.some((f) => f.code === newPlan.fee_code.trim())) return;
  // Le champ numérique rend un nombre : avant FIX-PRI 6, `.trim()` levait une erreur et le forfait n'était jamais envoyé.
  const capacity = capacityValue(newPlan.capacity);
  const done = await act.run(() => primaryServices.createPlan({
    service_kind: newPlan.service_kind, label: newPlan.label.trim(), fee_code: newPlan.fee_code.trim(), billing_cycle: newPlan.billing_cycle,
    capacity, route_label: newPlan.route_label.trim() === '' ? null : newPlan.route_label.trim(),
  }));
  if (done) {
    notice.value = t('pri.svc.created');
    newPlan.label = '';
    newPlan.fee_code = '';
    newPlan.capacity = '';
    newPlan.route_label = '';
    tried.value = false;
    await loadPlans();
  }
}

async function toggleActive(p: ServicePlan): Promise<void> {
  const done = await act.run(() => primaryServices.updatePlan(p.id, { active: !p.active }));
  if (done) await loadPlans();
}

async function addSubscription(): Promise<void> {
  const current = plan.value;
  const candidate = candidates.value.find((c) => c.person_id === subscribe.person_id);
  if (!current || !candidate || !subscribe.starts_on) return;
  const done = await act.run(() => primaryServices.subscribe(current.id, { person_id: candidate.person_id, academic_year_id: candidate.academic_year_id, starts_on: subscribe.starts_on }));
  if (done) {
    notice.value = t('pri.svc.subscribed');
    subscribe.person_id = '';
    await Promise.all([loadPlans(), loadPlan()]);
  }
}

async function endSubscription(id: string): Promise<void> {
  const done = await act.run(() => primaryServices.endSubscription(id, endsOn.value));
  if (done) {
    ending.value = null;
    notice.value = t('pri.svc.ended');
    await Promise.all([loadPlans(), loadPlan()]);
  }
}

async function invoiceCycle(): Promise<void> {
  const current = plan.value;
  billing.result = '';
  if (!current || billing.cycle_key.trim() === '' || !billing.due_on) return;
  const done = await act.run(() => primaryServices.invoice(current.id, { cycle_key: billing.cycle_key.trim(), due_on: billing.due_on }));
  if (done) billing.result = t('pri.svc.invoiced', { invoiced: done.invoiced, already: done.already });
}

async function saveAttendance(): Promise<void> {
  const current = plan.value;
  if (!current || !attendance.value) return;
  const entries = Object.entries(marks).filter(([, v]) => v !== null).map(([subscription_id, present]) => ({ subscription_id, present: present === true }));
  if (entries.length === 0) return;
  const done = await act.run(() => primaryServices.saveAttendance(current.id, day.value, entries));
  if (done) notice.value = t('pri.svc.attendanceSaved', { n: done.saved });
}

watch(day, () => void loadAttendance());
onMounted(() => void loadPlans());
// Les droits peuvent arriver après le montage (contexte chargé en différé) : la liste des frais suit canManage.
watch(canManage, async (manage) => {
  if (manage && fees.value === null) fees.value = await serviceFees();
}, { immediate: true });
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.svc.title') }}</h1>
      <p class="lede">{{ t('pri.svc.intro') }}</p>
    </header>
    <ErrorNotice :error="load.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="notice" class="notice notice--success" role="status" data-test="notice">{{ notice }}</p>

    <section class="card" aria-labelledby="sv-plans">
      <h2 id="sv-plans">{{ t('pri.svc.plans') }}</h2>
      <p v-if="catalogue.plans.length === 0" class="muted">{{ t('pri.svc.noPlan') }}</p>
      <ul class="rows">
        <li v-for="p in catalogue.plans" :key="p.id" :data-test="`plan-${p.id}`">
          <span>
            <strong>{{ p.label }}</strong> · {{ kindLabel(p.service_kind) }} · {{ t(`pri.svc.cycle.${p.billing_cycle}` as MessageKey) }}
            <template v-if="p.route_label"> · {{ p.route_label }}</template>
          </span>
          <span>
            <span class="tag">{{ t('pri.svc.subscribers', { n: p.subscribers ?? 0 }) }}<template v-if="p.capacity !== null"> / {{ p.capacity }}</template></span>
            <span v-if="isFull(p)" class="tag tag--warning">{{ t('pri.svc.full') }}</span>
            <span v-if="!p.active" class="tag tag--neutral">{{ t('pri.svc.inactive') }}</span>
            <button type="button" class="btn btn--quiet" :data-test="`open-${p.id}`" @click="open(p)">{{ t('pri.svc.open') }}</button>
            <button v-if="canManage" type="button" class="btn btn--quiet" @click="toggleActive(p)">{{ p.active ? t('pri.svc.deactivate') : t('pri.svc.activate') }}</button>
          </span>
        </li>
      </ul>
      <form v-if="canManage" class="form" novalidate data-test="new-plan" @submit.prevent="createPlan">
        <h3>{{ t('pri.svc.create') }}</h3>
        <div class="grid2">
          <div class="field"><label for="sv-kind">{{ t('pri.svc.kind') }}</label><select id="sv-kind" v-model="newPlan.service_kind"><option v-for="(k, code) in catalogue.kinds" :key="code" :value="code">{{ locale === 'en' ? k.en : k.fr }}</option></select></div>
          <div class="field"><label for="sv-label">{{ t('pri.svc.label') }}</label><input id="sv-label" v-model="newPlan.label" type="text" maxlength="160" :aria-invalid="tried && !newPlan.label.trim()" /><span v-if="tried && !newPlan.label.trim()" class="error">{{ t('pri.svc.required') }}</span></div>
          <div class="field">
            <label for="sv-fee">{{ t('pri.svc.fee') }}</label>
            <select v-if="fees && fees.length > 0" id="sv-fee" v-model="newPlan.fee_code" :aria-invalid="tried && !newPlan.fee_code.trim()">
              <option value="" disabled>{{ t('pri.svc.pickFee') }}</option>
              <option v-for="f in fees" :key="f.code" :value="f.code">{{ f.name }} ({{ f.code }}) · {{ formatXaf(f.amount_xaf) }}</option>
            </select>
            <input v-else id="sv-fee" v-model="newPlan.fee_code" type="text" maxlength="40" :aria-invalid="tried && !newPlan.fee_code.trim()" aria-describedby="sv-fee-hint" />
            <span v-if="tried && !newPlan.fee_code.trim()" class="error">{{ t('pri.svc.required') }}</span>
            <span v-if="fees && fees.length === 0" id="sv-fee-hint" class="muted" data-test="fee-hint">{{ t('pri.svc.noCodedFee') }}</span>
          </div>
          <div class="field"><label for="sv-cycle">{{ t('pri.svc.cycle') }}</label><select id="sv-cycle" v-model="newPlan.billing_cycle"><option v-for="c in SERVICE_CYCLES" :key="c" :value="c">{{ t(`pri.svc.cycle.${c}` as MessageKey) }}</option></select></div>
          <div class="field"><label for="sv-cap">{{ t('pri.svc.capacity') }}</label><input id="sv-cap" v-model="newPlan.capacity" type="number" min="1" max="5000" /></div>
          <div class="field"><label for="sv-route">{{ t('pri.svc.route') }}</label><input id="sv-route" v-model="newPlan.route_label" type="text" maxlength="160" /></div>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('pri.svc.create') }}</button>
      </form>
    </section>

    <template v-if="plan">
      <section class="card" aria-labelledby="sv-subs" data-test="subscriptions">
        <h2 id="sv-subs">{{ t('pri.svc.subs') }} · {{ plan.label }}</h2>
        <p v-if="subscriptions.length === 0" class="muted">{{ t('pri.svc.noSub') }}</p>
        <ul class="rows">
          <li v-for="s in subscriptions" :key="s.id" :data-test="`sub-${s.person_id}`">
            <span>{{ s.name ?? s.person_id }} · {{ t('pri.svc.from') }} {{ s.starts_on }}<template v-if="s.ends_on"> · {{ t('pri.svc.until') }} {{ s.ends_on }}</template></span>
            <span>
              <span class="tag" :class="s.status === 'active' ? 'tag--success' : 'tag--neutral'">{{ t(`pri.svc.status.${s.status}` as MessageKey) }}</span>
              <button v-if="canManage && s.status === 'active' && ending !== s.id" type="button" class="btn btn--quiet" :data-test="`end-${s.person_id}`" @click="ending = s.id">{{ t('pri.svc.end') }}</button>
              <template v-if="ending === s.id">
                <label class="sr-only" :for="`sv-end-${s.id}`">{{ t('pri.svc.endDate') }}</label>
                <input :id="`sv-end-${s.id}`" v-model="endsOn" type="date" />
                <button type="button" class="btn btn--ink" :data-test="`end-yes-${s.person_id}`" @click="endSubscription(s.id)">{{ t('pri.svc.endConfirm') }}</button>
              </template>
            </span>
          </li>
        </ul>
        <form v-if="canManage && plan.active" class="form" novalidate data-test="subscribe" @submit.prevent="addSubscription">
          <h3>{{ t('pri.svc.subscribe') }}</h3>
          <div class="grid2">
            <div class="field"><label for="sv-learner">{{ t('pri.svc.learner') }}</label><select id="sv-learner" v-model="subscribe.person_id"><option value="" disabled>{{ t('pri.svc.pickLearner') }}</option><option v-for="c in freeCandidates" :key="c.person_id" :value="c.person_id">{{ c.name }}</option></select></div>
            <div class="field"><label for="sv-start">{{ t('pri.svc.startsOn') }}</label><input id="sv-start" v-model="subscribe.starts_on" type="date" required /></div>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="act.busy.value || subscribe.person_id === ''">{{ t('pri.svc.subscribe') }}</button>
        </form>
      </section>

      <section v-if="canManage" class="card" aria-labelledby="sv-bill" data-test="billing">
        <h2 id="sv-bill">{{ t('pri.svc.billing') }}</h2>
        <form class="form" novalidate @submit.prevent="invoiceCycle">
          <div class="grid2">
            <div class="field"><label for="sv-key">{{ t('pri.svc.cycleKey') }}</label><input id="sv-key" v-model="billing.cycle_key" type="text" maxlength="40" required /></div>
            <div class="field"><label for="sv-due">{{ t('pri.svc.dueOn') }}</label><input id="sv-due" v-model="billing.due_on" type="date" required /></div>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="act.busy.value || billing.cycle_key.trim() === ''">{{ t('pri.svc.invoice') }}</button>
        </form>
        <p v-if="billing.result" class="notice" role="status" data-test="invoiced">{{ billing.result }}</p>
      </section>

      <section class="card" aria-labelledby="sv-att" data-test="attendance">
        <h2 id="sv-att">{{ t('pri.svc.attendance') }}</h2>
        <div class="field"><label for="sv-day">{{ t('pri.svc.date') }}</label><input id="sv-day" v-model="day" type="date" :max="todayIso()" /></div>
        <p v-if="attendance && attendance.items.length === 0" class="muted">{{ t('pri.svc.noneThatDay') }}</p>
        <ul class="rows">
          <li v-for="item in attendance?.items ?? []" :key="item.subscription_id" :data-test="`att-${item.person_id}`">
            <span>{{ item.name }}</span>
            <span class="seg" role="group" :aria-label="item.name">
              <label class="check"><input v-model="marks[item.subscription_id]" type="radio" :name="`att-${item.subscription_id}`" :value="true" :disabled="!canManage" /> {{ t('pri.svc.present') }}</label>
              <label class="check"><input v-model="marks[item.subscription_id]" type="radio" :name="`att-${item.subscription_id}`" :value="false" :disabled="!canManage" /> {{ t('pri.svc.absent') }}</label>
              <span v-if="marks[item.subscription_id] === null || marks[item.subscription_id] === undefined" class="muted">{{ t('pri.svc.unset') }}</span>
            </span>
          </li>
        </ul>
        <button v-if="canManage && attendance && attendance.items.length > 0" type="button" class="btn btn--primary" data-test="save-attendance" :disabled="act.busy.value" @click="saveAttendance">{{ t('pri.svc.saveAttendance') }}</button>
      </section>
    </template>
  </div>
</template>
