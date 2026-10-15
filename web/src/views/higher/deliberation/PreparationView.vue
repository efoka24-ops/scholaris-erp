<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, type Jury, type PointsResult, type SimStudent, type Simulation } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import SimulationTable from './SimulationTable.vue';
import { DP } from './access';
import { dt, num } from './i18n';

// Préparation (EX-DEL-03, API-13) : simulation sans écriture, essai d'options de règles, points de jury nominatifs et motivés.
const props = defineProps<{ id: string }>();
const ctx = useContextStore();
const higher = useHigherStore();
const sim = useHigherAction();
const pts = useHigherAction();

const jury = ref<Jury | null>(null);
const result = ref<Simulation | null>(null);
const opts = reactive({ margin: '0,5', unit_pass_mark: '', jury_points_max: '', eliminatory_unit_mark: '' });
const target = ref<SimStudent | null>(null);
const grant = reactive({ points: '', reason: '' });
const grantTried = ref(false);
const granted = ref<PointsResult | null>(null);

const writable = computed(() => !higher.readOnly);
const canSimulate = computed(() => ctx.can(...DP.prepare) && writable.value);
const canGrant = computed(() => ctx.can(...DP.prepareOrPreside) && writable.value);
const parse = (v: string): number | null => (v.trim() === '' ? null : Number(v.replace(',', '.')));

async function simulate(): Promise<void> {
  const overrides: Record<string, number> = {};
  for (const key of ['unit_pass_mark', 'jury_points_max', 'eliminatory_unit_mark'] as const) {
    const v = parse(opts[key]);
    if (v !== null && !Number.isNaN(v)) overrides[key] = v;
  }
  const margin = parse(opts.margin);
  const res = await sim.run(() => deliberation.simulate(props.id, { ...(Object.keys(overrides).length ? { overrides } : {}), ...(margin !== null && !Number.isNaN(margin) ? { margin } : {}) }));
  if (res) result.value = res;
}

function pick(s: SimStudent): void {
  target.value = s;
  granted.value = null;
  grantTried.value = false;
  Object.assign(grant, { points: '', reason: '' });
}

async function give(): Promise<void> {
  grantTried.value = true;
  const s = target.value;
  const points = parse(grant.points);
  if (!s || points === null || Number.isNaN(points) || (points > 0 && !grant.reason.trim())) return;
  const res = await pts.run(() => deliberation.points(props.id, { person_id: s.person_id, points, reason: grant.reason.trim() }));
  if (!res) return;
  granted.value = res;
  await simulate();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups');
  jury.value = (await sim.run(() => deliberation.jury(props.id))) ?? null;
});
</script>

<template>
  <DelFrame :title="dt('hdl.prep.title')" :lede="dt('hdl.prep.lede')" :selector="false">
    <p v-if="jury"><router-link :to="{ name: 'higher-del-jury', params: { id } }">← {{ higher.groupName(jury.structure_id) }} · {{ jury.period_label || jury.period_id }}</router-link></p>
    <form v-if="canSimulate" class="card hi-form" novalidate @submit.prevent="simulate">
      <h2 class="hi-wide">{{ dt('hdl.prep.options') }}</h2>
      <p class="hint hi-wide">{{ dt('hdl.prep.optionsHint') }}</p>
      <div class="field"><label for="op-margin">{{ dt('hdl.prep.margin') }}</label><input id="op-margin" v-model="opts.margin" type="text" inputmode="decimal" /></div>
      <div class="field"><label for="op-pass">{{ dt('hdl.prep.passMark') }}</label><input id="op-pass" v-model="opts.unit_pass_mark" type="text" inputmode="decimal" /></div>
      <div class="field"><label for="op-comp">{{ dt('hdl.prep.juryMax') }}</label><input id="op-comp" v-model="opts.jury_points_max" type="text" inputmode="decimal" /></div>
      <div class="field"><label for="op-elim">{{ dt('hdl.prep.eliminatory') }}</label><input id="op-elim" v-model="opts.eliminatory_unit_mark" type="text" inputmode="decimal" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="sim.busy.value" data-test="simulate">{{ dt('hdl.prep.simulate') }}</button></div>
    </form>
    <ErrorNotice :error="sim.error.value" />
    <SimulationTable v-if="result" :sim="result" :can-grant="canGrant" class="hi-section" @grant="pick" />
    <p v-else class="muted hi-section">{{ dt('hdl.prep.noRun') }}</p>

    <section v-if="target" class="card card--strong hi-section" role="dialog" aria-labelledby="pt-title" data-test="points">
      <h2 id="pt-title">{{ dt('hdl.prep.pointsTitle') }} · {{ target.name ?? target.person_id }}</h2>
      <p class="hint">{{ dt('hdl.prep.pointsHint') }}</p>
      <div class="hi-form">
        <div class="field"><label for="pt-value">{{ dt('hdl.prep.pointsValue') }}</label><input id="pt-value" v-model="grant.points" type="text" inputmode="decimal" :aria-invalid="grantTried && parse(grant.points) === null" /></div>
        <div class="field hi-wide">
          <label for="pt-reason">{{ dt('hdl.prep.reason') }}</label><textarea id="pt-reason" v-model="grant.reason" maxlength="500" :aria-invalid="grantTried && !grant.reason.trim()" />
          <span v-if="grantTried && !grant.reason.trim() && (parse(grant.points) ?? 0) > 0" class="error">{{ dt('hdl.prep.reasonRequired') }}</span>
        </div>
      </div>
      <ErrorNotice :error="pts.error.value" />
      <p v-if="granted" class="notice notice--success" role="status">
        {{ granted.capped ? dt('hdl.prep.capped', { granted: num(granted.points_granted), requested: num(granted.points_requested) }) : dt('hdl.prep.granted', { granted: num(granted.points_granted) }) }}
      </p>
      <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="pts.busy.value" @click="give">{{ dt('hdl.save') }}</button><button type="button" class="btn btn--quiet" @click="target = null">{{ dt('hdl.back') }}</button></div>
    </section>
  </DelFrame>
</template>
