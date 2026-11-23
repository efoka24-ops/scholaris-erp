<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { isRecentTwoFactorRequired, runPlatformAction } from '@/api/platform';
import { clearPending, loadPending, savePending, type PendingAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import { useI18n } from '@/i18n';

// Après une nouvelle double authentification : propose de reprendre l'action interrompue sur la page où elle a été demandée.
const emit = defineEmits<{ done: [] }>();
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const pending = ref<PendingAction | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const doneLabel = ref<string | null>(null);
const again = ref<PendingAction | null>(null);

onMounted(() => {
  const value = loadPending();
  if (value && value.returnTo === route.fullPath) pending.value = value;
});

async function replay(): Promise<void> {
  const action = pending.value;
  if (!action) return;
  busy.value = true;
  error.value = null;
  try {
    await runPlatformAction({ method: action.method, path: action.path, body: action.body });
    clearPending();
    pending.value = null;
    doneLabel.value = action.label;
    emit('done');
  } catch (e) {
    if (isRecentTwoFactorRequired(e)) again.value = action;
    else {
      error.value = e;
      clearPending();
      pending.value = null;
    }
  } finally {
    busy.value = false;
  }
}

function drop(): void {
  clearPending();
  pending.value = null;
}

async function revalidate(): Promise<void> {
  if (!again.value) return;
  savePending({ ...again.value, savedAt: Date.now() });
  await router.push({ name: 'twofactor', query: { redirect: again.value.returnTo } });
}
</script>

<template>
  <div v-if="pending" class="notice replay" role="status" aria-labelledby="replay-title">
    <p id="replay-title"><strong>{{ t('plat.replay.title') }}</strong></p>
    <p>{{ t('plat.replay.text', { label: pending.label }) }}</p>
    <div class="row">
      <button type="button" class="btn btn--primary" :disabled="busy" @click="replay">{{ busy ? t('common.loading') : t('plat.replay.run') }}</button>
      <button type="button" class="btn btn--quiet" :disabled="busy" @click="drop">{{ t('plat.replay.drop') }}</button>
    </div>
  </div>
  <TwoFactorPrompt :pending="again" @revalidate="revalidate" @dismiss="again = null" />
  <ErrorNotice :error="error" />
  <p v-if="doneLabel" class="notice notice--success" role="status">{{ t('plat.replay.done', { label: doneLabel }) }}</p>
</template>
