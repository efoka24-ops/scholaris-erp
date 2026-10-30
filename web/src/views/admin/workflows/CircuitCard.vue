<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { workflowsApi, type Workflow, type WorkflowChange, type WorkflowScope } from '@/api/workflows';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useAction } from '../useAction';
import { actorLabel, buildPayload, draftFrom, lockReason, stepLabel, type Draft } from './helpers';

// Un circuit : schéma des étapes (qui -> qui), valeur par défaut et valeur active, verrous expliqués, édition, retour au défaut, historique.
const props = defineProps<{ workflow: Workflow; scope: WorkflowScope; readonly?: boolean }>();
const emit = defineEmits<{ changed: [workflow: Workflow]; message: [text: string] }>();
const { t, locale } = useI18n();
const router = useRouter();
const route = useRoute();

type Mode = 'view' | 'edit' | 'review' | 'reset';
const mode = ref<Mode>('view');
const draft = ref<Draft>({});
const reason = ref('');
const showHistory = ref(false);
const history = ref<WorkflowChange[]>([]);
const saveAction = useAction();
const historyAction = useAction();
const isClient = computed(() => props.scope.kind === 'client');
const uid = `wf-${props.workflow.application}-${props.workflow.key}`;
const name = computed(() => (locale.value === 'en' ? props.workflow.label_en : props.workflow.label_fr));
const payload = computed(() => buildPayload(props.workflow, draft.value));
const hasChange = computed(() => Object.keys(payload.value).length > 0);
const editableSteps = computed(() => props.workflow.steps.filter((s) => s.imposed_by === null && (s.editable.actor || s.editable.enabled || s.editable.recent_confirmation)));
const needsIdentity = computed(() => saveAction.error.value instanceof ApiError && saveAction.error.value.status === 403 && saveAction.error.value.slug.includes('factor'));

function startEdit(): void {
  draft.value = draftFrom(props.workflow);
  reason.value = '';
  saveAction.error.value = null;
  mode.value = 'edit';
}

function cancel(): void {
  mode.value = 'view';
  saveAction.error.value = null;
}

const reasonOk = computed(() => reason.value.trim().length >= 5 && reason.value.trim().length <= 500);

/** Résumé lisible de ce qui va changer, étape par étape. */
const summary = computed(() =>
  props.workflow.steps.flatMap((step) => {
    const p = payload.value[step.key];
    if (!p) return [];
    const parts: string[] = [];
    if (p.actor !== undefined) parts.push(actorLabel(step.actors, p.actor, locale.value));
    if (p.enabled === false) parts.push(t('wf.step.skipped'));
    if (p.recent_confirmation !== undefined) parts.push(p.recent_confirmation ? t('wf.step.confirmYes') : t('wf.step.confirmNo'));
    if (p.enforced) parts.push(t('wf.badge.imposed'));
    return [{ key: step.key, label: stepLabel(step, locale.value), text: parts.join(' · ') }];
  }),
);

async function save(): Promise<void> {
  const updated = await saveAction.run(async () => {
    if (!hasChange.value) return workflowsApi.reset(props.scope, props.workflow, reason.value.trim());
    return workflowsApi.save(props.scope, props.workflow, payload.value, reason.value.trim());
  });
  if (updated) {
    mode.value = 'view';
    history.value = [];
    emit('changed', updated);
    emit('message', t('wf.saved'));
  }
}

async function resetToDefault(): Promise<void> {
  const updated = await saveAction.run(() => workflowsApi.reset(props.scope, props.workflow, reason.value.trim()));
  if (updated) {
    mode.value = 'view';
    history.value = [];
    emit('changed', updated);
    emit('message', t('wf.reset.done'));
  }
}

async function toggleHistory(): Promise<void> {
  showHistory.value = !showHistory.value;
  if (showHistory.value) {
    const rows = await historyAction.run(() => workflowsApi.history(props.scope, props.workflow));
    history.value = rows ?? [];
  }
}

function confirmIdentity(): void {
  void router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}

const effectiveText = (stepKey: string): string => {
  const step = props.workflow.steps.find((s) => s.key === stepKey);
  return step ? actorLabel(step.actors, step.effective.actor, locale.value) : '';
};
</script>

