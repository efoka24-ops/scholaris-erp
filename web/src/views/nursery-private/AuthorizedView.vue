<script setup lang="ts">
// Personnes autorisées d'un enfant (secrétaire, directrice), code du jour, demandes des parents à valider et restrictions de garde
// (directrice seule : décisions de justice, jamais visibles du parent).
import { onMounted, ref, watch } from 'vue';
import { nursery, type Authorized, type Child, type Restriction } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { today, useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const childId = ref('');
const people = ref<Authorized[]>([]);
const requests = ref<{ id: string; learner_id: string; name: string; relationship: string; grant_type: string }[]>([]);
const restrictions = ref<Restriction[]>([]);
const issued = ref<{ id: string; code: string } | null>(null);
const blank = () => ({ name: '', relationship: '', phone: '', grant_type: 'permanent', valid_on: today() });
const form = ref(blank());
const rform = ref({ restricted_name: '', nature: 'no_pickup', decision_reference: '', valid_from: today() });

watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
  childId.value = '';
});
watch(childId, () => void reload());

async function reload(): Promise<void> {
  people.value = childId.value ? ((await run(() => nursery.authorized(childId.value))) ?? []) : [];
  restrictions.value = childId.value && can(...P.restrict) ? ((await run(() => nursery.restrictions(childId.value))) ?? []) : [];
  issued.value = null;
}
async function loadRequests(): Promise<void> {
  if (can(...P.authorizedManage)) requests.value = (await run(() => nursery.pendingRequests())) ?? [];
}
async function add(): Promise<void> {
  const f = form.value;
  const done = await run(() => nursery.addAuthorized({ learner_id: childId.value, name: f.name.trim(), relationship: f.relationship.trim(), grant_type: f.grant_type, ...(f.phone.trim() ? { phone: f.phone.trim() } : {}), ...(f.grant_type === 'day' ? { valid_on: f.valid_on } : {}) }));
  if (done) {
    form.value = blank();
    await reload();
  }
}
async function revoke(a: Authorized): Promise<void> {
  if (await run(() => nursery.revokeAuthorized(a.id))) await reload();
}
async function code(a: Authorized): Promise<void> {
  const done = await run(() => nursery.issueCode({ learner_id: childId.value, pickup_id: a.id, valid_on: a.valid_on ?? today() }));
  issued.value = done ? { id: a.id, code: done.code } : null;
}
async function decide(id: string, approve: boolean): Promise<void> {
  if (await run(() => nursery.decideRequest(id, approve))) await loadRequests();
}
async function restrict(): Promise<void> {
  const f = rform.value;
  if (await run(() => nursery.addRestriction({ learner_id: childId.value, restricted_name: f.restricted_name.trim(), nature: f.nature, decision_reference: f.decision_reference.trim(), valid_from: f.valid_from }))) {
    rform.value = { restricted_name: '', nature: 'no_pickup', decision_reference: '', valid_from: today() };
    await reload();
  }
}
async function lift(r: Restriction): Promise<void> {
  const reason = window.prompt(t('nur.auth.liftReason'));
  if (reason && reason.trim() && (await run(() => nursery.liftRestriction(r.id, reason.trim())))) await reload();
}
onMounted(() => void loadRequests());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.auth.title') }}</h1>
      <p class="lede">{{ t('nur.auth.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="requests.length > 0" class="card" aria-labelledby="au-req">
      <h2 id="au-req">{{ t('nur.auth.requests') }}</h2>
      <ul class="list">
        <li v-for="r in requests" :key="r.id" class="row row--between">
          <span><strong>{{ r.name }}</strong> <span class="muted">{{ r.relationship }}</span></span>
          <span class="row">
            <button type="button" class="btn btn--primary" :disabled="busy" @click="decide(r.id, true)">{{ t('nur.auth.approve') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="busy" @click="decide(r.id, false)">{{ t('nur.auth.reject') }}</button>
          </span>
        </li>
      </ul>
    </section>

    <section class="card">
      <div class="grid2">
        <SectionPicker v-model="section" id="au-section" />
        <div class="field">
          <label for="au-child">{{ t('nur.auth.child') }}</label>
          <select id="au-child" v-model="childId">
            <option value="">{{ t('nur.auth.pickChild') }}</option>
            <option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option>
          </select>
        </div>
      </div>
      <template v-if="childId">
        <p v-if="people.length === 0" class="notice notice--warning" role="status">{{ t('nur.pickup.noneAuthorized') }}</p>
        <ul class="list">
          <li v-for="a in people" :key="a.id">
            <div class="row row--between">
              <span><strong>{{ a.name }}</strong> <span class="muted">{{ a.relationship }}<span v-if="a.phone"> · {{ a.phone }}</span></span>
                <span v-if="a.grant_type === 'day'" class="tag tag--neutral">{{ t('nur.auth.dayOnly', { date: a.valid_on ?? '' }) }}</span></span>
              <span v-if="can(...P.authorizedManage)" class="row">
                <button v-if="a.grant_type === 'day'" type="button" class="btn btn--quiet" :disabled="busy" @click="code(a)">{{ t('nur.auth.makeCode') }}</button>
                <button type="button" class="btn btn--quiet" :disabled="busy" @click="revoke(a)">{{ t('nur.auth.revoke') }}</button>
              </span>
            </div>
            <p v-if="issued && issued.id === a.id" class="notice" role="status">{{ t('nur.auth.codeShown', { code: issued.code }) }}</p>
          </li>
        </ul>

        <form v-if="can(...P.authorizedManage)" class="grid2" @submit.prevent="add">
          <h2 class="span2">{{ t('nur.auth.add') }}</h2>
          <div class="field"><label for="au-name">{{ t('nur.auth.name') }}</label><input id="au-name" v-model="form.name" type="text" maxlength="160" required autocomplete="off" /></div>
          <div class="field"><label for="au-rel">{{ t('nur.auth.relationship') }}</label><input id="au-rel" v-model="form.relationship" type="text" maxlength="60" required /></div>
          <div class="field"><label for="au-phone">{{ t('nur.enrol.phone') }}</label><input id="au-phone" v-model="form.phone" type="tel" maxlength="30" /></div>
          <div class="field">
            <label for="au-type">{{ t('nur.auth.type') }}</label>
            <select id="au-type" v-model="form.grant_type">
              <option value="permanent">{{ t('nur.auth.permanent') }}</option>
              <option value="day">{{ t('nur.auth.day') }}</option>
            </select>
          </div>
          <div v-if="form.grant_type === 'day'" class="field"><label for="au-on">{{ t('nur.auth.on') }}</label><input id="au-on" v-model="form.valid_on" type="date" required /></div>
          <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.auth.addDo') }}</button></div>
        </form>

        <div v-if="can(...P.restrict)" class="restrict">
          <h2>{{ t('nur.auth.restrictions') }}</h2>
          <p class="muted">{{ t('nur.auth.restrictHelp') }}</p>
          <ul class="list">
            <li v-for="r in restrictions" :key="r.id" class="row row--between">
              <span><strong>{{ r.restricted_name }}</strong> · {{ r.decision_reference }} <span class="tag" :class="r.status === 'active' ? 'tag--danger' : 'tag--neutral'">{{ r.status === 'active' ? t('nur.auth.active') : t('nur.auth.lifted') }}</span></span>
              <button v-if="r.status === 'active'" type="button" class="btn btn--quiet" :disabled="busy" @click="lift(r)">{{ t('nur.auth.lift') }}</button>
            </li>
          </ul>
          <form class="grid2" @submit.prevent="restrict">
            <div class="field"><label for="re-name">{{ t('nur.auth.restrictedName') }}</label><input id="re-name" v-model="rform.restricted_name" type="text" maxlength="160" required /></div>
            <div class="field"><label for="re-ref">{{ t('nur.auth.decisionRef') }}</label><input id="re-ref" v-model="rform.decision_reference" type="text" maxlength="120" required /></div>
            <div class="field"><label for="re-from">{{ t('nur.auth.from') }}</label><input id="re-from" v-model="rform.valid_from" type="date" required /></div>
            <div><button type="submit" class="btn" :disabled="busy">{{ t('nur.auth.restrictDo') }}</button></div>
          </form>
        </div>
      </template>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 12px 0; padding: 0; display: grid; gap: 10px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.span2 { grid-column: 1 / -1; }
.restrict { margin-top: 24px; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
