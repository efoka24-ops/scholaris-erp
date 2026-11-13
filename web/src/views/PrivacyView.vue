<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Consent, DataRequest, PrivacySettings } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// Paramètres de confidentialité : consentements de la personne ; pour l'équipe, demandes des personnes concernées et délais.
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const PURPOSES = ['communication', 'photo', 'parent_access'] as const;
const consents = ref<Consent[]>([]);
const requests = ref<DataRequest[]>([]);
const settings = ref<PrivacySettings | null>(null);
const error = ref<unknown>(null);
const loading = ref(false);
const staff = computed(() => ctx.can('privacy.requests.read'));
const personId = computed(() => auth.me?.person_id ?? null);

const active = (purpose: string): Consent | undefined => consents.value.find((c) => c.purpose === purpose && !c.revoked_at);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    if (personId.value) consents.value = await http.all<Consent>('/consents', { 'filter[person_id]': personId.value }, {}, 2);
    if (staff.value) {
      requests.value = await http.all<DataRequest>('/data-requests', {}, {}, 3);
      settings.value = (await http.get<{ data: PrivacySettings }>('/privacy/settings')).data;
    }
  } catch (e) {
    error.value = e;
  } finally {
    loading.value = false;
  }
}

async function toggle(purpose: string): Promise<void> {
  if (!personId.value) return;
  error.value = null;
  try {
    const current = active(purpose);
    if (current) await http.post(`/consents/${current.id}/revoke`);
    else await http.post('/consents', { person_id: personId.value, purpose });
    await load();
  } catch (e) {
    error.value = e;
  }
}

onMounted(load);
</script>

<template>
  <div class="page narrow">
    <header class="page-head">
      <p class="eyebrow">{{ t('privacy.eyebrow') }}</p>
      <h1>{{ t('privacy.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

    <section v-if="personId" class="card" aria-labelledby="cons-title">
      <h2 id="cons-title">{{ t('privacy.consents') }}</h2>
      <p class="muted">{{ t('privacy.consentsIntro') }}</p>
      <ul class="plain">
        <li v-for="p in PURPOSES" :key="p" class="row row--between">
          <div>
            <strong>{{ t(`privacy.purpose.${p}`) }}</strong>
            <div class="muted">{{ t(`privacy.purposeHelp.${p}`) }}</div>
          </div>
          <button type="button" class="btn" :aria-pressed="!!active(p)" @click="toggle(p)">
            {{ active(p) ? t('privacy.revoke') : t('privacy.grant') }}
          </button>
        </li>
      </ul>
    </section>

    <template v-if="staff">
      <section v-if="settings" class="card" aria-labelledby="set-title">
        <h2 id="set-title">{{ t('privacy.settings') }}</h2>
        <dl class="facts">
          <div><dt class="eyebrow">{{ t('privacy.deadline') }}</dt><dd>{{ t('privacy.days', { count: settings.deadline_days ?? 0 }) }}</dd></div>
          <div><dt class="eyebrow">{{ t('privacy.retention') }}</dt><dd>{{ t('privacy.months', { count: settings.requests_retention_months ?? 0 }) }}</dd></div>
        </dl>
      </section>
      <section class="card" aria-labelledby="req-title">
        <h2 id="req-title">{{ t('privacy.requests') }}</h2>
        <p v-if="!loading && requests.length === 0" class="muted">{{ t('privacy.noRequests') }}</p>
        <ul class="plain">
          <li v-for="r in requests" :key="r.id" class="row row--between">
            <span>{{ r.kind }}<small v-if="r.due_at" class="muted"> · {{ formatDateTime(r.due_at, locale) }}</small></span>
            <StatusTag :tone="r.overdue ? 'warning' : 'neutral'">{{ r.overdue ? t('privacy.overdue') : r.status }}</StatusTag>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.narrow {
  max-width: 820px;
}
.plain {
  list-style: none;
  padding: 0;
  margin: 0;
}
.plain li {
  padding: 0.6rem 0;
  border-bottom: 1px solid var(--border);
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.75rem;
  margin: 0;
}
.facts dd {
  margin: 0;
  font-weight: 700;
  font-size: 20px;
}
</style>