<template>
  <article class="circuit" :aria-labelledby="`${uid}-h`" :data-test="`circuit-${workflow.application}-${workflow.key}`">
    <header class="circuit__head">
      <h3 :id="`${uid}-h`">{{ name }}</h3>
      <span v-if="workflow.customised" class="tag tag--custom" data-test="badge-custom">{{ t('wf.badge.custom') }}</span>
      <span v-else class="tag">{{ t('wf.badge.default') }}</span>
    </header>

    <ol class="flow" :aria-label="t('wf.diagram', { name })">
      <li v-for="step in workflow.steps" :key="step.key" class="flow__step" :class="{ 'flow__step--off': !step.effective.enabled }" :data-test="`step-${step.key}`">
        <p class="flow__title">{{ stepLabel(step, locale) }}</p>
        <p class="flow__who"><span class="flow__k">{{ t('wf.step.who') }}</span> {{ effectiveText(step.key) }}</p>
        <p v-if="step.effective.actor !== step.default.actor" class="flow__def">{{ t('wf.step.default', { value: actorLabel(step.actors, step.default.actor, locale) }) }}</p>
        <p class="flow__tags">
          <span v-if="!step.effective.enabled" class="tag tag--warn">{{ t('wf.badge.off') }}</span>
          <span v-if="step.effective.recent_confirmation" class="tag">{{ t('wf.badge.confirm') }}</span>
          <span v-if="step.optional" class="tag">{{ t('wf.badge.optional') }}</span>
          <span v-if="step.imposed_by || step.effective.enforced_by" class="tag tag--warn">{{ t('wf.badge.imposed') }}</span>
        </p>
        <p class="flow__src">{{ t(`wf.source.${step.effective.source}`) }}</p>
        <ul v-if="step.locks.length > 0" class="locks">
          <li v-for="lock in step.locks" :key="lock.code"><span class="lock" role="img" :aria-label="t('wf.lock.aria')">&#128274;</span> {{ lockReason(lock, locale) }}</li>
        </ul>
      </li>
    </ol>

    <details class="invariants">
      <summary>{{ t('wf.locks') }} ({{ workflow.invariants.length }})</summary>
      <p class="muted">{{ t('wf.locks.hint') }}</p>
      <ul class="locks">
        <li v-for="lock in workflow.invariants" :key="lock.code"><span class="lock" role="img" :aria-label="t('wf.lock.aria')">&#128274;</span> {{ lockReason(lock, locale) }}</li>
      </ul>
    </details>

    <p v-if="workflow.override" class="muted">
      {{ t('wf.history.entry', { version: workflow.override.version, kind: t('wf.history.set'), date: formatDate(workflow.override.updated_at) }) }} · {{ workflow.override.reason }}
    </p>

    <div v-if="mode === 'view' && !readonly" class="actions">
      <button type="button" class="btn" :disabled="editableSteps.length === 0" data-test="edit" @click="startEdit">{{ t('wf.edit') }}</button>
      <button v-if="workflow.override" type="button" class="btn" data-test="reset" @click="((mode = 'reset'), (reason = ''), (saveAction.error.value = null))">{{ t('wf.reset') }}</button>
      <button type="button" class="btn" :aria-expanded="showHistory" data-test="history" @click="toggleHistory">{{ showHistory ? t('wf.history.hide') : t('wf.history') }}</button>
    </div>
    <div v-else-if="mode === 'view'" class="actions">
      <button type="button" class="btn" :aria-expanded="showHistory" @click="toggleHistory">{{ showHistory ? t('wf.history.hide') : t('wf.history') }}</button>
    </div>

    <form v-if="mode === 'edit'" class="edit" data-test="edit-form" @submit.prevent="mode = 'review'">
      <fieldset v-for="step in editableSteps" :key="step.key" class="edit__step">
        <legend>{{ stepLabel(step, locale) }}</legend>
        <div v-if="step.editable.actor" class="field">
          <label :for="`${uid}-${step.key}-actor`">{{ t('wf.edit.actor') }}</label>
          <select :id="`${uid}-${step.key}-actor`" v-model="draft[step.key]!.actor" :data-test="`actor-${step.key}`">
            <option v-for="a in step.actors" :key="a.key" :value="a.key">{{ locale === 'en' ? a.label_en : a.label_fr }}</option>
          </select>
        </div>
        <label v-if="step.editable.enabled" class="check"><input v-model="draft[step.key]!.enabled" type="checkbox" :data-test="`enabled-${step.key}`" /> {{ t('wf.edit.enabled') }}</label>
        <label v-if="step.editable.recent_confirmation" class="check"><input v-model="draft[step.key]!.recent_confirmation" type="checkbox" :data-test="`confirm-${step.key}`" /> {{ t('wf.edit.confirmation') }}</label>
        <label v-if="isClient" class="check"><input v-model="draft[step.key]!.enforced" type="checkbox" :data-test="`enforced-${step.key}`" /> {{ t('wf.edit.enforced') }}</label>
      </fieldset>
      <div class="field">
        <label :for="`${uid}-reason`">{{ t('wf.edit.reason') }}</label>
        <textarea :id="`${uid}-reason`" v-model="reason" rows="2" maxlength="500" :aria-describedby="`${uid}-reason-h`" data-test="reason"></textarea>
        <p :id="`${uid}-reason-h`" class="muted">{{ t('wf.edit.reasonHelp') }}</p>
      </div>
      <p v-if="!hasChange" class="muted">{{ t('wf.edit.nothing') }}</p>
      <div class="actions">
        <button type="submit" class="btn btn--primary" :disabled="!reasonOk" data-test="review">{{ t('wf.edit.review') }}</button>
        <button type="button" class="btn" @click="cancel">{{ t('wf.cancel') }}</button>
      </div>
    </form>

    <section v-if="mode === 'review'" class="confirm" role="alertdialog" :aria-labelledby="`${uid}-ct`" data-test="review-panel">
      <h4 :id="`${uid}-ct`">{{ t('wf.confirm.title') }}</h4>
      <p>{{ t('wf.confirm.text', { name }) }}</p>
      <ul>
        <li v-for="line in summary" :key="line.key"><strong>{{ line.label }}</strong> : {{ line.text }}</li>
        <li v-if="summary.length === 0">{{ t('wf.edit.nothing') }}</li>
      </ul>
      <p class="muted">{{ reason }}</p>
      <div class="actions">
        <button type="button" class="btn btn--primary" :disabled="saveAction.busy.value" data-test="save" @click="save">{{ t('wf.confirm.save') }}</button>
        <button type="button" class="btn" @click="mode = 'edit'">{{ t('wf.confirm.back') }}</button>
      </div>
    </section>

    <section v-if="mode === 'reset'" class="confirm" role="alertdialog" :aria-labelledby="`${uid}-rt`" data-test="reset-panel">
      <h4 :id="`${uid}-rt`">{{ t('wf.reset.title') }}</h4>
      <div class="field">
        <label :for="`${uid}-rreason`">{{ t('wf.edit.reason') }}</label>
        <textarea :id="`${uid}-rreason`" v-model="reason" rows="2" maxlength="500" data-test="reset-reason"></textarea>
      </div>
      <div class="actions">
        <button type="button" class="btn btn--primary" :disabled="!reasonOk || saveAction.busy.value" data-test="reset-confirm" @click="resetToDefault">{{ t('wf.reset.confirm') }}</button>
        <button type="button" class="btn" @click="cancel">{{ t('wf.cancel') }}</button>
      </div>
    </section>

    <div v-if="needsIdentity" class="notice" role="status">
      <p>{{ t('wf.confirmIdentity.hint') }}</p>
      <button type="button" class="btn" @click="confirmIdentity">{{ t('wf.confirmIdentity') }}</button>
    </div>
    <ErrorNotice :error="saveAction.error.value" />

    <section v-if="showHistory" class="history" :aria-label="t('wf.history')">
      <ErrorNotice :error="historyAction.error.value" />
      <p v-if="history.length === 0 && !historyAction.busy.value" class="muted">{{ t('wf.history.empty') }}</p>
      <ol>
        <li v-for="c in history" :key="c.version">
          <strong>{{ t('wf.history.entry', { version: c.version, kind: t(c.kind === 'set' ? 'wf.history.set' : 'wf.history.reset'), date: formatDate(c.changed_at) }) }}</strong>
          · {{ t('wf.history.by', { user: c.changed_by }) }}
          <br />{{ c.reason }}
        </li>
      </ol>
    </section>
  </article>
