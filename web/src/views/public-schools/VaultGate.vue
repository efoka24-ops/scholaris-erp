<script setup lang="ts">
// Barrière du code personnel (T645) : crée le code sur un appareil neuf, le demande à chaque ouverture, efface l'appareil au
// cinquième échec. Le contenu (slot) n'est rendu que coffre ouvert : rien de nominatif n'est lisible avant.
import { onMounted, ref } from 'vue';
import CodeInput from '@/components/CodeInput.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { syncDevices } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { useOfflineStore } from '@/stores/offline';
import { MAX_FAILURES, PIN_PATTERN, initialised, refreshVault, setupPin, unlockWithPin, unlocked, wipeVault } from './vault';

const { t } = useI18n();
const offline = useOfflineStore();
const emit = defineEmits<{ unlocked: [] }>();

const code = ref('');
const confirm = ref('');
const message = ref<'wiped' | 'wrong' | 'mismatch' | null>(null);
const remaining = ref(MAX_FAILURES);
const error = ref<unknown>(null);
const busy = ref(false);
const deviceId = ref<string | null>(null);

async function load(): Promise<void> {
  const state = await refreshVault();
  deviceId.value = state.deviceId;
  remaining.value = MAX_FAILURES - state.failures;
  if (state.unlocked) emit('unlocked');
}

async function create(): Promise<void> {
  message.value = null;
  error.value = null;
  if (code.value !== confirm.value) {
    message.value = 'mismatch';
    return;
  }
  busy.value = true;
  try {
    await setupPin(code.value);
    // Code aussi connu du serveur lorsque l'appareil est inscrit (PUT /sync/devices/{id}/pin) : échec réseau sans conséquence ici.
    if (deviceId.value && offline.online) await syncDevices.setPin(deviceId.value, code.value).catch(() => undefined);
    emit('unlocked');
  } catch (e) {
    error.value = e;
  } finally {
    busy.value = false;
    code.value = '';
    confirm.value = '';
  }
}

async function unlock(): Promise<void> {
  message.value = null;
  error.value = null;
  busy.value = true;
  const typed = code.value;
  try {
    const outcome = await unlockWithPin(typed);
    if (!outcome.ok) {
      remaining.value = outcome.remaining;
      message.value = outcome.wiped ? 'wiped' : 'wrong';
      return;
    }
    // Vérification serveur quand l'appareil est inscrit : un ordre d'effacement (423) vide l'appareil aussitôt.
    if (deviceId.value && offline.online) {
      try {
        await syncDevices.unlock(deviceId.value, typed);
      } catch (e) {
        if (e instanceof ApiError && e.status === 423) {
          await wipeVault();
          message.value = 'wiped';
          return;
        }
      }
    }
    emit('unlocked');
  } finally {
    busy.value = false;
    code.value = '';
  }
}

onMounted(() => void load());
</script>

<template>
  <slot v-if="unlocked" />
  <section v-else-if="initialised !== null" class="card vault" aria-labelledby="vault-title">
    <h2 id="vault-title">{{ initialised ? t('ps.vault.unlockTitle') : t('ps.vault.createTitle') }}</h2>
    <p class="muted">{{ initialised ? t('ps.vault.unlockIntro') : t('ps.vault.createIntro') }}</p>
    <ErrorNotice :error="error" />
    <div v-if="message === 'wiped'" class="notice notice--error" role="alert">
      <strong>{{ t('ps.vault.wiped') }}</strong> {{ t('ps.vault.wipedHint') }}
    </div>
    <div v-else-if="message === 'wrong'" class="notice notice--warning" role="alert">{{ t('ps.vault.wrong', { count: remaining }) }}</div>
    <div v-else-if="message === 'mismatch'" class="notice notice--warning" role="alert">{{ t('ps.vault.mismatch') }}</div>

    <form v-if="initialised" @submit.prevent="unlock">
      <CodeInput v-model="code" :label="t('ps.vault.code')" :disabled="busy" @complete="unlock" />
      <button type="submit" class="btn btn--primary btn--block" :disabled="busy || !PIN_PATTERN.test(code)">{{ t('ps.vault.unlock') }}</button>
    </form>
    <form v-else @submit.prevent="create">
      <CodeInput v-model="code" :label="t('ps.vault.code')" :disabled="busy" />
      <CodeInput v-model="confirm" :label="t('ps.vault.confirm')" :disabled="busy" />
      <button type="submit" class="btn btn--primary btn--block" :disabled="busy || !PIN_PATTERN.test(code) || !PIN_PATTERN.test(confirm)">{{ t('ps.vault.create') }}</button>
    </form>
  </section>
</template>

<style scoped>
.vault { max-width: 28rem; margin: 0 auto; display: grid; gap: 12px; }
.vault form { display: grid; gap: 14px; }
</style>
