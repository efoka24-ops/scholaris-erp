<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { attendance, type BarredList } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { TP } from './access';
import { useHt } from './i18n';

// Listes de non-autorisés à composer transmises au service des examens (EX-PRE-05), avec accusé de réception.
const props = defineProps<{ groupId: string; version: number }>();
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const items = ref<BarredList[]>([]);
const canReceive = computed(() => ctx.can(...TP.examManage) && !higher.readOnly);

async function load(): Promise<void> {
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (props.groupId) q['filter[group_id]'] = props.groupId;
  const res = await list.run(() => attendance.barredLists(q));
  if (res) items.value = res;
}

async function receive(l: BarredList): Promise<void> {
  const res = await list.run(() => attendance.receive(l.id));
  if (res) await load();
}

watch(() => [props.groupId, props.version], load);
onMounted(load);
</script>

<template>
  <section class="hi-section">
    <h2>{{ t('ht.ass.lists') }}</h2>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('ht.ass.transmittedAt') }}</th><th scope="col">{{ t('hi.group') }}</th><th scope="col" class="num">{{ t('ht.ass.barredCount') }}</th><th scope="col">{{ t('ht.ass.receivedAt') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="l in items" :key="l.id">
            <th scope="row">{{ formatDate(l.transmitted_at, locale) }}</th>
            <td>{{ higher.groupName(l.group_id) }}</td>
            <td class="num">{{ l.barred_count }}</td>
            <td>{{ l.received_at ? formatDate(l.received_at, locale) : '—' }}</td>
            <td class="hi-actions"><button v-if="canReceive && !l.received_at" type="button" class="hi-linkbtn" :disabled="list.busy.value" @click="receive(l)">{{ t('ht.ass.receive') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="5" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
