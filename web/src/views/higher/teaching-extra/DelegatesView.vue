<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { delegates, type GroupDelegate } from '@/api/higher-extra';
import { formatDate } from '@/lib/format';
import { useContextStore } from '@/stores/context';
import PersonPicker from '../PersonPicker.vue';
import GroupField from '../teaching/GroupField.vue';
import TeachingFrame from '../teaching/TeachingFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { useXt } from '../xi18n';
import { XP } from './access';

// Délégués de groupe (EX-ENS) : désignation et retrait ; les délégués contresignent le cahier de textes de leur groupe.
const { t, locale } = useXt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<GroupDelegate[]>([]);
const filterGroup = ref('');
const form = reactive({ group: '', person: null as string | null, tried: false });
const notice = ref('');
const canManage = computed(() => ctx.can(...XP.delegateManage) && !higher.readOnly);
const { label } = useHigherNames(() => items.value.map((d) => d.person_id));

async function load(): Promise<void> {
  const res = await list.run(() => delegates.list({ ...higher.scope(), ...(filterGroup.value ? { 'filter[group_id]': filterGroup.value } : {}) }));
  if (res) items.value = res;
}

async function designate(): Promise<void> {
  form.tried = true;
  notice.value = '';
  if (!form.group || !form.person) return;
  const res = await act.run(() => delegates.designate({ group_id: form.group, person_id: form.person as string }));
  if (!res) return;
  Object.assign(form, { person: null, tried: false });
  notice.value = t('hx.del.done');
  await load();
}

async function remove(d: GroupDelegate): Promise<void> {
  notice.value = '';
  const res = await act.run(() => delegates.remove(d.id));
  if (res === undefined) return;
  notice.value = t('hx.del.removed');
  items.value = items.value.filter((x) => x.id !== d.id);
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('groups', 'levels');
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('hx.del.title')" :lede="t('hx.del.lede')" @school-change="load">
    <div class="hi-form">
      <GroupField id="dl-filter" :model-value="filterGroup" :label="t('hx.del.group')" @update:model-value="(v: string) => { filterGroup = v; load(); }" />
    </div>
    <ErrorNotice :error="list.error.value" />
    <p v-if="items.length === 0" class="muted">{{ t('hx.del.empty') }}</p>
    <ul v-else class="hi-list" data-test="delegates">
      <li v-for="d in items" :key="d.id" :data-delegate="d.id">
        <span class="hi-grow"><strong data-test="name">{{ label(d.person_id) }}</strong><br /><span class="muted hi-small">{{ higher.groupName(d.group_id) }} · {{ t('hx.del.since') }} {{ formatDate(d.created_at, locale) }}</span></span>
        <button v-if="canManage" type="button" class="hi-linkbtn" :disabled="act.busy.value" data-test="remove" @click="remove(d)">{{ t('hx.del.remove') }}</button>
      </li>
    </ul>

    <form v-if="canManage" class="card card--strong hi-section" novalidate data-test="designate" @submit.prevent="designate">
      <h2>{{ t('hx.del.designate') }}</h2>
      <div class="hi-form">
        <GroupField id="dl-group" v-model="form.group" :label="t('hx.del.group')" :invalid="form.tried" />
        <div class="hi-wide">
          <PersonPicker id="dl-person" v-model="form.person" :label="t('hx.del.student')" />
          <span v-if="form.tried && !form.person" class="error">{{ t('hx.del.chooseStudent') }}</span>
        </div>
      </div>
      <ErrorNotice :error="act.error.value" />
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hx.del.designate') }}</button>
    </form>
    <p v-if="notice" class="notice notice--success hi-section" role="status" data-test="notice">{{ notice }}</p>
  </TeachingFrame>
</template>
