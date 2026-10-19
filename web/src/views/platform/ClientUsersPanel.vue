<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { accessChannels, fetchClientUsers, type ClientUser } from '@/api/platform';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import AccessCodePanel from './AccessCodePanel.vue';

// Utilisateurs d'un client, en lecture seule (aucune donnée métier), avec l'action « Code d'accès » pour dépanner un compte.
const props = defineProps<{ tenant: string; readonly?: boolean }>();
const { t, locale } = useI18n();
const items = ref<ClientUser[]>([]);
const loadError = ref<unknown>(null);
const loaded = ref(false);
const codeFor = ref<ClientUser | null>(null);
const statusKey = (s: string): MessageKey => `pa.adm.status.${s}` as MessageKey;

async function load(): Promise<void> {
  loadError.value = null;
  try {
    items.value = await fetchClientUsers(props.tenant);
  } catch (e) {
    loadError.value = e;
  } finally {
    loaded.value = true;
  }
}

onMounted(load);
</script>

<template>
  <section class="block" aria-labelledby="users-title">
    <h2 id="users-title">{{ t('pa.users.title') }}</h2>
    <p class="muted">{{ t('pa.users.hint') }}</p>
    <ErrorNotice :error="loadError" />
    <AccessCodePanel
      v-if="codeFor"
      :key="codeFor.id"
      :tenant="tenant"
      :user-id="codeFor.id"
      :name="`${codeFor.first_name} ${codeFor.last_name}`"
      :channels="accessChannels(codeFor.email, codeFor.phone)"
      @close="codeFor = null"
    />
    <p v-if="loaded && !loadError && items.length === 0" class="card muted">{{ t('pa.users.empty') }}</p>
    <div v-if="items.length > 0" class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('pa.users.title') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('pa.name') }}</th>
            <th scope="col">{{ t('pa.login') }}</th>
            <th scope="col">{{ t('pa.users.roles') }}</th>
            <th scope="col">{{ t('plat.col.status') }}</th>
            <th scope="col">{{ t('pa.adm.lastLogin') }}</th>
            <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in items" :key="u.id">
            <th scope="row">{{ u.first_name }} {{ u.last_name }}</th>
            <td>{{ u.email ?? u.phone }}</td>
            <td>{{ u.roles.join(', ') || '—' }}</td>
            <td>{{ t(statusKey(u.status)) }}</td>
            <td>{{ u.last_login_at ? formatDateTime(u.last_login_at, locale) : '—' }}</td>
            <td>
              <button v-if="!readonly && u.status !== 'locked'" type="button" class="btn btn--quiet" data-test="user-access-code" @click="codeFor = u">{{ t('pa.code.action') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.block {
  margin-top: 28px;
}
</style>
