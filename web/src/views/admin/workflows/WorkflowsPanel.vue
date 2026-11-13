<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { workflowsApi, type Workflow, type WorkflowScope } from '@/api/workflows';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useAction } from '../useAction';
import CircuitCard from './CircuitCard.vue';

// Liste des circuits par application. Réutilisé par l'écran d'administration (établissement) et par la fiche d'un client (plateforme).
const props = defineProps<{ scope: WorkflowScope; readonly?: boolean }>();
const { t } = useI18n();
const workflows = ref<Workflow[]>([]);
const loaded = ref(false);
const message = ref('');
const load = useAction();

const groups = computed(() => {
  const map = new Map<string, Workflow[]>();
  for (const w of workflows.value) map.set(w.application, [...(map.get(w.application) ?? []), w]);
  return [...map.entries()];
});

const appLabel = (app: string): string => {
  const key = `wf.app.${app}` as MessageKey;
  const label = t(key);
  return label === key ? app : label;
};

async function refresh(): Promise<void> {
  const rows = await load.run(() => workflowsApi.list(props.scope));
  if (rows) workflows.value = rows;
  loaded.value = true;
}

function replace(updated: Workflow): void {
  workflows.value = workflows.value.map((w) => (w.id === updated.id ? updated : w));
}

onMounted(refresh);
</script>

<template>
  <section class="wf" aria-labelledby="wf-title">
    <h2 id="wf-title">{{ t('wf.title') }}</h2>
    <p class="muted">{{ scope.kind === 'client' ? t('wf.platformLede') : t('wf.lede') }}</p>
    <p v-if="message" class="notice" role="status" data-test="message">{{ message }}</p>
    <ErrorNotice :error="load.error.value" />
    <p v-if="load.busy.value" role="status">{{ t('wf.loading') }}</p>
    <p v-else-if="loaded && workflows.length === 0 && !load.error.value" class="muted">{{ t('wf.empty') }}</p>
    <section v-for="[app, items] in groups" :key="app" class="wf__app">
      <h3 class="wf__apptitle">{{ appLabel(app) }}</h3>
      <CircuitCard v-for="w in items" :key="w.id" :workflow="w" :scope="scope" :readonly="readonly" @changed="replace" @message="message = $event" />
    </section>
  </section>
</template>

<style scoped>
.wf { margin-top: 24px; }
.wf__apptitle { margin: 24px 0 12px; }
.muted { color: var(--muted, #555); }
</style>
