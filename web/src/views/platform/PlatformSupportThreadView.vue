<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import {
  assignSupport,
  fetchSupportAgents,
  fetchSupportConversation,
  fetchSupportMessages,
  replySupport,
  setSupportStatus,
  SUPPORT_STATUSES,
  type SupportAgent,
  type SupportConversation,
  type SupportMessage,
  type SupportStatus,
} from '@/api/messaging';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import MessageText from '@/views/messaging/MessageText.vue';
import { usePolling } from '@/views/messaging/usePolling';

// Conversation de support vue par la plateforme : messages, réponse, statut et assignation (chaque action est journalisée).
const props = defineProps<{ id: string }>();
const { t, locale } = useI18n();
const conversation = ref<SupportConversation | null>(null);
const messages = ref<SupportMessage[]>([]);
const older = ref<string | null>(null);
const agents = ref<SupportAgent[]>([]);
const draft = ref('');
const sending = ref(false);
const error = ref<unknown>(null);
const actionError = ref<unknown>(null);
const notice = ref('');

const ordered = computed(() => [...messages.value].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)));
const names = computed(() => new Map((conversation.value?.participants ?? []).map((p) => [p.person_id, p.name ?? t('msg.thread.unknown')])));

function author(m: SupportMessage): string {
  if (m.sender_side === 'platform') return `${t('msg.sup.fromPlatform')} · ${agents.value.find((a) => a.user_id === m.sender_id)?.name ?? m.sender_id}`;
  return `${t('msg.sup.fromClient')} · ${names.value.get(m.sender_id) ?? t('msg.thread.unknown')}`;
}

function merge(incoming: SupportMessage[]): void {
  const byId = new Map(messages.value.map((m) => [m.id, m]));
  for (const m of incoming) byId.set(m.id, m);
  messages.value = [...byId.values()];
}

async function load(): Promise<void> {
  error.value = null;
  try {
    conversation.value = await fetchSupportConversation(props.id);
    const page = await fetchSupportMessages(props.id);
    messages.value = page.items;
    older.value = page.nextCursor;
  } catch (e) {
    error.value = e;
  }
  try {
    agents.value = await fetchSupportAgents();
  } catch {
    /* liste d'aide seulement */
  }
}

async function refresh(): Promise<void> {
  try {
    merge((await fetchSupportMessages(props.id)).items);
  } catch {
    /* rafraîchissement silencieux */
  }
}

async function loadOlder(): Promise<void> {
  if (!older.value) return;
  try {
    const page = await fetchSupportMessages(props.id, older.value);
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
  actionError.value = null;
  try {
    merge([await replySupport(props.id, body)]);
    draft.value = '';
    conversation.value = await fetchSupportConversation(props.id);
  } catch (e) {
    actionError.value = e;
  } finally {
    sending.value = false;
  }
}

async function changeStatus(event: Event): Promise<void> {
  const status = (event.target as HTMLSelectElement).value as SupportStatus;
  actionError.value = null;
  try {
    conversation.value = await setSupportStatus(props.id, status);
    notice.value = t('msg.sup.saved');
  } catch (e) {
    actionError.value = e;
  }
}

async function changeAssignee(event: Event): Promise<void> {
  const value = (event.target as HTMLSelectElement).value;
  actionError.value = null;
  try {
    conversation.value = await assignSupport(props.id, value === '' ? null : value);
    notice.value = t('msg.sup.saved');
  } catch (e) {
    actionError.value = e;
  }
}

onMounted(load);
usePolling(refresh);
</script>

<template>
  <div class="page thread">
    <router-link :to="{ name: 'platform-support' }" class="back">{{ t('msg.sup.back') }}</router-link>
    <ErrorNotice :error="error" />
    <template v-if="conversation">
      <header class="page-head">
        <p class="eyebrow">{{ conversation.tenant_name ?? conversation.tenant_id }} · {{ conversation.establishment_name ?? conversation.establishment_id }}</p>
        <h1>{{ conversation.subject }}</h1>
      </header>

      <div class="row controls">
        <div class="field">
          <label for="sup-th-status">{{ t('msg.sup.setStatus') }}</label>
          <select id="sup-th-status" :value="conversation.support_status" @change="changeStatus">
            <option v-for="s in SUPPORT_STATUSES" :key="s" :value="s">{{ t(`msg.sup.status.${s}` as 'msg.sup.status.open') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sup-th-assignee">{{ t('msg.sup.assign') }}</label>
          <select id="sup-th-assignee" :value="conversation.assignee ?? ''" @change="changeAssignee">
            <option value="">{{ t('msg.sup.unassigned') }}</option>
            <option v-for="a in agents" :key="a.user_id" :value="a.user_id">{{ a.name }}</option>
          </select>
        </div>
      </div>
      <p v-if="notice" class="muted" role="status">{{ notice }}</p>
      <ErrorNotice :error="actionError" />

      <button v-if="older" type="button" class="btn btn--ghost" @click="loadOlder">{{ t('msg.thread.older') }}</button>
      <ol class="messages" aria-live="polite">
        <li v-for="m in ordered" :key="m.id" class="bubble" :class="{ platform: m.sender_side === 'platform' }">
          <p class="who">
            <strong>{{ author(m) }}</strong>
            <time :datetime="m.created_at">{{ formatDateTime(m.created_at, locale) }}</time>
          </p>
          <p v-if="m.body === null" class="muted"><em>{{ t('msg.thread.hidden') }}</em></p>
          <MessageText v-else :body="m.body" />
        </li>
      </ol>

      <p v-if="conversation.status === 'archived'" class="card muted">{{ t('msg.thread.archived') }}</p>
      <form v-else class="composer" novalidate @submit.prevent="send">
        <label for="sup-draft">{{ t('msg.sup.reply') }}</label>
        <textarea id="sup-draft" v-model="draft" rows="3" maxlength="5000" required />
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
.controls {
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
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
}
.bubble.platform {
  align-self: flex-end;
  border-color: var(--accent, #f26b1d);
}
.who {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 4px;
  font-size: 13px;
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
