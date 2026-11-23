<script setup lang="ts">
// Écran de porte hors ligne (T735, C-111) : paquet du matin gardé dans le coffre chiffré, arrivée / remise / refus / maintien écrits
// d'abord sur l'appareil, rejoués au retour du réseau (identifiant client stable), conflits affichés jusqu'au choix de la personne.
// Hors ligne, la remise se fait seulement à une personne autorisée du paquet ; paquet trop ancien ou d'un autre jour = mode « doute ».
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { MorningPackage, MorningPackageChild } from '@/api/publicNursery';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { todayIso } from '@/lib/format';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useContextStore } from '@/stores/context';
import VaultGate from '../public-schools/VaultGate.vue';
import {
  GateRefusal,
  cachedPackage,
  discardGate,
  flushGate,
  handoverCandidates,
  listGateQueue,
  loadPackage,
  onGateQueueChange,
  packageState,
  recordGate,
  retryGate,
  type GateAction,
  type GateQueued,
} from './offlineGate';

const { t } = useI18n();
const ctx = useContextStore();
const error = ref<unknown>(null);
const busy = ref(false);
const ready = ref(false);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const pkg = ref<MorningPackage | null>(null);
const fromCache = ref(false);
const queue = ref<GateQueued[]>([]);
const notice = ref<string | null>(null);
const search = ref('');
const chosen = ref<Record<string, string>>({});
const refusedName = ref<Record<string, string>>({});
const remarks = ref<Record<string, string>>({});
const today = todayIso();

const est = computed(() => ctx.establishmentId ?? '');
const state = computed(() => (pkg.value ? packageState(pkg.value, today) : null));
const doubt = computed(() => !pkg.value || (state.value?.doubt ?? true));
const pending = computed(() => queue.value.filter((i) => i.status === 'pending'));
const problems = computed(() => queue.value.filter((i) => i.status !== 'pending'));
const children = computed(() => {
  const q = search.value.trim().toLowerCase();
  return (pkg.value?.children ?? []).filter((c) => !q || c.name.toLowerCase().includes(q));
});

/** Derniers gestes enregistrés sur l'appareil pour un enfant (affichés tant qu'ils ne sont pas envoyés). */
function localOf(learnerId: string): GateQueued[] {
  return queue.value.filter((i) => i.learner_id === learnerId);
}

async function refreshQueue(): Promise<void> {
  queue.value = await listGateQueue();
}

async function guard<T>(action: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;
  try {
    return await action();
  } catch (e) {
    if (e instanceof GateRefusal) notice.value = t(`pn.offline.refusal.${e.code}` as MessageKey);
    else error.value = e;
    return undefined;
  } finally {
    busy.value = false;
  }
}

async function init(): Promise<void> {
  pkg.value = (await cachedPackage(est.value)) ?? null;
  fromCache.value = pkg.value !== null;
  await refreshQueue();
  ready.value = true;
  if (online.value) await sync();
}

async function download(): Promise<void> {
  const result = await guard(() => loadPackage(est.value, today));
  if (!result) return;
  pkg.value = result.pkg;
  fromCache.value = result.fromCache;
  notice.value = result.fromCache ? t('pn.offline.packageFromCache') : t('pn.offline.packageReady');
}

async function act(child: MorningPackageChild, type: GateAction['type']): Promise<void> {
  const action: GateAction = { type, learner_id: child.id, learner_name: child.name, school_day: pkg.value?.date ?? today };
  if (type === 'handover') action.pickup_id = chosen.value[child.id];
  if (type === 'refusal') action.declared_name = refusedName.value[child.id] ?? '';
  if (type === 'hold') action.remark = remarks.value[child.id] ?? '';
  const saved = await guard(() => recordGate(est.value, action, { pkg: pkg.value, doubt: doubt.value }));
  if (!saved) return;
  notice.value = t(`pn.offline.saved.${type}` as MessageKey, { name: child.name, person: saved.pickup_name ?? saved.declared_name ?? '' });
  refusedName.value[child.id] = '';
  remarks.value[child.id] = '';
  await refreshQueue();
  if (online.value) await sync();
}

