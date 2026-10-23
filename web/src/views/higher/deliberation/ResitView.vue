<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, type ResitElement } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { formatDateTime } from '@/lib/format';
import { locale } from '@/i18n';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import { DP } from './access';
import { dt, num } from './i18n';

// Rattrapage (EX-DEL-04) : convoqués par EC, enregistrement de la liste et convocation NOT-13 (SMS et e-mail).
const props = defineProps<{ id: string }>();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();
const elements = ref<ResitElement[]>([]);
const notified = ref<number | null>(null);
const canWrite = computed(() => ctx.can(...DP.prepare) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => deliberation.resitList(props.id));
  if (res) elements.value = res;
}

async function save(notify: boolean): Promise<void> {
  const res = await act.run(() => deliberation.convokeResit(props.id, notify));
  if (!res) return;
  elements.value = res.elements;
  notified.value = res.notified;
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <DelFrame :title="dt('hdl.resit.title')" :lede="dt('hdl.resit.lede')" :selector="false">
    <p><router-link :to="{ name: 'higher-del-jury', params: { id } }">← {{ dt('hdl.back') }}</router-link></p>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <p v-if="notified !== null" class="notice notice--success" role="status">{{ dt('hdl.resit.done', { count: notified }) }}</p>
    <section v-for="el in elements" :key="el.element_code" class="hi-section" :aria-labelledby="`rs-${el.element_code}`">
      <h2 :id="`rs-${el.element_code}`">{{ dt('hdl.resit.element', { code: el.element_code, unit: el.unit_code }) }}</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">{{ dt('hdl.student') }}</th><th scope="col" class="num">{{ dt('hdl.resit.note') }}</th><th scope="col">{{ dt('hdl.resit.notified') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in el.students" :key="s.person_id">
              <th scope="row">{{ s.name ?? s.person_id }}</th><td class="num">{{ num(s.note) }}</td><td>{{ s.notified_at ? formatDateTime(s.notified_at, locale) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
    <p v-if="elements.length === 0 && !list.busy.value" class="muted">{{ dt('hdl.none') }}</p>
    <div v-if="canWrite && elements.length > 0" class="hi-actions hi-section">
      <button type="button" class="btn" :disabled="act.busy.value" @click="save(false)">{{ dt('hdl.resit.save') }}</button>
      <button type="button" class="btn btn--primary" :disabled="act.busy.value" data-test="convoke" @click="save(true)">{{ dt('hdl.resit.convoke') }}</button>
    </div>
  </DelFrame>
</template>
