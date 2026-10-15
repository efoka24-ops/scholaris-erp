<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { isSecondFactorRequired } from '@/api/higher';
import { grades, type Correction, type CorrectionStatus } from '@/api/higher-grades';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { formatDateTime } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import GradesNav from './GradesNav.vue';
import MarkHistory from './MarkHistory.vue';
import { describe } from './marks';
import { G } from './perms';
import { gk, useGradesI18n } from './i18n';

// Corrections de notes verrouillées (EX-NOT-06) : chaîne chef de département puis directeur d'école (2FA récente) ;
// séparation des tâches : le demandeur ne décide jamais sa propre demande (le serveur refuse aussi : 403).
const { tg, locale } = useGradesI18n();
const ctx = useContextStore();
const auth = useAuthStore();
const higher = useHigherStore();
const route = useRoute();
const router = useRouter();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Correction[]>([]);
const status = ref<CorrectionStatus | ''>('pending');
const rejecting = ref<Correction | null>(null);
const rejectReason = ref('');
const tried = ref(false);
const needsTwoFactor = ref(false);
const historyOf = ref<{ markId: string; title: string } | null>(null);

const canDecide = computed(() => ctx.can(...G.correctionApprove) && !higher.readOnly);
const mine = (c: Correction): boolean => c.requested_by === auth.me?.id;

async function load(): Promise<void> {
  const res = await list.run(() => grades.corrections(status.value ? { 'filter[status]': status.value } : {}));
  if (res) items.value = res;
}

async function after(res: Correction | undefined): Promise<void> {
  if (res) {
    rejecting.value = null;
    rejectReason.value = '';
    tried.value = false;
    await load();
    return;
  }
  if (isSecondFactorRequired(act.error.value)) {
    needsTwoFactor.value = true;
    act.error.value = null;
  }
}

async function approve(c: Correction): Promise<void> {
  needsTwoFactor.value = false;
  await after(await act.run(() => grades.approveCorrection(c.id)));
}

async function reject(): Promise<void> {
  tried.value = true;
  const c = rejecting.value;
  if (!c || !rejectReason.value.trim()) return;
  needsTwoFactor.value = false;
  await after(await act.run(() => grades.rejectCorrection(c.id, rejectReason.value.trim())));
}

async function revalidate(): Promise<void> {
  await router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="tg('hg.corr.title')" :lede="tg('hg.corr.lede')" section="portal" :selector="false">
    <GradesNav />
    <div class="hi-form">
      <div class="field">
        <label for="cf-st">{{ tg('hg.status') }}</label>
        <select id="cf-st" v-model="status" @change="load"><option value="">{{ tg('hg.all') }}</option><option v-for="s in ['pending', 'approved', 'rejected']" :key="s" :value="s">{{ tg(gk('hg.corr.state.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div v-if="needsTwoFactor" class="notice notice--warning" role="alert" data-test="needs-2fa">
      <p><strong>{{ tg('hg.twofa.title') }}</strong></p>
      <p>{{ tg('hg.twofa.text') }}</p>
      <button type="button" class="btn btn--ink" @click="revalidate">{{ tg('hg.twofa.go') }}</button>
    </div>
    <ul class="hi-list card">
      <li v-for="c in items" :key="c.id" data-test="correction">
        <div class="hi-grow">
          <strong>{{ c.element_code }} · {{ c.assessment_label }}</strong>
          <p class="hi-small">{{ tg('hg.corr.oldNew', { old: describe(c.old, locale), new: describe(c.new, locale) }) }} — {{ c.reason }}</p>
          <p class="muted hi-small">{{ c.attachment_ref }} · {{ formatDateTime(c.created_at, locale) }}</p>
          <p v-if="c.next_step" class="hi-small">{{ tg('hg.corr.nextStep', { label: c.next_step.label }) }}</p>
          <p v-for="s in c.approval_trail" :key="`${s.step}-${s.at}`" class="muted hi-small">{{ s.label }} · {{ tg(gk('hg.corr.state.', s.decision)) }}<template v-if="s.reason"> · {{ s.reason }}</template></p>
        </div>
        <span class="tag" :class="`tag--${toneOf(c.status) ?? 'neutral'}`">{{ tg(gk('hg.corr.state.', c.status)) }}</span>
        <div class="hi-actions">
          <button type="button" class="hi-linkbtn" @click="historyOf = { markId: c.mark_id, title: `${c.element_code ?? ''} · ${c.assessment_label ?? ''}` }">{{ tg('hg.grid.history') }}</button>
          <template v-if="c.status === 'pending' && canDecide">
            <span v-if="mine(c)" class="muted hi-small">{{ tg('hg.corr.own') }}</span>
            <template v-else>
              <button type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="approve" @click="approve(c)">{{ tg('hg.corr.approve') }}</button>
              <button type="button" class="btn btn--quiet" @click="rejecting = c; tried = false">{{ tg('hg.corr.reject') }}</button>
            </template>
          </template>
        </div>
      </li>
      <li v-if="items.length === 0" class="muted">{{ tg('hg.corr.none') }}</li>
    </ul>

    <section v-if="rejecting" class="card card--strong hi-section" role="dialog" aria-labelledby="rj-title">
      <h2 id="rj-title">{{ tg('hg.corr.reject') }} · {{ rejecting.element_code }}</h2>
      <div class="field">
        <label for="rj-reason">{{ tg('hg.corr.rejectReason') }}</label>
        <textarea id="rj-reason" v-model="rejectReason" maxlength="500" :aria-invalid="tried && !rejectReason.trim()" />
        <span v-if="tried && !rejectReason.trim()" class="error">{{ tg('hg.required') }}</span>
      </div>
      <div class="hi-actions">
        <button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="reject">{{ tg('hg.life.confirm') }}</button>
        <button type="button" class="btn btn--quiet" @click="rejecting = null">{{ tg('hg.life.cancel') }}</button>
      </div>
    </section>
    <MarkHistory v-if="historyOf" :key="historyOf.markId" :mark-id="historyOf.markId" :title="historyOf.title" @close="historyOf = null" />
  </HigherFrame>
</template>
