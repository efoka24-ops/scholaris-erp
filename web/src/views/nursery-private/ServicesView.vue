<script setup lang="ts">
// Services garderie, cantine et transport : offres (liées à un frais du catalogue : le montant vient du socle Billing), abonnements des
// enfants, facturation du cycle (une seule fois par cycle), présence du jour au service.
import { computed, onMounted, ref, watch } from 'vue';
import { nursery, type Child, type ServicePlan, type ServiceRoster, type ServiceSubscription } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { today, useAction, usePermissions } from './useNur';

const KINDS = ['daycare', 'canteen', 'transport'] as const;
const CYCLES = ['month', 'term', 'year'] as const;
const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const plans = ref<ServicePlan[]>([]);
const subs = ref<ServiceSubscription[]>([]);
const section = ref('');
const children = ref<Child[]>([]);
const planForm = ref({ kind: 'canteen', label: '', fee_item_id: '', billing_cycle: 'month' });
const subForm = ref({ learner_id: '', plan_id: '', starts_on: today() });
const billOn = ref(today());
const billResult = ref<{ issued: number; already: number; failed: number } | null>(null);
const rosterPlan = ref('');
const rosterDate = ref(today());
const roster = ref<ServiceRoster | null>(null);
const marks = ref<Record<string, boolean>>({});
const planLabel = computed(() => Object.fromEntries(plans.value.map((p) => [p.id, p.label])));
const childName = computed(() => Object.fromEntries(children.value.map((c) => [c.id, c.full_name])));

