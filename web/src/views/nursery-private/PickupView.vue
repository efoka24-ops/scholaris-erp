<script setup lang="ts">
// Sortie sécurisée : la liste du jour d'une section. L'enfant n'est remis qu'à une personne désignée par son AUTORISATION (ou un code du
// jour) : aucun champ « nom libre » ne remet un enfant. Hors ligne : paquet du matin gardé sur l'appareil, gestes mis en file puis rejoués
// sans doublon ; paquet trop ancien (limite lue de /settings par le serveur) : mode « doute », aucune remise sans appel à la direction.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { nursery, type PickupPackage } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { cachedPackage, enqueueGesture, flushOutbox, isDoubtful, listOutbox, savePackage } from './outbox';
import SectionPicker from './SectionPicker.vue';
import { newUuid, today, useAction } from './useNur';

type PackageChild = PickupPackage['children'][number];

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();
const section = ref('');
const pkg = ref<PickupPackage | null>(null);
const offline = ref(false);
const pending = ref(0);
const message = ref('');
const refusing = ref('');
const declared = ref('');
const coding = ref('');
const code = ref('');
const holding = ref('');
const holdRemark = ref('');

const est = computed(() => ctx.establishmentId ?? '');
const doubtful = computed(() => isDoubtful(pkg.value));

async function refreshPending(): Promise<void> {
  pending.value = (await listOutbox(est.value)).length;
}
async function load(): Promise<void> {
  message.value = '';
  if (!section.value) {
    pkg.value = null;
    return;
  }
  try {
    const fresh = await nursery.pickupPackage(section.value, today());
    await savePackage(est.value, fresh);
    pkg.value = fresh;
    offline.value = false;
  } catch (e) {
    if ((e as { status?: number }).status === 0) {
      pkg.value = await cachedPackage(est.value, section.value);
      offline.value = true;
      if (pkg.value === null) error.value = e;
    } else {
      error.value = e;
    }
  }
}
watch(section, () => void load());

function restricted(child: PackageChild, authorizedId: string, name: string): boolean {
  const norm = (s: string): string => s.trim().replace(/\s+/g, ' ').toLowerCase();
  return child.restrictions.some((r) => r.restricted_pickup_id === authorizedId || norm(r.restricted_name) === norm(name));
}
function reason(e: unknown): string {
  const code = (e as { problem?: { code?: string } }).problem?.code;
  return code ? t(`nur.pickup.reason.${code}` as never) : t('nur.pickup.reason.unknown');
}

/** Un geste : en ligne il part tout de suite, hors ligne il est mis en file (rejoué sans doublon au retour du réseau). */
async function gesture(kind: 'handover' | 'refusal' | 'hold', child: PackageChild, extra: Record<string, unknown>, label: string): Promise<void> {
  const payload = { learner_id: child.id, ...extra };
  message.value = '';
  if (offline.value) {
    await enqueueGesture(est.value, kind, label, payload);
    message.value = t('nur.pickup.queued');
    await refreshPending();
    return;
  }
  try {
    if (kind === 'handover') {
      const result = await run(() => nursery.handover({ ...payload, client_uuid: newUuid() }));
      if (result) message.value = t('nur.pickup.handed', { name: child.name });
    } else if (kind === 'refusal') {
      const result = await run(() => nursery.refusal({ ...payload, client_uuid: newUuid() }));
      if (result) message.value = t('nur.pickup.refusedRecorded', { sent: result.alerts?.sent ?? 0 });
    } else {
      const result = await run(() => nursery.hold({ ...payload, client_uuid: newUuid() }));
      if (result) message.value = t('nur.pickup.heldRecorded', { name: child.name });
    }
    if (error.value) {
      const failure = error.value;
      if ((failure as { status?: number }).status === 422 && (failure as { problem?: { code?: string } }).problem?.code) {
        message.value = t('nur.pickup.refusedBy', { name: child.name, reason: reason(failure) });
        error.value = null;
      }
    }
  } catch {
    /* run() garde l'erreur */
  }
}
function hand(child: PackageChild, authorizedId: string, name: string): void {
  void gesture('handover', child, { pickup_id: authorizedId }, t('nur.pickup.handTo', { name }));
}
async function handByCode(child: PackageChild): Promise<void> {
  await gesture('handover', child, { code: code.value.trim() }, t('nur.pickup.byCode'));
  code.value = '';
  coding.value = '';
}
async function refuse(child: PackageChild): Promise<void> {
  await gesture('refusal', child, declared.value.trim() ? { declared_name: declared.value.trim() } : {}, t('nur.pickup.refuseLabel'));
  declared.value = '';
  refusing.value = '';
}
async function hold(child: PackageChild): Promise<void> {
  await gesture('hold', child, { remark: holdRemark.value.trim() }, t('nur.pickup.holdLabel'));
  holdRemark.value = '';
  holding.value = '';
}
async function sync(): Promise<void> {
  const report = await flushOutbox(est.value);
  await refreshPending();
  message.value = report.failed.length > 0 ? t('nur.pickup.syncFailed', { count: report.failed.length }) : t('nur.pickup.synced', { count: report.sent });
  if (report.sent > 0 || report.failed.length === 0) await load();
}
const onOnline = (): void => void sync();

