<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { addLifeBookMedia, createLifeBookDraft, listLifeBooks, publishLifeBook } from '@/api/publicNursery';
import { useI18n } from '@/i18n';

interface LifeBookEntry {
  id: string;
  title: string;
  body: string | null;
  activity_on: string;
  status: string;
  media: Array<{ id: string; media_url: string; status: string; no_child_visible: number; masked_from_id: string | null }>;
}

const { t } = useI18n();
const error = ref<unknown>(null);
const entries = ref<LifeBookEntry[]>([]);
const selectedId = ref('');
const saving = ref(false);
const draft = reactive({ structure_id: '', title: '', body: '', activity_on: new Date().toISOString().slice(0, 10) });
// Par défaut un média reste en attente d'étiquetage : il ne peut pas être publié avant que les enfants visibles soient déclarés.
const media = reactive({ content: '', mime_type: 'image/jpeg' as 'image/jpeg' | 'image/png', child_ids: '', no_child_visible: false, tagging_done: false, masked_from_id: '' });
const hasSection = computed(() => draft.structure_id.trim() !== '');

async function load(): Promise<void> {
  if (!hasSection.value) {
    entries.value = [];
    selectedId.value = '';
    return;
  }
  try {
    const rows = await listLifeBooks(draft.structure_id.trim());
    entries.value = rows.data as unknown as LifeBookEntry[];
    selectedId.value = entries.value[0]?.id ?? '';
  } catch (e) {
    error.value = e;
  }
}

async function saveDraft(): Promise<void> {
  if (!hasSection.value) return;
  saving.value = true;
  error.value = null;
  try {
    await createLifeBookDraft({ structure_id: draft.structure_id.trim(), title: draft.title.trim(), body: draft.body, activity_on: draft.activity_on });
    await load();
  } catch (e) {
    error.value = e;
  } finally {
    saving.value = false;
  }
}

async function publish(id: string): Promise<void> {
  saving.value = true;
  error.value = null;
  try {
    await publishLifeBook(id);
    await load();
  } catch (e) {
    error.value = e;
  } finally {
    saving.value = false;
  }
}

