<script setup lang="ts">
// Appareil partagé (T645) : état du coffre chiffré, verrouillage, effacement, et inscription de l'appareil par la direction
// (POST /sync/devices) pour que le code personnel soit aussi contrôlé par le serveur (5 échecs = ordre d'effacement).
import { ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { syncDevices } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { useContextStore } from '@/stores/context';
import { P } from './access';
import VaultGate from './VaultGate.vue';
import { useAction } from './usePs';
import { lockVault, refreshVault, setDeviceId, wipeVault } from './vault';

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const deviceId = ref<string | null>(null);
const label = ref('');
const done = ref<'registered' | 'wiped' | null>(null);

async function load(): Promise<void> {
  deviceId.value = (await refreshVault()).deviceId;
}

async function register(): Promise<void> {
  const created = await run(() => syncDevices.register(label.value.trim()));
  if (!created) return;
  await setDeviceId(created.id);
  deviceId.value = created.id;
  label.value = '';
  done.value = 'registered';
}

async function wipe(): Promise<void> {
  if (!window.confirm(t('ps.device.confirmWipe'))) return;
  await wipeVault();
  done.value = 'wiped';
  deviceId.value = null;
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.device.title') }}</h1>
      <p class="lede">{{ t('ps.device.intro') }}</p>
    </header>

    <p v-if="done === 'wiped'" class="notice notice--success" role="status">{{ t('ps.device.wiped') }}</p>
    <VaultGate @unlocked="load">
      <ErrorNotice :error="error" />
      <section class="card stack" aria-labelledby="dev-state">
        <h2 id="dev-state">{{ t('ps.device.stateTitle') }}</h2>
        <p>{{ t('ps.device.stateText') }}</p>
        <p class="muted">{{ deviceId ? t('ps.device.registered', { id: deviceId }) : t('ps.device.notRegistered') }}</p>
        <div class="row">
          <button type="button" class="btn" @click="lockVault()">{{ t('ps.device.lock') }}</button>
          <button type="button" class="btn btn--quiet" @click="wipe">{{ t('ps.device.wipe') }}</button>
        </div>
      </section>

      <section v-if="ctx.can(...P.devicesManage) && !deviceId" class="card stack" aria-labelledby="dev-reg">
        <h2 id="dev-reg">{{ t('ps.device.registerTitle') }}</h2>
        <p class="muted">{{ t('ps.device.registerText') }}</p>
        <form @submit.prevent="register">
          <div class="field">
            <label for="dev-label">{{ t('ps.device.label') }}</label>
            <input id="dev-label" v-model="label" type="text" maxlength="120" required />
          </div>
          <button type="submit" class="btn btn--primary" :disabled="busy || label.trim() === ''">{{ t('ps.device.register') }}</button>
        </form>
        <p v-if="done === 'registered'" class="notice notice--success" role="status">{{ t('ps.device.registeredOk') }}</p>
      </section>
    </VaultGate>
  </div>
</template>
