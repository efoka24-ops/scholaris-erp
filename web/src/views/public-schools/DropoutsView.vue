<script setup lang="ts">
// Abandons déclarés ou présumés (absences prolongées) : relance de la famille puis clôture avec l'issue constatée.
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type Dropout } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { useAction } from './usePs';

const { t } = useI18n();
const { error, busy, run } = useAction();

const rows = ref<Dropout[]>([]);
const scope = ref<'open' | 'all'>('open');
const outcome = reactive<Record<string, string>>({});
const OUTCOMES = ['returned', 'transferred', 'confirmed'] as const;

async function load(): Promise<void> {
  rows.value = (await run(() => publicSchools.dropouts(scope.value))) ?? rows.value;
}

function replace(updated: Dropout | undefined): void {
  if (updated) rows.value = rows.value.map((r) => (r.id === updated.id ? updated : r));
}
const contact = async (d: Dropout): Promise<void> => replace(await run(() => publicSchools.contactDropout(d.id)));
const resolve = async (d: Dropout): Promise<void> => {
  replace(await run(() => publicSchools.resolveDropout(d.id, outcome[d.id] ?? 'returned')));
  if (scope.value === 'open') rows.value = rows.value.filter((r) => r.outcome === null);
};

function outcomeLabel(o: string): string {
  const known: Record<string, string> = { returned: t('ps.dropouts.outcome.returned'), transferred: t('ps.dropouts.outcome.transferred'), confirmed: t('ps.dropouts.outcome.confirmed') };
  return known[o] ?? o;
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.dropouts.title') }}</h1>
      <p class="lede">{{ t('ps.dropouts.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <div class="field filter">
      <label for="dr-scope">{{ t('ps.dropouts.show') }}</label>
      <select id="dr-scope" v-model="scope" @change="load">
        <option value="open">{{ t('ps.dropouts.open') }}</option>
        <option value="all">{{ t('ps.dropouts.everything') }}</option>
      </select>
    </div>

    <p v-if="rows.length === 0" class="muted">{{ t('ps.dropouts.none') }}</p>
    <ul class="cards">
      <li v-for="d in rows" :key="d.id" class="card">
        <div class="row row--between">
          <div>
            <router-link :to="{ name: 'ps-pupil', params: { id: d.person_id } }"><strong>{{ d.person_id }}</strong></router-link>
            <div class="muted">{{ t('ps.dropouts.since', { date: formatDate(d.detected_on) }) }}</div>
          </div>
          <span class="tag" :class="d.kind === 'presumed' ? 'tag--warning' : 'tag--neutral'">{{ d.kind === 'presumed' ? t('ps.dropouts.presumed') : t('ps.dropouts.declared') }}</span>
        </div>
        <p v-if="d.note" class="muted">{{ d.note }}</p>
        <p v-if="d.family_contacted_at" class="muted">{{ t('ps.dropouts.contacted', { date: formatDate(d.family_contacted_at) }) }}</p>
        <p v-if="d.outcome" class="tag tag--success">{{ outcomeLabel(d.outcome) }}</p>
        <div v-else class="row actions">
          <button type="button" class="btn" :disabled="busy" @click="contact(d)">{{ t('ps.dropouts.contact') }}</button>
          <div class="field inline">
            <label :for="`dr-out-${d.id}`">{{ t('ps.dropouts.resolveAs') }}</label>
            <select :id="`dr-out-${d.id}`" v-model="outcome[d.id]">
              <option v-for="o in OUTCOMES" :key="o" :value="o">{{ outcomeLabel(o) }}</option>
            </select>
          </div>
          <button type="button" class="btn btn--primary" :disabled="busy" @click="resolve(d)">{{ t('ps.dropouts.resolve') }}</button>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.cards { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.filter { max-width: 20rem; }
.actions { align-items: flex-end; flex-wrap: wrap; }
.inline { margin: 0; }
</style>
