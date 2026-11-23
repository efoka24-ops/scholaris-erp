<script setup lang="ts">
import { computed, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { REGULARISATION_LEVELS, actions, type Entitlement, type RegularisationLevel } from '@/api/platform';
import { useI18n } from '@/i18n';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Blocage, déblocage et promesse de paiement (délai supplémentaire) d'un client ou d'une de ses applications.
// Motif obligatoire, double authentification récente, journal : tout est vérifié et audité côté serveur.
const props = defineProps<{
  tenant: string;
  clientName: string;
  /** Application visée ; null : toutes les applications ouvertes du client. */
  appKey: string | null;
  appLabel?: string;
  kind: 'lock' | 'unlock' | 'delay';
  idPrefix: string;
}>();
const emit = defineEmits<{ done: []; cancel: [] }>();
const { t } = useI18n();
const action = useSensitiveAction();

const reason = ref('');
const level = ref<RegularisationLevel | ''>('');
const until = ref('');
const fieldError = ref<string | null>(null);

const day = (offset: number): string => new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10);
const minDate = computed(() => day(1));
const maxDate = computed(() => day(90));
const scope = computed(() => (props.appKey ? t('rg.act.scopeApp', { app: props.appLabel ?? props.appKey }) : t('rg.act.scopeAll')));

async function submit(): Promise<void> {
  fieldError.value = null;
  const text = reason.value.trim();
  if (text === '' || text.length > 500) {
    fieldError.value = t('rg.act.reasonRequired');
    return;
  }
  if (props.kind === 'delay' && (until.value < minDate.value || until.value > maxDate.value)) {
    fieldError.value = t('rg.act.untilInvalid');
    return;
  }
  if (props.kind === 'unlock' && until.value !== '' && (until.value < minDate.value || until.value > maxDate.value)) {
    fieldError.value = t('rg.act.untilInvalid');
    return;
  }
  const names = { client: props.clientName };
  const build =
    props.kind === 'lock'
      ? actions.lockClient(props.tenant, text, level.value === '' ? null : level.value, props.appKey)
      : props.kind === 'unlock'
        ? actions.unlockClient(props.tenant, text, until.value || null, props.appKey)
        : actions.grantDelay(props.tenant, until.value, text, props.appKey);
  const res = await action.run<Entitlement[]>(build, t(`rg.act.label.${props.kind}`, names));
  if (res) emit('done');
}
</script>

<template>
  <form class="panel" novalidate @submit.prevent="submit">
    <h4>{{ t(`rg.act.title.${kind}`) }}</h4>
    <p class="muted">{{ t(`rg.act.text.${kind}`) }} {{ scope }}</p>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <div class="field">
      <label :for="`${idPrefix}-reason`">{{ t('rg.act.reason') }}</label>
      <textarea :id="`${idPrefix}-reason`" v-model="reason" rows="3" maxlength="500" :aria-invalid="!!fieldError" :aria-describedby="`${idPrefix}-reason-hint`" />
      <span :id="`${idPrefix}-reason-hint`" class="hint">{{ t('rg.act.reasonHint') }}</span>
    </div>
    <div v-if="kind === 'lock'" class="field">
      <label :for="`${idPrefix}-level`">{{ t('rg.act.level') }}</label>
      <select :id="`${idPrefix}-level`" v-model="level">
        <option value="">{{ t('rg.act.levelDefault') }}</option>
        <option v-for="l in REGULARISATION_LEVELS" :key="l" :value="l">{{ t(`rg.level.${l}`) }}</option>
      </select>
      <span class="hint">{{ t('rg.act.levelHint') }}</span>
    </div>
    <div v-if="kind !== 'lock'" class="field">
      <label :for="`${idPrefix}-until`">{{ kind === 'delay' ? t('rg.act.until') : t('rg.act.untilOptional') }}</label>
      <input :id="`${idPrefix}-until`" v-model="until" type="date" :min="minDate" :max="maxDate" :aria-describedby="`${idPrefix}-until-hint`" />
      <span :id="`${idPrefix}-until-hint`" class="hint">{{ kind === 'delay' ? t('rg.act.untilHintDelay') : t('rg.act.untilHintUnlock') }}</span>
    </div>
    <p v-if="fieldError" class="error-line" role="alert">{{ fieldError }}</p>
    <div class="row">
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t(`rg.act.submit.${kind}`) }}</button>
      <button type="button" class="btn btn--quiet" @click="emit('cancel')">{{ t('rg.act.cancel') }}</button>
    </div>
  </form>
</template>

<style scoped>
.panel {
  border-top: 1px solid var(--border);
  margin-top: 12px;
  padding-top: 12px;
  max-width: 560px;
}
.error-line {
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
h4 {
  margin: 0 0 8px;
}
</style>
