<script setup lang="ts">
// Besoins particuliers : signalement sans diagnostic (domaine et description courte), orientation et clôture par la directrice. Donnée sensible :
// visible de la section, de la direction et des parents seulement ; chaque consultation est journalisée par le serveur.
import { onMounted, ref, watch } from 'vue';
import { nursery, type Child, type Need } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { P } from './access';
import SectionPicker from './SectionPicker.vue';
import { useAction, usePermissions } from './useNur';

const AREAS = ['language', 'vision', 'hearing', 'motor', 'behaviour', 'other'] as const;
const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const needs = ref<Need[]>([]);
const form = ref({ learner_id: '', need_area: 'language', summary: '' });
const notes = ref<Record<string, string>>({});

async function load(): Promise<void> {
  needs.value = (await run(() => nursery.needs())) ?? [];
}
watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
});
async function report(): Promise<void> {
  if (await run(() => nursery.reportNeed({ learner_id: form.value.learner_id, need_area: form.value.need_area, summary: form.value.summary.trim() }))) {
    form.value.summary = '';
    await load();
  }
}
async function orient(n: Need): Promise<void> {
  if (await run(() => nursery.orientNeed(n.id, (notes.value[n.id] ?? '').trim()))) await load();
}
async function close(n: Need): Promise<void> {
  if (await run(() => nursery.closeNeed(n.id))) await load();
}
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.needs.title') }}</h1>
      <p class="lede">{{ t('nur.needs.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <form v-if="can(...P.needReport)" class="card" @submit.prevent="report">
      <h2>{{ t('nur.needs.report') }}</h2>
      <SectionPicker v-model="section" id="nd-section" />
      <div class="field">
        <label for="nd-child">{{ t('nur.auth.child') }}</label>
        <select id="nd-child" v-model="form.learner_id" required><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option></select>
      </div>
      <div class="field">
        <label for="nd-area">{{ t('nur.needs.area') }}</label>
        <select id="nd-area" v-model="form.need_area"><option v-for="a in AREAS" :key="a" :value="a">{{ t(`nur.needs.a.${a}` as MessageKey) }}</option></select>
      </div>
      <div class="field"><label for="nd-sum">{{ t('nur.needs.summary') }}</label><textarea id="nd-sum" v-model="form.summary" rows="3" maxlength="500" required></textarea><span class="hint">{{ t('nur.needs.noDiagnosis') }}</span></div>
      <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.needs.reportDo') }}</button>
    </form>

    <section class="card">
      <p v-if="needs.length === 0" class="muted">{{ t('nur.needs.none') }}</p>
      <ul class="list">
        <li v-for="n in needs" :key="n.id">
          <p><strong>{{ t(`nur.needs.a.${n.need_area}` as MessageKey) }}</strong> <span class="tag tag--neutral">{{ t(`nur.needs.s.${n.status}` as MessageKey) }}</span></p>
          <p>{{ n.summary }}</p>
          <p v-if="n.orientation_note" class="muted">{{ n.orientation_note }}</p>
          <div v-if="n.status !== 'closed' && can(...P.needManage)" class="row">
            <input v-model="notes[n.id]" type="text" maxlength="500" :aria-label="t('nur.needs.orientNote')" :placeholder="t('nur.needs.orientNote')" />
            <button type="button" class="btn btn--quiet" :disabled="busy || !(notes[n.id] ?? '').trim()" @click="orient(n)">{{ t('nur.needs.orient') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="busy" @click="close(n)">{{ t('nur.needs.close') }}</button>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 10px; border-top: 1px solid var(--border, #dcdcd8); }
textarea { width: 100%; }
</style>
