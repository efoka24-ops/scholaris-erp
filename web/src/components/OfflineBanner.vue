<script setup lang="ts">
import { useI18n } from '@/i18n';
import { useOfflineStore } from '@/stores/offline';

const { t, tp } = useI18n();
const offline = useOfflineStore();
</script>

<template>
  <div v-if="!offline.online" class="banner banner--offline" role="status">
    <strong>{{ t('offline.banner') }}</strong>
    <span>{{ t('offline.bannerHint') }}</span>
  </div>
  <div v-if="offline.pending > 0" class="banner banner--pending" role="status">
    <span>{{ tp('offline.pending', offline.pending) }}</span>
    <button v-if="offline.online" type="button" class="btn btn--quiet" :disabled="offline.syncing" @click="offline.sync()">
      {{ offline.syncing ? t('offline.syncing') : t('offline.syncNow') }}
    </button>
  </div>
  <div v-if="offline.needsAttention > 0" class="banner banner--attention" role="alert">
    <span>{{ tp('offline.attention', offline.needsAttention) }}</span>
    <router-link class="btn btn--quiet" :to="{ name: 'sync' }">{{ t('offline.review') }}</router-link>
  </div>
</template>

<style scoped>
.banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--ink);
}
.banner--offline {
  font-size: 12px;
  font-weight: 700;
  background: var(--offline);
  color: #fff;
}
.banner--pending {
  background: var(--surface);
}
.banner--attention {
  background: var(--surface);
  border-left: 6px solid var(--warning);
}
</style>
