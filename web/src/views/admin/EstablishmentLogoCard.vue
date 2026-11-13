<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { checkLogoFile, deleteLogo, fetchLogo, LOGO_MAX_BYTES, uploadLogo } from '@/api/admin';
import { useI18n } from '@/i18n';

// Carte « Logo » de la fiche d'un établissement : aperçu, téléversement, remplacement, suppression. Le logo est imprimé au centre
// de l'en-tête des bulletins et relevés. Contrôle de type et de taille ici, revérifié par le serveur (type lu dans le contenu).
const props = defineProps<{ id: string; canManage: boolean }>();
const emit = defineEmits<{ (e: 'changed'): void }>();
const { t } = useI18n();

const loadAction = useAction();
const saveAction = useAction();
const removeAction = useAction();

const current = ref<string | null>(null);
const picked = ref<File | null>(null);
const pickedUrl = ref<string | null>(null);
const refused = ref<'' | 'type' | 'size'>('');
const confirming = ref(false);
const done = ref<'' | 'saved' | 'removed'>('');
const input = ref<HTMLInputElement | null>(null);

const shown = computed(() => pickedUrl.value ?? current.value);
const hasLogo = computed(() => current.value !== null);
const sizeLimit = computed(() => `${Math.round(LOGO_MAX_BYTES / 1024 / 1024)} Mo`);

function makeUrl(blob: Blob): string | null {
  return typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(blob) : null;
}
function revoke(url: string | null): void {
  if (url && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
}

async function load(): Promise<void> {
  const blob = await loadAction.run(() => fetchLogo(props.id));
  revoke(current.value);
  current.value = blob ? makeUrl(blob) : null;
}

function clearPicked(): void {
  revoke(pickedUrl.value);
  pickedUrl.value = null;
  picked.value = null;
  if (input.value) input.value.value = '';
}

function onPick(event: Event): void {
  done.value = '';
  refused.value = '';
  clearPicked();
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const problem = checkLogoFile(file);
  if (problem) {
    refused.value = problem;
    if (input.value) input.value.value = '';
    return;
  }
  picked.value = file;
  pickedUrl.value = makeUrl(file);
}

async function upload(): Promise<void> {
  const file = picked.value;
  if (!file) return;
  done.value = '';
  const res = await saveAction.run(() => uploadLogo(props.id, file));
  if (!res) return;
  clearPicked();
  await load();
  done.value = 'saved';
  emit('changed');
}

async function remove(): Promise<void> {
  confirming.value = false;
  done.value = '';
  const res = await removeAction.run(() => deleteLogo(props.id));
  if (!res) return;
  revoke(current.value);
  current.value = null;
  clearPicked();
  done.value = 'removed';
  emit('changed');
}

onMounted(load);
onBeforeUnmount(() => {
  revoke(current.value);
  revoke(pickedUrl.value);
});
</script>

<template>
  <section class="card logo-card" aria-labelledby="logo-title">
    <h2 id="logo-title">{{ t('admin.logo.title') }}</h2>
    <p class="muted">{{ t('admin.logo.lede', { size: sizeLimit }) }}</p>
    <ErrorNotice :error="loadAction.error.value" />
    <ErrorNotice :error="saveAction.error.value" />
    <ErrorNotice :error="removeAction.error.value" />

    <div class="logo-card__preview" :class="{ 'logo-card__preview--empty': !shown }">
      <img v-if="shown" :src="shown" :alt="t('admin.logo.alt')" class="logo-card__img" />
      <span v-else>{{ t('admin.logo.none') }}</span>
    </div>
    <p v-if="picked" class="muted small" role="status">{{ t('admin.logo.picked', { name: picked.name }) }}</p>

    <div v-if="canManage" class="field">
      <label for="logo-file">{{ hasLogo ? t('admin.logo.chooseReplace') : t('admin.logo.choose') }}</label>
      <input id="logo-file" ref="input" type="file" accept="image/png,image/jpeg" aria-describedby="logo-hint" :aria-invalid="refused !== ''" @change="onPick" />
      <span id="logo-hint" class="hint" :class="{ error: refused !== '' }">
        {{ refused === 'type' ? t('admin.logo.badType') : refused === 'size' ? t('admin.logo.badSize', { size: sizeLimit }) : t('admin.logo.hint', { size: sizeLimit }) }}
      </span>
    </div>
    <p v-else class="muted">{{ t('admin.readOnly') }}</p>

    <div v-if="canManage" class="row">
      <button type="button" class="btn btn--primary" :disabled="!picked || saveAction.busy.value" @click="upload">
        {{ hasLogo ? t('admin.logo.replace') : t('admin.logo.upload') }}
      </button>
      <button v-if="hasLogo && !confirming" type="button" class="btn btn--quiet" :disabled="removeAction.busy.value" @click="confirming = true">{{ t('admin.logo.remove') }}</button>
      <template v-if="confirming">
        <button type="button" class="btn btn--primary" :disabled="removeAction.busy.value" @click="remove">{{ t('admin.logo.confirmRemove') }}</button>
        <button type="button" class="btn btn--quiet" @click="confirming = false">{{ t('admin.cancel') }}</button>
      </template>
      <span v-if="done === 'saved'" role="status" class="ok">{{ t('admin.logo.saved') }}</span>
      <span v-if="done === 'removed'" role="status" class="ok">{{ t('admin.logo.removed') }}</span>
    </div>
  </section>
</template>

<style scoped>
.ok {
  color: var(--success);
  font-weight: 700;
}
.logo-card__preview {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 7.5rem;
  margin-block: 0.75rem;
  padding: 0.5rem;
  border: 1px dashed var(--input-border);
  background: var(--surface);
}
.logo-card__preview--empty {
  color: var(--muted, #555);
}
.logo-card__img {
  max-width: 100%;
  max-height: 7rem;
  object-fit: contain;
}
</style>
