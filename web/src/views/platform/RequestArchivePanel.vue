<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { actions, type AccessRequest } from '@/api/platform';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useSensitiveAction } from './useSensitiveAction';
import TwoFactorPrompt from './TwoFactorPrompt.vue';

// Demande refusée ou de test : archiver (motif, retirée des files), puis supprimer définitivement si aucun client n'a été créé.
const props = defineProps<{ request: AccessRequest }>();
const emit = defineEmits<{ changed: [request: AccessRequest] }>();
const { t, locale } = useI18n();
const router = useRouter();
const action = useSensitiveAction();
const archiving = ref(false);
const reason = ref('');
const error = ref<string | null>(null);

const canArchive = computed(() => !props.request.archived_at && props.request.status !== 'accepted');
const canDelete = computed(() => !!props.request.archived_at && !props.request.tenant_id);

async function archive(): Promise<void> {
  const text = reason.value.trim();
  error.value = text === '' ? t('plat.cli.reasonRequired') : null;
  if (error.value) return;
  const res = await action.run<AccessRequest>(actions.archiveRequest(props.request.id, text), `${t('pa.req.archive')} · ${props.request.reference}`);
  if (!res) return;
  archiving.value = false;
  emit('changed', res);
}

async function remove(): Promise<void> {
  if (!window.confirm(t('pa.req.confirmDelete', { name: props.request.institution_name }))) return;
  const res = await action.run<{ deleted: boolean }>(actions.deleteRequest(props.request.id), `${t('pa.req.delete')} · ${props.request.reference}`);
  if (!res) return;
  await router.replace({ name: 'platform-requests', query: { statut: 'all' } });
}
</script>

<template>
  <section v-if="canArchive || canDelete || request.archived_at" class="card block" aria-labelledby="ra-title">
    <h2 id="ra-title">{{ t('pa.req.archiveTitle') }}</h2>
    <TwoFactorPrompt :pending="action.needsTwoFactor.value" @revalidate="action.revalidate" @dismiss="action.dismiss" />
    <ErrorNotice :error="action.error.value" />
    <p v-if="request.archived_at" class="muted">{{ t('pa.req.archivedOn', { date: formatDate(request.archived_at, locale), reason: request.archive_reason ?? '' }) }}</p>
    <p v-else class="muted">{{ t('pa.req.archiveHint') }}</p>
    <div class="row">
      <button v-if="canArchive" type="button" class="btn" :aria-expanded="archiving" @click="archiving = !archiving">{{ t('pa.req.archive') }}</button>
      <button v-if="canDelete" type="button" class="btn" :disabled="action.busy.value" @click="remove">{{ t('pa.req.delete') }}</button>
    </div>
    <form v-if="archiving" class="panel" novalidate @submit.prevent="archive">
      <div class="field">
        <label for="ra-reason">{{ t('plat.cli.reason') }}</label>
        <textarea id="ra-reason" v-model="reason" rows="2" maxlength="500" :aria-invalid="!!error" />
        <span v-if="error" class="error-line">{{ error }}</span>
      </div>
      <button type="submit" class="btn btn--primary" :disabled="action.busy.value">{{ t('pa.req.archive') }}</button>
    </form>
  </section>
</template>

<style scoped>
.block {
  margin-top: 20px;
}
.panel {
  margin-top: 12px;
  max-width: 560px;
}
.error-line {
  display: block;
  color: var(--warning);
  font-weight: 700;
  font-size: 13px;
}
</style>
