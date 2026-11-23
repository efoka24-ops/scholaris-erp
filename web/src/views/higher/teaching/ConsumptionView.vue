<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { formatMinutes, teaching, type Consumption, type VolumeRow } from '@/api/higher-teaching';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import TeachingFrame from './TeachingFrame.vue';
import { hk, useHt } from './i18n';

// Consommation des volumes CM/TD/TP par EC et groupe (EX-EDT-04) : prévu, programmé, tenu (appel), consigné, reste et dépassement.
const { t } = useHt();
const higher = useHigherStore();
const list = useHigherAction();

const rows = ref<Consumption[]>([]);
const groupId = ref('');
const from = ref('');
const to = ref('');
const COLS: Array<keyof VolumeRow> = ['planned', 'scheduled', 'held', 'logged', 'remaining', 'overrun'];
const pct = (r: VolumeRow): number => (r.planned > 0 ? Math.min(100, Math.round((r.held / r.planned) * 100)) : 0);

async function load(): Promise<void> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (groupId.value) q.group_id = groupId.value;
  if (from.value) q.from = from.value;
  if (to.value) q.to = to.value;
  const res = await list.run(() => teaching.consumption(q));
  if (res) rows.value = res;
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.vol.title')" :lede="t('ht.vol.lede')" @school-change="load">
    <form class="hi-form" @submit.prevent="load">
      <div class="field">
        <label for="vo-group">{{ t('hi.group') }}</label>
        <select id="vo-group" v-model="groupId"><option value="">{{ t('hi.filterAll') }}</option><option v-for="g in higher.groups" :key="g.id" :value="g.id">{{ higher.levelName(g.level_id) }} · {{ g.name }}</option></select>
      </div>
      <div class="field"><label for="vo-from">{{ t('ht.vol.from') }}</label><input id="vo-from" v-model="from" type="date" /></div>
      <div class="field"><label for="vo-to">{{ t('ht.vol.to') }}</label><input id="vo-to" v-model="to" type="date" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="list.busy.value">{{ t('ht.load') }}</button></div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ t('ht.element') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col">{{ t('ht.kind') }}</th><th v-for="c in COLS" :key="c" scope="col" class="num">{{ t(hk('ht.vol.', c)) }}</th></tr>
        </thead>
        <tbody>
          <template v-for="r in rows" :key="r.element_id + r.group_id">
            <tr v-for="(v, kind) in r.by_kind" :key="kind" data-test="vol-row">
              <th scope="row">{{ r.element_code }} · {{ r.element_title }}</th>
              <td>{{ higher.groupName(r.group_id) }}</td>
              <td>
                {{ t(hk('ht.kind.', String(kind))) }}
                <span class="vol-bar" :aria-label="`${pct(v)} %`"><span :style="{ width: `${pct(v)}%` }" /></span>
              </td>
              <td v-for="c in COLS" :key="c" class="num" :class="{ 'vol-over': c === 'overrun' && v.overrun > 0 }">{{ formatMinutes(v[c]) }}</td>
            </tr>
          </template>
          <tr v-if="rows.length === 0"><td :colspan="3 + COLS.length" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>
  </TeachingFrame>
</template>

<style scoped>
.vol-bar {
  display: block;
  height: 4px;
  background: var(--track);
  margin-top: 4px;
  min-width: 60px;
}
.vol-bar > span {
  display: block;
  height: 100%;
  background: var(--accent);
}
.vol-over {
  color: var(--danger);
  font-weight: 700;
}
</style>
