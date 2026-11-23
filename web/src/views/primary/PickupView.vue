<script setup lang="ts">
// Sortie sécurisée : l'enfant n'est remis qu'à une personne autorisée EN VIGUEUR ce jour (registre du socle) ; toute autre personne donne un
// refus, journalisé et signalé au parent et à la direction. Écran de l'agent : classe du jour, personnes autorisées, remise, refus, alertes.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primaryPickup, type PickupDay } from '@/api/primary';
import { useI18n } from '@/i18n';
import { P } from './access';
import { usePicker } from './usePicker';
import { useAction, usePermissions } from './usePri';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const picker = usePicker();
const day = ref<PickupDay | null>(null);
const notice = ref('');
const refusing = ref<string | null>(null);
const tried = ref(false);
const refusal = reactive({ name: '', note: '' });
const canHandover = computed(() => can(...P.pickupHandover));

async function load(): Promise<void> {
  day.value = null;
  if (picker.classId.value === '') return;
  const res = await run(() => primaryPickup.day(picker.classId.value));
  if (res) day.value = res;
}

async function handover(personId: string, pickupId: string, name: string): Promise<void> {
  notice.value = '';
  const done = await run(() => primaryPickup.handover(picker.classId.value, { person_id: personId, pickup_id: pickupId }));
  if (done) {
    notice.value = t('pri.pickup.handed', { name });
    await load();
  }
}

function openRefusal(personId: string): void {
  refusing.value = personId;
  refusal.name = '';
  refusal.note = '';
  tried.value = false;
}

async function refuse(personId: string): Promise<void> {
  tried.value = true;
  if (refusal.name.trim() === '') return;
  const done = await run(() => primaryPickup.refuse(picker.classId.value, { person_id: personId, presented_name: refusal.name.trim(), note: refusal.note.trim() === '' ? null : refusal.note.trim() }));
  if (done) {
    notice.value = t('pri.pickup.refused', { n: done.notified });
    refusing.value = null;
    await load();
  }
}

const time = (iso: string): string => iso.slice(11, 16);

watch(picker.classId, () => void load());
onMounted(async () => {
  await run(() => picker.load());
  await load();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.pickup.title') }}</h1>
      <p class="lede">{{ t('pri.pickup.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <div class="field">
        <label for="pk-class">{{ t('pri.call.class') }}</label>
        <select id="pk-class" v-model="picker.classId.value"><option v-for="c in picker.classes.value" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </div>
      <p v-if="notice" class="notice notice--success" role="status" data-test="notice">{{ notice }}</p>
    </section>

    <template v-if="day">
      <p v-if="!day.enabled" class="notice notice--warning" role="status" data-test="disabled">{{ t('pri.pickup.disabled') }}</p>
      <p v-else-if="day.learners.length === 0" class="muted">{{ t('pri.pickup.noLearner') }}</p>
      <ul v-else class="stack">
        <li v-for="l in day.learners" :key="l.person_id" class="card" :data-test="`learner-${l.person_id}`">
          <h2>{{ l.name }}</h2>
          <h3>{{ t('pri.pickup.authorised') }}</h3>
          <p v-if="l.authorised.length === 0" class="muted" data-test="none-authorised">{{ t('pri.pickup.noneAuthorised') }}</p>
          <ul class="rows">
            <li v-for="a in l.authorised" :key="a.pickup_id">
              <span>{{ a.name }}<template v-if="a.relationship"> · {{ a.relationship }}</template></span>
              <button v-if="canHandover" type="button" class="btn btn--primary" :data-test="`handover-${a.pickup_id}`" :disabled="busy" @click="handover(l.person_id, a.pickup_id, a.name)">{{ t('pri.pickup.handover', { name: a.name }) }}</button>
            </li>
          </ul>
          <template v-if="canHandover">
            <button v-if="refusing !== l.person_id" type="button" class="btn btn--quiet" :data-test="`refuse-open-${l.person_id}`" @click="openRefusal(l.person_id)">{{ t('pri.pickup.refuseTitle') }}</button>
            <form v-else class="form" novalidate :data-test="`refuse-form-${l.person_id}`" @submit.prevent="refuse(l.person_id)">
              <h3>{{ t('pri.pickup.refuseTitle') }}</h3>
              <div class="field">
                <label :for="`pk-name-${l.person_id}`">{{ t('pri.pickup.presentedName') }}</label>
                <input :id="`pk-name-${l.person_id}`" v-model="refusal.name" type="text" maxlength="160" :aria-invalid="tried && refusal.name.trim() === ''" />
                <span v-if="tried && refusal.name.trim() === ''" class="error">{{ t('pri.svc.required') }}</span>
              </div>
              <div class="field"><label :for="`pk-note-${l.person_id}`">{{ t('pri.pickup.note') }}</label><input :id="`pk-note-${l.person_id}`" v-model="refusal.note" type="text" maxlength="255" /></div>
              <button type="submit" class="btn btn--ink" :disabled="busy">{{ t('pri.pickup.refuse') }}</button>
            </form>
          </template>
          <template v-if="l.events.length > 0">
            <h3>{{ t('pri.pickup.today') }}</h3>
            <ul class="rows" :data-test="`events-${l.person_id}`">
              <li v-for="(e, i) in l.events" :key="i">
                <span>{{ e.outcome === 'handed' ? t('pri.pickup.event.handed', { name: e.presented_name ?? '', time: time(e.handled_at) }) : t('pri.pickup.event.refused', { name: e.presented_name ?? '', time: time(e.handled_at) }) }}</span>
                <span class="tag" :class="e.outcome === 'handed' ? 'tag--success' : 'tag--warning'">{{ e.outcome }}</span>
              </li>
            </ul>
          </template>
        </li>
      </ul>
    </template>
  </div>
</template>
