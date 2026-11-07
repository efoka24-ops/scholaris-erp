<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Announcement } from '@/api/types';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Annonces de l'établissement et messages envoyés (SMS, e-mail, notification) avec suivi de délivrance.
const { t, locale } = useI18n();
const ctx = useContextStore();

interface Message {
  id: string;
  template_key?: string | null;
  subject?: string | null;
  body?: string | null;
  status: string;
  channels?: string[];
  created_at?: string;
}

const announcements = ref<Announcement[]>([]);
const messages = ref<Message[]>([]);
const error = ref<unknown>(null);
const loading = ref(false);
const form = reactive({ title: '', body: '' });
const canSend = computed(() => ctx.can('messaging.send'));
const canRead = computed(() => ctx.can('messaging.read'));

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    announcements.value = await http.all<Announcement>('/announcements', {}, {}, 5);
    if (canRead.value) messages.value = await http.all<Message>('/messages', {}, {}, 3);
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function publish(): Promise<void> {
  error.value = null;
  try {
    await http.post('/announcements', { title: form.title.trim(), body: form.body.trim() });
    form.title = '';
    form.body = '';
    await load();
  } catch (e) {
    error.value = e;
  }
}

async function send(id: string): Promise<void> {
  error.value = null;
  try {
    await http.post(`/messages/${id}/send`);
    await load();
  } catch (e) {
    error.value = e;
  }
}

onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('messages.eyebrow') }}</p>
      <h1>{{ t('messages.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

    <section aria-labelledby="ann-title">
      <h2 id="ann-title">{{ t('messages.announcements') }}</h2>
      <form v-if="canSend" class="card narrow" novalidate @submit.prevent="publish">
        <div class="field"><label for="a-title">{{ t('messages.annTitle') }}</label><input id="a-title" v-model="form.title" type="text" maxlength="160" /></div>
        <div class="field"><label for="a-body">{{ t('messages.annBody') }}</label><textarea id="a-body" v-model="form.body" maxlength="5000" /></div>
        <button type="submit" class="btn btn--primary" :disabled="!form.title.trim() || !form.body.trim()">{{ t('messages.publish') }}</button>
      </form>
      <p v-if="!loading && announcements.length === 0" class="card muted">{{ t('messages.noAnnouncements') }}</p>
      <article v-for="a in announcements" :key="a.id" class="card">
        <p class="eyebrow">{{ formatDateTime(a.published_at, locale) }}</p>
        <h3>{{ a.title }}</h3>
        <p class="body">{{ a.body }}</p>
      </article>
    </section>

    <section v-if="canRead" aria-labelledby="msg-title" class="msgs">
      <h2 id="msg-title">{{ t('messages.sent') }}</h2>
      <p v-if="!loading && messages.length === 0" class="card muted">{{ t('messages.noMessages') }}</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ t('messages.subject') }}</th><th scope="col">{{ t('billing.status') }}</th><th scope="col">{{ t('messages.created') }}</th><th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th></tr></thead>
          <tbody>
            <tr v-for="m in messages" :key="m.id">
              <th scope="row">{{ m.subject ?? m.template_key ?? m.body?.slice(0, 40) }}</th>
              <td><StatusTag :tone="m.status === 'sent' ? 'success' : 'neutral'">{{ m.status }}</StatusTag></td>
              <td>{{ formatDateTime(m.created_at, locale) }}</td>
              <td><button v-if="canSend && m.status === 'draft'" type="button" class="btn" @click="send(m.id)">{{ t('messages.send') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 640px;
}
.body {
  white-space: pre-wrap;
}
.msgs {
  margin-top: 1.5rem;
}
</style>
