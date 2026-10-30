<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { isNotEligible, nationalExams, type Candidate, type ListEvent, type NationalSession, type SessionStatistics } from '@/api/higher-nationalexams';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import PersonPicker from '../PersonPicker.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import CandidatesPanel from './CandidatesPanel.vue';
import ExportPanel from './ExportPanel.vue';
import ResultsPanel from './ResultsPanel.vue';
import { X } from './perms';
import { useNationalI18n, xk } from './i18n';

// Détail d'une session d'examen national (EX-EXN-02 à 06) : candidats, dossiers, export API-17, convocations, résultats, journal.
const props = defineProps<{ id: string }>();
const { tx } = useNationalI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const load = useHigherAction();
const add = useHigherAction();
const gen = useHigherAction();
const closing = useHigherAction();

const session = ref<NationalSession | null>(null);
const candidates = ref<Candidate[]>([]);
const stats = ref<SessionStatistics | null>(null);
const events = ref<ListEvent[]>([]);
const personId = ref<string | null>(null);
const info = ref('');
const reasons = ref<string[]>([]);

const canManage = computed(() => ctx.can(...X.manage) && !higher.readOnly);
const canExport = computed(() => ctx.can(...X.export));
const afterExport = computed(() => (session.value?.export_count ?? 0) > 0);

async function refresh(): Promise<void> {
  const res = await load.run(async () => {
    const [s, c, st] = await Promise.all([nationalExams.session(props.id), nationalExams.candidates(props.id, { per_page: 200 }), nationalExams.statistics(props.id)]);
    return { s, c, st };
  });
  if (!res) return;
  session.value = res.s;
  candidates.value = res.c;
  stats.value = res.st;
  events.value = await nationalExams.events(props.id).catch(() => []);
}

async function addCandidate(): Promise<void> {
  if (!personId.value) return;
  info.value = '';
  reasons.value = [];
  const res = await add.run(() => nationalExams.add(props.id, personId.value ?? ''));
  if (!res) {
    if (isNotEligible(add.error.value)) await explain();
    return;
  }
  personId.value = null;
  await refresh();
}

/** Raisons détaillées du refus RG-EXN-1 (GET /eligibility), en complément du message du serveur. */
async function explain(): Promise<void> {
  try {
    reasons.value = (await nationalExams.eligibility(props.id, personId.value ?? '')).reasons;
  } catch {
    reasons.value = [];
  }
}

async function check(): Promise<void> {
  if (!personId.value) return;
  reasons.value = [];
  const res = await add.run(() => nationalExams.eligibility(props.id, personId.value ?? ''));
  if (!res) return;
  if (res.rg_exn_1) info.value = tx('hx.eligible');
  else reasons.value = res.reasons.length > 0 ? res.reasons : [tx('hx.notEligible')];
}

async function generate(): Promise<void> {
  const res = await gen.run(() => nationalExams.generate(props.id));
  if (!res) return;
  info.value = tx('hx.generated', { added: res.added, already: res.already_listed });
  await refresh();
}

async function closeSession(): Promise<void> {
  if (await closing.run(() => nationalExams.close(props.id))) await refresh();
}

onMounted(async () => {
  await higher.ensure();
  await higher.loadRefs('programs', 'years');
  await refresh();
});
</script>

