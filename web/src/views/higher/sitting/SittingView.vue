<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { deliberation, needsSecondFactor, type Jury } from '@/api/higher-deliberation';
import { sittings, type Sitting } from '@/api/higher-extra';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import TwoFactorNotice from '../deliberation/TwoFactorNotice.vue';
import { DP } from '../deliberation/access';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { useXt } from '../xi18n';

// Séance de délibération d'un jury dans le moteur du socle (/deliberations) : ouverture (composition et présents du jury),
// clôture (procès-verbal, empreinte SHA-256) et réouverture motivée. Clôture et réouverture exigent une double authentification
// récente : sur 403, renvoi vers l'écran de 2FA puis retour ici. Signature et publication restent sur la fiche du jury.
const props = defineProps<{ id: string }>();
const { t, tv } = useXt();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const act = useHigherAction();

const jury = ref<Jury | null>(null);
const sitting = ref<Sitting | null>(null);
const needsTfa = ref(false);
const done = ref<'opened' | 'closed' | 'reopened' | null>(null);
const reason = ref('');
const reasonTried = ref(false);
const { label } = useHigherNames(() => (sitting.value?.lines ?? []).filter((l) => !l.name).map((l) => l.person_id));

const writable = computed(() => !higher.readOnly);
const canRun = computed(() => ctx.can(...DP.prepareOrPreside) && writable.value);
const status = computed(() => sitting.value?.status ?? null);
const reasonShort = computed(() => reason.value.trim().length < 10);
const presentIds = computed(() => (jury.value?.members ?? []).filter((m) => m.present).map((m) => m.person_id));

async function findSitting(j: Jury): Promise<Sitting | null> {
  try {
    return j.deliberation_ref ? await sittings.show(j.deliberation_ref) : await sittings.find({ structure_id: j.structure_id, period_id: j.period_id, exam_session: j.exam_session });
  } catch (e) {
    if (e instanceof ApiError && (e.status === 404 || e.status === 403)) return null;
    throw e;
  }
}

async function refresh(): Promise<void> {
  await higher.loadRefs('groups', 'years');
  const res = await load.run(async () => {
    const j = await deliberation.jury(props.id);
    return { j, s: await findSitting(j) };
  });
  if (!res) return;
  jury.value = res.j;
  sitting.value = res.s;
}

async function guarded(fn: () => Promise<Sitting>, outcome: 'opened' | 'closed' | 'reopened'): Promise<void> {
  needsTfa.value = false;
  done.value = null;
  const res = await act.run(fn);
  if (res) {
    sitting.value = res;
    done.value = outcome;
    return;
  }
  if (needsSecondFactor(act.error.value)) {
    needsTfa.value = true;
    act.error.value = null;
  }
}

async function open(): Promise<void> {
  const j = jury.value;
  if (!j) return;
  await guarded(async () => {
    let s = sitting.value;
    if (!s) {
      s = await sittings.open({
        structure_id: j.structure_id, period_id: j.period_id, period_label: j.period_label || j.period_id, exam_session: j.exam_session,
        president_id: j.president_person_id, members_expected: Math.max(1, j.members?.length ?? 1),
      });
      sitting.value = s;
    }
    if (presentIds.value.length) s = await sittings.attendance(s.id, presentIds.value);
    return sittings.start(s.id);
  }, 'opened');
}

async function close(): Promise<void> {
  if (sitting.value) await guarded(() => sittings.close((sitting.value as Sitting).id), 'closed');
}

