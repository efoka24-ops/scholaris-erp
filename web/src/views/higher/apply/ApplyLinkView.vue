<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { applyLink, type LinkState } from '@/api/higher-apply';
import HigherFrame from '../HigherFrame.vue';
import { useHigherAction } from '../useHigherAction';
import { useHigherStore } from '../store';
import { useApplyI18n } from './i18n';

// Lien public « Candidater » de l'établissement (scolarité) : création, copie pour WhatsApp ou affiche, désactivation, renouvellement.
const { ta } = useApplyI18n();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();
const state = ref<LinkState | null>(null);
const copied = ref(false);
const confirmRotate = ref(false);

const url = computed(() => (state.value?.path ? `${window.location.origin}${state.value.path}` : ''));
const canWrite = computed(() => !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => applyLink.get());
  if (res) state.value = res;
}
onMounted(async () => {
  await higher.ensure();
  await load();
});

async function change(body: { enabled?: boolean; rotate?: boolean }): Promise<void> {
  const res = await act.run(() => applyLink.put(body));
  if (!res) return;
  state.value = res;
  confirmRotate.value = false;
  copied.value = false;
}

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(url.value);
    copied.value = true;
  } catch {
    copied.value = false;
  }
}
</script>

<template>
  <HigherFrame :title="ta('ha.link.title')" :lede="ta('ha.link.lede')" section="admissions" :selector="false">
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <section class="card" aria-labelledby="lk-title">
      <h2 id="lk-title">{{ ta('ha.link.manage') }}</h2>
      <template v-if="state && state.exists">
        <p><span class="tag" data-test="state">{{ state.enabled ? ta('ha.link.active') : ta('ha.link.disabled') }}</span></p>
        <div class="field">
          <label for="lk-url">{{ ta('ha.link.manage') }}</label>
          <input id="lk-url" :value="url" type="text" readonly data-test="url" @focus="($event.target as HTMLInputElement).select()" />
        </div>
        <p v-if="copied" role="status">{{ ta('ha.link.copied') }}</p>
        <div class="actions">
          <button type="button" class="btn btn--primary" @click="copy">{{ ta('ha.link.copy') }}</button>
          <template v-if="canWrite">
            <button type="button" class="btn" :disabled="act.busy.value" data-test="toggle" @click="change({ enabled: !state.enabled })">{{ state.enabled ? ta('ha.link.disable') : ta('ha.link.enable') }}</button>
            <button v-if="!confirmRotate" type="button" class="btn btn--quiet" @click="confirmRotate = true">{{ ta('ha.link.rotate') }}</button>
          </template>
        </div>
        <div v-if="confirmRotate" class="notice" role="alert">
          <p>{{ ta('ha.link.rotateWarn') }}</p>
          <button type="button" class="btn btn--ink" :disabled="act.busy.value" data-test="rotate" @click="change({ rotate: true })">{{ ta('ha.link.rotate') }}</button>
          <button type="button" class="btn btn--quiet" @click="confirmRotate = false">{{ ta('ha.back') }}</button>
        </div>
      </template>
      <template v-else-if="state">
        <p>{{ ta('ha.link.none') }}</p>
        <button v-if="canWrite" type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="create" @click="change({ enabled: true })">{{ ta('ha.link.create') }}</button>
      </template>
    </section>
  </HigherFrame>
</template>