async function load(): Promise<void> {
  plans.value = (await run(() => nursery.servicePlans())) ?? [];
  subs.value = (await run(() => nursery.subscriptions({ status: 'active' }))) ?? [];
}
watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
});
async function addPlan(): Promise<void> {
  if (await run(() => nursery.createServicePlan({ ...planForm.value, label: planForm.value.label.trim(), fee_item_id: planForm.value.fee_item_id.trim() }))) {
    planForm.value.label = '';
    await load();
  }
}
async function togglePlan(p: ServicePlan): Promise<void> {
  if (await run(() => nursery.updateServicePlan(p.id, { active: !p.active }))) await load();
}
async function subscribe(): Promise<void> {
  if (await run(() => nursery.subscribe(subForm.value))) await load();
}
async function end(s: ServiceSubscription): Promise<void> {
  if (await run(() => nursery.endSubscription(s.id, today()))) await load();
}
async function billAll(): Promise<void> {
  const r = await run(() => nursery.invoiceAll({ on: billOn.value }));
  if (r) billResult.value = { issued: r.issued, already: r.already, failed: r.failed.length };
}
async function loadRoster(): Promise<void> {
  roster.value = rosterPlan.value ? ((await run(() => nursery.serviceRoster(rosterPlan.value, rosterDate.value))) ?? null) : null;
  marks.value = Object.fromEntries((roster.value?.children ?? []).map((c) => [c.learner_id, c.present ?? false]));
}
async function saveRoster(): Promise<void> {
  const body = { plan_id: rosterPlan.value, date: rosterDate.value, marks: Object.entries(marks.value).map(([learner_id, present]) => ({ learner_id, present })) };
  if (body.marks.length === 0) return;
  roster.value = (await run(() => nursery.recordServiceAttendance(body))) ?? roster.value;
}
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.svc.title') }}</h1>
      <p class="lede">{{ t('nur.svc.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card" aria-labelledby="sv-plans">
      <h2 id="sv-plans">{{ t('nur.svc.plans') }}</h2>
      <p v-if="plans.length === 0" class="muted">{{ t('nur.svc.noPlan') }}</p>
      <ul class="plain">
        <li v-for="p in plans" :key="p.id">
          <strong>{{ p.label }}</strong> · {{ t(`nur.svc.kind.${p.kind}` as MessageKey) }} · {{ t(`nur.svc.cycle.${p.billing_cycle}` as MessageKey) }}
          <span :class="['tag', p.active ? 'tag--ok' : 'tag--neutral']">{{ p.active ? t('nur.svc.active') : t('nur.svc.inactive') }}</span>
          <button v-if="can(...P.serviceManage)" type="button" class="btn btn--quiet" :disabled="busy" @click="togglePlan(p)">{{ p.active ? t('nur.svc.disable') : t('nur.svc.enable') }}</button>
        </li>
      </ul>
      <form v-if="can(...P.serviceManage)" class="grid" @submit.prevent="addPlan">
        <div class="field"><label for="sv-kind">{{ t('nur.svc.kindLabel') }}</label><select id="sv-kind" v-model="planForm.kind"><option v-for="k in KINDS" :key="k" :value="k">{{ t(`nur.svc.kind.${k}` as MessageKey) }}</option></select></div>
        <div class="field"><label for="sv-label">{{ t('nur.svc.label') }}</label><input id="sv-label" v-model="planForm.label" type="text" maxlength="160" required /></div>
        <div class="field"><label for="sv-fee">{{ t('nur.svc.fee') }}</label><input id="sv-fee" v-model="planForm.fee_item_id" type="text" maxlength="40" required /><span class="hint">{{ t('nur.svc.feeHint') }}</span></div>
        <div class="field"><label for="sv-cycle">{{ t('nur.svc.cycleLabel') }}</label><select id="sv-cycle" v-model="planForm.billing_cycle"><option v-for="c in CYCLES" :key="c" :value="c">{{ t(`nur.svc.cycle.${c}` as MessageKey) }}</option></select></div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.svc.addPlan') }}</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="sv-subs">
      <h2 id="sv-subs">{{ t('nur.svc.subs') }}</h2>
      <form v-if="can(...P.serviceManage)" class="grid" @submit.prevent="subscribe">
        <SectionPicker v-model="section" id="sv-section" />
        <div class="field"><label for="sv-child">{{ t('nur.auth.child') }}</label><select id="sv-child" v-model="subForm.learner_id" required><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option></select></div>
        <div class="field"><label for="sv-plan">{{ t('nur.svc.plan') }}</label><select id="sv-plan" v-model="subForm.plan_id" required><option value="">{{ t('nur.svc.pickPlan') }}</option><option v-for="p in plans.filter((x) => x.active)" :key="p.id" :value="p.id">{{ p.label }}</option></select></div>
        <div class="field"><label for="sv-start">{{ t('nur.svc.startsOn') }}</label><input id="sv-start" v-model="subForm.starts_on" type="date" required /></div>
        <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.svc.subscribe') }}</button></div>
      </form>
      <ul class="plain">
        <li v-for="s in subs" :key="s.id">
          {{ childName[s.learner_id] ?? s.learner_id }} · {{ planLabel[s.plan_id] ?? s.plan_label }} · {{ t('nur.svc.since', { date: s.starts_on }) }}
          <button v-if="can(...P.serviceManage)" type="button" class="btn btn--quiet" :disabled="busy" @click="end(s)">{{ t('nur.svc.end') }}</button>
        </li>
      </ul>
      <form v-if="can(...P.serviceManage)" class="row" @submit.prevent="billAll">
        <div class="field"><label for="sv-bill">{{ t('nur.svc.billOn') }}</label><input id="sv-bill" v-model="billOn" type="date" required /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('nur.svc.bill') }}</button>
      </form>
      <p v-if="billResult" role="status" aria-live="polite">{{ t('nur.svc.billed', billResult) }}</p>
    </section>

    <section class="card" aria-labelledby="sv-roster">
      <h2 id="sv-roster">{{ t('nur.svc.roster') }}</h2>
      <form class="row" @submit.prevent="loadRoster">
        <div class="field"><label for="sv-rplan">{{ t('nur.svc.plan') }}</label><select id="sv-rplan" v-model="rosterPlan" required><option value="">{{ t('nur.svc.pickPlan') }}</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.label }}</option></select></div>
        <div class="field"><label for="sv-rdate">{{ t('nur.register.date') }}</label><input id="sv-rdate" v-model="rosterDate" type="date" required /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('nur.register.show') }}</button>
      </form>
      <template v-if="roster">
        <p v-if="roster.children.length === 0" class="muted">{{ t('nur.svc.noChild') }}</p>
        <ul class="plain">
          <li v-for="c in roster.children" :key="c.learner_id">
            <label class="check"><input v-model="marks[c.learner_id]" type="checkbox" /> {{ c.name }}</label>
          </li>
        </ul>
        <button v-if="roster.children.length > 0" type="button" class="btn btn--primary" :disabled="busy" @click="saveRoster">{{ t('nur.svc.saveRoster') }}</button>
        <p role="status" aria-live="polite">{{ t('nur.svc.present', { count: roster.present }) }}</p>
      </template>
    </section>
  </div>
</template>

<style scoped>
.grid { display: grid; gap: 10px; grid-template-columns: 1fr; }
@media (min-width: 720px) { .grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items: end; } }
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
</style>
