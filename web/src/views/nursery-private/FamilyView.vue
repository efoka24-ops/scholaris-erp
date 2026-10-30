<script setup lang="ts">
// Espace des parents (maternelle privée) : pour CHACUN de leurs enfants seulement : personnes autorisées à le récupérer (ajout, retrait,
// code du jour), allergies, carnets publiés, cahier de vie (photos servies seulement avec le consentement photo de chaque enfant visible),
// besoins signalés et solde. Textes rendus tels quels (aucun HTML).
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { nursery, nurseryMedia, type Authorized, type Balance, type FamilyChild, type IssuedDocument, type Need, type ParentLifeEntry, type PhotoConsent, type ProgressBook, type ServiceSubscription } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatXaf } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { today, useAction } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const kids = ref<FamilyChild[]>([]);
const childId = ref('');
const people = ref<Authorized[]>([]);
const books = ref<ProgressBook[]>([]);
const life = ref<ParentLifeEntry[]>([]);
const needs = ref<Need[]>([]);
const balance = ref<Balance | null>(null);
const consent = ref<PhotoConsent | null>(null);
const docs = ref<IssuedDocument[]>([]);
const services = ref<ServiceSubscription[]>([]);
const signer = ref('');
const accept = ref(false);
const allergies = ref('');
const message = ref('');
const code = ref<{ id: string; code: string } | null>(null);
const photos = ref<Record<string, string>>({});
const blank = () => ({ name: '', relationship: '', phone: '', grant_type: 'permanent', valid_on: today() });
const form = ref(blank());

async function loadChild(): Promise<void> {
  message.value = '';
  code.value = null;
  if (!childId.value) return;
  const id = childId.value;
  people.value = (await run(() => nursery.family.pickups(id))) ?? [];
  books.value = (await run(() => nursery.family.books(id))) ?? [];
  life.value = (await run(() => nursery.family.lifebook(id))) ?? [];
  needs.value = (await run(() => nursery.family.needs(id))) ?? [];
  balance.value = (await run(() => nursery.family.balance(id))) ?? null;
  consent.value = (await run(() => nursery.family.photoConsent(id))) ?? null;
  docs.value = (await run(() => nursery.family.documents(id))) ?? [];
  services.value = (await run(() => nursery.family.services(id))) ?? [];
}
onMounted(async () => {
  kids.value = (await run(() => nursery.family.children())) ?? [];
  if (kids.value.length === 1) childId.value = kids.value[0]!.id;
});
watch(childId, () => void loadChild());

