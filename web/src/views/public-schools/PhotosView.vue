<script setup lang="ts">
// Photos géolocalisées de l'école (visites d'inspection, EX-ECO-04). Contrôle du type (JPEG ou PNG, signature du fichier comprise) et
// de la taille avant l'envoi ; l'aperçu se lit par le lien signé, avec la session (pas d'adresse ouverte à tous).
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type SchoolPhoto } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { P } from './access';
import { checkPhoto } from './photoCheck';
import { useAction, usePermissions } from './usePs';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const canUpload = computed(() => can(...P.inspectionWrite));

const photos = ref<SchoolPhoto[]>([]);
const previews = reactive<Record<string, string>>({});
const unreadable = reactive<Record<string, boolean>>({});
const fileInput = ref<HTMLInputElement | null>(null);
const file = ref<File | null>(null);
const localPreview = ref('');
const fileProblem = ref('');
const saved = ref(false);
const form = reactive({ latitude: '', longitude: '', takenAt: nowLocal(), visitId: '' });

function nowLocal(): string {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}

/** Choix d'un fichier : type, signature et taille contrôlés avant tout aperçu ou envoi. */
async function pick(event: Event): Promise<void> {
  saved.value = false;
  fileProblem.value = '';
  if (localPreview.value) URL.revokeObjectURL(localPreview.value);
  localPreview.value = '';
  file.value = null;
  const chosen = (event.target as HTMLInputElement).files?.[0];
  if (!chosen) return;
  const check = await checkPhoto(chosen);
  if (!check.ok) {
    fileProblem.value = t(check.problem === 'size' ? 'ps.photos.errSize' : check.problem === 'empty' ? 'ps.photos.errEmpty' : 'ps.photos.errType');
    if (fileInput.value) fileInput.value.value = '';
    return;
  }
  file.value = chosen;
  localPreview.value = URL.createObjectURL(chosen);
}

const lat = computed(() => (form.latitude.trim() === '' ? NaN : Number(form.latitude.replace(',', '.'))));
const lon = computed(() => (form.longitude.trim() === '' ? NaN : Number(form.longitude.replace(',', '.'))));
const coordsProblem = computed(() => {
  if (form.latitude.trim() === '' && form.longitude.trim() === '') return '';
  if (!(lat.value >= -90 && lat.value <= 90) || !(lon.value >= -180 && lon.value <= 180)) return t('ps.photos.errCoords');
  return '';
});
const visitProblem = computed(() => (form.visitId.trim() === '' || /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(form.visitId.trim()) ? '' : t('ps.photos.errVisit')));
const canSend = computed(() => file.value !== null && Number.isFinite(lat.value) && Number.isFinite(lon.value) && coordsProblem.value === '' && visitProblem.value === '' && form.takenAt !== '');

function locate(): void {
  if (!('geolocation' in navigator)) return;
  navigator.geolocation.getCurrentPosition((pos) => {
    form.latitude = pos.coords.latitude.toFixed(6);
    form.longitude = pos.coords.longitude.toFixed(6);
  });
}

function toBase64(f: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error ?? new Error('read'));
    reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
    reader.readAsDataURL(f);
  });
}

async function loadPreview(p: SchoolPhoto): Promise<void> {
  try {
    previews[p.id] = URL.createObjectURL(await publicSchools.photoContent(p.url));
  } catch {
    unreadable[p.id] = true;
  }
}

async function load(): Promise<void> {
  const rows = await run(() => publicSchools.photos());
  if (!rows) return;
  photos.value = rows;
  await Promise.all(rows.filter((p) => previews[p.id] === undefined).map(loadPreview));
}

async function send(): Promise<void> {
  const f = file.value;
  if (!f || !canSend.value) return;
  saved.value = false;
  const created = await run(async () =>
    publicSchools.addPhoto({
      content_base64: await toBase64(f),
      latitude: lat.value,
      longitude: lon.value,
      taken_at: new Date(form.takenAt).toISOString(),
      ...(form.visitId.trim() !== '' ? { visit_id: form.visitId.trim().toUpperCase() } : {}),
    }),
  );
  if (created) {
    saved.value = true;
    file.value = null;
    if (localPreview.value) URL.revokeObjectURL(localPreview.value);
    localPreview.value = '';
    if (fileInput.value) fileInput.value.value = '';
    form.visitId = '';
    await load();
  }
}

