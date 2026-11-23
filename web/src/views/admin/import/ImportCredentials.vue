<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ProvisionalCredential } from '@/api/imports';
import { saveBlob } from '@/api/exports';
import { copyText } from '@/views/platform/clipboard';
import { useI18n } from '@/i18n';
import { credentialsCsv, credentialsSheetHtml, printHtml } from './helpers';

// Remise UNIQUE des mots de passe provisoires : affichés ici seulement, jamais stockés. Le parent les jette à la fermeture.
const props = defineProps<{ credentials: ProvisionalCredential[]; establishment: string; roleLabel: (code: string) => string }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
const box = ref<HTMLElement | null>(null);
const copied = ref<'' | 'ok' | 'failed'>('');
const acknowledged = ref(false);

const personOf = (c: ProvisionalCredential): string => `${c.last_name.toUpperCase()} ${c.first_name}`.trim();
const rows = computed(() => props.credentials.map((c) => ({ login: c.login, password: c.provisional_password, person: personOf(c), role: props.roleLabel(c.role) })));

// Bloque la fermeture accidentelle de l'onglet tant que la remise n'est pas confirmée.
function guardUnload(event: BeforeUnloadEvent): void {
  if (!acknowledged.value) {
    event.preventDefault();
    event.returnValue = '';
  }
}

onMounted(async () => {
  window.addEventListener('beforeunload', guardUnload);
  await nextTick();
  box.value?.focus();
});
onBeforeUnmount(() => window.removeEventListener('beforeunload', guardUnload));

defineExpose({ acknowledged });

async function copy(): Promise<void> {
  const text = rows.value.map((r) => `${r.person}\t${r.login}\t${r.password}`).join('\n');
  copied.value = (await copyText(text)) ? 'ok' : 'failed';
}

function download(): void {
  const csv = credentialsCsv(props.credentials, t('imports.s4.csvHeaders').split(';'));
  saveBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'identifiants_import.csv');
}

function print(): void {
  printHtml(
    credentialsSheetHtml(rows.value, {
      title: t('imports.s4.sheetTitle'),
      establishment: t('imports.s4.sheetEstablishment', { name: props.establishment }),
      once: t('imports.s4.warning'),
      steps: t('imports.s4.sheetSteps', { site: window.location.origin }),
      columns: { login: t('imports.s4.login'), password: t('imports.s4.password'), person: t('imports.s4.person'), role: t('imports.s4.role') },
    }),
  );
}
</script>

<template>
  <section ref="box" class="card cred-card" tabindex="-1" aria-labelledby="imp-cred-title" data-test="import-credentials">
    <h2 id="imp-cred-title">{{ t('imports.s4.credsTitle') }}</h2>
    <p class="notice notice--warning" role="alert" data-test="import-once">{{ t('imports.s4.warning') }}</p>
    <div class="row">
      <button type="button" class="btn btn--ink" data-test="import-copy" @click="copy">{{ t('imports.s4.copy') }}</button>
      <button type="button" class="btn" data-test="import-download" @click="download">{{ t('imports.s4.download') }}</button>
      <button type="button" class="btn" data-test="import-print" @click="print">{{ t('imports.s4.print') }}</button>
    </div>
    <p v-if="copied === 'ok'" class="hint" role="status">{{ t('imports.s4.copied') }}</p>
    <p v-else-if="copied === 'failed'" class="error" role="status">{{ t('imports.s4.copyFailed') }}</p>
    <div class="table-wrap">
      <table>
        <caption class="sr-only">{{ t('imports.s4.table') }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('imports.s4.person') }}</th>
            <th scope="col">{{ t('imports.s4.role') }}</th>
            <th scope="col">{{ t('imports.s4.login') }}</th>
            <th scope="col">{{ t('imports.s4.password') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.login + r.person">
            <td>{{ r.person }}</td>
            <td>{{ r.role }}</td>
            <td>{{ r.login }}</td>
            <td class="pw-secret" data-test="import-password">{{ r.password }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <label class="check">
      <input v-model="acknowledged" type="checkbox" data-test="import-ack" />
      <span>{{ t('imports.s4.ack') }}</span>
    </label>
    <button type="button" class="btn btn--primary" :disabled="!acknowledged" data-test="import-close" @click="emit('close')">{{ t('imports.s4.close') }}</button>
  </section>
</template>

<style scoped>
.cred-card {
  border-left: 6px solid var(--accent);
}
.cred-card:focus {
  outline: 3px solid var(--ink);
}
.pw-secret {
  font-family: Consolas, 'Courier New', monospace;
  letter-spacing: 0.08em;
  font-weight: 700;
  user-select: all;
}
.check {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: var(--tap);
  margin: 12px 0;
  font-weight: 700;
}
.check input {
  width: 22px;
  height: 22px;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