</template>

<style scoped>
.circuit { border: 1px solid var(--border-strong); background: var(--surface); padding: 16px; margin-bottom: 24px; }
.circuit__head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.circuit__head h3 { margin: 0; flex: 1 1 14rem; }
.tag { display: inline-block; padding: 2px 8px; border: 1px solid var(--border-strong); font-size: 13px; font-weight: 700; }
.tag--custom { background: var(--ink); color: #fff; border-color: var(--ink); }
.tag--warn { border-style: dashed; }
.flow { list-style: none; margin: 16px 0; padding: 0; display: flex; flex-direction: column; gap: 0; }
.flow__step { position: relative; border: 1px solid var(--border-strong); padding: 12px; margin-bottom: 20px; }
.flow__step:not(:last-child)::after { content: '\2193'; position: absolute; left: 50%; bottom: -20px; font-size: 18px; }
.flow__step--off { opacity: 0.7; border-style: dashed; }
.flow__title { margin: 0 0 4px; font-weight: 700; }
.flow__who, .flow__def, .flow__src, .flow__tags { margin: 2px 0; }
.flow__k { font-weight: 700; }
.flow__def, .flow__src, .muted { font-size: 13px; color: var(--muted, #555); }
.locks { list-style: none; margin: 8px 0 0; padding: 0; font-size: 14px; }
.locks li { margin: 4px 0; }
.invariants { margin: 8px 0; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.edit__step { border: 1px solid var(--border-strong); margin: 12px 0; padding: 12px; }
.check { display: flex; align-items: center; gap: 8px; min-height: var(--tap); }
.confirm { border: 2px solid var(--ink); padding: 12px; margin-top: 12px; }
.history ol { padding-left: 20px; }
@media (min-width: 900px) {
  .flow { flex-direction: row; flex-wrap: wrap; gap: 24px; }
  .flow__step { flex: 1 1 14rem; margin-bottom: 0; }
  .flow__step:not(:last-child)::after { content: '\2192'; left: auto; right: -20px; bottom: auto; top: 50%; }
}
</style>
