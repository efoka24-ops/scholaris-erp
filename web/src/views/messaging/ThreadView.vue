<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  archiveConversation,
  fetchConversation,
  fetchMessages,
  MODERATOR_ROLES,
  postMessage,
  reportMessage,
  type ChatMessage,
  type Conversation,
} from '@/api/messaging';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import MessageText from './MessageText.vue';
import { usePolling } from './usePolling';
import { useUnreadStore } from './unread';

// Fil d'une conversation : messages du plus ancien au plus récent, envoi, signalement d'un message, archivage (direction).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const unread = useUnreadStore();

const conversation = ref<Conversation | null>(null);
const messages = ref<ChatMessage[]>([]);
const older = ref<string | null>(null);
const draft = ref('');
const sending = ref(false);
const error = ref<unknown>(null);
const sendError = ref<unknown>(null);
const reporting = ref<string | null>(null);
const reportReason = ref('');
const notice = ref('');

const me = computed(() => auth.me?.person_id ?? null);
const canModerate = computed(() => MODERATOR_ROLES.includes(ctx.activeRole ?? ''));
const ordered = computed(() => [...messages.value].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)));
const names = computed(() => new Map((conversation.value?.participants ?? []).map((p) => [p.person_id, p.name ?? t('msg.thread.unknown')])));

function author(m: ChatMessage): string {
  if (m.sender_side === 'platform') return t('msg.thread.supportName');
  if (m.sender_id && m.sender_id === me.value) return t('msg.thread.you');
  return (m.sender_id && names.value.get(m.sender_id)) || t('msg.thread.unknown');
}

function mine(m: ChatMessage): boolean {
  return m.sender_side === 'client' && !!m.sender_id && m.sender_id === me.value;
}

function merge(incoming: ChatMessage[]): void {
  const byId = new Map(messages.value.map((m) => [m.id, m]));
  for (const m of incoming) byId.set(m.id, m);
  messages.value = [...byId.values()];
}

async function load(): Promise<void> {
  error.value = null;
  try {
    conversation.value = await fetchConversation(props.id);
    const page = await fetchMessages(props.id);
    messages.value = page.items;
    older.value = page.nextCursor;
    void unread.refresh();
  } catch (e) {
    error.value = e;
  }
}

async function refresh(): Promise<void> {
  try {
    merge((await fetchMessages(props.id)).items);
    void unread.refresh();
  } catch {
    /* rafraîchissement silencieux : la prochaine tentative suivra */
  }
}

async function loadOlder(): Promise<void> {
  if (!older.value) return;
  try {
    const page = await fetchMessages(props.id, older.value);
    merge(page.items);
    older.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  }
}

async function send(): Promise<void> {
  const body = draft.value.trim();
  if (!body || sending.value) return;
  sending.value = true;
  sendError.value = null;
  try {
    merge([await postMessage(props.id, body)]);
    draft.value = '';
  } catch (e) {
    sendError.value = e;
  } finally {
    sending.value = false;
  }
}

async function sendReport(m: ChatMessage): Promise<void> {
  if (reportReason.value.trim().length < 3) return;
  sendError.value = null;
  try {
    await reportMessage(props.id, m.id, reportReason.value.trim());
    reporting.value = null;
    reportReason.value = '';
    notice.value = t('msg.thread.reported');
  } catch (e) {
    sendError.value = e;
  }
}

async function archive(): Promise<void> {
  if (!window.confirm(t('msg.thread.archiveConfirm'))) return;
  sendError.value = null;
  try {
    const updated = await archiveConversation(props.id);
    conversation.value = { ...updated, participants: conversation.value?.participants };
  } catch (e) {
    sendError.value = e;
  }
}

onMounted(load);
usePolling(refresh);
</script>

