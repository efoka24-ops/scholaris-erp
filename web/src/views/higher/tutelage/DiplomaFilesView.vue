<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { DIPLOMA_NEXT, diplomaRefusals, tutelage, type DiplomaFile, type DiplomaFileStatus } from '@/api/higher-nationalexams';
import { useContextStore } from '@/stores/context';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import { useHigherNames } from '../useHigherNames';
import PersonPicker from '../PersonPicker.vue';
import YearField from '../YearField.vue';
import { useNationalI18n, xk } from '../national-exams/i18n';
import { T } from '../national-exams/perms';
import TutelageFrame from './TutelageFrame.vue';

// Dossiers de diplôme (EX-TUT-03) : transmission à l'université (RG-TUT-2 : PV final signé et quitus) et suivi de leur état.
const { tx } = useNationalI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const send = useHigherAction();
const move = useHigherAction();

const items = ref<DiplomaFile[]>([]);
const personId = ref<string | null>(null);
const programId = ref('');
const yearId = ref('');
const names = useHigherNames(() => items.value.map((f) => f.person_id));
const canSend = computed(() => ctx.can(...T.internal) && !higher.readOnly);
const programs = computed(() => higher.programs.filter((p) => p.requires_tutelage));
const refusals = computed(() => diplomaRefusals(send.error.value));

async function load(): Promise<void> {
  const res = await list.run(() => tutelage.diplomaFiles({ ...(higher.scope() as Record<string, string>), per_page: 200 }));
  if (res) items.value = res;
}

async function transmit(): Promise<void> {
  if (!personId.value) return;
  const res = await send.run(() => tutelage.transmitDiplomaFile(personId.value ?? '', programId.value, yearId.value));
  if (!res) return;
  personId.value = null;
  await load();
}

async function advance(f: DiplomaFile, status: DiplomaFileStatus): Promise<void> {
  if (await move.run(() => tutelage.moveDiplomaFile(f.id, status))) await load();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  await load();
});
</script>

<template>
  <TutelageFrame :title="tx('htu.nav.files')" :lede="tx('htu.lede')" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ tx('hx.candidate') }}</th><th scope="col">{{ tx('htu.university') }}</th><th scope="col">{{ tx('htu.decision') }}</th><th scope="col">{{ tx('htu.status') }}</th><th v-if="canSend" scope="col">{{ tx('hx.actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="f in items" :key="f.id" data-test="file-row">
            <th scope="row">{{ names.name(f.person_id) }}<br /><small class="muted">{{ higher.programName(f.program_id) }} · {{ higher.yearName(f.academic_year_id) }}</small></th>
            <td>{{ f.university_name }}</td>
            <td>{{ f.decision_code }}</td>
            <td><span class="tag tag--neutral">{{ tx(xk('htu.fileState.', f.status)) }}</span></td>
            <td v-if="canSend">
              <button v-for="next in DIPLOMA_NEXT[f.status]" :key="next" type="button" class="btn btn--quiet" :disabled="move.busy.value" @click="advance(f, next)">{{ tx('htu.moveTo') }} {{ tx(xk('htu.fileState.', next)).toLowerCase() }}</button>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td :colspan="canSend ? 5 : 4" class="muted">{{ tx('htu.noFile') }}</td></tr>
        </tbody>
      </table>
    </div>
    <ErrorNotice :error="move.error.value" />

    <form v-if="canSend" class="card hi-section hi-form" data-test="file-form" @submit.prevent="transmit">
      <h2>{{ tx('htu.fileTransmit') }}</h2>
      <p class="muted">{{ tx('htu.rgTut2') }}</p>
      <PersonPicker id="tf-person" v-model="personId" :label="tx('htu.person')" />
      <div class="field">
        <label for="tf-program">{{ tx('htu.program') }}</label>
        <select id="tf-program" v-model="programId" required><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <YearField id="tf-year" v-model="yearId" :label="tx('htu.year')" />
      <div v-if="refusals" class="notice notice--danger" role="alert" data-test="rg-tut-2">
        <p><strong>{{ tx('htu.fileRefused') }}</strong></p>
        <ul><li v-for="r in refusals" :key="r">{{ r }}</li></ul>
      </div>
      <ErrorNotice v-else :error="send.error.value" />
      <button type="submit" class="btn btn--primary" :disabled="send.busy.value || !personId || !programId || !yearId">{{ tx('htu.fileTransmit') }}</button>
    </form>
  </TutelageFrame>
</template>
