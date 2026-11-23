<script setup lang="ts">
// Observation sans note : enfant, item du référentiel actif, niveau (les niveaux viennent de /settings) en trois gestes. Aucun total,
// aucune moyenne, aucun rang. Suivi de fréquence par domaine : écarts à combler, sans comparer les enfants entre eux.
import { computed, onMounted, ref, watch } from 'vue';
import { nursery, type Child, type Frame, type Gap } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import SectionPicker from './SectionPicker.vue';
import { newUuid, useAction } from './useNur';

const { t, locale } = useI18n();
const { error, busy, run } = useAction();
const section = ref('');
const children = ref<Child[]>([]);
const childId = ref('');
const frame = ref<Frame | null>(null);
const levels = ref<string[]>([]);
const domainId = ref('');
const itemId = ref('');
const remark = ref('');
const done = ref('');
const gaps = ref<Gap[]>([]);

const domains = computed(() => frame.value?.domains ?? []);
const items = computed(() => domains.value.find((d) => d.id === domainId.value)?.items.filter((i) => i.active) ?? []);
const text = (fr: string, en: string): string => (locale.value === 'en' ? en : fr);
const levelLabel = (code: string): string => {
  const key = `nur.observe.level.${code}` as MessageKey;
  const label = t(key);
  return label === key ? code : label;
};

onMounted(async () => {
  const active = ((await run(() => nursery.frames())) ?? []).find((f) => f.scope === 'tenant' && f.status === 'active');
  frame.value = active ? ((await run(() => nursery.frame(active.id))) ?? null) : null;
  const settings = await run(() => nursery.settings());
  levels.value = Array.isArray(settings?.['nursery.dev.levels']) ? (settings['nursery.dev.levels'] as string[]) : [];
});
watch(section, async (id) => {
  children.value = id ? ((await run(() => nursery.children(id))) ?? []) : [];
  childId.value = '';
  gaps.value = id ? ((await run(() => nursery.coverage(id))) ?? []) : [];
});
watch(domainId, () => (itemId.value = ''));

async function observe(level: string): Promise<void> {
  done.value = '';
  const saved = await run(() => nursery.observe({ learner_id: childId.value, item_id: itemId.value, level_code: level, client_uuid: newUuid(), ...(remark.value.trim() ? { remark: remark.value.trim() } : {}) }));
  if (saved) {
    done.value = t('nur.observe.saved');
    remark.value = '';
    gaps.value = (await run(() => nursery.coverage(section.value))) ?? [];
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.observe.title') }}</h1>
      <p class="lede">{{ t('nur.observe.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="!frame" class="notice notice--warning" role="status">{{ t('nur.observe.noFrame') }}</p>

    <section v-else class="card">
      <SectionPicker v-model="section" id="ob-section" />
      <div class="field">
        <label for="ob-child">{{ t('nur.auth.child') }}</label>
        <select id="ob-child" v-model="childId"><option value="">{{ t('nur.auth.pickChild') }}</option><option v-for="c in children" :key="c.id" :value="c.id">{{ c.full_name }}</option></select>
      </div>
      <div class="field">
        <label for="ob-domain">{{ t('nur.observe.domain') }}</label>
        <select id="ob-domain" v-model="domainId"><option value="">{{ t('nur.observe.pickDomain') }}</option><option v-for="d in domains" :key="d.id" :value="d.id">{{ text(d.label_fr, d.label_en) }}</option></select>
      </div>
      <div class="field">
        <label for="ob-item">{{ t('nur.observe.item') }}</label>
        <select id="ob-item" v-model="itemId"><option value="">{{ t('nur.observe.pickItem') }}</option><option v-for="i in items" :key="i.id" :value="i.id">{{ text(i.label_fr, i.label_en) }}</option></select>
      </div>
      <div class="field"><label for="ob-remark">{{ t('nur.observe.remark') }}</label><input id="ob-remark" v-model="remark" type="text" maxlength="500" /><span class="hint">{{ t('nur.observe.remarkHint') }}</span></div>
      <p v-if="levels.length === 0" class="notice notice--warning" role="status">{{ t('nur.observe.noLevels') }}</p>
      <div class="row levels" role="group" :aria-label="t('nur.observe.levels')">
        <button v-for="level in levels" :key="level" type="button" class="btn btn--primary btn--large" :disabled="busy || !childId || !itemId" @click="observe(level)">{{ levelLabel(level) }}</button>
      </div>
      <p v-if="done" class="notice" role="status" aria-live="polite">{{ done }}</p>
    </section>

    <section v-if="section" class="card" aria-labelledby="ob-gaps">
      <h2 id="ob-gaps">{{ t('nur.observe.gaps') }}</h2>
      <p v-if="gaps.length === 0" class="muted">{{ t('nur.observe.noGaps') }}</p>
      <ul class="plain">
        <li v-for="g in gaps" :key="g.learner_id + g.domain_id">{{ g.full_name }} · {{ text(g.domain_label_fr, g.domain_label_en) }} : {{ g.observations }} / {{ g.required }}</li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.levels { flex-wrap: wrap; gap: 8px; }
.levels .btn { min-height: 56px; flex: 1 1 140px; }
.plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
</style>
