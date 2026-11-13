<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { backofficeActions, fetchPlatformUser, type PlatformUserCard } from '@/api/platformBackoffice';
import { accessChannels } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { platformCan } from './platformAccess';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';
import AccessCodePanel from './AccessCodePanel.vue';
import ReplayNotice from './ReplayNotice.vue';

// Fiche d'un utilisateur vue par la plateforme (C-112 point 3) : connexions, actions, messages reçus, sessions ; forcer la
// déconnexion et réémettre un code d'accès (double authentification récente, journalisées). Coordonnées masquées.
const props = defineProps<{ tenant: string; user: string }>();
const { t, locale } = useI18n();
const card = ref<PlatformUserCard | null>(null);
const error = ref<unknown>(null);
const done = ref<string | null>(null);
const showCode = ref(false);
const action = useSensitiveAction();
const statusKey = (s: string): MessageKey => `pa.adm.status.${s}` as MessageKey;

async function load(): Promise<void> {
  error.value = null;
  try {
    card.value = await fetchPlatformUser(props.tenant, props.user);
  } catch (e) {
    error.value = e;
  }
}

async function logout(): Promise<void> {
  if (!card.value || !window.confirm(t('pu.confirmLogout', { name: `${card.value.first_name} ${card.value.last_name}` }))) return;
  done.value = null;
  const res = await action.run<{ revoked_sessions: number }>(backofficeActions.logoutUser(props.tenant, props.user), `${t('pu.logout')} · ${card.value.first_name} ${card.value.last_name}`);
  if (!res) return;
  done.value = t('pu.loggedOut', { count: res.revoked_sessions });
  await load();
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow"><router-link :to="{ name: 'platform-client', params: { tenant } }">← {{ t('pu.back') }}</router-link></p>
      <h1>{{ card ? `${card.first_name} ${card.last_name}` : t('pu.title') }}</h1>
    </header>
    <ReplayNotice @done="load" />
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <template v-if="card">
      <dl class="card facts">
        <div><dt>{{ t('pa.phone') }}</dt><dd data-test="user-phone">{{ card.phone }}</dd></div>
        <div><dt>{{ t('pa.email') }}</dt><dd>{{ card.email }}</dd></div>
        <div><dt>{{ t('plat.col.status') }}</dt><dd>{{ t(statusKey(card.status)) }}</dd></div>
        <div><dt>{{ t('pa.users.roles') }}</dt><dd>{{ card.roles.join(', ') || '—' }}</dd></div>
        <div><dt>{{ t('pa.adm.twoFactor') }}</dt><dd>{{ card.two_factor_enabled ? t('pa.yes') : t('pa.no') }}</dd></div>
        <div><dt>{{ t('pa.adm.lastLogin') }}</dt><dd>{{ card.last_login_at ? formatDateTime(card.last_login_at, locale) : '—' }}</dd></div>
      </dl>
      <div class="row actions">
        <button v-if="platformCan('platform.users.logout')" type="button" class="btn" data-test="force-logout" :disabled="action.busy.value || card.sessions.length === 0" @click="logout">{{ t('pu.logout') }}</button>
        <button v-if="platformCan('platform.users.access_code', 'platform.clients.manage') && card.status !== 'locked'" type="button" class="btn" @click="showCode = !showCode">{{ t('pa.code.action') }}</button>
      </div>
      <AccessCodePanel v-if="showCode" :tenant="tenant" :user-id="card.id" :name="`${card.first_name} ${card.last_name}`" :channels="accessChannels(card.email === '—' ? null : card.email, card.phone === '—' ? null : card.phone)" @close="showCode = false" />

      <h2>{{ t('pu.sessions') }}</h2>
      <p v-if="card.sessions.length === 0" class="muted">{{ t('pu.noSession') }}</p>
      <ul v-else class="list">
        <li v-for="s in card.sessions" :key="s.id">{{ s.device ?? '—' }} · {{ t('pk.since') }} {{ formatDateTime(s.created_at, locale) }}<span v-if="s.last_used_at"> · {{ t('pu.lastUsed') }} {{ formatDateTime(s.last_used_at, locale) }}</span></li>
      </ul>

      <h2>{{ t('pu.logins') }}</h2>
      <p v-if="card.logins.length === 0" class="muted">—</p>
      <ul v-else class="list">
        <li v-for="l in card.logins" :key="l.occurred_at">{{ formatDateTime(l.occurred_at, locale) }} · {{ l.ip ?? '—' }}<span v-if="l.method"> · {{ l.method }}</span></li>
      </ul>

      <h2>{{ t('pu.actions') }}</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('pk.date') }}</th><th scope="col">{{ t('pact.action') }}</th><th scope="col">{{ t('pact.object') }}</th></tr></thead>
          <tbody><tr v-for="(a, i) in card.actions" :key="i"><td>{{ formatDateTime(a.occurred_at, locale) }}</td><td><code>{{ a.action }}</code></td><td>{{ a.object_type }} · {{ a.object_id }}</td></tr></tbody>
        </table>
      </div>

      <h2>{{ t('pu.messages') }}</h2>
      <p v-if="card.messages.length === 0" class="muted">—</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('pk.date') }}</th><th scope="col">{{ t('pm.col.channel') }}</th><th scope="col">{{ t('pm.col.recipient') }}</th><th scope="col">{{ t('pm.col.template') }}</th><th scope="col">{{ t('plat.col.status') }}</th></tr></thead>
          <tbody><tr v-for="m in card.messages" :key="m.id"><td>{{ formatDateTime(m.created_at, locale) }}</td><td>{{ m.channel }}</td><td>{{ m.recipient }}</td><td>{{ m.template_key ?? '—' }}</td><td>{{ m.status }}</td></tr></tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
  gap: 8px 16px;
}
.facts dt {
  font-size: 13px;
  color: var(--muted, #5f5f5a);
}
.facts dd {
  margin: 0;
  font-weight: 700;
}
.actions {
  margin: 12px 0;
  gap: 8px;
}
.list {
  padding-left: 18px;
}
</style>
