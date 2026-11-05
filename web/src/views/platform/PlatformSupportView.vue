<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchClientEstablishments, fetchClients, type ClientEstablishment, type Tenant } from '@/api/platform';
import { fetchSupportAgents, fetchSupportInbox, openSupportToClient, SUPPORT_STATUSES, type SupportAgent, type SupportConversation } from '@/api/messaging';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { usePolling } from '@/views/messaging/usePolling';

// Boîte du support SCHOLARIS (platform_admin, platform_support) : conversations de nature support seulement, par client,
// statut et assignation. Le super admin peut écrire aux super administrateurs d'un client (403 pour les autres rôles).
const { t, locale } = useI18n();
const router = useRouter();
const filters = reactive({ status: 'open', tenantId: '', assignee: '' });
const items = ref<SupportConversation[]>([]);
const next = ref<string | null>(null);
const clients = ref<Tenant[]>([]);
const agents = ref<SupportAgent[]>([]);
const loading = ref(false);
const loaded = ref(false);
const error = ref<unknown>(null);

const draft = reactive({ open: false, tenantId: '', establishmentId: '', subject: '', body: '' });
const establishments = ref<ClientEstablishment[]>([]);
const opening = ref(false);
const openError = ref<unknown>(null);

async function load(more = false, quiet = false): Promise<void> {
  if (!quiet) {
    loading.value = true;
    error.value = null;
  }
  try {
    const page = await fetchSupportInbox(filters, more ? next.value : null);
    items.value = more ? [...items.value, ...page.items] : page.items;
    next.value = page.nextCursor;
  } catch (e) {
    if (!quiet) error.value = e;
  } finally {
    loading.value = false;
    loaded.value = true;
  }
}

async function loadReferences(): Promise<void> {
  try {
    [clients.value, agents.value] = await Promise.all([fetchClients(), fetchSupportAgents()]);
  } catch {
    /* listes d'aide seulement : la boîte reste utilisable sans elles */
  }
}

function agentName(id: string | null): string {
  if (!id) return t('msg.sup.unassigned');
  return agents.value.find((a) => a.user_id === id)?.name ?? id;
}

watch(
  () => draft.tenantId,
  async (tenant) => {
    establishments.value = [];
    draft.establishmentId = '';
    if (!tenant) return;
    try {
      establishments.value = await fetchClientEstablishments(tenant);
      if (establishments.value.length === 1) draft.establishmentId = establishments.value[0]!.id;
    } catch (e) {
      openError.value = e;
    }
  },
);

async function openConversation(): Promise<void> {
  if (opening.value || !draft.tenantId || !draft.establishmentId || !draft.subject.trim() || !draft.body.trim()) return;
  opening.value = true;
  openError.value = null;
  try {
    const created = await openSupportToClient({ tenant_id: draft.tenantId, establishment_id: draft.establishmentId, subject: draft.subject.trim(), body: draft.body.trim() });
    await router.push({ name: 'platform-support-thread', params: { id: created.id } });
  } catch (e) {
    openError.value = e;
  } finally {
    opening.value = false;
  }
}

