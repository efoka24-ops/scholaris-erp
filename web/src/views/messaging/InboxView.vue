<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchConversations, MODERATOR_ROLES, SUPPORT_ROLES, type Conversation } from '@/api/messaging';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import { usePolling } from './usePolling';
import { useUnreadStore } from './unread';

// Boîte de réception : conversations triées par dernier message (ordre du serveur), non lues en gras, rafraîchie toutes les 30 s.
const { t, tp, locale } = useI18n();
const ctx = useContextStore();
const unread = useUnreadStore();
const tab = ref<'open' | 'archived'>('open');
const items = ref<Conversation[]>([]);
const next = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);

const canModerate = computed(() => MODERATOR_ROLES.includes(ctx.activeRole ?? ''));
const canSupport = computed(() => SUPPORT_ROLES.includes(ctx.activeRole ?? ''));

async function load(more = false, quiet = false): Promise<void> {
  if (!quiet) loading.value = true;
  if (!quiet) error.value = null;
  try {
    const page = await fetchConversations({ status: tab.value }, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
    void unread.refresh();
  } catch (e) {
    if (!quiet) error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

function switchTab(value: 'open' | 'archived'): void {
  tab.value = value;
  void load();
}

function statusLabel(c: Conversation): string {
  if (c.kind !== 'support' || !c.support_status) return '';
  return t(`msg.support.${c.support_status}` as 'msg.support.open');
}

onMounted(() => load());
// Rafraîchit la première page seulement (sans perdre les pages déjà chargées si l'utilisateur en a demandé plus).
usePolling(() => (items.value.length <= 30 ? load(false, true) : undefined));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('msg.eyebrow') }}</p>
      <h1>{{ t('msg.inbox.title') }}</h1>
      <p class="lede">{{ t('msg.inbox.lede') }}</p>
    </header>

    <div class="row actions">
      <router-link :to="{ name: 'inbox-new' }" class="btn">{{ t('msg.inbox.new') }}</router-link>
      <router-link v-if="canSupport" :to="{ name: 'inbox-new', query: { support: '1' } }" class="btn btn--ghost">{{ t('msg.inbox.support') }}</router-link>
      <router-link v-if="canModerate" :to="{ name: 'inbox-moderation' }" class="btn btn--ghost">{{ t('msg.inbox.moderation') }}</router-link>
    </div>

    <div class="tabs" role="group">
      <button type="button" class="sel" :aria-pressed="tab === 'open'" @click="switchTab('open')">{{ t('msg.inbox.active') }}</button>
      <button type="button" class="sel" :aria-pressed="tab === 'archived'" @click="switchTab('archived')">{{ t('msg.inbox.archived') }}</button>
    </div>

    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('msg.inbox.empty') }}</p>

    <ul v-if="items.length > 0" class="inbox">
      <li v-for="c in items" :key="c.id">
        <router-link :to="{ name: 'inbox-thread', params: { id: c.id } }" class="item" :class="{ unread: (c.unread_count ?? 0) > 0 }">
          <span class="subject">{{ c.subject }}</span>
          <span class="meta">
            <span class="kind">{{ t(`msg.kind.${c.kind}` as 'msg.kind.support') }}</span>
            <span v-if="statusLabel(c)" class="status">{{ statusLabel(c) }}</span>
            <time :datetime="c.last_message_at ?? c.created_at">{{ formatDateTime(c.last_message_at ?? c.created_at, locale) }}</time>
          </span>
          <span v-if="(c.unread_count ?? 0) > 0" class="count">{{ tp('msg.inbox.unread', c.unread_count ?? 0) }}</span>
        </router-link>
      </li>
    </ul>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ t('msg.thread.older') }}</button>
  </div>
</template>

<style scoped>
.actions {
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 12px;
}
.inbox {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.item {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px 12px;
  padding: 12px 14px;
  border: 1px solid var(--line, #e5e5e5);
  border-radius: 10px;
  color: inherit;
  text-decoration: none;
  background: var(--card, #fff);
}
.item.unread .subject {
  font-weight: 800;
}
.subject {
  overflow-wrap: anywhere;
}
.meta {
  grid-column: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
  color: var(--muted);
}
.count {
  grid-row: 1 / span 2;
  grid-column: 2;
  align-self: center;
  font-weight: 700;
  font-size: 13px;
}
.more {
  margin-top: 16px;
}
</style>
