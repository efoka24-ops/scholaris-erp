<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { deliberation, type Jury } from '@/api/higher-deliberation';
import { useContextStore } from '@/stores/context';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import DelFrame from './DelFrame.vue';
import TwoFactorNotice from './TwoFactorNotice.vue';
import UnsignedNotice from './UnsignedNotice.vue';
import { DP } from './access';
import { dk, dt } from './i18n';
import { useJurySession } from './useJurySession';
import { useRouter } from 'vue-router';
import { useHigherNames } from '../useHigherNames';
import { useXt } from '../xi18n';

// Fiche d'un jury : composition, présence des membres (quorum), travaux (préparation, rattrapage, documents), signature et publication.
const props = defineProps<{ id: string }>();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const save = useHigherAction();
const session = useJurySession();

const jury = ref<Jury | null>(null);
const present = ref<string[]>([]);
const writable = computed(() => !higher.readOnly);
const canPrepare = computed(() => ctx.can(...DP.prepareOrPreside) && writable.value);
const canSign = computed(() => ctx.can(...DP.sign) && writable.value && !!jury.value?.deliberation_ref);
const canPublish = computed(() => ctx.can(...DP.publish) && writable.value && jury.value?.status !== 'published');
// Séance du moteur du socle (ouverture, clôture, réouverture motivée) : écran ../sitting, lien affiché si la route existe.
const { t: xt } = useXt();
const hasSitting = useRouter().hasRoute('higher-del-sitting');
const { name: personName } = useHigherNames(() => (jury.value?.members ?? []).filter((m) => !m.name).map((m) => m.person_id));
const title = computed(() => (jury.value ? `${higher.groupName(jury.value.structure_id)} · ${jury.value.period_label || jury.value.period_id}` : dt('hdl.jury.title')));

function apply(j: Jury): void {
  jury.value = j;
  present.value = (j.members ?? []).filter((m) => m.present).map((m) => m.person_id);
}

async function refresh(): Promise<void> {
  await higher.loadRefs('groups', 'years');
  const res = await load.run(() => deliberation.jury(props.id));
  if (res) apply(res);
}

async function saveAttendance(): Promise<void> {
  const res = await save.run(() => deliberation.attendance(props.id, present.value));
  if (res) apply(res);
}

async function sign(): Promise<void> {
  if (jury.value && (await session.sign(jury.value))) await refresh();
}
async function publish(): Promise<void> {
  if (jury.value && (await session.publish(jury.value))) await refresh();
}

onMounted(async () => {
  await higher.ensure();
  await refresh();
});
</script>

<template>
  <DelFrame :title="title" :selector="false">
    <ErrorNotice :error="load.error.value" />
    <template v-if="jury">
      <p>
        <span class="tag" :class="`tag--${toneOf(jury.status) ?? 'neutral'}`">{{ dk('hdl.state.', jury.status) }}</span>
        {{ dk('hdl.session.', jury.exam_session) }} · {{ higher.yearName(jury.academic_year_id) }}
      </p>

      <section class="hi-section" aria-labelledby="jy-links">
        <h2 id="jy-links">{{ dt('hdl.jury.links') }}</h2>
        <div class="hdl-links">
          <router-link class="btn" :to="{ name: 'higher-del-preparation', params: { id } }">{{ dt('hdl.jury.preparation') }}</router-link>
          <router-link v-if="jury.exam_session === 'normal'" class="btn" :to="{ name: 'higher-del-resit', params: { id } }">{{ dt('hdl.jury.resit') }}</router-link>
          <router-link class="btn" :to="{ name: 'higher-del-documents', params: { id } }">{{ dt('hdl.jury.documents') }}</router-link>
          <router-link v-if="hasSitting" class="btn" :to="{ name: 'higher-del-sitting', params: { id } }" data-test="sitting-link">{{ xt('hx.sit.link') }}</router-link>
        </div>
      </section>

      <section class="hi-section" aria-labelledby="jy-att">
        <h2 id="jy-att">{{ dt('hdl.jury.attendance') }}</h2>
        <p data-test="quorum">
          {{ dt('hdl.jury.quorumLine', { present: jury.present_count ?? 0, total: jury.members?.length ?? 0, quorum: jury.quorum_min }) }} ·
          <strong v-if="jury.quorum_reached">{{ dt('hdl.jury.quorumReached') }}</strong><span v-else class="error">{{ dt('hdl.jury.quorumMissing') }}</span>
        </p>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">{{ dt('hdl.jury.composition') }}</th><th scope="col">{{ dt('hdl.jury.role.member') }}</th><th scope="col">{{ dt('hdl.jury.present') }}</th><th scope="col">{{ dt('hdl.jury.sign') }}</th></tr></thead>
            <tbody>
              <tr v-for="m in jury.members ?? []" :key="m.person_id">
                <th scope="row">{{ m.name ?? personName(m.person_id) }}</th>
                <td>{{ dk('hdl.jury.role.', m.member_role) }}</td>
                <td><label class="check"><input v-model="present" type="checkbox" :value="m.person_id" :disabled="!canPrepare" /> {{ dt('hdl.jury.present') }}</label></td>
                <td>{{ m.signed ? dt('hdl.jury.signed') : dt('hdl.jury.notSigned') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <ErrorNotice :error="save.error.value" />
        <button v-if="canPrepare" type="button" class="btn btn--primary" :disabled="save.busy.value" @click="saveAttendance">{{ dt('hdl.jury.saveAttendance') }}</button>
      </section>

      <section class="card hi-section" aria-labelledby="jy-session">
        <h2 id="jy-session">{{ dt('hdl.jury.session') }}</h2>
        <p v-if="jury.minutes_fingerprint" class="muted">{{ dt('hdl.jury.fingerprint') }} : <code>{{ jury.minutes_fingerprint.slice(0, 16) }}…</code> (v{{ jury.minutes_version }})</p>
        <p v-if="!jury.deliberation_ref" class="muted">{{ dt('hdl.jury.noMinutes') }}</p>
        <TwoFactorNotice v-if="session.needsTfa.value" />
        <UnsignedNotice v-if="session.unsigned.value" />
        <ErrorNotice :error="session.error.value" />
        <p v-if="session.done.value === 'signed'" class="notice notice--success" role="status">{{ dt('hdl.jury.signDone') }}</p>
        <p v-if="session.done.value === 'published'" class="notice notice--success" role="status">{{ dt('hdl.jury.publishDone') }}</p>
        <div class="hi-actions">
          <button v-if="canSign" type="button" class="btn btn--ink" :disabled="session.busy.value" data-test="sign" @click="sign">{{ dt('hdl.jury.sign') }}</button>
          <button v-if="canPublish" type="button" class="btn btn--primary" :disabled="session.busy.value" data-test="publish" @click="publish">{{ dt('hdl.jury.publish') }}</button>
        </div>
        <p class="hint">{{ dt('hdl.jury.signHint') }} {{ dt('hdl.jury.publishHint') }}</p>
      </section>
    </template>
  </DelFrame>
</template>
