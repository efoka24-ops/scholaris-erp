<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { documents, type IssuedDoc } from '@/api/higher';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { P } from '../access';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useXt } from '../xi18n';

// Ligne du registre : duplicata (mention datée, numéro d'origine conservé) et annulation motivée (jamais de suppression).
const props = defineProps<{ doc: IssuedDoc; typeLabel: string; holder: string; tone: string }>();
const emit = defineEmits<{ (e: 'changed', doc: IssuedDoc): void }>();
const { t, tv, locale } = useXt();
const ctx = useContextStore();
const higher = useHigherStore();
const act = useHigherAction();
const cancelling = ref(false);
const form = reactive({ reason: '', replacedBy: '', tried: false });
const notice = ref('');

const canAct = computed(() => ctx.can(...P.documentIssue) && !higher.readOnly && props.doc.status === 'valid');

async function duplicate(): Promise<void> {
  notice.value = '';
  const res = await act.run(() => documents.duplicate(props.doc.id));
  if (!res) return;
  notice.value = t('hi.doc.duplicateDone', { mention: res.duplicate?.mention ?? props.doc.number });
  emit('changed', { ...props.doc, duplicate_count: props.doc.duplicate_count + 1 });
}

async function cancel(): Promise<void> {
  form.tried = true;
  if (!form.reason.trim()) return;
  const res = await act.run(() => documents.cancel(props.doc.id, { reason: form.reason.trim(), ...(form.replacedBy.trim() ? { replaced_by: form.replacedBy.trim() } : {}) }));
  if (!res) return;
  cancelling.value = false;
  notice.value = t('hx.doc.cancelDone', { number: props.doc.number });
  emit('changed', res);
}
</script>

<template>
  <li class="card dc-row" :data-doc="doc.id">
    <div class="dc-row__head">
      <strong class="hi-mono">{{ doc.number }}</strong>
      <span class="tag" :class="`tag--${tone}`">{{ tv('hi.doc.st.', doc.status) }}</span>
    </div>
    <p class="dc-row__line">{{ typeLabel }}</p>
    <p class="muted hi-small">{{ t('hi.doc.holder') }} : <span data-test="holder">{{ holder }}</span> · {{ t('hi.doc.issuedAt') }} {{ formatDate(doc.issued_at, locale) }}<template v-if="doc.duplicate_count"> · {{ t('hx.doc.duplicates', { count: doc.duplicate_count }) }}</template></p>
    <p v-if="doc.cancel_reason" class="muted hi-small">{{ t('hi.reason') }} : {{ doc.cancel_reason }}</p>
    <div class="hi-actions">
      <router-link class="hi-linkbtn" :to="{ name: 'verify', params: { token: doc.verification_token } }">{{ t('hi.doc.verifyLink') }}</router-link>
      <button v-if="canAct" type="button" class="hi-linkbtn" :disabled="act.busy.value" data-test="duplicate" @click="duplicate">{{ t('hi.doc.duplicate') }}</button>
      <button v-if="canAct && !cancelling" type="button" class="hi-linkbtn" data-test="cancel" @click="cancelling = true">{{ t('hi.doc.cancelAction') }}</button>
    </div>
    <form v-if="cancelling" class="hi-section" novalidate data-test="cancel-form" @submit.prevent="cancel">
      <h3>{{ t('hi.doc.cancelTitle', { number: doc.number }) }}</h3>
      <p class="muted hi-small">{{ t('hi.doc.cancelText') }}</p>
      <div class="field"><label :for="`dc-reason-${doc.id}`">{{ t('hx.doc.cancelReason') }}</label><input :id="`dc-reason-${doc.id}`" v-model="form.reason" type="text" :aria-invalid="form.tried && !form.reason.trim()" /><span v-if="form.tried && !form.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
      <div class="field"><label :for="`dc-repl-${doc.id}`">{{ t('hi.doc.replacedBy') }}</label><input :id="`dc-repl-${doc.id}`" v-model="form.replacedBy" type="text" /></div>
      <div class="hi-actions">
        <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hx.doc.cancelConfirm') }}</button>
        <button type="button" class="btn btn--quiet" @click="cancelling = false">{{ t('hx.doc.close') }}</button>
      </div>
    </form>
    <ErrorNotice :error="act.error.value" />
    <p v-if="notice" class="notice notice--success" role="status">{{ notice }}</p>
  </li>
</template>

<style scoped>
.dc-row p {
  margin: 0 0 4px;
}
.dc-row__head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
}
.dc-row__line {
  font-weight: 700;
}
</style>
