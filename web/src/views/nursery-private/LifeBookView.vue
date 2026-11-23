<script setup lang="ts">
// Cahier de vie de la section : publication de textes et de photos ÉTIQUETÉES (quels enfants figurent sur la photo). Une photo non
// étiquetée ne se publie pas ; le serveur ne la sert à un parent que si chaque enfant visible a un consentement photo en vigueur.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { nursery, nurseryMedia, type Child, type LifeEntry } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import SectionPicker from './SectionPicker.vue';
import { useAction } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const entries = ref<LifeEntry[]>([]);
const form = ref({ title: '', body: '' });
const photos = ref<Record<string, string>>({});
const tagging = ref<Record<string, string[]>>({});

const names = computed(() => Object.fromEntries(children.value.map((c) => [c.id, c.full_name])));
async function load(): Promise<void> {
  entries.value = section.value ? ((await run(() => nursery.lifebook(section.value))) ?? []) : [];
}
watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
  await load();
});
async function create(): Promise<void> {
  if (await run(() => nursery.createEntry({ section_id: section.value, title: form.value.title.trim(), ...(form.value.body.trim() ? { body: form.value.body.trim() } : {}) }))) {
    form.value = { title: '', body: '' };
    await load();
  }
}
function toBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] ?? '');
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}
async function upload(entry: LifeEntry, event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const content = await toBase64(file);
  if (await run(() => nursery.addMedia(entry.id, { content_base64: content, children: tagging.value[entry.id] ?? [], no_child_visible: (tagging.value[entry.id] ?? []).length === 0 && noChild.value[entry.id] === true }))) await load();
}
const noChild = ref<Record<string, boolean>>({});
function toggleTag(entryId: string, childId: string): void {
  const current = new Set(tagging.value[entryId] ?? []);
  if (current.has(childId)) current.delete(childId);
  else current.add(childId);
  tagging.value = { ...tagging.value, [entryId]: [...current] };
}
async function act(entry: LifeEntry, action: 'publish' | 'withdraw'): Promise<void> {
  if (await run(() => nursery.entryAction(entry.id, action))) await load();
}
async function show(mediaId: string): Promise<void> {
  const blob = await run(() => nurseryMedia(`/lifebook/media/${mediaId}`));
  if (blob) photos.value = { ...photos.value, [mediaId]: URL.createObjectURL(blob) };
}
onBeforeUnmount(() => Object.values(photos.value).forEach((url) => URL.revokeObjectURL(url)));
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.life.title') }}</h1>
      <p class="lede">{{ t('nur.life.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <SectionPicker v-model="section" id="lf-section" />
      <form v-if="section" @submit.prevent="create">
        <div class="field"><label for="lf-title">{{ t('nur.life.entryTitle') }}</label><input id="lf-title" v-model="form.title" type="text" maxlength="160" required /></div>
        <div class="field"><label for="lf-body">{{ t('nur.life.body') }}</label><textarea id="lf-body" v-model="form.body" rows="4" maxlength="5000"></textarea></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.life.create') }}</button>
      </form>
    </section>

    <section v-for="e in entries" :key="e.id" class="card" :aria-labelledby="`lf-${e.id}`">
      <h2 :id="`lf-${e.id}`">{{ e.title }} <span class="tag tag--neutral">{{ t(`nur.life.s.${e.status}` as MessageKey) }}</span></h2>
      <p class="muted">{{ e.activity_on }}</p>
      <p v-if="e.body" class="body">{{ e.body }}</p>
      <ul class="media">
        <li v-for="m in e.media" :key="m.id">
          <button v-if="!photos[m.id]" type="button" class="btn btn--quiet" @click="show(m.id)">{{ t('nur.life.photo') }}</button>
          <img v-else :src="photos[m.id]" :alt="t('nur.life.photoAlt', { who: m.children.map((id) => names[id] ?? id).join(', ') || t('nur.life.noChild') })" class="photo" />
          <span class="tag" :class="m.status === 'pending_tagging' ? 'tag--warning' : 'tag--neutral'">{{ t(`nur.life.m.${m.status}` as MessageKey) }}</span>
        </li>
      </ul>
      <fieldset v-if="e.status === 'draft'" class="tags">
        <legend>{{ t('nur.life.whoOnPhoto') }}</legend>
        <label v-for="c in children" :key="c.id" class="check"><input type="checkbox" :checked="(tagging[e.id] ?? []).includes(c.id)" @change="toggleTag(e.id, c.id)" /> {{ c.full_name }}</label>
        <label class="check"><input v-model="noChild[e.id]" type="checkbox" /> {{ t('nur.life.noChildVisible') }}</label>
        <div class="field"><label :for="`lf-file-${e.id}`">{{ t('nur.life.addPhoto') }}</label><input :id="`lf-file-${e.id}`" type="file" accept="image/jpeg,image/png" @change="upload(e, $event)" /></div>
      </fieldset>
      <div class="row">
        <button v-if="e.status === 'draft'" type="button" class="btn btn--primary" :disabled="busy" @click="act(e, 'publish')">{{ t('nur.life.publish') }}</button>
        <button v-if="e.status === 'published'" type="button" class="btn btn--quiet" :disabled="busy" @click="act(e, 'withdraw')">{{ t('nur.life.withdraw') }}</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.media { list-style: none; margin: 8px 0; padding: 0; display: flex; flex-wrap: wrap; gap: 12px; }
.photo { max-width: 160px; height: auto; border-radius: 8px; }
.tags { border: 1px solid var(--border, #dcdcd8); border-radius: 8px; margin: 12px 0; padding: 8px 12px; }
.check { display: flex; gap: 8px; align-items: center; min-height: 44px; }
.body { white-space: pre-wrap; }
textarea { width: 100%; }
</style>
