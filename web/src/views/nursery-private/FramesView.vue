<script setup lang="ts">
// Référentiel d'observation : le référentiel « exemple » de la plateforme est à valider avec l'école ; la directrice le copie, puis l'active.
// Un référentiel actif ne se modifie plus : on en copie une nouvelle version.
import { onMounted, ref } from 'vue';
import { nursery, type Frame } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useAction } from './useNur';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();
const frames = ref<Frame[]>([]);
const detail = ref<Frame | null>(null);

async function load(): Promise<void> {
  frames.value = (await run(() => nursery.frames())) ?? [];
}
async function open(f: Frame): Promise<void> {
  detail.value = (await run(() => nursery.frame(f.id))) ?? null;
}
async function copy(f: Frame): Promise<void> {
  const copied = await run(() => nursery.copyFrame(f.id));
  if (copied) {
    await load();
    detail.value = (await run(() => nursery.frame(copied.id))) ?? null;
  }
}
async function activate(f: Frame): Promise<void> {
  if (await run(() => nursery.activateFrame(f.id))) {
    await load();
    detail.value = (await run(() => nursery.frame(f.id))) ?? null;
  }
}
const text = (fr: string, en: string): string => (locale.value === 'en' ? en : fr);
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.frames.title') }}</h1>
      <p class="lede">{{ t('nur.frames.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <ul class="list">
        <li v-for="f in frames" :key="f.id" class="row row--between">
          <span>
            <strong>{{ text(f.label_fr, f.label_en) }}</strong> <span class="muted">v{{ f.version }}</span>
            <span class="tag tag--neutral">{{ t(`nur.frames.s.${f.status}` as MessageKey) }}</span>
            <span v-if="f.to_validate" class="tag tag--warning">{{ t('nur.frames.toValidate') }}</span>
          </span>
          <span class="row">
            <button type="button" class="btn btn--quiet" @click="open(f)">{{ t('nur.frames.open') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="busy" @click="copy(f)">{{ t('nur.frames.copy') }}</button>
            <button v-if="f.scope === 'tenant' && f.status === 'draft'" type="button" class="btn btn--primary" :disabled="busy" @click="activate(f)">{{ t('nur.frames.activate') }}</button>
          </span>
        </li>
      </ul>
    </section>
    <section v-if="detail" class="card" aria-labelledby="fr-detail">
      <h2 id="fr-detail">{{ text(detail.label_fr, detail.label_en) }}</h2>
      <div v-for="d in detail.domains ?? []" :key="d.id">
        <h3>{{ text(d.label_fr, d.label_en) }}</h3>
        <ul class="plain"><li v-for="i in d.items" :key="i.id">{{ text(i.label_fr, i.label_en) }}<span v-if="!i.active" class="muted"> ({{ t('nur.frames.inactive') }})</span></li></ul>
      </div>
    </section>
  </div>
</template>

<style scoped>
.list, .plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.list > li { flex-wrap: wrap; gap: 8px; }
</style>