onMounted(async () => {
  await refreshPending();
  window.addEventListener('online', onOnline);
});
onBeforeUnmount(() => window.removeEventListener('online', onOnline));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.pickup.title') }}</h1>
      <p class="lede">{{ t('nur.pickup.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="message" class="notice" role="status" aria-live="polite">{{ message }}</p>

    <section class="card">
      <SectionPicker v-model="section" id="pk-section" />
      <p v-if="offline" class="notice notice--warning" role="status">{{ t('nur.pickup.offline') }}</p>
      <div v-if="pkg && doubtful" class="notice notice--error" role="alert">
        <p><strong>{{ t('nur.pickup.doubt') }}</strong></p>
        <p v-for="d in pkg.direction" :key="d.phone"><a :href="`tel:${d.phone}`">{{ d.name }} : {{ d.phone }}</a></p>
      </div>
      <div v-if="pending > 0" class="row row--between">
        <span class="tag tag--warning">{{ t('nur.pickup.pending', { count: pending }) }}</span>
        <button type="button" class="btn btn--quiet" @click="sync">{{ t('nur.pickup.sync') }}</button>
      </div>
      <p v-if="pkg" class="muted">{{ t('nur.pickup.verify') }}</p>
    </section>

    <section v-for="child in pkg?.children ?? []" :key="child.id" class="card" :aria-labelledby="`pk-${child.id}`">
      <h2 :id="`pk-${child.id}`">{{ child.name }} <span v-if="child.absent" class="tag tag--neutral">{{ t('nur.call.absent') }}</span></h2>
      <p v-if="child.authorized.length === 0" class="notice notice--warning" role="status">{{ t('nur.pickup.noneAuthorized') }}</p>
      <ul class="list">
        <li v-for="a in child.authorized" :key="a.id" class="row row--between">
          <span>
            <strong>{{ a.name }}</strong> <span class="muted">{{ a.relationship }}</span>
            <span v-if="a.phone" class="muted"> · {{ a.phone }}</span>
            <span v-if="a.grant_type === 'day'" class="tag tag--neutral">{{ t('nur.auth.today') }}</span>
            <span v-if="restricted(child, a.id, a.name)" class="tag tag--danger">{{ t('nur.pickup.restricted') }}</span>
          </span>
          <button v-if="!restricted(child, a.id, a.name)" type="button" class="btn btn--primary" :disabled="busy || doubtful" @click="hand(child, a.id, a.name)">{{ t('nur.pickup.handTo', { name: a.name }) }}</button>
        </li>
      </ul>

      <div class="row actions">
        <button type="button" class="btn btn--quiet" :aria-expanded="coding === child.id" :disabled="offline || doubtful" @click="coding = coding === child.id ? '' : child.id">{{ t('nur.pickup.code') }}</button>
        <button type="button" class="btn btn--quiet" :aria-expanded="refusing === child.id" @click="refusing = refusing === child.id ? '' : child.id">{{ t('nur.pickup.notListed') }}</button>
        <button type="button" class="btn btn--quiet" :aria-expanded="holding === child.id" @click="holding = holding === child.id ? '' : child.id">{{ t('nur.pickup.hold') }}</button>
      </div>
      <form v-if="coding === child.id" class="field" @submit.prevent="handByCode(child)">
        <label :for="`pk-code-${child.id}`">{{ t('nur.pickup.codeLabel') }}</label>
        <input :id="`pk-code-${child.id}`" v-model="code" type="text" inputmode="numeric" autocomplete="off" maxlength="20" required />
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.pickup.codeDo') }}</button>
      </form>
      <form v-if="refusing === child.id" class="field" @submit.prevent="refuse(child)">
        <p class="muted">{{ t('nur.pickup.refuseHelp') }}</p>
        <label :for="`pk-decl-${child.id}`">{{ t('nur.pickup.declared') }}</label>
        <input :id="`pk-decl-${child.id}`" v-model="declared" type="text" maxlength="160" autocomplete="off" />
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.pickup.refuseDo') }}</button>
      </form>
      <form v-if="holding === child.id" class="field" @submit.prevent="hold(child)">
        <label :for="`pk-hold-${child.id}`">{{ t('nur.pickup.holdRemark') }}</label>
        <input :id="`pk-hold-${child.id}`" v-model="holdRemark" type="text" maxlength="500" required />
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.pickup.holdDo') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 8px 0; padding: 0; display: grid; gap: 10px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); gap: 8px; flex-wrap: wrap; }
.actions { flex-wrap: wrap; gap: 8px; }
.actions .btn, .list .btn { min-height: 44px; }
</style>
