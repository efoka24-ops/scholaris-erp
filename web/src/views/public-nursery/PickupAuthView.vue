<script setup lang="ts">
// Sécurité de la sortie (maternelle publique) : pour un enfant du registre du jour, ses personnes autorisées, la carte DOC-21 (numéro et QR,
// révoquée avec l'autorisation, jamais pour une personne visée par une restriction), le code à usage unique du jour (montré UNE seule fois,
// envoyé aussi par SMS aux parents) et le registre du jour DOC-19 (PDF). La remise elle-même se fait depuis le registre ou hors ligne.
import { onMounted, ref } from 'vue';
import {
  gate,
  openIssuedPdf,
  pickupAuth,
  type GateRegisterRow,
  type PickupCodeIssued,
  type PickupPerson,
} from '@/api/publicNursery';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { todayIso } from '@/lib/format';

const { t, locale } = useI18n();
const error = ref<unknown>(null);
const busy = ref(false);
const date = ref(todayIso());
const children = ref<GateRegisterRow[]>([]);
const learnerId = ref('');
const persons = ref<PickupPerson[]>([]);
const issued = ref<PickupCodeIssued | null>(null);
const notice = ref('');
const lang = (): 'fr' | 'en' => (locale.value === 'en' ? 'en' : 'fr');

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
async function loadChildren(): Promise<void> {
  const register = await run(() => gate.register(date.value));
  children.value = register?.children ?? [];
}
async function pick(): Promise<void> {
  issued.value = null;
  notice.value = '';
  persons.value = learnerId.value ? ((await run(() => pickupAuth.persons(learnerId.value))) ?? []) : [];
}
async function card(person: PickupPerson): Promise<void> {
  const doc = await run(() => pickupAuth.card(person.id, lang()));
  if (doc) {
    notice.value = t('pn.auth.cardReady', { number: doc.number });
    await run(() => openIssuedPdf(doc.document_id));
  }
}
async function code(person: PickupPerson): Promise<void> {
  issued.value = (await run(() => pickupAuth.issueCode({ learner_id: learnerId.value, pickup_id: person.id, valid_on: date.value }))) ?? null;
}
async function printRegister(): Promise<void> {
  const doc = await run(() => pickupAuth.registerDocument(date.value, lang()));
  if (doc) {
    notice.value = t('pn.auth.registerReady', { number: doc.number });
    await run(() => openIssuedPdf(doc.document_id));
  }
}
onMounted(() => void loadChildren());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.gate.eyebrow') }}</p>
      <h1>{{ t('pn.auth.title') }}</h1>
      <p class="lead">{{ t('pn.auth.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="notice" class="card" role="status" aria-live="polite">{{ notice }}</p>

    <form class="card row" @submit.prevent="loadChildren">
      <div class="field"><label for="pa-date">{{ t('pn.gate.date') }}</label><input id="pa-date" v-model="date" type="date" required /></div>
      <button type="submit" class="btn" :disabled="busy">{{ t('pn.gate.show') }}</button>
      <button type="button" class="btn btn--quiet" :disabled="busy" @click="printRegister">{{ t('pn.auth.printRegister') }}</button>
    </form>

    <section class="card" aria-labelledby="pa-child">
      <h2 id="pa-child">{{ t('pn.auth.child') }}</h2>
      <div class="field">
        <label for="pa-learner">{{ t('pn.auth.pickChild') }}</label>
        <select id="pa-learner" v-model="learnerId" @change="pick">
          <option value="">—</option>
          <option v-for="c in children" :key="c.learner_id" :value="c.learner_id">{{ c.name }}</option>
        </select>
      </div>
      <p v-if="learnerId && persons.length === 0" class="muted">{{ t('pn.auth.none') }}</p>
      <ul v-if="persons.length > 0" class="rows">
        <li v-for="p in persons" :key="p.id">
          <p>
            <strong>{{ p.pickup_name }}</strong> · {{ p.relationship }}
            <span class="tag tag--neutral">{{ p.grant_type === 'day' ? t('pn.auth.grantDay', { date: p.valid_on ?? '' }) : t('pn.auth.grantPermanent') }}</span>
            <span v-if="p.status !== 'active'" class="tag tag--warn">{{ t('pn.auth.revoked') }}</span>
          </p>
          <div v-if="p.status === 'active'" class="row">
            <button type="button" class="btn btn--quiet" :disabled="busy" @click="card(p)">{{ t('pn.auth.card') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="busy" @click="code(p)">{{ t('pn.auth.code') }}</button>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="issued" class="card" role="alert" aria-labelledby="pa-code">
      <h2 id="pa-code">{{ t('pn.auth.codeTitle') }}</h2>
      <p class="big code">{{ issued.code }}</p>
      <p class="muted">{{ t('pn.auth.codeHelp', { date: issued.valid_on }) }}</p>
    </section>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.rows > li { border-top: 1px solid var(--border, #dcdcd8); padding-top: 8px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; }
.code { font-size: 2rem; letter-spacing: 0.3em; font-variant-numeric: tabular-nums; }
button { min-height: 44px; }
</style>
