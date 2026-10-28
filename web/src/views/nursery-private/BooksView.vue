<script setup lang="ts">
// Carnet de suivi trimestriel : rédaction par l'éducatrice (texte, sans aucune note), soumission, validation et publication par la directrice.
// Le parent ne voit un carnet qu'une fois publié. Le contenu est rendu en texte (jamais de HTML).
import { computed, ref, watch } from 'vue';
import { nursery, type Child, type IssuedDocument, type ProgressBook } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { useAction, usePermissions } from './useNur';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const books = ref<ProgressBook[]>([]);
const childId = ref('');
const editing = ref<ProgressBook | null>(null);
const synthesis = ref('');
const issued = ref<IssuedDocument | null>(null);

const names = computed(() => Object.fromEntries(children.value.map((c) => [c.id, c.full_name])));
async function load(): Promise<void> {
  books.value = section.value ? ((await run(() => nursery.books({ section_id: section.value }))) ?? []) : [];
}
watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
  childId.value = '';
  editing.value = null;
  await load();
});
async function create(): Promise<void> {
  const book = await run(() => nursery.createBook(childId.value));
  if (book) {
    await load();
    open(book);
  }
}
function open(book: ProgressBook): void {
  editing.value = book;
  synthesis.value = book.synthesis;
}
async function save(): Promise<void> {
  if (!editing.value) return;
  const saved = await run(() => nursery.writeBook(editing.value!.id, { synthesis: synthesis.value }));
  if (saved) editing.value = saved;
}
/** Carnet publié : document du socle (numéro, QR, contenu figé) ; une seconde demande rend le même document. */
async function issue(book: ProgressBook, locale: 'fr' | 'en'): Promise<void> {
  issued.value = (await run(() => nursery.bookDocument(book.id, locale))) ?? null;
}
async function act(book: ProgressBook, action: 'submit' | 'validate' | 'return' | 'publish'): Promise<void> {
  if (action === 'submit' && editing.value?.id === book.id) await save();
  const next = await run(() => nursery.bookAction(book.id, action));
  if (next) {
    editing.value = editing.value?.id === book.id ? next : editing.value;
    await load();
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.books.title') }}</h1>
      <p class="lede">{{ t('nur.books.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="issued" class="notice" role="status" aria-live="polite">{{ t('nur.doc.issued', { number: issued.number }) }} <span v-if="issued.already_issued">{{ t('nur.doc.already') }}</span></p>
    <section class="card">
      <SectionPicker v-model="section" id="bk-section" />
      <div v-if="can(...P.observe) && section" class="row">
        <div class="field grow">
          <label for="bk-child">{{ t('nur.auth.child') }}</label>
          <select id="bk-child" v-model="childId"><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option></select>
        </div>
        <button type="button" class="btn btn--primary" :disabled="busy || !childId" @click="create">{{ t('nur.books.create') }}</button>
      </div>
      <p v-if="section && books.length === 0" class="muted">{{ t('nur.books.none') }}</p>
      <ul class="list">
        <li v-for="b in books" :key="b.id" class="row row--between">
          <span><strong>{{ names[b.learner_id] ?? b.learner_id }}</strong> <span class="tag tag--neutral">{{ t(`nur.books.s.${b.status}` as MessageKey) }}</span></span>
          <span class="row">
            <button type="button" class="btn btn--quiet" @click="open(b)">{{ t('nur.books.open') }}</button>
            <button v-if="b.status === 'draft' && can(...P.observe)" type="button" class="btn btn--quiet" :disabled="busy" @click="act(b, 'submit')">{{ t('nur.books.submit') }}</button>
            <button v-if="b.status === 'submitted' && can(...P.devValidate)" type="button" class="btn btn--primary" :disabled="busy" @click="act(b, 'validate')">{{ t('nur.books.validate') }}</button>
            <button v-if="(b.status === 'submitted' || b.status === 'validated') && can(...P.devValidate)" type="button" class="btn btn--quiet" :disabled="busy" @click="act(b, 'return')">{{ t('nur.books.return') }}</button>
            <button v-if="b.status === 'validated' && can(...P.devValidate)" type="button" class="btn btn--primary" :disabled="busy" @click="act(b, 'publish')">{{ t('nur.books.publish') }}</button>
            <button v-if="b.status === 'published' && can(...P.devValidate)" type="button" class="btn btn--quiet" :disabled="busy" @click="issue(b, 'fr')">{{ t('nur.doc.issueFr') }}</button>
            <button v-if="b.status === 'published' && can(...P.devValidate)" type="button" class="btn btn--quiet" :disabled="busy" @click="issue(b, 'en')">{{ t('nur.doc.issueEn') }}</button>
          </span>
        </li>
      </ul>
    </section>

    <section v-if="editing" class="card" aria-labelledby="bk-edit">
      <h2 id="bk-edit">{{ names[editing.learner_id] ?? editing.learner_id }}</h2>
      <h3>{{ t('nur.books.observed') }}</h3>
      <p v-if="editing.observations.length === 0" class="muted">{{ t('nur.books.noObs') }}</p>
      <ul class="plain">
        <li v-for="o in editing.observations" :key="o.item_id">{{ o.observed_on }} · {{ o.level_code }}<span v-if="o.remark"> · {{ o.remark }}</span></li>
      </ul>
      <div class="field">
        <label for="bk-synth">{{ t('nur.books.synthesis') }}</label>
        <textarea id="bk-synth" v-model="synthesis" rows="6" maxlength="3000" :readonly="editing.status !== 'draft'"></textarea>
        <span class="hint">{{ t('nur.books.hint') }}</span>
      </div>
      <button v-if="editing.status === 'draft'" type="button" class="btn btn--primary" :disabled="busy" @click="save">{{ t('nur.books.save') }}</button>
    </section>
  </div>
</template>

<style scoped>
.list, .plain { list-style: none; margin: 12px 0; padding: 0; display: grid; gap: 10px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); flex-wrap: wrap; gap: 8px; }
.grow { flex: 1; }
textarea { width: 100%; }
</style>
