<script setup lang="ts">
// Frais du secondaire (T830 à T832, T978) : le mode suit le profil de l'établissement (privé : préréglages et plans de paiement ;
// public : catalogue des frais autorisés et demandes d'ajout). Grilles, factures et paiements : écrans Frais du socle. Valeurs « à confirmer ».
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  planTotal, secondaryFees, validInstallments,
  type CatalogEntry, type FeeRequest, type FeesOverview, type Installment, type PaymentPlan,
} from '@/api/secondaryFees';
import { useI18n } from '@/i18n';
import { useAction, usePermissions } from '../useSec';

const { t } = useI18n();
const { error, busy, run } = useAction();
const { can } = usePermissions();

const overview = ref<FeesOverview | null>(null);
const plans = ref<PaymentPlan[]>([]);
const catalog = ref<CatalogEntry[]>([]);
const requests = ref<FeeRequest[]>([]);
const notice = ref('');

const canManage = computed(() => can('secondary.billing.manage'));
const canValidate = computed(() => can('secondary.fees.validate'));
const canRequest = computed(() => can('secondary.fees.catalog', 'secondary.billing.manage'));
const canKeepCatalog = computed(() => can('secondary.profile.manage'));
const money = (n: number): string => `${new Intl.NumberFormat('fr-FR').format(n)} FCFA`;

const plan = ref({ personId: '', invoiceId: '', reason: '', installments: [{ due_on: '', amount: 0 }] as Installment[] });
const planOk = computed(() => plan.value.personId.trim() !== '' && plan.value.reason.trim() !== '' && validInstallments(plan.value.installments));
const ask = ref({ kind: 'other', label: '', amount: 0, justification: '' });
const entry = ref({ kind: 'apee', label: '', maxAmount: '' as number | '', legalBasis: '', clientWide: true });

async function load(): Promise<void> {
  const o = await run(() => secondaryFees.overview());
  if (!o) return;
  overview.value = o;
  if (o.mode === 'private') plans.value = (await run(() => secondaryFees.plans())) ?? [];
  if (o.mode === 'public_catalog') {
    catalog.value = (await run(() => secondaryFees.catalog())) ?? [];
    requests.value = (await run(() => secondaryFees.requests())) ?? [];
  }
}

async function setupPrivate(): Promise<void> {
  const presets = await run(() => secondaryFees.setupPrivate());
  if (presets) {
    const applied = Object.values(presets).filter((p) => p.applied).length;
    notice.value = t('secf.setupDone', { count: applied });
    await load();
  }
}

function addInstallment(): void {
  plan.value.installments.push({ due_on: '', amount: 0 });
}
function removeInstallment(index: number): void {
  if (plan.value.installments.length > 1) plan.value.installments.splice(index, 1);
}

async function proposePlan(): Promise<void> {
  if (!planOk.value) return;
  const p = plan.value;
  const created = await run(() => secondaryFees.proposePlan({
    person_id: p.personId.trim(), reason: p.reason.trim(), installments: p.installments.map((i) => ({ due_on: i.due_on, amount: Number(i.amount) })),
    ...(p.invoiceId.trim() !== '' ? { invoice_id: p.invoiceId.trim() } : {}),
  }));
  if (created) {
    plan.value = { personId: '', invoiceId: '', reason: '', installments: [{ due_on: '', amount: 0 }] };
    notice.value = t('secf.planProposed');
    await load();
  }
}

async function decidePlan(p: PaymentPlan, approve: boolean): Promise<void> {
  const reason = approve ? '' : (window.prompt(t('secf.reasonPrompt')) ?? '').trim();
  if (!approve && reason === '') return;
  if (await run(() => (approve ? secondaryFees.approvePlan(p.id) : secondaryFees.refusePlan(p.id, reason)))) await load();
}
async function cancelPlan(p: PaymentPlan): Promise<void> {
  const reason = (window.prompt(t('secf.reasonPrompt')) ?? '').trim();
  if (reason !== '' && (await run(() => secondaryFees.cancelPlan(p.id, reason)))) await load();
}

