<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';
import { downloadExport, ExportFormatUnavailable, type ExportFormat } from '@/api/exports';
import type { QueryValue } from '@/api/http';
import { useI18n } from '@/i18n';

// Boutons « Exporter en CSV / Excel » d'une liste : télécharge …/export?format=… avec les filtres courants.
// Si le serveur refuse le format Excel (400, 422, 501), on le dit et on ne propose plus que le CSV.
const props = withDefaults(defineProps<{ path: string; query?: Record<string, QueryValue>; name?: string; caption?: string }>(), { query: () => ({}), name: 'export' });
const { t } = useI18n();

const busy = ref<ExportFormat | null>(null);
const xlsxUnavailable = ref(false);
const error = ref<unknown>(null);

async function run(format: ExportFormat): Promise<void> {
  busy.value = format;
  error.value = null;
  try {
    await downloadExport(props.path, props.query, format, props.name);
  } catch (e) {
    if (e instanceof ExportFormatUnavailable) xlsxUnavailable.value = true;
    else error.value = e;
  } finally {
    busy.value = null;
  }
}
</script>

<template>
  <div class="export" role="group" :aria-label="t('export.label')">
    <span v-if="caption" class="export__caption">{{ caption }}</span>
    <button type="button" class="btn btn--quiet" data-export="csv" :disabled="busy !== null" @click="run('csv')">
      {{ busy === 'csv' ? t('export.busy') : t('export.csv') }}
    </button>
    <button v-if="!xlsxUnavailable" type="button" class="btn btn--quiet" data-export="xlsx" :disabled="busy !== null" @click="run('xlsx')">
      {{ busy === 'xlsx' ? t('export.busy') : t('export.xlsx') }}
    </button>
    <p v-else class="muted export__note" role="status">{{ t('export.xlsxUnavailable') }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>

<style scoped>
.export {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.export__caption {
  font-weight: 700;
  font-size: 14px;
}
.export__note {
  margin: 0;
  font-size: 13px;
}
</style>
