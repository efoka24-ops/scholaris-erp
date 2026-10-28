<script setup lang="ts">
// Porte de la maternelle publique : arrivée de l'enfant, maintien à l'école (motif obligatoire), registre du jour (sorties, refus, alertes),
// effectifs contre le plafond paramétré (30 par défaut, alerte sans blocage) et contrôle O7 (aucune remise sans autorisation valide).
// La remise elle-même passe par la liste des personnes autorisées : jamais par un nom saisi ici.
import { onMounted, ref } from 'vue';
import { contributions, gate, type CapacityReport, type GateRegister, type GateRegisterRow } from '@/api/publicNursery';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { todayIso } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';

const { t } = useI18n();
const error = ref<unknown>(null);
const busy = ref(false);
const date = ref(todayIso());
const register = ref<GateRegister | null>(null);
const capacity = ref<CapacityReport | null>(null);
const audit = ref<{ ok: boolean; anomalies: { event_id: string; reason: string }[] } | null>(null);
const remarks = ref<Record<string, string>>({});

async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;
  try {
    return await action();
  } catch (e) {
    error.value = e;
    return undefined;
  } finally {
    busy.value = false;
  }
}
async function load(): Promise<void> {
  register.value = (await run(() => gate.register(date.value))) ?? null;
  capacity.value = (await run(() => contributions.capacity())) ?? capacity.value;
}
async function arrive(row: GateRegisterRow): Promise<void> {
  if (await run(() => gate.arrival({ learner_id: row.learner_id, school_day: date.value }))) await load();
}
async function hold(row: GateRegisterRow): Promise<void> {
  if (await run(() => gate.hold({ learner_id: row.learner_id, remark: (remarks.value[row.learner_id] ?? '').trim(), school_day: date.value }))) await load();
}
async function check(): Promise<void> {
  audit.value = (await run(() => gate.audit(date.value))) ?? null;
}
onMounted(() => void load());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.gate.eyebrow') }}</p>
      <h1>{{ t('pn.gate.title') }}</h1>
      <p class="lead">{{ t('pn.gate.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="capacity && capacity.over_capacity > 0" class="card alert" role="alert">
      <h2>{{ t('pn.gate.capacityAlert') }}</h2>
      <ul class="plain"><li v-for="c in capacity.classes.filter((x) => x.over_capacity)" :key="c.section_id">{{ t('pn.gate.over', { name: c.name, enrolled: c.enrolled, max: c.max_children ?? 0 }) }}</li></ul>
      <p class="muted">{{ t('pn.gate.capacityHelp') }}</p>
    </section>

    <form class="card row" @submit.prevent="load">
      <div class="field"><label for="gt-date">{{ t('pn.gate.date') }}</label><input id="gt-date" v-model="date" type="date" required /></div>
      <button type="submit" class="btn" :disabled="busy">{{ t('pn.gate.show') }}</button>
      <button type="button" class="btn btn--quiet" :disabled="busy" @click="check">{{ t('pn.gate.audit') }}</button>
    </form>
    <p v-if="audit" class="card" role="status" aria-live="polite">
      <span :class="['tag', audit.ok ? 'tag--ok' : 'tag--warn']">{{ audit.ok ? t('pn.gate.auditOk') : t('pn.gate.auditKo', { count: audit.anomalies.length }) }}</span>
    </p>

    <section v-if="register" class="card" aria-labelledby="gt-reg">
      <h2 id="gt-reg">{{ t('pn.gate.register') }}</h2>
      <p role="status">{{ t('pn.gate.totals', { children: register.totals.children ?? 0, arrived: register.totals.arrived ?? 0, left: register.totals.left ?? 0, still: register.totals.still_at_school ?? 0 }) }}</p>
      <ul class="rows">
        <li v-for="row in register.children" :key="row.learner_id">
          <p>
            <strong>{{ row.name }}</strong>
            <span class="tag tag--neutral">{{ t(`pn.gate.s.${row.status}` as MessageKey) }}</span>
            <span v-if="row.left_to"> · {{ row.left_to }}</span>
            <span v-if="row.refusals > 0" class="tag tag--warn">{{ t('pn.gate.refusals', { count: row.refusals }) }}</span>
            <span v-if="row.alerts.length > 0" class="tag tag--warn">{{ t('pn.gate.alerts', { count: row.alerts.length }) }}</span>
          </p>
          <div class="row">
            <button v-if="row.status === 'not_arrived'" type="button" class="btn btn--quiet" :disabled="busy" @click="arrive(row)">{{ t('pn.gate.arrive') }}</button>
            <template v-if="row.status === 'still_at_school'">
              <input v-model="remarks[row.learner_id]" type="text" maxlength="500" :aria-label="t('pn.gate.remark')" :placeholder="t('pn.gate.remark')" />
              <button type="button" class="btn btn--quiet" :disabled="busy || !(remarks[row.learner_id] ?? '').trim()" @click="hold(row)">{{ t('pn.gate.hold') }}</button>
            </template>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.rows > li { border-top: 1px solid var(--border, #dcdcd8); padding-top: 8px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; }
.alert { border-color: var(--warn, #b45309); }
button { min-height: 44px; }
</style>
