<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import KpiCard from '@/components/KpiCard.vue';
import StatusTag from '@/components/StatusTag.vue';
import { http } from '@/api/http';
import type { Dashboard } from '@/api/types';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';

// Tableau de bord direction / censeur (un établissement) ou groupe (vue consolidée). Un indicateur sans source reste affiché « indisponible ».
const { t, locale } = useI18n();
const ctx = useContextStore();
const kind = ref<'direction' | 'censeur' | 'groupe'>(ctx.isGroup ? 'groupe' : 'direction');
const data = ref<Dashboard | null>(null);
const expiring = ref<Array<{ id: string; label?: string; name?: string; valid_to?: string; expires_on?: string }>>([]);
const loading = ref(true);
const error = ref<unknown>(null);

const canGroup = computed(() => ctx.can('reporting.group.read') && ctx.isGroup);
const kinds = computed<Array<'direction' | 'censeur' | 'groupe'>>(() => (ctx.isGroup ? ['groupe'] : ['direction', 'censeur']));

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    data.value = (await http.get<{ data: Dashboard }>(`/dashboards/${kind.value}`)).data;
  } catch (e) {
    error.value = e;
    data.value = null;
  } finally {
    loading.value = false;
  }
  if (kind.value === 'direction' && ctx.establishmentId) {
    try {
      expiring.value = (await http.get<{ data: typeof expiring.value }>('/authorizations/expiring', { days: 30 })).data;
    } catch {
      expiring.value = []; // l'alerte est un plus : son absence ne bloque pas le tableau de bord
    }
  }
}

watch(kind, load);
watch(() => ctx.activeId, () => {
  kind.value = ctx.isGroup ? 'groupe' : kind.value === 'groupe' ? 'direction' : kind.value;
  void load();
});
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ ctx.activeTile?.name }}</p>
      <h1>{{ t('dashboard.title') }}</h1>
    </header>

    <div v-if="kinds.length > 1" class="tabs" role="group" :aria-label="t('dashboard.kind')">
      <button v-for="k in kinds" :key="k" type="button" :aria-pressed="kind === k" @click="kind = k">{{ t(`dashboard.${k}`) }}</button>
    </div>

    <ErrorNotice :error="error" />
    <p v-if="loading" class="muted">{{ t('common.loading') }}</p>

    <div v-if="expiring.length" class="notice notice--warning" role="alert">
      <strong>{{ t('dashboard.expiring') }}</strong>
      <ul>
        <li v-for="a in expiring" :key="a.id">{{ a.label ?? a.name ?? a.id }}<template v-if="a.valid_to ?? a.expires_on"> · {{ formatDate(a.valid_to ?? a.expires_on, locale) }}</template></li>
      </ul>
    </div>

    <template v-if="data">
      <p class="muted">{{ t('dashboard.generated', { time: formatDateTime(data.generated_at, locale) }) }}</p>
      <p v-if="data.meta?.degraded_providers?.length" class="notice notice--warning">{{ t('dashboard.degraded') }}</p>

      <section v-if="data.indicators" aria-labelledby="ind-title">
        <h2 id="ind-title" class="sr-only">{{ t('dashboard.indicators') }}</h2>
        <div class="grid grid--kpi">
          <KpiCard v-for="i in data.indicators" :key="i.key" :indicator="i" />
        </div>
      </section>

      <template v-if="data.consolidated">
        <section aria-labelledby="cons-title">
          <h2 id="cons-title">{{ t('dashboard.consolidated') }}</h2>
          <div class="grid grid--kpi">
            <KpiCard v-for="i in data.consolidated" :key="i.key" :indicator="i" />
          </div>
        </section>
        <section v-if="data.establishments?.length" aria-labelledby="est-title" class="est">
          <h2 id="est-title">{{ t('dashboard.byEstablishment') }}</h2>
          <article v-for="e in data.establishments" :key="e.establishment_id" class="card">
            <h3>{{ e.name }}</h3>
            <div class="grid grid--kpi">
              <KpiCard v-for="i in e.indicators" :key="i.key" :indicator="i" />
            </div>
          </article>
        </section>
      </template>
      <p v-if="canGroup" class="muted"><StatusTag tone="ink">{{ t('dashboard.groupOnly') }}</StatusTag></p>
    </template>
  </div>
</template>

<style scoped>
.tabs {
  display: flex;
  margin-bottom: 1rem;
}
.tabs button {
  min-height: var(--tap);
  padding: 0 1rem;
  font: inherit;
  font-weight: 700;
  background: var(--surface);
  border: 1px solid var(--ink);
  cursor: pointer;
}
.tabs button[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
}
.est {
  margin-top: 1.5rem;
}
</style>