async function add(): Promise<void> {
  const f = form.value;
  const result = await run(() => nursery.family.addPickup({ learner_id: childId.value, name: f.name.trim(), relationship: f.relationship.trim(), grant_type: f.grant_type, ...(f.phone.trim() ? { phone: f.phone.trim() } : {}), ...(f.grant_type === 'day' ? { valid_on: f.valid_on } : {}) }));
  if (result) {
    form.value = blank();
    await loadChild();
    message.value = result.status === 'pending' ? t('nur.family.pending') : t('nur.family.added');
  }
}
async function revoke(a: Authorized): Promise<void> {
  if (await run(() => nursery.family.revokePickup(a.id))) await loadChild();
}
async function makeCode(a: Authorized): Promise<void> {
  const done = await run(() => nursery.family.issueCode({ learner_id: childId.value, pickup_id: a.id }));
  code.value = done ? { id: a.id, code: done.code } : null;
}
async function saveAllergies(): Promise<void> {
  if (await run(() => nursery.family.declareAllergy(childId.value, allergies.value.trim() || null))) message.value = t('nur.family.allergySaved');
}
/** Consentement photo donné par le parent (case d'accord obligatoire) ; le retrait est immédiat : plus aucune photo de l'enfant n'est servie. */
async function giveConsent(): Promise<void> {
  const next = await run(() => nursery.family.givePhotoConsent({ learner_id: childId.value, signed_by_name: signer.value.trim(), accept: true }));
  if (next) {
    consent.value = next;
    accept.value = false;
    message.value = t('nur.family.consentGiven');
  }
}
async function withdrawConsent(): Promise<void> {
  const next = await run(() => nursery.family.withdrawPhotoConsent(childId.value));
  if (next) {
    consent.value = next;
    message.value = t('nur.family.consentWithdrawn');
    await loadChild();
  }
}
async function showPhoto(mediaId: string): Promise<void> {
  const blob = await run(() => nurseryMedia(`/portal/lifebook/media/${mediaId}`));
  if (blob) photos.value = { ...photos.value, [mediaId]: URL.createObjectURL(blob) };
}
onBeforeUnmount(() => Object.values(photos.value).forEach((url) => URL.revokeObjectURL(url)));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.family.title') }}</h1>
      <p class="lede">{{ t('nur.family.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="message" class="notice" role="status" aria-live="polite">{{ message }}</p>

    <section class="card">
      <div class="field">
        <label for="fa-child">{{ t('nur.family.child') }}</label>
        <select id="fa-child" v-model="childId"><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="k in kids" :key="k.id" :value="k.id">{{ k.full_name }} · {{ k.section_label }}</option></select>
      </div>
      <p v-if="kids.length === 0" class="muted">{{ t('nur.family.noChild') }}</p>
    </section>

    <template v-if="childId">
      <section v-if="balance" class="card" aria-labelledby="fa-bal">
        <h2 id="fa-bal">{{ t('nur.family.balance') }}</h2>
        <p>{{ t('nur.fees.owed', { amount: formatXaf(balance.balance_xaf) }) }} <span v-if="balance.in_arrears" class="tag tag--warning">{{ t('nur.fees.arrears') }}</span></p>
      </section>

      <section class="card" aria-labelledby="fa-pick">
        <h2 id="fa-pick">{{ t('nur.family.pickups') }}</h2>
        <p class="muted">{{ t('nur.family.pickupsHelp') }}</p>
        <ul class="list">
          <li v-for="a in people" :key="a.id">
            <div class="row row--between">
              <span><strong>{{ a.name }}</strong> <span class="muted">{{ a.relationship }}</span> <span v-if="a.grant_type === 'day'" class="tag tag--neutral">{{ t('nur.auth.dayOnly', { date: a.valid_on ?? '' }) }}</span></span>
              <span class="row">
                <button v-if="a.grant_type === 'day'" type="button" class="btn btn--quiet" :disabled="busy" @click="makeCode(a)">{{ t('nur.auth.makeCode') }}</button>
                <button type="button" class="btn btn--quiet" :disabled="busy" @click="revoke(a)">{{ t('nur.auth.revoke') }}</button>
              </span>
            </div>
            <p v-if="code && code.id === a.id" class="notice" role="status">{{ t('nur.auth.codeShown', { code: code.code }) }}</p>
          </li>
        </ul>
        <form class="grid2" @submit.prevent="add">
          <div class="field"><label for="fa-name">{{ t('nur.auth.name') }}</label><input id="fa-name" v-model="form.name" type="text" maxlength="160" required autocomplete="off" /></div>
          <div class="field"><label for="fa-rel">{{ t('nur.auth.relationship') }}</label><input id="fa-rel" v-model="form.relationship" type="text" maxlength="60" required /></div>
          <div class="field"><label for="fa-phone">{{ t('nur.enrol.phone') }}</label><input id="fa-phone" v-model="form.phone" type="tel" maxlength="30" /></div>
          <div class="field">
            <label for="fa-type">{{ t('nur.auth.type') }}</label>
            <select id="fa-type" v-model="form.grant_type"><option value="permanent">{{ t('nur.auth.permanent') }}</option><option value="day">{{ t('nur.auth.day') }}</option></select>
          </div>
          <div v-if="form.grant_type === 'day'" class="field"><label for="fa-on">{{ t('nur.auth.on') }}</label><input id="fa-on" v-model="form.valid_on" type="date" required /></div>
          <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.auth.addDo') }}</button></div>
        </form>
      </section>

      <form class="card" aria-labelledby="fa-all" @submit.prevent="saveAllergies">
        <h2 id="fa-all">{{ t('nur.family.allergies') }}</h2>
        <div class="field"><label for="fa-allergy">{{ t('nur.family.allergyLabel') }}</label><input id="fa-allergy" v-model="allergies" type="text" maxlength="500" /><span class="hint">{{ t('nur.family.allergyHint') }}</span></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('nur.family.allergySave') }}</button>
      </form>

      <section class="card" aria-labelledby="fa-books">
        <h2 id="fa-books">{{ t('nur.family.books') }}</h2>
        <p v-if="books.length === 0" class="muted">{{ t('nur.family.noBooks') }}</p>
        <article v-for="b in books" :key="b.id" class="book">
          <p class="body">{{ b.synthesis }}</p>
          <ul class="plain"><li v-for="(text, domain) in b.domains" :key="domain"><strong>{{ domain }}</strong> : {{ text }}</li></ul>
        </article>
      </section>

      <section class="card" aria-labelledby="fa-life">
        <h2 id="fa-life">{{ t('nur.family.life') }}</h2>
        <p v-if="life.length === 0" class="muted">{{ t('nur.family.noLife') }}</p>
        <article v-for="e in life" :key="e.id" class="book">
          <h3>{{ e.title }}</h3>
          <p class="muted">{{ e.activity_on }}</p>
          <p v-if="e.body" class="body">{{ e.body }}</p>
          <div class="row photos">
            <template v-for="m in e.media" :key="m.id">
              <img v-if="photos[m.id]" :src="photos[m.id]" :alt="t('nur.family.photoAlt', { title: e.title })" class="photo" />
              <button v-else type="button" class="btn btn--quiet" @click="showPhoto(m.id)">{{ t('nur.life.photo') }}</button>
            </template>
          </div>
          <p v-if="e.withheld_media > 0" class="muted">{{ t('nur.family.withheld', { count: e.withheld_media }) }}</p>
        </article>
      </section>

      <section v-if="consent" class="card" aria-labelledby="fa-consent">
        <h2 id="fa-consent">{{ t('nur.family.consent') }}</h2>
        <p>{{ consent.active ? t('nur.consent.activeHelp') : t('nur.consent.noneHelp') }}</p>
        <form v-if="!consent.active" class="grid" @submit.prevent="giveConsent">
          <div class="field"><label for="fa-signer">{{ t('nur.consent.signedBy') }}</label><input id="fa-signer" v-model="signer" type="text" maxlength="160" required /></div>
          <label class="check"><input v-model="accept" type="checkbox" /> {{ t('nur.family.consentText') }}</label>
          <div><button type="submit" class="btn btn--primary" :disabled="busy || !accept || !signer.trim()">{{ t('nur.family.consentGive') }}</button></div>
        </form>
        <button v-else type="button" class="btn" :disabled="busy" @click="withdrawConsent">{{ t('nur.family.consentWithdraw') }}</button>
      </section>

      <section v-if="docs.length > 0" class="card" aria-labelledby="fa-docs">
        <h2 id="fa-docs">{{ t('nur.family.documents') }}</h2>
        <ul class="plain"><li v-for="d in docs" :key="d.id">{{ t(`nur.doc.kind.${d.kind}` as MessageKey) }} · {{ d.number }} · <a :href="d.verification_path" target="_blank" rel="noopener">{{ t('nur.doc.verify') }}</a></li></ul>
      </section>

      <section v-if="services.length > 0" class="card" aria-labelledby="fa-svc">
        <h2 id="fa-svc">{{ t('nur.family.services') }}</h2>
        <ul class="plain"><li v-for="s in services" :key="s.id">{{ s.plan_label }} · {{ t('nur.svc.since', { date: s.starts_on }) }}<span v-if="s.ends_on"> → {{ s.ends_on }}</span></li></ul>
      </section>

      <section v-if="needs.length > 0" class="card" aria-labelledby="fa-needs">
        <h2 id="fa-needs">{{ t('nur.family.needs') }}</h2>
        <ul class="plain"><li v-for="n in needs" :key="n.id">{{ n.summary }}<span v-if="n.orientation_note" class="muted"> · {{ n.orientation_note }}</span></li></ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
.grid { display: grid; gap: 10px; }
.list, .plain { list-style: none; margin: 8px 0; padding: 0; display: grid; gap: 10px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.photos { flex-wrap: wrap; gap: 12px; }
.photo { max-width: 200px; height: auto; border-radius: 8px; }
.body { white-space: pre-wrap; }
.book { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