async function attach(id: string): Promise<void> {
  if (!media.content.trim()) return;
  saving.value = true;
  error.value = null;
  try {
    await addLifeBookMedia(id, {
      content: media.content,
      mime_type: media.mime_type,
      child_ids: media.no_child_visible ? [] : media.child_ids.split(',').map((item) => item.trim()).filter(Boolean),
      no_child_visible: media.no_child_visible,
      tagging_done: media.tagging_done,
      ...(media.masked_from_id.trim() !== '' ? { masked_from_id: media.masked_from_id.trim() } : {}),
    });
    media.content = '';
    media.child_ids = '';
    media.masked_from_id = '';
    await load();
  } catch (e) {
    error.value = e;
  } finally {
    saving.value = false;
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page pn-screen">
    <header class="page-head">
      <p class="eyebrow">{{ t('pn.lifebook.eyebrow') }}</p>
      <h1>{{ t('pn.lifebook.title') }}</h1>
      <p class="lead">{{ t('pn.lifebook.intro') }}</p>
    </header>

    <ErrorNotice :error="error" />

    <div class="grid">
      <section class="card panel">
        <h2>{{ t('pn.lifebook.formTitle') }}</h2>
        <form class="field section-line" @submit.prevent="load">
          <div>
            <label for="lb-section">{{ t('pn.lifebook.section') }}</label>
            <input id="lb-section" v-model="draft.structure_id" type="text" placeholder="section-a" autocomplete="off" />
          </div>
          <button type="submit" class="btn" :disabled="!hasSection">{{ t('pn.lifebook.show') }}</button>
        </form>
        <p v-if="!hasSection" class="muted small">{{ t('pn.lifebook.needSection') }}</p>
        <div class="field grid2">
          <div>
            <label for="lb-title">{{ t('pn.lifebook.entryTitle') }}</label>
            <input id="lb-title" v-model="draft.title" type="text" placeholder="Atelier peinture" />
          </div>
          <div>
            <label for="lb-date">{{ t('pn.lifebook.date') }}</label>
            <input id="lb-date" v-model="draft.activity_on" type="date" />
          </div>
        </div>
        <div class="field">
          <label for="lb-body">{{ t('pn.lifebook.body') }}</label>
          <textarea id="lb-body" v-model="draft.body" rows="4" :placeholder="t('pn.lifebook.bodyPlaceholder')"></textarea>
        </div>
        <div class="row">
          <button type="button" class="btn btn--primary" :disabled="saving || !hasSection || draft.title.trim() === ''" @click="saveDraft">{{ t('pn.lifebook.saveDraft') }}</button>
          <button type="button" class="btn" :disabled="saving || selectedId === ''" @click="publish(selectedId)">{{ t('pn.lifebook.publish') }}</button>
        </div>

        <div class="field">
          <label for="lb-content">{{ t('pn.lifebook.mediaContent') }}</label>
          <textarea id="lb-content" v-model="media.content" rows="3" :placeholder="t('pn.lifebook.mediaPlaceholder')"></textarea>
        </div>
        <div class="field grid2">
          <div>
            <label for="lb-mime">{{ t('pn.lifebook.mime') }}</label>
            <select id="lb-mime" v-model="media.mime_type">
              <option value="image/jpeg">image/jpeg</option>
              <option value="image/png">image/png</option>
            </select>
          </div>
          <div>
            <label for="lb-mask">{{ t('pn.lifebook.maskedFrom') }}</label>
            <input id="lb-mask" v-model="media.masked_from_id" type="text" placeholder="media-id" autocomplete="off" />
          </div>
        </div>
        <div class="field grid2">
          <div>
            <label for="lb-childs">{{ t('pn.lifebook.children') }}</label>
            <input id="lb-childs" v-model="media.child_ids" type="text" placeholder="learner-1, learner-2" autocomplete="off" :disabled="media.no_child_visible" />
          </div>
          <div class="checkline">
            <label><input v-model="media.no_child_visible" type="checkbox" /> {{ t('pn.lifebook.noChildVisible') }}</label>
            <label><input v-model="media.tagging_done" type="checkbox" /> {{ t('pn.lifebook.taggingDone') }}</label>
          </div>
        </div>
        <button type="button" class="btn" :disabled="saving || selectedId === '' || media.content.trim() === ''" @click="attach(selectedId)">{{ t('pn.lifebook.attach') }}</button>
      </section>

      <aside class="card panel">
        <h2>{{ t('pn.lifebook.media') }}</h2>
        <p v-if="entries.length === 0" class="muted">{{ t('pn.lifebook.noEntry') }}</p>
        <article v-for="entry in entries" :key="entry.id" class="entry">
          <header>
            <strong>{{ entry.title }}</strong>
            <div class="muted small">{{ entry.activity_on }} · {{ entry.status }}</div>
          </header>
          <p>{{ entry.body }}</p>
          <ul class="media-list">
            <li v-for="m in entry.media" :key="m.id">
              <span>{{ m.id }}</span>
              <span class="muted small">{{ m.status }}<template v-if="m.masked_from_id"> · {{ t('pn.lifebook.masked') }}</template></span>
            </li>
          </ul>
        </article>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.pn-screen {
  max-width: 1200px;
}
.lead {
  max-width: 760px;
  color: var(--muted, #5f5b52);
}
.grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.grid2 {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
.section-line {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
}
.section-line > div {
  flex: 1 1 200px;
}
.small {
  font-size: 13px;
}
.checkline {
  display: flex;
  flex-direction: column;
  gap: 8px;
  justify-content: flex-end;
}
.entry {
  padding: 12px;
  border: 1px solid var(--border, #dcdcd8);
  border-radius: 12px;
  display: grid;
  gap: 10px;
}
.media-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.media-list li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.03);
}
@media (min-width: 600px) {
  .grid2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (min-width: 901px) {
  .grid {
    grid-template-columns: minmax(0, 1.05fr) minmax(320px, 0.95fr);
  }
}
</style>