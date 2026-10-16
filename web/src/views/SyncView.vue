<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import StatusTag from '@/components/StatusTag.vue';
import { useI18n } from '@/i18n';
import { useOfflineStore } from '@/stores/offline';
import { discardItem, listQueue, onQueueChange, resolveConflict, retryItem, type QueueItem } from '@/offline/queue';
import { formatDateTime } from '@/lib/format';
import { persistent } from '@/offline/db';

// File d'envoi : ce qui attend, ce qui a échoué, et les conflits (les deux versions, jamais d'écrasement silencieux).
const { t, tp, locale } = useI18n();
const offline = useOfflineStore();
const items = ref<QueueItem[]>([]);
let stop: (() => void) | null = null;

async function refresh(): Promise<void> {
  items.value = await listQueue();
}

async function keepServer(i: QueueItem): Promise<void> {
  await resolveConflict(i.client_uuid, 'keep-server');
  await offline.refresh();
}
async function resend(i: QueueItem): Promise<void> {
  await resolveConflict(i.client_uuid, 'resend');
  await offline.sync();
}
async function retry(i: QueueItem): Promise<void> {
  await retryItem(i.client_uuid);
  await offline.sync();
}
async function discard(i: QueueItem): Promise<void> {
  if (window.confirm(t('sync.confirmDiscard'))) await discardItem(i.client_uuid);
  await offline.refresh();
}

const attendanceSummary = (i: QueueItem, which: 'client_version' | 'server_version'): string => {
  const v = i[which] as { records?: unknown[]; present_count?: number; absent_count?: number; late_count?: number; status?: string } | undefined;
  if (!v) return '—';
  if (i.kind !== 'attendance') {
    const g = v as { value?: number | null; score?: number | null; absence?: string | null };
    if (i.kind === 'higher-marks' && g.absence) return g.absence.toUpperCase();
    return g.absence ? t('grades.absent') : String(g.value ?? g.score ?? '—');
  }
  const bits: string[] = [];
  if (typeof v.present_count === 'number') bits.push(tp('attendance.present', v.present_count), tp('attendance.absent', v.absent_count ?? 0), tp('attendance.late', v.late_count ?? 0));
  else if (Array.isArray(v.records)) bits.push(tp('sync.lines', v.records.length));
  if (v.status) bits.push(String(v.status));
  return bits.join(' · ') || '—';
};

onMounted(() => {
  void refresh();
  stop = onQueueChange(() => void refresh());
});
onUnmounted(() => stop?.());
</script>

<template>
  <div class="page narrow">
    <header class="page-head">
      <p class="eyebrow">{{ t('sync.eyebrow') }}</p>
      <h1>{{ t('sync.title') }}</h1>
    </header>

    <p class="status" role="status">
      <StatusTag :tone="offline.online ? 'success' : 'ink'">{{ offline.online ? t('sync.online') : t('sync.offline') }}</StatusTag>
      <span>{{ tp('offline.pending', offline.pending) }}</span>
      <button type="button" class="btn btn--primary" :disabled="!offline.online || offline.syncing || offline.pending === 0" @click="offline.sync()">
        {{ offline.syncing ? t('offline.syncing') : t('offline.syncNow') }}
      </button>
    </p>
    <p v-if="!persistent" class="notice notice--warning">{{ t('sync.noStorage') }}</p>
    <p v-if="offline.lastSyncAt" class="muted">{{ t('sync.last', { date: formatDateTime(offline.lastSyncAt, locale) }) }}</p>

    <p v-if="items.length === 0" class="card muted">{{ t('sync.empty') }}</p>
    <ul class="list">
      <li v-for="i in items" :key="i.client_uuid" class="card">
        <div class="row row--between">
          <div>
            <p class="eyebrow">{{ i.kind === 'attendance' ? t('sync.kindAttendance') : t('sync.kindGrade') }} · {{ formatDateTime(i.created_at, locale) }}</p>
            <h2>{{ i.label }}</h2>
          </div>
          <StatusTag :tone="i.status === 'pending' ? 'neutral' : i.status === 'conflict' ? 'ink' : 'warning'">{{ t(`sync.status.${i.status}`) }}</StatusTag>
        </div>

        <template v-if="i.status === 'conflict'">
          <p class="notice notice--warning">{{ i.message || t('sync.conflictIntro') }}</p>
          <div class="versions">
            <div class="card"><h3>{{ t('grades.yourVersion') }}</h3><p>{{ attendanceSummary(i, 'client_version') }}</p></div>
            <div class="card"><h3>{{ t('grades.serverVersion') }}</h3><p>{{ attendanceSummary(i, 'server_version') }}</p></div>
          </div>
          <div class="row">
            <button type="button" class="btn" @click="keepServer(i)">{{ t('sync.keepServer') }}</button>
            <button v-if="i.kind !== 'attendance'" type="button" class="btn" @click="resend(i)">{{ t('sync.resend') }}</button>
          </div>
        </template>

        <template v-else-if="i.status === 'error'">
          <p class="notice notice--error" role="alert">{{ i.message || t('sync.errorIntro') }}</p>
          <div class="row">
            <button type="button" class="btn" @click="retry(i)">{{ t('sync.retry') }}</button>
            <button type="button" class="btn" @click="discard(i)">{{ t('sync.discard') }}</button>
          </div>
        </template>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 820px;
}
.status {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}
.list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.versions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin: 0.5rem 0;
}
</style>
