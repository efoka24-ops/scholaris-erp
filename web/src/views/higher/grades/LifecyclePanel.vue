<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { isSecondFactorRequired } from '@/api/higher';
import { grades, isMinutesUnsigned, type Assessment, type AssessmentStatus } from '@/api/higher-grades';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { useConfirmation } from '@/views/account/useConfirmation';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { G } from './perms';
import { gk, useGradesI18n, type HigherGradesKey } from './i18n';

// Cycle de vie (EX-NOT-05) : soumettre, renvoyer avec motif, valider, verrouiller et publier (2FA récente), lever l'anonymat.
// 403 « second-factor-required » : on part vers l'écran de 2FA avec retour ici (`resume` rouvre la confirmation).
type Action = 'submit' | 'sendBack' | 'validate' | 'lock' | 'publish' | 'lift';

const props = defineProps<{ assessment: Assessment; nextStates: AssessmentStatus[] }>();
const emit = defineEmits<{ (e: 'changed', a: Assessment): void }>();
const { tg, locale } = useGradesI18n();
const confirmation = useConfirmation();
const ctx = useContextStore();
const higher = useHigherStore();
const route = useRoute();
const router = useRouter();
const act = useHigherAction();

const pending = ref<Action | null>(null);
const reason = ref('');
const tried = ref(false);
const needsTwoFactor = ref(false);
const minutesUnsigned = ref(false);

const LABELS: Record<Action, HigherGradesKey> = {
  submit: 'hg.life.submit', sendBack: 'hg.life.sendBack', validate: 'hg.life.validate', lock: 'hg.life.lock', publish: 'hg.life.publish', lift: 'hg.life.lift',
};
const a = computed(() => props.assessment);
const actions = computed<Action[]>(() => {
  if (higher.readOnly) return [];
  const next = new Set(props.nextStates);
  const out: Action[] = [];
  const s = a.value.status;
  if (s === 'draft' && next.has('submitted') && ctx.can(...G.submit)) out.push('submit');
  if (s === 'submitted' && next.has('validated') && ctx.can(...G.validate)) out.push('validate');
  if (s === 'submitted' && next.has('draft') && ctx.can(...G.validate)) out.push('sendBack');
  if (s === 'validated' && next.has('locked') && ctx.can(...G.validate)) out.push('lock');
  if (s === 'locked' && next.has('published') && ctx.can(...G.publish)) out.push('publish');
  if (a.value.anonymous && !a.value.anonymity_lifted && (s === 'locked' || s === 'published') && ctx.can(...G.lift)) out.push('lift');
  return out;
});
const confirmText = computed(() => (pending.value === 'lock' ? tg('hg.life.confirmLock') : pending.value === 'publish' ? tg('hg.life.confirmPublish') : ''));

function call(action: Action): Promise<Assessment> {
  const id = a.value.id;
  switch (action) {
    case 'submit': return grades.submit(id);
    case 'sendBack': return grades.sendBack(id, reason.value.trim());
    case 'validate': return grades.validate(id);
    case 'lock': return grades.lock(id);
    case 'publish': return grades.publish(id);
    case 'lift': return grades.liftAnonymity(id);
  }
}

async function confirm(): Promise<void> {
  const action = pending.value;
  if (!action) return;
  tried.value = true;
  if (action === 'sendBack' && !reason.value.trim()) return;
  needsTwoFactor.value = false;
  minutesUnsigned.value = false;
  const res = await act.run(() => call(action));
  if (res) {
    pending.value = null;
    reason.value = '';
    tried.value = false;
    emit('changed', res);
    return;
  }
  if (isSecondFactorRequired(act.error.value)) {
    needsTwoFactor.value = true;
    act.error.value = null;
  } else if (isMinutesUnsigned(act.error.value)) {
    minutesUnsigned.value = true;
    act.error.value = null;
  }
}

async function revalidate(): Promise<void> {
  const back = router.resolve({ name: route.name ?? undefined, params: route.params, query: { ...route.query, resume: pending.value ?? undefined } });
  await router.push({ name: 'twofactor', query: { redirect: back.fullPath } });
}

onMounted(() => {
  const resume = route.query.resume;
  if (typeof resume === 'string' && resume in LABELS) pending.value = resume as Action;
});
</script>

<template>
  <section class="card hi-section" data-test="lifecycle">
    <h2>{{ tg('hg.life.title') }}</h2>
    <p><span class="tag" :class="`tag--${a.status === 'published' ? 'success' : 'neutral'}`">{{ tg(gk('hg.state.', a.status)) }}</span></p>
    <div v-if="actions.length" class="hi-actions">
      <button v-for="x in actions" :key="x" type="button" class="btn" :class="x === 'publish' || x === 'lock' ? 'btn--ink' : 'btn--quiet'" :data-test="`life-${x}`" @click="pending = x; tried = false">{{ tg(LABELS[x]) }}</button>
    </div>

    <div v-if="pending" class="hi-section" role="dialog" aria-labelledby="life-confirm" data-test="life-confirm">
      <h3 id="life-confirm">{{ tg(LABELS[pending]) }}</h3>
      <p v-if="confirmText">{{ confirmText }}</p>
      <div v-if="pending === 'sendBack'" class="field">
        <label for="life-reason">{{ tg('hg.life.reason') }}</label>
        <textarea id="life-reason" v-model="reason" :aria-invalid="tried && !reason.trim()" />
        <span v-if="tried && !reason.trim()" class="error">{{ tg('hg.required') }}</span>
      </div>
      <div class="hi-actions">
        <button type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="life-go" @click="confirm">{{ tg('hg.life.confirm') }}</button>
        <button type="button" class="btn btn--quiet" @click="pending = null">{{ tg('hg.life.cancel') }}</button>
      </div>
    </div>

    <div v-if="needsTwoFactor" class="notice notice--warning" role="alert" data-test="needs-2fa">
      <p><strong>{{ confirmation.title(tg('hg.twofa.title')) }}</strong></p>
      <p>{{ confirmation.text(tg('hg.twofa.text')) }}</p>
      <button type="button" class="btn btn--ink" @click="revalidate">{{ confirmation.go(tg('hg.twofa.go')) }}</button>
    </div>
    <div v-if="minutesUnsigned" class="notice notice--warning" role="alert" data-test="pv-required"><p>{{ tg('hg.life.pvRequired') }}</p></div>
    <ErrorNotice :error="act.error.value" />

    <details v-if="a.status_trail.length" class="hi-section">
      <summary>{{ tg('hg.life.trail') }}</summary>
      <ul class="hi-list">
        <li v-for="(s, i) in a.status_trail" :key="i">
          <span class="tag tag--neutral">{{ tg(gk('hg.state.', s.to)) }}</span>
          <span class="muted hi-small">{{ s.at ? formatDate(s.at, locale) : '' }}</span>
          <span v-if="s.reason">{{ s.reason }}</span>
        </li>
      </ul>
    </details>
  </section>
</template>