async function sendRequest(): Promise<void> {
  const a = ask.value;
  if (a.label.trim() === '' || a.justification.trim() === '' || !Number.isInteger(Number(a.amount))) return;
  if (await run(() => secondaryFees.request({ fee_kind: a.kind, label: a.label.trim(), amount: Number(a.amount), justification: a.justification.trim() }))) {
    ask.value = { kind: 'other', label: '', amount: 0, justification: '' };
    notice.value = t('secf.requestSent');
    await load();
  }
}
async function decideRequest(r: FeeRequest, accept: boolean): Promise<void> {
  const reason = (window.prompt(t('secf.reasonPrompt')) ?? '').trim();
  if (reason === '') return;
  const basis = accept ? (window.prompt(t('secf.legalBasisPrompt')) ?? '').trim() : '';
  if (accept && basis === '') return;
  if (await run(() => secondaryFees.decide(r.id, accept ? 'accepted' : 'refused', reason, basis || undefined))) await load();
}
async function addEntry(): Promise<void> {
  const e = entry.value;
  if (e.label.trim() === '' || e.legalBasis.trim() === '') return;
  const created = await run(() => secondaryFees.addCatalog({
    fee_kind: e.kind, label: e.label.trim(), legal_basis: e.legalBasis.trim(), client_wide: e.clientWide,
    ...(e.maxAmount !== '' ? { max_amount: Number(e.maxAmount) } : {}),
  }));
  if (created) {
    entry.value = { kind: 'apee', label: '', maxAmount: '', legalBasis: '', clientWide: true };
    await load();
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('sec.home.eyebrow') }}</p>
      <h1>{{ t('secf.title') }}</h1>
      <p class="lede">{{ t('secf.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <template v-if="overview">
      <section class="card" aria-labelledby="sf-mode">
        <h2 id="sf-mode">{{ t(`secf.mode.${overview.mode}`) }}</h2>
        <p class="muted">{{ t(`secf.modeText.${overview.mode}`) }}</p>
        <p class="muted">{{ t('secf.toConfirm') }}</p>
        <p>{{ t('secf.refusedKinds', { kinds: overview.refused_kinds.join(', ') || '—' }) }}</p>
        <p class="muted">{{ t('secf.noExclusion') }}</p>
      </section>

      <template v-if="overview.mode === 'private'">
        <section class="card" aria-labelledby="sf-setup">
          <h2 id="sf-setup">{{ t('secf.setupTitle') }}</h2>
          <dl class="pairs">
            <dt>{{ t('secf.allocationRule') }}</dt><dd>{{ overview.allocation_rule || t('secf.notSet') }}</dd>
            <dt>{{ t('secf.kindOrder') }}</dt><dd>{{ overview.allocation_kind_order?.join(' → ') ?? t('secf.notSet') }}</dd>
            <dt>{{ t('secf.siblingOrder') }}</dt><dd>{{ overview.allocation_sibling_order ?? t('secf.notSet') }}</dd>
            <dt>{{ t('secf.reminders') }}</dt><dd>{{ overview.reminder_offsets?.join(', ') ?? t('secf.notSet') }}</dd>
          </dl>
          <p class="muted">{{ t('secf.setupHelp') }}</p>
          <button v-if="canManage" type="button" class="btn btn--primary" :disabled="busy" @click="setupPrivate">{{ t('secf.setupApply') }}</button>
          <p class="muted">{{ t('secf.gridHelp') }} <RouterLink :to="{ name: 'billing' }">{{ t('secf.gridLink') }}</RouterLink></p>
        </section>

        <section v-if="canManage" class="card" aria-labelledby="sf-plan-new">
          <h2 id="sf-plan-new">{{ t('secf.planNew') }}</h2>
          <form class="stack" @submit.prevent="proposePlan">
            <div class="field">
              <label for="sf-person">{{ t('secf.personId') }}</label>
              <input id="sf-person" v-model="plan.personId" type="text" maxlength="40" autocomplete="off" />
            </div>
            <div class="field">
              <label for="sf-invoice">{{ t('secf.invoiceId') }}</label>
              <input id="sf-invoice" v-model="plan.invoiceId" type="text" maxlength="40" autocomplete="off" />
            </div>
            <div class="field">
              <label for="sf-reason">{{ t('secf.reason') }}</label>
              <input id="sf-reason" v-model="plan.reason" type="text" maxlength="255" />
            </div>
            <fieldset class="stack">
              <legend>{{ t('secf.installments') }}</legend>
              <div v-for="(i, index) in plan.installments" :key="index" class="row">
                <label :for="`sf-due-${index}`" class="sr-only">{{ t('secf.dueOn') }}</label>
                <input :id="`sf-due-${index}`" v-model="i.due_on" type="date" />
                <label :for="`sf-amt-${index}`" class="sr-only">{{ t('secf.amount') }}</label>
                <input :id="`sf-amt-${index}`" v-model.number="i.amount" type="number" min="1" step="1" inputmode="numeric" />
                <button type="button" class="btn" :aria-label="t('secf.removeInstallment')" @click="removeInstallment(index)">−</button>
              </div>
              <div><button type="button" class="btn" @click="addInstallment">{{ t('secf.addInstallment') }}</button></div>
              <p class="muted">{{ t('secf.total', { amount: money(planTotal(plan.installments)) }) }}</p>
            </fieldset>
            <div><button type="submit" class="btn btn--primary" :disabled="busy || !planOk">{{ t('secf.propose') }}</button></div>
          </form>
        </section>

        <section class="card" aria-labelledby="sf-plans">
          <h2 id="sf-plans">{{ t('secf.plans') }}</h2>
          <p v-if="plans.length === 0" class="muted">{{ t('secf.noPlans') }}</p>
          <ul v-else class="rows">
            <li v-for="p in plans" :key="p.id">
              <strong>{{ p.person_id }}</strong> · {{ money(p.total_amount) }} · {{ t(`secf.planStatus.${p.status}`) }}
              <span v-if="p.in_force" class="tag">{{ t('secf.inForce') }}</span>
              <div class="muted">{{ p.installments.map((i) => `${i.due_on} : ${money(i.amount)}`).join(' · ') }}</div>
              <div class="actions">
                <template v-if="p.status === 'pending' && canValidate">
                  <button type="button" class="btn btn--primary" :disabled="busy" @click="decidePlan(p, true)">{{ t('secf.approve') }}</button>
                  <button type="button" class="btn" :disabled="busy" @click="decidePlan(p, false)">{{ t('secf.refuse') }}</button>
                </template>
                <button v-if="(p.status === 'pending' || p.status === 'approved') && (canManage || canValidate)" type="button" class="btn" :disabled="busy" @click="cancelPlan(p)">{{ t('secf.cancel') }}</button>
              </div>
            </li>
          </ul>
        </section>
      </template>

      <template v-if="overview.mode === 'public_catalog'">
        <section class="card" aria-labelledby="sf-catalog">
          <h2 id="sf-catalog">{{ t('secf.catalog') }}</h2>
          <p v-if="catalog.length === 0" class="muted">{{ t('secf.catalogEmpty') }}</p>
          <ul v-else class="rows">
            <li v-for="c in catalog" :key="c.id">
              <strong>{{ c.label }}</strong> · {{ c.fee_kind }}
              <span v-if="c.max_amount !== null"> · {{ t('secf.max', { amount: money(c.max_amount) }) }}</span>
              <span v-if="c.client_wide" class="tag">{{ t('secf.clientWide') }}</span>
              <div class="muted">{{ c.legal_basis }} · {{ c.valid_from }}<template v-if="c.valid_to"> → {{ c.valid_to }}</template></div>
            </li>
          </ul>
          <form v-if="canKeepCatalog" class="stack" @submit.prevent="addEntry">
            <h3>{{ t('secf.catalogAdd') }}</h3>
            <div class="field">
              <label for="sf-e-kind">{{ t('secf.kind') }}</label>
              <select id="sf-e-kind" v-model="entry.kind">
                <option v-for="k in overview.kinds" :key="k" :value="k">{{ k }}</option>
              </select>
            </div>
            <div class="field"><label for="sf-e-label">{{ t('secf.label') }}</label><input id="sf-e-label" v-model="entry.label" type="text" maxlength="160" /></div>
            <div class="field"><label for="sf-e-max">{{ t('secf.maxAmount') }}</label><input id="sf-e-max" v-model.number="entry.maxAmount" type="number" min="0" step="1" inputmode="numeric" /></div>
            <div class="field"><label for="sf-e-basis">{{ t('secf.legalBasis') }}</label><input id="sf-e-basis" v-model="entry.legalBasis" type="text" maxlength="255" /></div>
            <label class="check"><input v-model="entry.clientWide" type="checkbox" /> {{ t('secf.clientWide') }}</label>
            <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secf.catalogAdd') }}</button></div>
          </form>
        </section>

        <section class="card" aria-labelledby="sf-requests">
          <h2 id="sf-requests">{{ t('secf.requests') }}</h2>
          <p class="muted">{{ t('secf.requestsHelp') }}</p>
          <p v-if="requests.length === 0" class="muted">{{ t('secf.noRequests') }}</p>
          <ul v-else class="rows">
            <li v-for="r in requests" :key="r.id">
              <strong>{{ r.label }}</strong> · {{ r.fee_kind }} · {{ money(r.amount) }} · {{ t(`secf.requestStatus.${r.status}`) }}
              <span v-if="r.origin === 'guard'" class="tag tag--warning">{{ t('secf.fromRefusal') }}</span>
              <div v-if="r.decision_reason" class="muted">{{ r.decision_reason }}</div>
              <div v-if="r.status === 'pending' && canKeepCatalog" class="actions">
                <button type="button" class="btn btn--primary" :disabled="busy" @click="decideRequest(r, true)">{{ t('secf.accept') }}</button>
                <button type="button" class="btn" :disabled="busy" @click="decideRequest(r, false)">{{ t('secf.refuse') }}</button>
              </div>
            </li>
          </ul>
          <form v-if="canRequest" class="stack" @submit.prevent="sendRequest">
            <h3>{{ t('secf.requestNew') }}</h3>
            <div class="field">
              <label for="sf-r-kind">{{ t('secf.kind') }}</label>
              <select id="sf-r-kind" v-model="ask.kind">
                <option v-for="k in overview.kinds" :key="k" :value="k">{{ k }}</option>
              </select>
            </div>
            <div class="field"><label for="sf-r-label">{{ t('secf.label') }}</label><input id="sf-r-label" v-model="ask.label" type="text" maxlength="160" /></div>
            <div class="field"><label for="sf-r-amount">{{ t('secf.amount') }}</label><input id="sf-r-amount" v-model.number="ask.amount" type="number" min="0" step="1" inputmode="numeric" /></div>
            <div class="field"><label for="sf-r-just">{{ t('secf.justification') }}</label><textarea id="sf-r-just" v-model="ask.justification" maxlength="500" rows="3" /></div>
            <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('secf.requestSend') }}</button></div>
          </form>
        </section>
      </template>
    </template>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 10px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.row input { flex: 1 1 140px; min-width: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.pairs { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 4px 12px; margin: 0 0 8px; }
.pairs dd { margin: 0; overflow-wrap: anywhere; }
.check { display: flex; gap: 8px; align-items: center; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