<template>
  <div class="page thread">
    <router-link :to="{ name: 'inbox' }" class="back">{{ t('msg.thread.back') }}</router-link>
    <ErrorNotice :error="error" />

    <template v-if="conversation">
      <header class="page-head">
        <p class="eyebrow">{{ t(`msg.kind.${conversation.kind}` as 'msg.kind.support') }}</p>
        <h1>{{ conversation.subject }}</h1>
        <p class="muted participants">
          {{ t('msg.thread.participants') }} :
          <span v-if="conversation.kind === 'support'">{{ t('msg.thread.supportName') }}, </span>
          {{ (conversation.participants ?? []).map((p) => p.name ?? t('msg.thread.unknown')).join(', ') }}
        </p>
        <button v-if="canModerate && conversation.status === 'open'" type="button" class="btn btn--ghost" @click="archive">{{ t('msg.thread.archive') }}</button>
      </header>

      <button v-if="older" type="button" class="btn btn--ghost" @click="loadOlder">{{ t('msg.thread.older') }}</button>
      <p v-if="ordered.length === 0" class="muted">{{ t('msg.thread.empty') }}</p>
      <ol class="messages" aria-live="polite">
        <li v-for="m in ordered" :key="m.id" class="bubble" :class="{ mine: mine(m), platform: m.sender_side === 'platform' }">
          <p class="who">
            <strong>{{ author(m) }}</strong>
            <time :datetime="m.created_at">{{ formatDateTime(m.created_at, locale) }}</time>
          </p>
          <p v-if="m.moderation_status === 'hidden' && m.body === null" class="muted"><em>{{ t('msg.thread.hidden') }}</em></p>
          <MessageText v-else-if="m.body !== null" :body="m.body" />
          <ul v-if="m.attachments.length > 0" class="attachments" :aria-label="t('msg.thread.attachments')">
            <li v-for="a in m.attachments" :key="a.document_id">📎 {{ a.number }} ({{ a.type }})</li>
          </ul>
          <template v-if="!mine(m) && m.moderation_status === 'visible'">
            <button v-if="reporting !== m.id" type="button" class="link" @click="reporting = m.id">{{ t('msg.thread.report') }}</button>
            <form v-else class="report" novalidate @submit.prevent="sendReport(m)">
              <label :for="`rep-${m.id}`">{{ t('msg.thread.reportReason') }}</label>
              <input :id="`rep-${m.id}`" v-model="reportReason" type="text" maxlength="160" minlength="3" required />
              <div class="row">
                <button type="submit" class="btn">{{ t('msg.thread.reportSend') }}</button>
                <button type="button" class="btn btn--ghost" @click="reporting = null">{{ t('msg.thread.cancel') }}</button>
              </div>
            </form>
          </template>
        </li>
      </ol>

      <p v-if="notice" class="card" role="status">{{ notice }}</p>
      <ErrorNotice :error="sendError" />
      <p v-if="conversation.status === 'archived'" class="card muted">{{ t('msg.thread.archived') }}</p>
      <form v-else class="composer" novalidate @submit.prevent="send">
        <label for="msg-draft">{{ t('msg.thread.write') }}</label>
        <textarea id="msg-draft" v-model="draft" rows="3" maxlength="5000" required />
        <button type="submit" class="btn" :disabled="sending || draft.trim() === ''">{{ sending ? t('msg.thread.sending') : t('msg.thread.send') }}</button>
      </form>
    </template>
  </div>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 8px;
}
.participants {
  overflow-wrap: anywhere;
}
.messages {
  list-style: none;
  margin: 12px 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.bubble {
  max-width: min(640px, 92%);
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid var(--line, #e5e5e5);
  background: var(--card, #fff);
}
.bubble.mine {
  align-self: flex-end;
  background: var(--soft, #f4f4f4);
}
.bubble.platform {
  border-color: var(--accent, #f26b1d);
}
.who {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 4px;
  font-size: 13px;
}
.who time {
  color: var(--muted);
}
.attachments {
  margin: 6px 0 0;
  padding-left: 0;
  list-style: none;
  font-size: 13px;
}
.link {
  background: none;
  border: 0;
  padding: 0;
  margin-top: 4px;
  font-size: 12px;
  color: var(--muted);
  text-decoration: underline;
  cursor: pointer;
}
.report {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 6px;
}
.composer {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 12px;
}
.composer textarea {
  width: 100%;
}
.composer .btn {
  align-self: flex-end;
}
</style>
