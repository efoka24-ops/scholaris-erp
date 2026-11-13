<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ComingSoon from '@/components/ComingSoon.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { http } from '@/api/http';
import type { Person } from '@/api/types';
import { formatDate, todayIso } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';

// Sortie des enfants (maquette MatSortie.html) : personnes autorisées à récupérer l'enfant, permanentes ou pour le jour seulement.
// La confirmation de la remise et la notification au parent n'ont pas encore d'interface côté serveur : annoncées, non simulées.
interface Pickup {
  id: string;
  learner_id: string;
  pickup_name: string;
  relationship: string | null;
  phone: string | null;
  grant_type: 'permanent' | 'day';
  valid_on: string | null;
  status: string;
}
const { t, locale } = useI18n();
const ctx = useContextStore();

const learners = ref<Person[]>([]);
const learnerId = ref('');
const pickups = ref<Pickup[]>([]);
const loading = ref(false);
const error = ref<unknown>(null);
const form = reactive({ pickup_name: '', relationship: '', phone: '', grant_type: 'permanent' as 'permanent' | 'day' });

const canManage = computed(() => ctx.can('attendance.discipline.manage'));
const today = todayIso();
const initials = (name: string): string =>
  name
    .split(/\s+/)
    .map((w) => w.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase();
const learner = computed(() => learners.value.find((p) => p.id === learnerId.value) ?? null);
/** Autorisées aujourd'hui : permanentes actives, et celles du jour dont la date est aujourd'hui. */
const active = computed(() => pickups.value.filter((p) => p.status === 'active' && (p.grant_type === 'permanent' || p.valid_on === today)));

async function loadLearners(): Promise<void> {
  try {
    learners.value = await http.all<Person>('/people', {}, {}, 5);
  } catch (e) {
    error.value = e;
  }
}

async function loadPickups(): Promise<void> {
  pickups.value = [];
  if (!learnerId.value) return;
  loading.value = true;
  error.value = null;
  try {
    pickups.value = await http.all<Pickup>('/authorized-pickups', { 'filter[learner_id]': learnerId.value }, {}, 3);
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function add(): Promise<void> {
  if (!learnerId.value || form.pickup_name.trim() === '') return;
  error.value = null;
  try {
    const body: Record<string, unknown> = { learner_id: learnerId.value, pickup_name: form.pickup_name.trim(), grant_type: form.grant_type };
    if (form.relationship.trim() !== '') body.relationship = form.relationship.trim();
    if (form.phone.trim() !== '') body.phone = form.phone.trim();
    if (form.grant_type === 'day') body.valid_on = today;
    await http.post('/authorized-pickups', body);
    form.pickup_name = '';
    form.relationship = '';
    form.phone = '';
    await loadPickups();
  } catch (e) {
    error.value = e;
  }
}

async function revoke(p: Pickup): Promise<void> {
  error.value = null;
  try {
    await http.post(`/authorized-pickups/${p.id}/revoke`);
    await loadPickups();
  } catch (e) {
    error.value = e;
  }
}

watch(learnerId, () => void loadPickups());
onMounted(() => void loadLearners());
</script>

<template>
  <div class="page pickup">
    <header class="page-head">
      <p class="eyebrow">{{ t('nursery.eyebrow') }}</p>
      <h1>{{ t('nursery.pickupTitle') }}</h1>
    </header>
    <ErrorNotice :error="error" />

    <div class="field">
      <label for="pk-learner">{{ t('nursery.child') }}</label>
      <select id="pk-learner" v-model="learnerId">
        <option value="" disabled>{{ t('grades.choose') }}</option>
        <option v-for="p in learners" :key="p.id" :value="p.id">{{ p.first_name }} {{ p.last_name }}</option>
      </select>
    </div>

    <template v-if="learner">
      <section class="card" aria-labelledby="pk-who">
        <h2 id="pk-who">{{ t('nursery.who') }}</h2>
        <p v-if="loading" class="muted">{{ t('common.loading') }}</p>
        <p v-else-if="active.length === 0" class="muted">{{ t('nursery.nobody') }}</p>
        <ul v-else class="people">
          <li v-for="p in active" :key="p.id">
            <span class="avatar-sq" aria-hidden="true">{{ initials(p.pickup_name) }}</span>
            <div class="grow">
              <b>{{ p.pickup_name }}</b>
              <div class="muted small">
                {{ p.relationship ?? '' }} · {{ p.grant_type === 'permanent' ? t('nursery.permanent') : t('nursery.dayOnly', { date: formatDate(p.valid_on, locale) }) }}<template v-if="p.phone"> · {{ p.phone }}</template>
              </div>
            </div>
            <button v-if="canManage" type="button" class="btn btn--quiet" @click="revoke(p)">{{ t('nursery.revoke') }}</button>
          </li>
        </ul>
        <p class="notice notice--warning">{{ t('nursery.checkFace') }}</p>
      </section>

      <form v-if="canManage" class="card form" novalidate @submit.prevent="add">
        <h2>{{ t('nursery.addTitle') }}</h2>
        <div class="grid2">
          <div class="field"><label for="pk-name">{{ t('nursery.name') }}</label><input id="pk-name" v-model="form.pickup_name" type="text" /></div>
          <div class="field"><label for="pk-rel">{{ t('nursery.relationship') }}</label><input id="pk-rel" v-model="form.relationship" type="text" /></div>
          <div class="field"><label for="pk-phone">{{ t('enroll.phone') }}</label><input id="pk-phone" v-model="form.phone" type="tel" inputmode="tel" /></div>
          <div class="field">
            <label for="pk-type">{{ t('nursery.grant') }}</label>
            <select id="pk-type" v-model="form.grant_type"><option value="permanent">{{ t('nursery.permanent') }}</option><option value="day">{{ t('nursery.todayOnly') }}</option></select>
          </div>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="form.pickup_name.trim() === ''">{{ t('nursery.add') }}</button>
      </form>

      <ComingSoon :title="t('nursery.handoverTitle')" :text="t('nursery.handoverText')" />
    </template>
  </div>
</template>

<style scoped>
.pickup {
  max-width: 720px;
}
.people {
  list-style: none;
  margin: 12px 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.people li {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid var(--border, #dcdcd8);
  padding: 10px 12px;
}
.grow {
  flex: 1;
}
.small {
  font-size: 13px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 12px 0;
}
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px 16px;
}
</style>
