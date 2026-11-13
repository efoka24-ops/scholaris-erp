<script setup lang="ts">
// Fiche de carrière d'un enseignant (EX-PER-01 à EX-PER-03) : statut, matricule, contrat, diplôme et prise de service ; formations ;
// mutations dans le territoire. Le directeur voit et écrit tout ; la supervision ne lit que statut, grade et affectations (RG-PER-2),
// avec une finalité déclarée.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type PublicStatus, type StaffMove, type StaffProfile, type Training } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { P } from './access';
import PurposePicker from './PurposePicker.vue';
import SchoolPicker from './SchoolPicker.vue';
import { useAction, usePermissions } from './usePs';

const props = defineProps<{ id: string }>();
const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const canWrite = computed(() => can(...P.staffWrite));

const STATUSES: PublicStatus[] = ['civil_servant', 'contract', 'parents_teacher', 'volunteer'];
const profile = ref<StaffProfile | null>(null);
const trainings = ref<Training[]>([]);
const moves = ref<StaffMove[]>([]);
const saved = ref(false);
const message = ref('');
const form = reactive({ status: '' as PublicStatus | '', civil: '', contract: '', diploma: '', start: '' });
const training = reactive({ title: '', starts_on: '', ends_on: '', organiser: '' });
const move = reactive({ to: '', on: todayIso(), reason: '' });

/** Le directeur seul reçoit matricule, contrat, diplôme et prise de service : la clé absente = fiche réduite (supervision). */
const full = computed(() => profile.value !== null && 'civil_service_number' in profile.value);
const statusLabel = (s: PublicStatus | null): string => (s ? t(`ps.career.status.${s}` as 'ps.career.status.civil_servant') : t('ps.career.statusNone'));
const profileProblem = computed(() => {
  if (form.status === '') return t('ps.career.errStatus');
  if (form.status === 'civil_servant' && form.civil.trim() === '') return t('ps.career.errCivil');
  if (form.status === 'contract' && form.contract.trim() === '') return t('ps.career.errContract');
  return '';
});
const trainingProblem = computed(() => {
  if (training.title.trim() === '' || training.starts_on === '') return t('ps.career.errTraining');
  if (training.ends_on !== '' && training.ends_on < training.starts_on) return t('ps.career.errTrainingDates');
  return '';
});

function fill(p: StaffProfile): void {
  profile.value = p;
  form.status = p.public_status ?? '';
  form.civil = p.civil_service_number ?? '';
  form.contract = p.contract_number ?? '';
  form.diploma = p.professional_diploma ?? '';
  form.start = p.service_start_on ?? '';
}

async function load(): Promise<void> {
  await run(async () => {
    fill(await publicSchools.staffProfile(props.id));
    moves.value = await publicSchools.staffMoves(props.id);
    // Les formations relèvent de la carrière : le serveur les réserve au directeur (403 pour la supervision).
    trainings.value = canWrite.value ? await publicSchools.trainings(props.id) : [];
  });
}

const optional = (v: string): string | null => (v.trim() === '' ? null : v.trim());

async function saveProfile(): Promise<void> {
  if (profileProblem.value !== '' || form.status === '') return;
  saved.value = false;
  const body = {
    public_status: form.status,
    civil_service_number: optional(form.civil),
    contract_number: optional(form.contract),
    professional_diploma: optional(form.diploma),
    service_start_on: optional(form.start),
  };
  const updated = await run(() => publicSchools.saveStaffProfile(props.id, body));
  if (updated) {
    fill(updated);
    saved.value = true;
  }
}

async function addTraining(): Promise<void> {
  if (trainingProblem.value !== '') return;
  const created = await run(() =>
    publicSchools.addTraining(props.id, {
      title: training.title.trim(),
      starts_on: training.starts_on,
      ends_on: optional(training.ends_on),
      organiser: optional(training.organiser),
    }),
  );
  if (created) {
    trainings.value = [created, ...trainings.value];
    Object.assign(training, { title: '', starts_on: '', ends_on: '', organiser: '' });
  }
}