<template>
  <HigherFrame :title="session?.specialty_label ?? tx('hx.session')" :lede="tx('hx.lede')" section="portal" @school-change="refresh">
    <p><router-link class="btn btn--quiet" :to="{ name: 'higher-national-exams' }">← {{ tx('hx.back') }}</router-link></p>
    <ErrorNotice :error="load.error.value" />

    <template v-if="session">
      <section class="card hi-section" data-test="session-info">
        <h2>{{ tx(xk('hx.type.', session.exam_type)) }} · {{ higher.programName(session.program_id) }}</h2>
        <dl class="hx-dl">
          <div><dt>{{ tx('hx.year') }}</dt><dd>{{ higher.yearName(session.academic_year_id) }}</dd></div>
          <div><dt>{{ tx('hx.status') }}</dt><dd><span class="tag tag--neutral">{{ tx(xk('hx.sessionState.', session.status)) }}</span></dd></div>
          <div><dt>{{ tx('hx.calendarRef') }}</dt><dd>{{ session.calendar_ref ?? '—' }}</dd></div>
          <div><dt>{{ tx('hx.deadline') }}</dt><dd>{{ session.registration_deadline ?? '—' }}</dd></div>
          <div><dt>{{ tx('hx.examStarts') }}</dt><dd>{{ session.exam_starts_on ?? '—' }}</dd></div>
          <div><dt>{{ tx('hx.fee') }}</dt><dd>{{ session.fee_xaf }}</dd></div>
          <div><dt>{{ tx('hx.pieces') }}</dt><dd>{{ session.required_pieces.join(', ') || '—' }}</dd></div>
        </dl>
        <p class="muted">{{ tx('hx.threshold', { from: session.attendance_from, to: session.attendance_to }) }}</p>
        <p v-if="afterExport" class="notice notice--warning" role="status" data-test="after-export">{{ tx('hx.afterExport') }}</p>
      </section>

      <section class="hi-section">
        <h2>{{ tx('hx.candidates') }}</h2>
        <form v-if="canManage" class="card hi-form" data-test="add-candidate" @submit.prevent="addCandidate">
          <PersonPicker id="xa-person" v-model="personId" :label="tx('hx.addPerson')" />
          <div class="hx-actions">
            <button type="submit" class="btn btn--primary" :disabled="add.busy.value || !personId">{{ tx('hx.add') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="add.busy.value || !personId" @click="check">{{ tx('hx.checkEligibility') }}</button>
            <button type="button" class="btn btn--quiet" :disabled="gen.busy.value" @click="generate">{{ tx('hx.generate') }}</button>
          </div>
          <div v-if="isNotEligible(add.error.value)" class="notice notice--danger" role="alert" data-test="not-eligible">
            <p>{{ tx('hx.notEligible') }}</p>
            <ul v-if="reasons.length > 0"><li v-for="r in reasons" :key="r">{{ r }}</li></ul>
          </div>
          <ErrorNotice v-else :error="add.error.value ?? gen.error.value" />
          <ul v-if="!isNotEligible(add.error.value) && reasons.length > 0" class="notice notice--warning" data-test="reasons"><li v-for="r in reasons" :key="r">{{ tx('hx.notEligibleHint', { reasons: r }) }}</li></ul>
          <p v-if="info" class="notice" role="status" data-test="info">{{ info }}</p>
        </form>
        <CandidatesPanel :session="session" :candidates="candidates" :can-manage="canManage" @changed="refresh" />
      </section>

      <ExportPanel :session-id="session.id" :can-export="canExport" :can-manage="canManage" @changed="refresh" />
      <ResultsPanel :session-id="session.id" :can-manage="canManage" :stats="stats" @changed="refresh" />

      <section v-if="events.length > 0" class="card hi-section" data-test="events">
        <h2>{{ tx('hx.journal') }}</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">{{ tx('hx.date') }}</th><th scope="col">{{ tx('hx.eventKind') }}</th><th scope="col">{{ tx('hx.alerted') }}</th></tr></thead>
            <tbody>
              <tr v-for="e in events" :key="e.id"><td>{{ e.created_at }}</td><td>{{ e.event_kind }}<template v-if="e.after_export"> ({{ tx('hx.afterExportFlag') }})</template></td><td class="num">{{ e.alerted_count }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <p v-if="canManage && session.status !== 'closed'">
        <button type="button" class="btn" :disabled="closing.busy.value" @click="closeSession">{{ tx('hx.close') }}</button>
      </p>
      <ErrorNotice :error="closing.error.value" />
    </template>
  </HigherFrame>
</template>

<style scoped>
.hx-dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0.5rem 1rem; margin: 0; }
.hx-dl dt { font-size: 0.85em; color: var(--muted, #666); }
.hx-dl dd { margin: 0; }
.hx-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
</style>