onMounted(() => void load());
onBeforeUnmount(() => {
  for (const url of Object.values(previews)) URL.revokeObjectURL(url);
  if (localPreview.value) URL.revokeObjectURL(localPreview.value);
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.photos.title') }}</h1>
      <p class="lede">{{ t('ps.photos.intro') }}</p>
      <p><router-link :to="{ name: 'ps-school' }">{{ t('ps.rooms.back') }}</router-link></p>
    </header>
    <ErrorNotice :error="error" />

    <form v-if="canUpload" class="card form" @submit.prevent="send">
      <h2>{{ t('ps.photos.addTitle') }}</h2>
      <div class="field">
        <label for="ph-file">{{ t('ps.photos.file') }}</label>
        <input id="ph-file" ref="fileInput" type="file" accept="image/jpeg,image/png" aria-describedby="ph-file-hint" @change="pick" />
        <span id="ph-file-hint" class="hint">{{ t('ps.photos.fileHint') }}</span>
      </div>
      <p v-if="fileProblem" class="notice notice--error" role="alert">{{ fileProblem }}</p>
      <img v-if="localPreview" class="preview" :src="localPreview" :alt="t('ps.photos.previewAlt')" />
      <div class="field">
        <label for="ph-lat">{{ t('ps.photos.latitude') }}</label>
        <input id="ph-lat" v-model="form.latitude" type="text" inputmode="decimal" autocomplete="off" />
      </div>
      <div class="field">
        <label for="ph-lon">{{ t('ps.photos.longitude') }}</label>
        <input id="ph-lon" v-model="form.longitude" type="text" inputmode="decimal" autocomplete="off" />
      </div>
      <button type="button" class="btn btn--quiet" @click="locate">{{ t('ps.photos.locate') }}</button>
      <p v-if="coordsProblem" class="notice notice--error" role="alert">{{ coordsProblem }}</p>
      <div class="field">
        <label for="ph-at">{{ t('ps.photos.takenAt') }}</label>
        <input id="ph-at" v-model="form.takenAt" type="datetime-local" required />
      </div>
      <div class="field">
        <label for="ph-visit">{{ t('ps.photos.visit') }}</label>
        <input id="ph-visit" v-model="form.visitId" type="text" maxlength="26" autocomplete="off" />
      </div>
      <p v-if="visitProblem" class="notice notice--error" role="alert">{{ visitProblem }}</p>
      <button type="submit" class="btn btn--primary" :disabled="busy || !canSend">{{ t('ps.photos.send') }}</button>
      <p v-if="saved" class="notice notice--success" role="status">{{ t('ps.photos.saved') }}</p>
    </form>

    <section class="card" aria-labelledby="ph-list">
      <h2 id="ph-list">{{ t('ps.photos.listTitle') }}</h2>
      <p v-if="photos.length === 0" class="muted">{{ t('ps.photos.none') }}</p>
      <ul class="gallery">
        <li v-for="p in photos" :key="p.id">
          <figure>
            <img v-if="previews[p.id]" class="thumb" :src="previews[p.id]" :alt="t('ps.photos.alt', { date: formatDateTime(p.taken_at) })" />
            <div v-else class="thumb thumb--empty">{{ unreadable[p.id] ? t('ps.photos.unreadable') : t('common.loading') }}</div>
            <figcaption>
              {{ formatDateTime(p.taken_at) }}<br />
              <span class="muted">{{ p.latitude }}, {{ p.longitude }}</span>
            </figcaption>
          </figure>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.form { display: grid; gap: 10px; max-width: 32rem; }
.preview { max-width: 100%; max-height: 16rem; object-fit: contain; border: 1px solid var(--border, #dcdcd8); }
.gallery { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 12px; }
.gallery figure { margin: 0; }
.thumb { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; border: 1px solid var(--border, #dcdcd8); }
.thumb--empty { display: grid; place-items: center; background: var(--surface-2, #f3f3f0); font-size: 13px; }
</style>