async function moveStaff(): Promise<void> {
  if (move.to === '' || move.on === '') return;
  if (!window.confirm(t('ps.career.confirmMove'))) return;
  const done = await run(() => publicSchools.moveStaff(props.id, { to_establishment_id: move.to, effective_on: move.on, reason: optional(move.reason) }));
  if (done) {
    moves.value = [done, ...moves.value];
    move.to = '';
    move.reason = '';
    message.value = t('ps.career.moved');
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.career.title') }}</h1>
      <p><router-link :to="{ name: 'ps-staff' }">{{ t('ps.career.back') }}</router-link></p>
    </header>
    <PurposePicker id="sp-purpose" />
    <ErrorNotice :error="error" />

    <template v-if="profile">
      <section class="card stack" aria-labelledby="sp-profile">
        <h2 id="sp-profile">{{ t('ps.career.profile') }}</h2>
        <dl class="facts">
          <dt>{{ t('ps.career.grade') }}</dt><dd>{{ profile.grade ?? '—' }}</dd>
          <dt>{{ t('ps.career.employment') }}</dt><dd>{{ profile.employment ?? '—' }}</dd>
          <dt>{{ t('ps.career.status') }}</dt><dd>{{ statusLabel(profile.public_status) }}</dd>
        </dl>
        <p v-if="!full" class="muted">{{ t('ps.career.reduced') }}</p>

        <form v-if="canWrite && full" class="form" @submit.prevent="saveProfile">
          <div class="field">
            <label for="sp-status">{{ t('ps.career.status') }}</label>
            <select id="sp-status" v-model="form.status" required>
              <option value="" disabled>{{ t('ps.career.statusPick') }}</option>
              <option v-for="s in STATUSES" :key="s" :value="s">{{ statusLabel(s) }}</option>
            </select>
          </div>
          <div class="field">
            <label for="sp-civil">{{ t('ps.career.civil') }}</label>
            <input id="sp-civil" v-model="form.civil" type="text" maxlength="40" autocomplete="off" />
          </div>
          <div class="field">
            <label for="sp-contract">{{ t('ps.career.contract') }}</label>
            <input id="sp-contract" v-model="form.contract" type="text" maxlength="40" autocomplete="off" />
          </div>
          <div class="field">
            <label for="sp-diploma">{{ t('ps.career.diploma') }}</label>
            <input id="sp-diploma" v-model="form.diploma" type="text" maxlength="80" />
          </div>
          <div class="field">
            <label for="sp-start">{{ t('ps.career.start') }}</label>
            <input id="sp-start" v-model="form.start" type="date" />
          </div>
          <p v-if="profileProblem" class="notice notice--warning" role="status">{{ profileProblem }}</p>
          <button type="submit" class="btn btn--primary" :disabled="busy || profileProblem !== ''">{{ t('ps.career.save') }}</button>
          <p v-if="saved" class="notice notice--success" role="status">{{ t('ps.career.saved') }}</p>
        </form>
        <dl v-else-if="full" class="facts">
          <dt>{{ t('ps.career.civil') }}</dt><dd>{{ profile.civil_service_number ?? '—' }}</dd>
          <dt>{{ t('ps.career.contract') }}</dt><dd>{{ profile.contract_number ?? '—' }}</dd>
          <dt>{{ t('ps.career.diploma') }}</dt><dd>{{ profile.professional_diploma ?? '—' }}</dd>
          <dt>{{ t('ps.career.start') }}</dt><dd>{{ profile.service_start_on ? formatDate(profile.service_start_on) : '—' }}</dd>
        </dl>
      </section>

      <section v-if="canWrite" class="card stack" aria-labelledby="sp-trainings">
        <h2 id="sp-trainings">{{ t('ps.career.trainings') }}</h2>
        <p v-if="trainings.length === 0" class="muted">{{ t('ps.career.trainingsNone') }}</p>
        <ul v-else>
          <li v-for="tr in trainings" :key="tr.id">
            <strong>{{ tr.title }}</strong> · {{ formatDate(tr.starts_on) }}<template v-if="tr.ends_on"> → {{ formatDate(tr.ends_on) }}</template>
            <template v-if="tr.organiser"> · {{ tr.organiser }}</template>
          </li>
        </ul>
        <form class="form" @submit.prevent="addTraining">
          <h3>{{ t('ps.career.addTraining') }}</h3>
          <div class="field">
            <label for="sp-t-title">{{ t('ps.career.trainingTitle') }}</label>
            <input id="sp-t-title" v-model="training.title" type="text" maxlength="160" />
          </div>
          <div class="field">
            <label for="sp-t-from">{{ t('ps.career.trainingFrom') }}</label>
            <input id="sp-t-from" v-model="training.starts_on" type="date" />
          </div>
          <div class="field">
            <label for="sp-t-to">{{ t('ps.career.trainingTo') }}</label>
            <input id="sp-t-to" v-model="training.ends_on" type="date" />
          </div>
          <div class="field">
            <label for="sp-t-org">{{ t('ps.career.organiser') }}</label>
            <input id="sp-t-org" v-model="training.organiser" type="text" maxlength="160" />
          </div>
          <p v-if="trainingProblem && (training.title || training.starts_on)" class="notice notice--warning" role="status">{{ trainingProblem }}</p>
          <button type="submit" class="btn" :disabled="busy || trainingProblem !== ''">{{ t('ps.career.addTrainingGo') }}</button>
        </form>
      </section>

      <section class="card stack" aria-labelledby="sp-moves">
        <h2 id="sp-moves">{{ t('ps.career.moves') }}</h2>
        <p v-if="moves.length === 0" class="muted">{{ t('ps.career.movesNone') }}</p>
        <ul v-else>
          <li v-for="m in moves" :key="m.id">
            {{ formatDate(m.effective_on) }} · {{ t('ps.career.moveLine', { from: m.from_establishment_id, to: m.to_establishment_id }) }}<template v-if="m.reason"> · {{ m.reason }}</template>
          </li>
        </ul>
        <p v-if="message" class="notice notice--success" role="status">{{ message }}</p>
        <form v-if="canWrite" class="form" @submit.prevent="moveStaff">
          <h3>{{ t('ps.career.moveTitle') }}</h3>
          <SchoolPicker id="sp-move" v-model="move.to" />
          <div class="field">
            <label for="sp-move-on">{{ t('ps.career.moveOn') }}</label>
            <input id="sp-move-on" v-model="move.on" type="date" required />
          </div>
          <div class="field">
            <label for="sp-move-reason">{{ t('ps.career.moveReason') }}</label>
            <input id="sp-move-reason" v-model="move.reason" type="text" maxlength="255" />
          </div>
          <button type="submit" class="btn" :disabled="busy || move.to === '' || move.on === ''">{{ t('ps.career.moveGo') }}</button>
        </form>
      </section>
    </template>
  </div>
</template>

<style scoped>
.facts { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
.facts dt { font-weight: 700; }
.facts dd { margin: 0; }
.form { display: grid; gap: 10px; max-width: 36rem; }
</style>
