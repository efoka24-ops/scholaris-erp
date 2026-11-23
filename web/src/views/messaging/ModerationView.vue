<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  dismissReport,
  fetchMessages,
  fetchReports,
  hideMessage,
  REPORT_STATUSES,
  restoreMessage,
  type ChatMessage,
  type ConversationReport,
  type ReportStatus,
} from '@/api/messaging';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import MessageText from './MessageText.vue';

// Modération (direction, messaging.moderate) : messages signalés, lecture du message (journalisée par le serveur),
// masquer avec motif, rétablir, classer sans suite.
const { t, locale } = useI18n();
const status = ref<ReportStatus>('open');
const items = ref<ConversationReport[]>([]);
const next = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);
const notice = ref('');
const shown = reactive<Record<string, ChatMessage | null>>({});
const reasons = reactive<Record<string, string>>({});

async function load(more = false): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const page = await fetchReports(status.value, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

async function show(r: ConversationReport): Promise<void> {
  error.value = null;
  try {
    const messages = await fetchMessages(r.conversation_id, null);
    let found = messages.items.find((m) => m.id === r.message_id) ?? null;
    let cursor = messages.nextCursor;
    while (!found && cursor) {
      const page = await fetchMessages(r.conversation_id, cursor);
      found = page.items.find((m) => m.id === r.message_id) ?? null;
      cursor = page.nextCursor;
    }
    shown[r.id] = found;
  } catch (e) {
    error.value = e;
  }
}

async function act(r: ConversationReport, action: 'hide' | 'restore' | 'dismiss'): Promise<void> {
  error.value = null;
  notice.value = '';
  try {
    if (action === 'hide') {
      const reason = (reasons[r.id] ?? '').trim();
      if (reason.length < 3) return;
      shown[r.id] = await hideMessage(r.conversation_id, r.message_id, reason);
    } else if (action === 'restore') {
      shown[r.id] = await restoreMessage(r.conversation_id, r.message_id);
    } else {
      await dismissReport(r.id);
    }
    notice.value = t('msg.mod.done');
    await load();
  } catch (e) {
    error.value = e;
  }
}

function changeStatus(value: ReportStatus): void {
  status.value = value;
  void load();
}

onMounted(() => load());
</script>

<template>
  <div class="page">
    <router-link :to="{ name: 'inbox' }" class="back">{{ t('msg.thread.back') }}</router-link>
    <header class="page-head">
      <p class="eyebrow">{{ t('msg.eyebrow') }}</p>
      <h1>{{ t('msg.mod.title') }}</h1>
      <p class="lede">{{ t('msg.mod.lede') }}</p>
    </header>

    <div class="tabs" role="group" :aria-label="t('msg.mod.status')">
      <button v-for="s in REPORT_STATUSES" :key="s" type="button" class="sel" :aria-pressed="status === s" @click="changeStatus(s)">
        {{ t(`msg.mod.status.${s}` as 'msg.mod.status.open') }}
      </button>
    </div>

    <p v-if="notice" class="card" role="status">{{ notice }}</p>
    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('msg.mod.empty') }}</p>

    <ul class="reports">
      <li v-for="r in items" :key="r.id" class="card">
        <p class="muted">{{ t('msg.mod.reportedAt', { date: formatDateTime(r.created_at, locale) }) }}</p>
        <p><strong>{{ t('msg.mod.reason') }} :</strong> {{ r.reason }}</p>
        <button v-if="shown[r.id] === undefined" type="button" class="btn btn--ghost" @click="show(r)">{{ t('msg.mod.show') }}</button>
        <blockquote v-else-if="shown[r.id]" class="quoted">
          <MessageText :body="shown[r.id]?.body ?? ''" />
          <p v-if="shown[r.id]?.moderation_status === 'hidden'" class="muted"><em>{{ t('msg.thread.hidden') }}</em></p>
        </blockquote>
        <div class="row actions">
          <template v-if="r.status === 'open'">
            <label class="sr-only" :for="`mod-${r.id}`">{{ t('msg.mod.hideReason') }}</label>
            <input :id="`mod-${r.id}`" v-model="reasons[r.id]" type="text" maxlength="160" :placeholder="t('msg.mod.hideReason')" />
            <button type="button" class="btn" :disabled="(reasons[r.id] ?? '').trim().length < 3" @click="act(r, 'hide')">{{ t('msg.mod.hide') }}</button>
            <button type="button" class="btn btn--ghost" @click="act(r, 'dismiss')">{{ t('msg.mod.dismiss') }}</button>
          </template>
          <button v-else-if="r.status === 'actioned'" type="button" class="btn btn--ghost" @click="act(r, 'restore')">{{ t('msg.mod.restore') }}</button>
        </div>
      </li>
    </ul>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ t('msg.thread.older') }}</button>
  </div>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 8px;
}
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.reports {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.quoted {
  margin: 8px 0;
  padding: 8px 12px;
  border-left: 3px solid var(--line, #ddd);
}
.actions {
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.actions input {
  flex: 1 1 200px;
}
.more {
  margin-top: 16px;
}
</style>
