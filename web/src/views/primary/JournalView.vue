<script setup lang="ts">
// Cahier de liaison de la classe : leçons, devoirs et mots aux familles ; une entrée publiée prévient les parents de la classe.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { listStructures } from '@/api/admin';
import { primaryRaw } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { P } from './access';
import { useAction, usePermissions } from './usePri';

interface Entry { id: string; entry_date: string; entry_kind: string; title: string; body: string; published_at: string | null; seen_count: number }

const { t, locale } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const classes = ref<{ id: string; name: string }[]>([]);
const classId = ref('');
const entries = ref<Entry[]>([]);
const form = reactive({ kind: 'liaison', title: '', body: '', date: todayIso() });
const canWrite = computed(() => can(...P.journalWrite));

async function load(): Promise<void> {
  if (classId.value === '') return;
  const data = await run(() => primaryRaw.get<{ items: Entry[] }>(`/classes/${classId.value}/journal`));
  entries.value = data?.items ?? [];
}
async function add(): Promise<void> {
  const done = await run(() => primaryRaw.post(`/classes/${classId.value}/journal`, { entry_kind: form.kind, title: form.title.trim(), body: form.body.trim(), entry_date: form.date }));
  if (done) {
    Object.assign(form, { title: '', body: '' });
    await load();
  }
}
async function publish(e: Entry): Promise<void> {
  if (await run(() => primaryRaw.post(`/journal/${e.id}/publish`))) await load();
}

watch(classId, () => void load());
onMounted(async () => {
  await run(async () => {
    classes.value = (await listStructures()).filter((s) => s.kind === 'class').map((s) => ({ id: s.id, name: s.name }));
    classId.value = classes.value[0]?.id ?? '';
  });
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.journal.title') }}</h1>
      <p class="lede">{{ t('pri.journal.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <div class="field">
        <label for="jr-class">{{ t('pri.call.class') }}</label>
        <select id="jr-class" v-model="classId"><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </div>
    </section>
    <section v-if="canWrite" class="card" aria-labelledby="jr-new">
      <h2 id="jr-new">{{ t('pri.journal.new') }}</h2>
      <form @submit.prevent="add">
        <div class="field">
          <label for="jr-kind">{{ t('pri.journal.kind') }}</label>
          <select id="jr-kind" v-model="form.kind">
            <option v-for="k in ['liaison', 'lesson', 'homework']" :key="k" :value="k">{{ t(`pri.journal.kind.${k}` as MessageKey) }}</option>
          </select>
        </div>
        <div class="field"><label for="jr-date">{{ t('pri.journal.date') }}</label><input id="jr-date" v-model="form.date" type="date" required /></div>
        <div class="field"><label for="jr-title">{{ t('pri.journal.titleLabel') }}</label><input id="jr-title" v-model="form.title" type="text" maxlength="160" required /></div>
        <div class="field"><label for="jr-body">{{ t('pri.journal.body') }}</label><textarea id="jr-body" v-model="form.body" rows="4" maxlength="5000" required></textarea></div>
        <button type="submit" class="btn btn--primary" :disabled="busy || classId === ''">{{ t('pri.journal.add') }}</button>
      </form>
    </section>
    <section class="card">
      <p v-if="entries.length === 0" class="muted">{{ t('pri.journal.none') }}</p>
      <ul class="list">
        <li v-for="e in entries" :key="e.id">
          <div class="row row--between">
            <div>
              <strong>{{ e.title }}</strong> <span class="tag tag--neutral">{{ t(`pri.journal.kind.${e.entry_kind}` as MessageKey) }}</span>
              <div class="muted">{{ formatDate(e.entry_date, locale) }}<span v-if="e.published_at"> · {{ t('pri.journal.seen', { count: e.seen_count }) }}</span></div>
            </div>
            <button v-if="canWrite && !e.published_at" type="button" class="btn" :disabled="busy" @click="publish(e)">{{ t('pri.journal.publish') }}</button>
            <span v-else-if="e.published_at" class="tag tag--success">{{ t('pri.journal.published') }}</span>
          </div>
          <p>{{ e.body }}</p>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
</style>
