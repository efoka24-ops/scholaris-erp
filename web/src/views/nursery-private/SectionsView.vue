<script setup lang="ts">
// Sections de l'école (petite, moyenne, grande ou libellés propres à l'école) : liste avec effectifs et création par la directrice.
import { onMounted, ref } from 'vue';
import { nursery, type Child, type Section } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import { P } from './access';
import { ageLabel, useAction, usePermissions } from './useNur';

const { t, tp } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const sections = ref<Section[]>([]);
const opened = ref('');
const children = ref<Child[]>([]);
const form = ref({ label: '', capacity: '', subsystem: 'fr' });

async function load(): Promise<void> {
  sections.value = (await run(() => nursery.sections())) ?? [];
}
async function toggle(section: Section): Promise<void> {
  if (opened.value === section.id) {
    opened.value = '';
    return;
  }
  opened.value = section.id;
  children.value = (await run(() => nursery.children(section.id))) ?? [];
}
async function create(): Promise<void> {
  const capacity = Number(form.value.capacity);
  const created = await run(() => nursery.createSection({ label: form.value.label.trim(), subsystem: form.value.subsystem, ...(capacity > 0 ? { capacity } : {}) }));
  if (created) {
    form.value = { label: '', capacity: '', subsystem: 'fr' };
    await load();
  }
}
onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.section.title') }}</h1>
      <p class="lede">{{ t('nur.section.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <p v-if="sections.length === 0" class="muted">{{ t('nur.section.none') }}</p>
      <ul class="list">
        <li v-for="s in sections" :key="s.id">
          <div class="row row--between">
            <div>
              <strong>{{ s.label }}</strong>
              <div class="muted">{{ tp('nur.home.enrolled', s.enrolled) }}<span v-if="s.capacity"> · {{ t('nur.section.capacity', { count: s.capacity }) }}</span></div>
            </div>
            <button type="button" class="btn btn--quiet" :aria-expanded="opened === s.id" @click="toggle(s)">{{ opened === s.id ? t('nur.close') : t('nur.section.children') }}</button>
          </div>
          <ul v-if="opened === s.id" class="kids">
            <li v-for="c in children" :key="c.id">{{ c.full_name }} <span class="muted">{{ ageLabel(c.age_months, t('nur.years'), t('nur.months')) }}</span></li>
            <li v-if="children.length === 0" class="muted">{{ t('nur.section.empty') }}</li>
          </ul>
        </li>
      </ul>
    </section>

    <form v-if="can(...P.sectionManage)" class="card grid2" @submit.prevent="create">
      <h2 class="span2">{{ t('nur.section.create') }}</h2>
      <div class="field"><label for="sec-label">{{ t('nur.section.label') }}</label><input id="sec-label" v-model="form.label" type="text" maxlength="160" required /></div>
      <div class="field"><label for="sec-cap">{{ t('nur.section.capacityLabel') }}</label><input id="sec-cap" v-model="form.capacity" type="number" min="1" max="500" inputmode="numeric" /></div>
      <div class="field">
        <label for="sec-sub">{{ t('nur.section.subsystem') }}</label>
        <select id="sec-sub" v-model="form.subsystem">
          <option value="fr">{{ t('nur.section.sub.fr') }}</option>
          <option value="en">{{ t('nur.section.sub.en') }}</option>
          <option value="both">{{ t('nur.section.sub.both') }}</option>
        </select>
      </div>
      <div><button type="submit" class="btn btn--primary" :disabled="busy">{{ t('nur.section.createDo') }}</button></div>
    </form>
  </div>
</template>

<style scoped>
.list, .kids { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.kids { padding-top: 8px; gap: 4px; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.span2 { grid-column: 1 / -1; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