async function sync(): Promise<void> {
  const report = await guard(() => flushGate());
  await refreshQueue();
  if (!report) return;
  if (report.offline) notice.value = t('pn.offline.stillOffline');
  else if (report.unauthorized) notice.value = t('pn.offline.unauthorized');
  else if (report.sent + report.conflicts + report.errors > 0) notice.value = t('pn.offline.synced', { sent: report.sent, conflicts: report.conflicts + report.errors });
}

async function retry(item: GateQueued): Promise<void> {
  await retryGate(item.client_uuid);
  await sync();
}
async function discard(item: GateQueued): Promise<void> {
  if (!window.confirm(t('pn.offline.confirmDiscard'))) return;
  await discardGate(item.client_uuid);
  await refreshQueue();
}

function onOnline(): void {
  online.value = true;
  if (ready.value) void sync();
}
function onOffline(): void {
  online.value = false;
}
let stop: (() => void) | null = null;
onMounted(() => {
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  stop = onGateQueueChange(() => void refreshQueue());
});
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline);
  window.removeEventListener('offline', onOffline);
  stop?.();
});

const reasonLabel = (item: GateQueued): string =>
  item.reason ? t(`pn.offline.reason.${item.reason}` as MessageKey) : (item.message ?? t('pn.offline.reason.unknown'));
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.gate.eyebrow') }}</p>
      <h1>{{ t('pn.offline.title') }}</h1>
      <p class="lead">{{ t('pn.offline.intro') }}</p>
    </header>

    <VaultGate @unlocked="init">
      <ErrorNotice :error="error" />
      <p :class="['tag', online ? 'tag--ok' : 'tag--warn']" data-test="network" role="status">{{ online ? t('pn.offline.online') : t('pn.offline.offline') }}</p>
      <p v-if="notice" class="notice" role="status" aria-live="polite" data-test="notice">{{ notice }}</p>

      <section class="card stack" aria-labelledby="po-pkg">
        <h2 id="po-pkg">{{ t('pn.offline.packageTitle') }}</h2>
        <p v-if="pkg" data-test="package">{{ t('pn.offline.packageInfo', { date: pkg.date, count: pkg.children.length, age: state?.ageDays ?? 0 }) }}<span v-if="fromCache"> · {{ t('pn.offline.cached') }}</span></p>
        <p v-else class="muted">{{ t('pn.offline.noPackage') }}</p>
        <button type="button" class="btn btn--primary" data-test="download" :disabled="busy" @click="download">{{ t('pn.offline.download') }}</button>
      </section>

      <section v-if="pkg && doubt" class="card alert" role="alert" data-test="doubt">
        <h2>{{ t('pn.offline.doubtTitle') }}</h2>
        <p>{{ t(state?.reason === 'too_old' ? 'pn.offline.doubtOld' : 'pn.offline.doubtDay') }}</p>
        <ul class="plain">
          <li v-for="d in pkg.direction" :key="d.phone"><a :href="`tel:${d.phone}`">{{ d.name }} · {{ d.phone }}</a></li>
        </ul>
      </section>

      <section v-if="problems.length > 0" class="card alert" aria-labelledby="po-conf" data-test="conflicts">
        <h2 id="po-conf">{{ t('pn.offline.conflictsTitle', { count: problems.length }) }}</h2>
        <ul class="rows">
          <li v-for="item in problems" :key="item.client_uuid" :data-test="`conflict-${item.client_uuid}`">
            <p><strong>{{ item.learner_name }}</strong> · {{ t(`pn.offline.type.${item.type}` as MessageKey) }}<span v-if="item.pickup_name || item.declared_name"> ({{ item.pickup_name || item.declared_name }})</span></p>
            <p class="muted">{{ reasonLabel(item) }}</p>
            <div class="row">
              <button v-if="item.status === 'error'" type="button" class="btn btn--quiet" :disabled="busy" @click="retry(item)">{{ t('pn.offline.retry') }}</button>
              <button type="button" class="btn btn--quiet" :disabled="busy" @click="discard(item)">{{ t('pn.offline.discard') }}</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="card row" aria-labelledby="po-q">
        <h2 id="po-q" class="sr-only">{{ t('pn.offline.queueTitle') }}</h2>
        <p data-test="pending">{{ t('pn.offline.pending', { count: pending.length }) }}</p>
        <button type="button" class="btn" data-test="sync" :disabled="busy || pending.length === 0" @click="sync">{{ t('pn.offline.sync') }}</button>
      </section>

      <section v-if="pkg" class="card" aria-labelledby="po-children">
        <h2 id="po-children">{{ t('pn.offline.childrenTitle') }}</h2>
        <div class="field"><label for="po-search">{{ t('pn.offline.search') }}</label><input id="po-search" v-model="search" type="search" /></div>
        <ul class="rows">
          <li v-for="child in children" :key="child.id" :data-test="`child-${child.id}`">
            <p>
              <strong>{{ child.name }}</strong>
              <span v-if="child.absent" class="tag tag--neutral">{{ t('pn.offline.absent') }}</span>
              <span v-if="child.restrictions.length > 0" class="tag tag--warn">{{ t('pn.offline.restricted', { names: child.restrictions.map((r) => r.restricted_name).join(', ') }) }}</span>
              <span v-for="l in localOf(child.id)" :key="l.client_uuid" class="tag tag--neutral">{{ t(`pn.offline.type.${l.type}` as MessageKey) }} · {{ t(`pn.offline.status.${l.status}` as MessageKey) }}</span>
            </p>
            <div class="row">
              <button type="button" class="btn btn--quiet" :data-test="`arrive-${child.id}`" :disabled="busy" @click="act(child, 'arrival')">{{ t('pn.gate.arrive') }}</button>
            </div>
            <div class="row">
              <select v-model="chosen[child.id]" :aria-label="t('pn.offline.person')" :data-test="`person-${child.id}`" :disabled="doubt">
                <option value="">{{ t('pn.offline.choose') }}</option>
                <option v-for="p in handoverCandidates(child)" :key="p.id" :value="p.id">{{ p.name }} · {{ p.relationship }}</option>
              </select>
              <button type="button" class="btn btn--primary" :data-test="`handover-${child.id}`" :disabled="busy || doubt || !chosen[child.id]" @click="act(child, 'handover')">{{ t('pn.offline.handover') }}</button>
              <span v-if="handoverCandidates(child).length === 0" class="muted">{{ t('pn.offline.nobody') }}</span>
            </div>
            <div class="row">
              <input v-model="refusedName[child.id]" type="text" maxlength="160" :aria-label="t('pn.offline.refusedName')" :placeholder="t('pn.offline.refusedName')" :data-test="`refused-${child.id}`" />
              <button type="button" class="btn btn--quiet" :data-test="`refuse-${child.id}`" :disabled="busy || !(refusedName[child.id] ?? '').trim()" @click="act(child, 'refusal')">{{ t('pn.offline.refuse') }}</button>
            </div>
            <div class="row">
              <input v-model="remarks[child.id]" type="text" maxlength="500" :aria-label="t('pn.gate.remark')" :placeholder="t('pn.gate.remark')" :data-test="`remark-${child.id}`" />
              <button type="button" class="btn btn--quiet" :data-test="`hold-${child.id}`" :disabled="busy || !(remarks[child.id] ?? '').trim()" @click="act(child, 'hold')">{{ t('pn.gate.hold') }}</button>
            </div>
          </li>
        </ul>
      </section>
    </VaultGate>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.rows > li { border-top: 1px solid var(--border, #dcdcd8); padding-top: 8px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-top: 6px; }
.alert { border-color: var(--warn, #b45309); }
button, select { min-height: 44px; }
</style>
