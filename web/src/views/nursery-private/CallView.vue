<script setup lang="ts">
// Appel du matin par l'aide ou l'éducatrice : tous présents par défaut, les exceptions (absent, en retard) se touchent ; le serveur
// enregistre l'appel (idempotent par client_uuid) et une arrivée pour chaque enfant présent.
import { computed, ref, watch } from 'vue';
import { nursery, type RollRow } from '@/api/nurseryPrivate';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n } from '@/i18n';
import SectionPicker from './SectionPicker.vue';
import { newUuid, useAction } from './useNur';

const { t } = useI18n();
const { error, busy, run } = useAction();
const section = ref('');
const part = ref<'morning' | 'afternoon'>('morning');
const roll = ref<RollRow[]>([]);
const marks = ref<Record<string, 'A' | 'R'>>({});
const result = ref<{ present: number; late: number; absent: number } | null>(null);
const uuid = ref(newUuid());

watch(section, async (id) => {
  marks.value = {};
  result.value = null;
  uuid.value = newUuid();
  roll.value = id ? ((await run(() => nursery.roll(id))) ?? []) : [];
});
function cycle(child: RollRow): void {
  const current = marks.value[child.child_id];
  const next = { ...marks.value };
  if (current === undefined) next[child.child_id] = 'A';
  else if (current === 'A') next[child.child_id] = 'R';
  else delete next[child.child_id];
  marks.value = next;
}
const label = (child: RollRow): string => {
  const mark = marks.value[child.child_id];
  return mark === 'A' ? t('nur.call.absent') : mark === 'R' ? t('nur.call.late') : t('nur.call.present');
};
const absent = computed(() => Object.values(marks.value).filter((m) => m === 'A').length);

async function submit(): Promise<void> {
  const exceptions = Object.entries(marks.value).map(([child_id, status]) => ({ child_id, status }));
  result.value = (await run(() => nursery.call(section.value, { day_part: part.value, client_uuid: uuid.value, validate: true, exceptions }))) ?? null;
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('nur.home.eyebrow') }}</p>
      <h1>{{ t('nur.call.title') }}</h1>
      <p class="lede">{{ t('nur.call.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <section class="card">
      <div class="grid2">
        <SectionPicker v-model="section" id="ca-section" />
        <div class="field">
          <label for="ca-part">{{ t('nur.call.part') }}</label>
          <select id="ca-part" v-model="part">
            <option value="morning">{{ t('nur.call.morning') }}</option>
            <option value="afternoon">{{ t('nur.call.afternoon') }}</option>
          </select>
        </div>
      </div>
      <p v-if="roll.length > 0" class="muted">{{ t('nur.call.hint') }}</p>
      <ul class="list">
        <li v-for="c in roll" :key="c.child_id" class="row row--between">
          <span>{{ c.full_name }}<span v-if="c.status === 'absent'" class="muted"> · {{ t('nur.call.alreadyAbsent') }}</span></span>
          <button type="button" class="btn btn--quiet toggle" :class="`toggle--${marks[c.child_id] ?? 'P'}`" :aria-label="`${c.full_name} : ${label(c)}`" @click="cycle(c)">{{ label(c) }}</button>
        </li>
      </ul>
      <button v-if="roll.length > 0" type="button" class="btn btn--primary btn--large btn--block" :disabled="busy" @click="submit">{{ t('nur.call.submit', { count: absent }) }}</button>
      <p v-if="result" class="notice" role="status">{{ t('nur.call.done', { present: result.present, late: result.late, absent: result.absent }) }}</p>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 12px 0; padding: 0; display: grid; gap: 8px; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; }
.toggle { min-width: 120px; min-height: 44px; }
.toggle--A { background: var(--danger-bg, #fde8e8); font-weight: 600; }
.toggle--R { background: var(--warning-bg, #fff4d6); font-weight: 600; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
