<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { grades, type MarkEvent } from '@/api/higher-grades';
import { formatDateTime } from '@/lib/format';
import { useHigherAction } from '../useHigherAction';
import { describe } from './marks';
import { gk, useGradesI18n } from './i18n';

// Historique d'une note (ajout seul) : saisie, import, synchronisation, correction approuvée.
const props = defineProps<{ markId: string; title: string }>();
const emit = defineEmits<{ (e: 'close'): void }>();
const { tg, locale } = useGradesI18n();
const load = useHigherAction();
const events = ref<MarkEvent[]>([]);

onMounted(async () => {
  const res = await load.run(() => grades.markEvents(props.markId));
  if (res) events.value = res;
});
</script>

<template>
  <section class="card card--strong hi-section" role="dialog" aria-labelledby="mh-title" data-test="mark-history">
    <h2 id="mh-title">{{ tg('hg.hist.title') }} · {{ title }}</h2>
    <ErrorNotice :error="load.error.value" />
    <ul class="hi-list">
      <li v-for="e in events" :key="e.id">
        <span class="tag tag--neutral">{{ tg(gk('hg.hist.kind.', e.kind)) }}</span>
        <span class="hi-grow">{{ describe(e.old, locale) }} → <strong>{{ describe(e.new, locale) }}</strong> <span class="muted hi-small">v{{ e.version }}</span></span>
        <span v-if="e.reason" class="hi-small">{{ e.reason }}</span>
        <span class="muted hi-small">{{ formatDateTime(e.created_at, locale) }}</span>
      </li>
      <li v-if="!load.busy.value && events.length === 0" class="muted">{{ tg('hg.hist.none') }}</li>
    </ul>
    <button type="button" class="btn btn--quiet" @click="emit('close')">{{ tg('hg.hist.close') }}</button>
  </section>
</template>
