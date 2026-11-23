<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import type { PendingAction } from './useSensitiveAction';
import { useI18n } from '@/i18n';

// Proposition de ressaisir le code de double authentification quand une action sensible est refusée (403 two-factor-recent-required).
const props = defineProps<{ pending: PendingAction | null }>();
const emit = defineEmits<{ revalidate: []; dismiss: [] }>();
const { t } = useI18n();
const box = ref<HTMLElement | null>(null);

watch(
  () => props.pending,
  async (value) => {
    if (!value) return;
    await nextTick();
    box.value?.focus();
  },
);
</script>

<template>
  <div v-if="pending" ref="box" class="notice notice--warning tfa" role="alert" tabindex="-1" aria-labelledby="tfa-recent-title">
    <p id="tfa-recent-title"><strong>{{ t('plat.tfa.title') }}</strong></p>
    <p>{{ t('plat.tfa.text') }}</p>
    <div class="row">
      <button type="button" class="btn btn--ink" @click="emit('revalidate')">{{ t('plat.tfa.revalidate') }}</button>
      <button type="button" class="btn btn--quiet" @click="emit('dismiss')">{{ t('plat.cancel') }}</button>
    </div>
  </div>
</template>

<style scoped>
.tfa:focus {
  outline: 3px solid var(--ink);
}
</style>
