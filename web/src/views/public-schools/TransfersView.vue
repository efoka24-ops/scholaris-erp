<script setup lang="ts">
// Transferts d'élèves entre écoles : demandes reçues (accueil : accepter avec date et classe, refuser avec motif, clore) et
// demandes envoyées (certificat de radiation, exeat). Seule l'école d'accueil décide ; l'élève n'est inscrit nulle part avant l'accord.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type ClassRow, type Transfer } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { currentYear, useAction, usePermissions } from './usePs';

const { t } = useI18n();
const { establishmentId } = usePermissions();
const { error, busy, run } = useAction();

const direction = ref<'' | 'incoming' | 'outgoing'>('');
const transfers = ref<Transfer[]>([]);
const classes = ref<ClassRow[]>([]);
const open = ref<string | null>(null);
const form = reactive({ arrivalOn: todayIso(), classId: '', reason: '' });
const notice = ref('');

const isIncoming = (tr: Transfer): boolean => tr.to_establishment_id === establishmentId.value;
const shown = computed(() => transfers.value);
const stateTag = (s: string): string => (s === 'completed' ? 'tag--success' : s === 'refused' ? 'tag--warning' : 'tag--neutral');
const stateLabel = (s: string): string => {
  const map: Record<string, string> = { requested: t('ps.transfers.state.requested'), accepted: t('ps.transfers.state.accepted'), completed: t('ps.transfers.state.completed'), refused: t('ps.transfers.state.refused') };
  return map[s] ?? s;
};

async function load(): Promise<void> {
  await run(async () => {
    transfers.value = await publicSchools.transfers(direction.value || undefined);
    if (classes.value.length === 0) {
      const year = await currentYear();
      if (year) classes.value = await publicSchools.classes(year.id);
    }
  });
}

function toggle(tr: Transfer): void {
  open.value = open.value === tr.id ? null : tr.id;
  form.classId = tr.to_structure_id ?? '';
  form.reason = '';
  notice.value = '';
}

async function replace(action: () => Promise<Transfer>, done: string): Promise<void> {
  const updated = await run(action);
  if (updated) {
    transfers.value = transfers.value.map((x) => (x.id === updated.id ? updated : x));
    notice.value = done;
    open.value = null;
  }
}
const accept = (tr: Transfer) => replace(() => publicSchools.acceptTransfer(tr.id, form.arrivalOn, form.classId || null), t('ps.transfers.accepted'));
const complete = (tr: Transfer) => replace(() => publicSchools.completeTransfer(tr.id, form.classId), t('ps.transfers.completed'));
const refuse = (tr: Transfer) => replace(() => publicSchools.refuseTransfer(tr.id, form.reason.trim()), t('ps.transfers.refused'));

async function exeat(tr: Transfer): Promise<void> {
  const done = await run(() => publicSchools.issueExeat(tr.id));
  if (done) {
    tr.exeat_document_id = done.exeat_document_id;
    notice.value = t('ps.transfers.exeatIssued');
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.transfers.title') }}</h1>
      <p class="lede">{{ t('ps.transfers.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div class="field filter">
      <label for="tr-dir">{{ t('ps.transfers.direction') }}</label>
      <select id="tr-dir" v-model="direction" @change="load">
        <option value="">{{ t('ps.transfers.all') }}</option>
        <option value="incoming">{{ t('ps.transfers.incoming') }}</option>
        <option value="outgoing">{{ t('ps.transfers.outgoing') }}</option>
      </select>
    </div>
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>

    <p v-if="shown.length === 0" class="muted">{{ t('ps.transfers.none') }}</p>
    <ul class="cards">
      <li v-for="tr in shown" :key="tr.id" class="card">
        <div class="row row--between">
          <div>
            <strong>{{ tr.pupil_name ?? tr.person_id }}</strong>
            <div class="muted">{{ isIncoming(tr) ? t('ps.transfers.incomingFrom', { id: tr.from_establishment_id }) : t('ps.transfers.outgoingTo', { id: tr.to_establishment_id }) }} · {{ formatDate(tr.requested_at) }}</div>
          </div>
          <span class="tag" :class="stateTag(tr.state)">{{ stateLabel(tr.state) }}</span>
        </div>
        <p v-if="tr.refusal_reason" class="muted">{{ t('ps.transfers.reason') }} : {{ tr.refusal_reason }}</p>
        <p v-if="tr.arrival_on" class="muted">{{ t('ps.transfers.arrival', { date: formatDate(tr.arrival_on) }) }}</p>
        <div class="row">
          <button v-if="isIncoming(tr) && (tr.state === 'requested' || tr.state === 'accepted')" type="button" class="btn" :aria-expanded="open === tr.id" @click="toggle(tr)">
            {{ t('ps.transfers.decide') }}
          </button>
          <button v-if="!isIncoming(tr) && (tr.state === 'accepted' || tr.state === 'completed') && !tr.exeat_document_id" type="button" class="btn" :disabled="busy" @click="exeat(tr)">
            {{ t('ps.transfers.exeat') }}
          </button>
          <span v-if="tr.exeat_document_id" class="tag tag--success">{{ t('ps.transfers.exeatDone') }}</span>
        </div>

        <form v-if="open === tr.id && tr.state === 'requested'" class="decision" @submit.prevent="accept(tr)">
          <div class="field">
            <label :for="`tr-date-${tr.id}`">{{ t('ps.transfers.arrivalOn') }}</label>
            <input :id="`tr-date-${tr.id}`" v-model="form.arrivalOn" type="date" required />
          </div>
          <div class="field">
            <label :for="`tr-class-${tr.id}`">{{ t('ps.transfers.class') }}</label>
            <select :id="`tr-class-${tr.id}`" v-model="form.classId">
              <option value="">{{ t('ps.transfers.classLater') }}</option>
              <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
            </select>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('ps.transfers.accept') }}</button>
          <div class="field">
            <label :for="`tr-reason-${tr.id}`">{{ t('ps.transfers.refuseReason') }}</label>
            <input :id="`tr-reason-${tr.id}`" v-model="form.reason" type="text" />
          </div>
          <button type="button" class="btn" :disabled="busy || form.reason.trim() === ''" @click="refuse(tr)">{{ t('ps.transfers.refuse') }}</button>
        </form>

        <form v-if="open === tr.id && tr.state === 'accepted'" class="decision" @submit.prevent="complete(tr)">
          <div class="field">
            <label :for="`tr-cclass-${tr.id}`">{{ t('ps.transfers.class') }}</label>
            <select :id="`tr-cclass-${tr.id}`" v-model="form.classId" required>
              <option value="" disabled>{{ t('ps.classes.pick') }}</option>
              <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
            </select>
          </div>
          <button type="submit" class="btn btn--primary" :disabled="busy || form.classId === ''">{{ t('ps.transfers.complete') }}</button>
        </form>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.cards { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.filter { max-width: 20rem; }
.decision { display: grid; gap: 8px; margin-top: 12px; max-width: 28rem; }
</style>
