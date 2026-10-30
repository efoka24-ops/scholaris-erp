<script setup lang="ts">
// Carnet trimestriel DOC-18 de la maternelle publique, branché sur le serveur : brouillon de l'éducatrice (points d'appui, synthèse
// positive), validation puis publication par la direction, réédition Duplicata ou Rectificatif, PDF du carnet publié seulement.
// Aucun calcul : niveaux tels qu'observés ; un terme à éviter (paramètre) bloque la validation côté serveur.
import { computed, reactive, ref } from 'vue';
import { progressBooks, type PublicProgressBook } from '@/api/publicNursery';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';

const LEVELS = ['acquired', 'in_progress', 'not_yet'] as const;
const { t } = useI18n();
const error = ref<unknown>(null);
const busy = ref(false);
const book = ref<PublicProgressBook | null>(null);
const draft = reactive({ learner_id: '', structure_id: '', period_id: 'T1', school_year: '', synthesis: '' });
const points = ref<{ domain: string; label: string; level: string; comment: string }[]>([]);
const point = reactive({ domain: '', label: '', level: 'in_progress' });
const ready = computed(() => draft.learner_id.trim() !== '' && draft.structure_id.trim() !== '' && draft.school_year.trim() !== '');

async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;
  try {
    return await action();
  } catch (e) {
    error.value = e;
    return undefined;
  } finally {
    busy.value = false;
  }
}
function addPoint(): void {
  if (!point.label.trim()) return;
  points.value.push({ domain: point.domain.trim(), label: point.label.trim(), level: point.level, comment: '' });
  point.label = '';
}
function removePoint(index: number): void {
  points.value.splice(index, 1);
}
async function save(): Promise<void> {
  book.value = (await run(() => progressBooks.saveDraft({ ...draft, synthesis: draft.synthesis.trim(), items: points.value }))) ?? book.value;
}
async function act(action: 'validate' | 'publish'): Promise<void> {
  const id = book.value?.id;
  if (id) book.value = (await run(() => progressBooks[action](id))) ?? book.value;
}
async function reissue(label: 'duplicate' | 'rectified'): Promise<void> {
  const id = book.value?.id;
  if (id) book.value = (await run(() => progressBooks.reissue(id, label))) ?? book.value;
}
async function pdf(): Promise<void> {
  const id = book.value?.id;
  if (!id) return;
  const blob = await run(() => progressBooks.pdf(id));
  if (blob) window.open(URL.createObjectURL(blob), '_blank', 'noopener');
}
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.progressBook.eyebrow') }}</p>
      <h1>{{ t('pn.progressBook.title') }}</h1>
      <p class="lead">{{ t('pn.progressBook.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div class="grid">
      <form class="card panel" @submit.prevent="save">
        <h2>{{ t('pn.progressBook.formTitle') }}</h2>
        <div class="grid2">
          <div class="field"><label for="pb-learner">{{ t('nursery.child') }}</label><input id="pb-learner" v-model="draft.learner_id" type="text" maxlength="40" required /></div>
          <div class="field"><label for="pb-section">{{ t('pn.progressBook.section') }}</label><input id="pb-section" v-model="draft.structure_id" type="text" maxlength="40" required /></div>
          <div class="field">
            <label for="pb-period">{{ t('pn.progressBook.period') }}</label>
            <select id="pb-period" v-model="draft.period_id"><option value="T1">T1</option><option value="T2">T2</option><option value="T3">T3</option></select>
          </div>
          <div class="field"><label for="pb-year">{{ t('pn.progressBook.year') }}</label><input id="pb-year" v-model="draft.school_year" type="text" maxlength="9" placeholder="2026-2027" required /></div>
        </div>
        <fieldset class="grid2">
          <legend>{{ t('pn.progressBook.items') }}</legend>
          <div class="field"><label for="pb-domain">{{ t('pn.progressBook.domain') }}</label><input id="pb-domain" v-model="point.domain" type="text" maxlength="80" /></div>
          <div class="field"><label for="pb-point">{{ t('pn.progressBook.item') }}</label><input id="pb-point" v-model="point.label" type="text" maxlength="200" @keydown.enter.prevent="addPoint" /></div>
          <div class="field">
            <label for="pb-level">{{ t('pn.progressBook.level') }}</label>
            <select id="pb-level" v-model="point.level"><option v-for="l in LEVELS" :key="l" :value="l">{{ t(`pn.progressBook.l.${l}` as MessageKey) }}</option></select>
          </div>
          <div><button type="button" class="btn" :aria-label="t('pn.progressBook.addPoint')" @click="addPoint">+</button></div>
        </fieldset>
        <ul class="points">
          <li v-for="(p, index) in points" :key="p.label + index">
            <span>{{ p.domain }} · {{ p.label }} : {{ t(`pn.progressBook.l.${p.level}` as MessageKey) }}</span>
            <button type="button" class="btn btn--quiet" :aria-label="`${t('pn.progressBook.removePoint')} : ${p.label}`" @click="removePoint(index)">×</button>
          </li>
        </ul>
        <div class="field"><label for="pb-summary">{{ t('pn.progressBook.summary') }}</label><textarea id="pb-summary" v-model="draft.synthesis" rows="4" maxlength="3000"></textarea></div>
        <button type="submit" class="btn btn--primary" :disabled="busy || !ready">{{ t('pn.progressBook.saveDraft') }}</button>
      </form>

      <aside class="card panel" aria-live="polite">
        <h2>{{ t('pn.progressBook.preview') }}</h2>
        <p v-if="!book" class="muted">{{ t('pn.progressBook.empty') }}</p>
        <template v-else>
          <p><span class="tag tag--neutral">{{ t(`pn.progressBook.s.${book.status}` as MessageKey) }}</span><span v-if="book.edition_label"> · {{ t(`pn.progressBook.e.${book.edition_label}` as MessageKey) }} ({{ book.edition }})</span></p>
          <p>{{ book.synthesis }}</p>
          <ul class="points"><li v-for="(p, i) in book.snapshot" :key="i">{{ p.domain }} · {{ p.label }} : {{ t(`pn.progressBook.l.${p.level}` as MessageKey) }}</li></ul>
          <div class="row">
            <button v-if="book.status === 'draft' || book.status === 'submitted'" type="button" class="btn btn--primary" :disabled="busy" @click="act('validate')">{{ t('pn.progressBook.validate') }}</button>
            <button v-if="book.status === 'validated'" type="button" class="btn btn--primary" :disabled="busy" @click="act('publish')">{{ t('pn.progressBook.publish') }}</button>
            <button v-if="book.status === 'published'" type="button" class="btn" :disabled="busy" @click="pdf">{{ t('pn.progressBook.pdf') }}</button>
            <button v-if="book.status === 'published'" type="button" class="btn btn--quiet" :disabled="busy" @click="reissue('duplicate')">{{ t('pn.progressBook.e.duplicate') }}</button>
            <button v-if="book.status === 'published'" type="button" class="btn btn--quiet" :disabled="busy" @click="reissue('rectified')">{{ t('pn.progressBook.e.rectified') }}</button>
          </div>
        </template>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.pn-screen { max-width: 1120px; }
.lead { max-width: 760px; color: var(--muted, #5f5b52); }
.grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
.panel { display: flex; flex-direction: column; gap: 12px; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 12px; border: 0; padding: 0; }
.points { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.points li { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--border, #dcdcd8); border-radius: 12px; }
.row { display: flex; flex-wrap: wrap; gap: 8px; }
button { min-height: 44px; }
@media (min-width: 900px) {
  .grid { grid-template-columns: 1.2fr 1fr; }
  .grid2 { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
}
</style>
