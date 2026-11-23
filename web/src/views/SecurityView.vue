<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Device } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Appareils connectés : révocation d'un appareil, déconnexion partout.
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const router = useRouter();
const devices = ref<Device[]>([]);
const error = ref<unknown>(null);

async function load(): Promise<void> {
  try {
    devices.value = (await http.get<{ data: Device[] }>('/me/devices')).data;
  } catch (e) {
    error.value = e;
  }
}

async function revoke(id: string): Promise<void> {
  error.value = null;
  try {
    await http.delete(`/me/devices/${id}`);
    await load();
  } catch (e) {
    error.value = e;
  }
}

async function everywhere(): Promise<void> {
  if (!window.confirm(t('security.confirmAll'))) return;
  try {
    await http.delete('/me/sessions');
    auth.clear();
    ctx.clear();
    await router.replace({ name: 'login' });
  } catch (e) {
    error.value = e;
  }
}

onMounted(load);
</script>

<template>
  <div class="page narrow">
    <header class="page-head">
      <p class="eyebrow">{{ t('security.eyebrow') }}</p>
      <h1>{{ t('security.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />

    <section class="card" aria-labelledby="pw-title">
      <h2 id="pw-title">{{ t('acc.pw.title') }}</h2>
      <p class="muted">{{ t('acc.pw.rules') }}</p>
      <router-link class="btn" :to="{ name: 'change-password' }">{{ t('acc.pw.open') }}</router-link>
    </section>

    <section class="card" aria-labelledby="dev-title">
      <h2 id="dev-title">{{ t('security.devices') }}</h2>
      <ul class="plain">
        <li v-for="d in devices" :key="d.id" class="row row--between">
          <div>
            <strong>{{ d.name }}</strong> <StatusTag v-if="d.current" tone="ink">{{ t('security.thisOne') }}</StatusTag>
            <div class="muted">{{ d.location ?? '' }} · {{ t('security.lastSeen', { date: formatDateTime(d.last_seen_at, locale) }) }}</div>
          </div>
          <button v-if="!d.current" type="button" class="btn" @click="revoke(d.id)">{{ t('security.revoke') }}</button>
        </li>
      </ul>
      <button type="button" class="btn btn--ink" @click="everywhere">{{ t('security.signOutAll') }}</button>
    </section>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 760px;
}
.plain {
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
}
.plain li {
  padding: 0.6rem 0;
  border-bottom: 1px solid var(--border);
}
</style>
