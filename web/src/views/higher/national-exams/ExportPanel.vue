<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { isSecondFactorRequired } from '@/api/higher';
import { nationalExams, saveExportedFile, type ExportResult } from '@/api/higher-nationalexams';
import TwoFactorNotice from '../deliberation/TwoFactorNotice.vue';
import { useHigherAction } from '../useHigherAction';
import { useNationalI18n } from './i18n';

// Export de la liste (API-17, 2FA récente, filigrane nominatif) et convocations (numéros de candidat, centre d'examen).
const props = defineProps<{ sessionId: string; canExport: boolean; canManage: boolean }>();
const emit = defineEmits<{ (e: 'changed'): void }>();
const { tx } = useNationalI18n();
const exp = useHigherAction();
const conv = useHigherAction();

const format = ref<'csv' | 'xlsx'>('csv');
const exported = ref<ExportResult | null>(null);
const centre = ref('');
const prefix = ref('');
const convened = ref<number | null>(null);

async function run(): Promise<void> {
  const res = await exp.run(() => nationalExams.exportList(props.sessionId, format.value));
  if (!res) return;
  exported.value = res;
  saveExportedFile(res.file);
  emit('changed');
}

async function convene(): Promise<void> {
  const res = await conv.run(() => nationalExams.convene(props.sessionId, centre.value.trim(), prefix.value.trim()));
  if (!res) return;
  convened.value = res.convened;
  emit('changed');
}
</script>

<template>
  <div class="hi-section">
    <section v-if="canExport" class="card" data-test="export">
      <h2>{{ tx('hx.export') }}</h2>
      <p class="muted">{{ tx('hx.formatPending') }}</p>
      <div class="field">
        <label for="xe-format">{{ tx('hx.format') }}</label>
        <select id="xe-format" v-model="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
      </div>
      <button type="button" class="btn btn--primary" :disabled="exp.busy.value" @click="run">{{ tx('hx.export') }}</button>
      <template v-if="isSecondFactorRequired(exp.error.value)">
        <p class="notice" role="status">{{ tx('hx.needs2fa') }}</p>
        <TwoFactorNotice />
      </template>
      <ErrorNotice v-else :error="exp.error.value" />
      <div v-if="exported" class="notice notice--warning" role="status" data-test="exported">
        <p>{{ tx('hx.exported', { rows: exported.file.rows, fingerprint: exported.fingerprint.substring(0, 12) }) }}</p>
        <p data-test="watermark">{{ tx('hx.watermark', { watermark: exported.watermark }) }}</p>
        <button type="button" class="btn btn--quiet" @click="saveExportedFile(exported.file)">{{ tx('hx.download', { file: exported.file.filename }) }}</button>
      </div>
    </section>

    <form v-if="canManage" class="card hi-form" data-test="convene" @submit.prevent="convene">
      <h2>{{ tx('hx.convene') }}</h2>
      <div class="field"><label for="xv-centre">{{ tx('hx.centre.label') }}</label><input id="xv-centre" v-model="centre" type="text" required /></div>
      <div class="field"><label for="xv-prefix">{{ tx('hx.prefix') }}</label><input id="xv-prefix" v-model="prefix" type="text" /></div>
      <button type="submit" class="btn btn--primary" :disabled="conv.busy.value">{{ tx('hx.convene') }}</button>
      <ErrorNotice :error="conv.error.value" />
      <p v-if="convened !== null" class="notice" role="status" data-test="convened">{{ tx('hx.convened', { count: convened }) }}</p>
    </form>
  </div>
</template>
