<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { attendance, DIRECT_SANCTIONS, type Sanction, type SanctionKind } from '@/api/higher-teaching';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import PersonPicker from '../PersonPicker.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import { TP } from './access';
import { hk, useHt } from './i18n';

// Sanctions (avertissement, blâme, exclusion temporaire) et notification DOC-31. L'exclusion définitive passe par le conseil.
const { t, locale } = useHt();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Sanction[]>([]);
const { label: personLabel } = useHigherNames(() => items.value.map((x) => x.person_id));
const touched = ref(false);
const form = reactive({ person_id: null as string | null, sanction_kind: 'warning' as SanctionKind, reason: '', starts_on: '', ends_on: '' });
const canSanction = computed(() => ctx.can(...TP.sanction) && !higher.readOnly);
const datesBad = computed(() => touched.value && form.sanction_kind === 'temporary_exclusion' && (!form.starts_on || !form.ends_on || form.ends_on < form.starts_on));

async function load(): Promise<void> {
  const res = await list.run(() => attendance.sanctions(higher.scope()));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.person_id || !form.reason.trim() || datesBad.value) return;
  const person = form.person_id;
  const temp = form.sanction_kind === 'temporary_exclusion';
  const res = await act.run(() => attendance.sanction({ person_id: person, sanction_kind: form.sanction_kind, reason: form.reason.trim(), ...(temp ? { starts_on: form.starts_on, ends_on: form.ends_on } : {}) }));
  if (!res) return;
  touched.value = false;
  Object.assign(form, { reason: '', starts_on: '', ends_on: '' });
  await load();
}

async function notify(s: Sanction): Promise<void> {
  const res = await act.run(() => attendance.notify(s.id));
  if (res) await load();
}

defineExpose({ load });
onMounted(load);
</script>

<template>
  <ErrorNotice :error="list.error.value" />
  <ErrorNotice :error="act.error.value" />
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">{{ t('hi.date') }}</th><th scope="col">{{ t('ht.rep.student') }}</th><th scope="col">{{ t('ht.dis.kind') }}</th><th scope="col">{{ t('hi.reason') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
      <tbody>
        <tr v-for="s in items" :key="s.id">
          <th scope="row">{{ formatDate(s.decided_at, locale) }}</th>
          <td>{{ personLabel(s.person_id) }}</td>
          <td>{{ t(hk('ht.dis.kind.', s.sanction_kind)) }}<span v-if="s.starts_on" class="muted hi-small"> · {{ formatDate(s.starts_on, locale) }} → {{ formatDate(s.ends_on, locale) }}</span></td>
          <td>{{ s.reason }}</td>
          <td><span class="tag tag--neutral">{{ t(hk('ht.dis.state.', s.status)) }}</span><span v-if="s.document_number" class="muted hi-small"> · {{ s.document_number }}</span></td>
          <td class="hi-actions"><button v-if="canSanction && !s.document_number" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="notify(s)">{{ t('ht.dis.notify') }}</button></td>
        </tr>
        <tr v-if="items.length === 0"><td colspan="6" class="muted">{{ t('hi.none') }}</td></tr>
      </tbody>
    </table>
  </div>

  <form v-if="canSanction" class="card hi-section hi-form" novalidate @submit.prevent="create">
    <h2 class="hi-wide">{{ t('ht.dis.newSanction') }}</h2>
    <div class="hi-wide"><PersonPicker id="sa-person" v-model="form.person_id" :label="t('ht.rep.student')" /><span v-if="touched && !form.person_id" class="error">{{ t('hi.required') }}</span></div>
    <div class="field"><label for="sa-kind">{{ t('ht.dis.kind') }}</label><select id="sa-kind" v-model="form.sanction_kind"><option v-for="k in DIRECT_SANCTIONS" :key="k" :value="k">{{ t(hk('ht.dis.kind.', k)) }}</option></select></div>
    <template v-if="form.sanction_kind === 'temporary_exclusion'">
      <div class="field"><label for="sa-from">{{ t('ht.dis.exclusionFrom') }}</label><input id="sa-from" v-model="form.starts_on" type="date" :aria-invalid="datesBad" /></div>
      <div class="field"><label for="sa-to">{{ t('ht.dis.exclusionTo') }}</label><input id="sa-to" v-model="form.ends_on" type="date" :aria-invalid="datesBad" /><span v-if="datesBad" class="error">{{ t('hi.datesBad') }}</span></div>
    </template>
    <div class="field hi-wide"><label for="sa-reason">{{ t('hi.reason') }}</label><textarea id="sa-reason" v-model="form.reason" :aria-invalid="touched && !form.reason.trim()" /><span v-if="touched && !form.reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
    <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
  </form>
</template>