onMounted(() => {
  void load();
  void loadReferences();
});
usePolling(() => (items.value.length <= 50 ? load(false, true) : undefined));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('plat.eyebrow') }}</p>
      <h1>{{ t('msg.sup.title') }}</h1>
      <p class="lede">{{ t('msg.sup.lede') }}</p>
    </header>

    <form class="row filters" novalidate @submit.prevent="load()">
      <div class="field">
        <label for="sup-status">{{ t('msg.sup.status') }}</label>
        <select id="sup-status" v-model="filters.status">
          <option value="">{{ t('msg.sup.all') }}</option>
          <option v-for="s in SUPPORT_STATUSES" :key="s" :value="s">{{ t(`msg.sup.status.${s}` as 'msg.sup.status.open') }}</option>
        </select>
      </div>
      <div class="field">
        <label for="sup-client">{{ t('msg.sup.client') }}</label>
        <select id="sup-client" v-model="filters.tenantId">
          <option value="">{{ t('msg.sup.all') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field">
        <label for="sup-assignee">{{ t('msg.sup.assignee') }}</label>
        <select id="sup-assignee" v-model="filters.assignee">
          <option value="">{{ t('msg.sup.all') }}</option>
          <option value="none">{{ t('msg.sup.unassigned') }}</option>
          <option v-for="a in agents" :key="a.user_id" :value="a.user_id">{{ a.name }}</option>
        </select>
      </div>
      <button type="submit" class="btn" :disabled="loading">{{ t('msg.sup.apply') }}</button>
    </form>

    <ErrorNotice :error="error" />
    <p v-if="loading && items.length === 0" class="muted" role="status">{{ t('common.loading') }}</p>
    <p v-else-if="loaded && !error && items.length === 0" class="card muted">{{ t('msg.sup.empty') }}</p>
    <ul class="inbox">
      <li v-for="c in items" :key="c.id">
        <router-link :to="{ name: 'platform-support-thread', params: { id: c.id } }" class="item" :class="{ unread: (c.unread_count ?? 0) > 0 }">
          <span class="subject">{{ c.subject }}</span>
          <span class="meta">
            <span>{{ c.tenant_name ?? c.tenant_id }} · {{ c.establishment_name ?? c.establishment_id }}</span>
            <span>{{ t(`msg.sup.status.${c.support_status}` as 'msg.sup.status.open') }}</span>
            <span>{{ t('msg.sup.assignee') }} : {{ agentName(c.assignee) }}</span>
            <time :datetime="c.last_message_at ?? c.created_at">{{ formatDateTime(c.last_message_at ?? c.created_at, locale) }}</time>
          </span>
          <span v-if="(c.unread_count ?? 0) > 0" class="count">{{ c.unread_count }}</span>
        </router-link>
      </li>
    </ul>
    <button v-if="next" type="button" class="btn more" :disabled="loading" @click="load(true)">{{ t('plat.loadMore') }}</button>

    <section class="card new">
      <button type="button" class="btn btn--ghost" :aria-expanded="draft.open" @click="draft.open = !draft.open">{{ t('msg.sup.new') }}</button>
      <form v-if="draft.open" class="stack" novalidate @submit.prevent="openConversation">
        <p class="muted">{{ t('msg.sup.newLede') }}</p>
        <div class="field">
          <label for="sup-new-client">{{ t('msg.sup.client') }}</label>
          <select id="sup-new-client" v-model="draft.tenantId" required>
            <option value="" disabled>{{ t('msg.sup.chooseClient') }}</option>
            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sup-new-est">{{ t('msg.sup.establishment') }}</label>
          <select id="sup-new-est" v-model="draft.establishmentId" required>
            <option value="" disabled>{{ t('msg.sup.chooseEstablishment') }}</option>
            <option v-for="e in establishments" :key="e.id" :value="e.id">{{ e.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="sup-new-subject">{{ t('msg.new.subject') }}</label>
          <input id="sup-new-subject" v-model="draft.subject" type="text" maxlength="160" required />
        </div>
        <div class="field">
          <label for="sup-new-body">{{ t('msg.new.body') }}</label>
          <textarea id="sup-new-body" v-model="draft.body" rows="4" maxlength="5000" required />
        </div>
        <ErrorNotice :error="openError" />
        <button type="submit" class="btn" :disabled="opening">{{ t('msg.sup.open') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.filters {
  align-items: flex-end;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.filters .field {
  flex: 1 1 180px;
  max-width: 280px;
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
}
.item.unread .subject {
  font-weight: 800;
}
.subject {
  overflow-wrap: anywhere;
}
.meta {
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
}
.more {
  margin-top: 16px;
}
.new {
  margin-top: 24px;
}
.stack {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
  max-width: 640px;
}
.stack input,
.stack select,
.stack textarea {
  width: 100%;
}
.stack .btn {
  align-self: flex-start;
}
</style>
