<script setup lang="ts">
// File des appels de l'appareil : en attente, en conflit (la version du serveur n'est jamais écrasée) ou refusés.
import { computed } from 'vue';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useOfflineStore } from '@/stores/offline';
import { discardCall, retryCall, type QueuedCall } from './offlineCalls';
import type { usePendingCalls } from './usePendingCalls';

const props = defineProps<{ queue: ReturnType<typeof usePendingCalls> }>();
const { t, tp } = useI18n();
const offline = useOfflineStore();

const pending = computed(() => props.queue.items.value.filter((i) => i.status === 'pending'));
const attention = computed(() => props.queue.items.value.filter((i) => i.status !== 'pending'));

async function retry(item: QueuedCall): Promise<void> {
  await retryCall(item.client_uuid);
  await props.queue.sync();
}
async function discard(item: QueuedCall): Promise<void> {
  if (window.confirm(t('ps.queue.confirmDiscard'))) await discardCall(item.client_uuid);
}
const part = (item: QueuedCall): string => t(item.day_part === 'morning' ? 'ps.call.morning' : 'ps.call.afternoon');
</script>

<template>
  <section v-if="queue.items.value.length > 0" class="card queue" aria-labelledby="queue-title">
    <h2 id="queue-title">{{ t('ps.queue.title') }}</h2>
    <p v-if="pending.length > 0" class="row row--between" data-test="pending-counter" role="status">
      <span>{{ tp('ps.queue.pending', pending.length) }}</span>
      <button v-if="offline.online" type="button" class="btn btn--quiet" :disabled="queue.syncing.value" @click="queue.sync()">
        {{ queue.syncing.value ? t('ps.queue.syncing') : t('ps.queue.syncNow') }}
      </button>
    </p>
    <ul class="list">
      <li v-for="item in queue.items.value" :key="item.client_uuid">
        <div>
          <strong>{{ item.class_label }}</strong> · {{ formatDate(item.date) }} · {{ part(item) }}
          <div class="muted small">{{ t('ps.queue.counts', { absent: item.absent, late: item.late }) }}</div>
          <p v-if="item.status === 'conflict'" class="notice notice--warning" role="alert">{{ t('ps.queue.conflict') }} {{ item.message }}</p>
          <p v-else-if="item.status === 'error'" class="notice notice--error" role="alert">{{ t('ps.queue.error') }} {{ item.message }}</p>
        </div>
        <span v-if="item.status === 'pending'" class="tag tag--neutral">{{ t('ps.queue.waiting') }}</span>
        <div v-else class="row">
          <button v-if="item.status === 'error'" type="button" class="btn" @click="retry(item)">{{ t('ps.queue.retry') }}</button>
          <button type="button" class="btn btn--quiet" @click="discard(item)">{{ t('ps.queue.discard') }}</button>
        </div>
      </li>
    </ul>
    <p v-if="attention.length > 0" class="muted small">{{ t('ps.queue.attentionHint') }}</p>
  </section>
</template>

<style scoped>
.queue { display: grid; gap: 10px; }
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.list li { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
.small { font-size: 13px; }
</style>
