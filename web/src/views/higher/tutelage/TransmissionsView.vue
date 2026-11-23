<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { TRANSMISSION_KINDS, saveExportedFile, tutelage, type Transmission, type TransmissionKind } from '@/api/higher-nationalexams';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import YearField from '../YearField.vue';
import { useNationalI18n, xk } from '../national-exams/i18n';
import { T } from '../national-exams/perms';
import TutelageFrame from './TutelageFrame.vue';

// Transmissions à l'université de tutelle (EX-TUT-02) : listes des inscrits, notes, relevés et procès-verbaux (refus 409 tant que le PV n'est pas signé).
const { tx } = useNationalI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const send = useHigherAction();
const answer = useHigherAction();

const items = ref<Transmission[]>([]);
const sent = ref('');
const form = reactive({ kind: 'lists' as TransmissionKind, program_id: '', academic_year_id: '', level_id: '', period_id: '', format: 'csv' as 'csv' | 'xlsx', note: '' });
const canSend = computed(() => ctx.can(...T.internal) && !higher.readOnly);
const programs = computed(() => higher.programs.filter((p) => p.requires_tutelage));
const refused = computed(() => send.error.value instanceof ApiError && send.error.value.status === 409);

async function load(): Promise<void> {
  const res = await list.run(() => tutelage.transmissions({ ...(higher.scope() as Record<string, string>), per_page: 200 }));
  if (res) items.value = res;
}

async function transmit(): Promise<void> {
  sent.value = '';
  const res = await send.run(() => tutelage.transmit({
    kind: form.kind, program_id: form.program_id, academic_year_id: form.academic_year_id, format: form.format,
    ...(form.level_id.trim() ? { level_id: form.level_id.trim() } : {}), ...(form.period_id.trim() ? { period_id: form.period_id.trim() } : {}),
    ...(form.note.trim() ? { note: form.note.trim() } : {}),
  }));
  if (!res) return;
  sent.value = tx('htu.sentSuccess', { items: res.transmission.items_count });
  if (res.file) saveExportedFile(res.file);
  await load();
}

async function reply(t: Transmission, status: 'acknowledged' | 'rejected'): Promise<void> {
  if (await answer.run(() => tutelage.answer(t.id, status))) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  await load();
});
</script>

<template>
  <TutelageFrame :title="tx('htu.nav.transmissions')" :lede="tx('htu.lede')" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ tx('htu.kind') }}</th><th scope="col">{{ tx('htu.university') }}</th><th scope="col" class="num">{{ tx('htu.items') }}</th><th scope="col">{{ tx('htu.sentAt') }}</th><th scope="col">{{ tx('htu.status') }}</th><th v-if="canSend" scope="col">{{ tx('hx.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="t in items" :key="t.id" data-test="transmission-row">
            <th scope="row">{{ tx(xk('htu.kind.', t.transmission_kind)) }}<br /><small class="muted">{{ higher.programName(t.program_id) }} · {{ higher.yearName(t.academic_year_id) }}</small></th>
            <td>{{ t.university_name }}</td>
            <td class="num">{{ t.items_count }}</td>
            <td>{{ t.sent_at }}</td>
            <td><span class="tag tag--neutral">{{ tx(xk('htu.state.', t.status)) }}</span></td>
            <td v-if="canSend">
              <template v-if="t.status === 'sent'">
                <button type="button" class="btn btn--quiet" :disabled="answer.busy.value" @click="reply(t, 'acknowledged')">{{ tx('htu.acknowledge') }}</button>
                <button type="button" class="btn btn--quiet" :disabled="answer.busy.value" @click="reply(t, 'rejected')">{{ tx('htu.reject') }}</button>
              </template>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td :colspan="canSend ? 6 : 5" class="muted">{{ tx('htu.none') }}</td></tr>
        </tbody>
      </table>
    </div>
    <ErrorNotice :error="answer.error.value" />

    <form v-if="canSend" class="card hi-section hi-form" data-test="transmit" @submit.prevent="transmit">
      <h2>{{ tx('htu.transmit') }}</h2>
      <div class="field">
        <label for="tt-kind">{{ tx('htu.kind') }}</label>
        <select id="tt-kind" v-model="form.kind"><option v-for="k in TRANSMISSION_KINDS" :key="k" :value="k">{{ tx(xk('htu.kind.', k)) }}</option></select>
        <small v-if="form.kind === 'minutes'" class="muted">{{ tx('htu.minutesHelp') }}</small>
      </div>
      <div class="field">
        <label for="tt-program">{{ tx('htu.program') }}</label>
        <select id="tt-program" v-model="form.program_id" required><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="tt-year" v-model="form.academic_year_id" :label="tx('htu.year')" />
      <div class="field"><label for="tt-level">{{ tx('htu.level') }}</label><input id="tt-level" v-model="form.level_id" type="text" /></div>
      <div class="field"><label for="tt-period">{{ tx('htu.period') }}</label><input id="tt-period" v-model="form.period_id" type="text" /></div>
      <div class="field">
        <label for="tt-format">{{ tx('hx.format') }}</label>
        <select id="tt-format" v-model="form.format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select>
      </div>
      <div class="field"><label for="tt-note">{{ tx('htu.note') }}</label><input id="tt-note" v-model="form.note" type="text" maxlength="500" /></div>
      <p v-if="refused" class="notice notice--danger" role="alert" data-test="refused">{{ tx('htu.transmitRefused', { detail: (send.error.value as ApiError).problem?.detail ?? (send.error.value as ApiError).message }) }}</p>
      <ErrorNotice v-else :error="send.error.value" />
      <p v-if="sent" class="notice" role="status" data-test="sent">{{ sent }}</p>
      <button type="submit" class="btn btn--primary" :disabled="send.busy.value || !form.program_id || !form.academic_year_id">{{ tx('htu.transmit') }}</button>
    </form>
  </TutelageFrame>
</template>
