<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { teaching, type Room } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import TeachingFrame from './TeachingFrame.vue';
import { TP } from './access';
import { useHt } from './i18n';

// Salles de cours et d'examen ; la capacité est contrôlée à chaque créneau (conflit « capacité »).
const { t } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Room[]>([]);
const touched = ref(false);
const form = reactive({ school_id: '', code: '', name: '', capacity: 40 });
const canManage = computed(() => ctx.can(...TP.timetableManage) && !higher.readOnly);
const schoolOptions = computed(() => (higher.schools.length ? higher.schools : higher.selectableSchools));

async function load(): Promise<void> {
  await higher.loadRefs('schools');
  if (!form.school_id) form.school_id = higher.scopedSchoolId ?? (schoolOptions.value.length === 1 ? schoolOptions.value[0]!.id : '');
  const res = await list.run(() => teaching.rooms(higher.scope()));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.school_id || !form.code.trim() || !form.name.trim() || !(Number(form.capacity) >= 1)) return;
  const res = await act.run(() => teaching.createRoom({ school_id: form.school_id, code: form.code.trim(), name: form.name.trim(), capacity: Number(form.capacity) }));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { code: '', name: '' });
  await load();
}

async function toggle(r: Room): Promise<void> {
  const res = await act.run(() => teaching.updateRoom(r.id, { active: !r.active }));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <TeachingFrame :title="t('ht.room.title')" :lede="t('ht.room.lede')" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.code') }}</th><th scope="col">{{ t('hi.name') }}</th><th scope="col" class="num">{{ t('ht.room.capacity') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in items" :key="r.id">
            <th scope="row">{{ r.code }}</th><td>{{ r.name }}</td><td class="num">{{ r.capacity }}</td>
            <td><span class="tag" :class="r.active ? 'tag--success' : 'tag--warning'">{{ r.active ? t('ht.room.active') : t('ht.room.inactive') }}</span></td>
            <td class="hi-actions"><button v-if="canManage" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="toggle(r)">{{ r.active ? t('ht.room.disable') : t('ht.room.enable') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="5" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('ht.room.new') }}</h2>
      <div class="field">
        <label for="rm-school">{{ t('hi.school.label') }}</label>
        <select id="rm-school" v-model="form.school_id" :aria-invalid="touched && !form.school_id"><option value="" disabled>{{ t('hi.schoolPick') }}</option><option v-for="s in schoolOptions" :key="s.id" :value="s.id">{{ s.code ? `${s.code} · ${s.name}` : s.name }}</option></select>
        <span v-if="touched && !form.school_id" class="error">{{ t('ht.room.schoolPick') }}</span>
      </div>
      <div class="field"><label for="rm-code">{{ t('hi.code') }}</label><input id="rm-code" v-model="form.code" type="text" :aria-invalid="touched && !form.code.trim()" /></div>
      <div class="field"><label for="rm-name">{{ t('hi.name') }}</label><input id="rm-name" v-model="form.name" type="text" :aria-invalid="touched && !form.name.trim()" /></div>
      <div class="field"><label for="rm-cap">{{ t('ht.room.capacity') }}</label><input id="rm-cap" v-model.number="form.capacity" type="number" min="1" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </TeachingFrame>
</template>