async function reopen(): Promise<void> {
  reasonTried.value = true;
  if (!sitting.value || reasonShort.value) return;
  await guarded(() => sittings.reopen((sitting.value as Sitting).id, reason.value.trim()), 'reopened');
  if (done.value === 'reopened') {
    reason.value = '';
    reasonTried.value = false;
  }
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <HigherFrame :title="t('hx.sit.title')" :lede="t('hx.sit.lede')" section="home" :selector="false">
    <template #actions>
      <router-link class="btn btn--quiet" :to="{ name: 'higher-del-jury', params: { id } }">← {{ t('hx.sit.back') }}</router-link>
    </template>
    <ErrorNotice :error="load.error.value" />
    <template v-if="jury">
      <p class="muted">{{ higher.groupName(jury.structure_id) }} · {{ jury.period_label || jury.period_id }} · {{ higher.yearName(jury.academic_year_id) }}</p>

      <section class="card card--strong hi-section" aria-labelledby="st-state" data-test="sitting">
        <h2 id="st-state">{{ t('hx.sit.status') }}</h2>
        <p v-if="!sitting" class="muted" data-test="no-sitting">{{ t('hx.sit.none') }}</p>
        <template v-else>
          <p><span class="tag" :class="`tag--${toneOf(status) ?? 'neutral'}`" data-test="status">{{ tv('hx.sit.st.', status) }}</span> {{ t('hx.sit.quorum', { present: sitting.members_present?.length ?? 0, expected: sitting.members_expected }) }}</p>
          <p v-if="sitting.minutes_fingerprint" class="muted hi-small">{{ t('hdl.jury.fingerprint') }} : <code>{{ sitting.minutes_fingerprint.slice(0, 16) }}…</code> (v{{ sitting.minutes_version }})</p>
          <p v-if="sitting.reopen_reason" class="muted hi-small">{{ t('hx.sit.lastReason', { reason: sitting.reopen_reason }) }}</p>
        </template>

        <TwoFactorNotice v-if="needsTfa" />
        <ErrorNotice :error="act.error.value" />
        <p v-if="done" class="notice notice--success" role="status" data-test="done">{{ t(`hx.sit.done.${done}`) }}</p>

        <div v-if="canRun && (!sitting || status === 'draft')" class="hi-section">
          <p class="hint">{{ t('hx.sit.openHint') }}</p>
          <button type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="open" @click="open">{{ t('hx.sit.open') }}</button>
        </div>
        <div v-if="canRun && status === 'open'" class="hi-section">
          <p class="hint">{{ t('hx.sit.closeHint') }}</p>
          <button type="button" class="btn btn--ink" :disabled="act.busy.value" data-test="close" @click="close">{{ t('hx.sit.close') }}</button>
        </div>
        <form v-if="writable && status === 'closed'" class="hi-section" novalidate data-test="reopen" @submit.prevent="reopen">
          <div class="field">
            <label for="st-reason">{{ t('hx.sit.reason') }}</label>
            <textarea id="st-reason" v-model="reason" rows="3" maxlength="255" aria-describedby="st-reason-hint" :aria-invalid="reasonTried && reasonShort" />
            <span id="st-reason-hint" class="hint">{{ t('hx.sit.reasonHint') }}</span>
            <span v-if="reasonTried && reasonShort" class="error">{{ t('hx.sit.reasonShort') }}</span>
          </div>
          <button type="submit" class="btn" :disabled="act.busy.value">{{ t('hx.sit.reopen') }}</button>
        </form>
      </section>

      <section v-if="sitting?.lines?.length" class="hi-section" aria-labelledby="st-lines">
        <h2 id="st-lines">{{ t('hx.sit.decisions') }}</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">{{ t('hx.sit.student') }}</th><th scope="col">{{ t('hx.sit.proposal') }}</th><th scope="col">{{ t('hx.sit.decision') }}</th></tr></thead>
            <tbody>
              <tr v-for="l in sitting.lines" :key="l.id">
                <th scope="row">{{ l.name ?? label(l.person_id) }}<span v-if="l.matricule" class="muted hi-small"> · {{ l.matricule }}</span></th>
                <td>{{ l.proposal ?? '—' }}</td>
                <td>{{ l.decision ?? '—' }}<span v-if="l.reason" class="muted hi-small"> ({{ l.reason }})</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </HigherFrame>
</template>
