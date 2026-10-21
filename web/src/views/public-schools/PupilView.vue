<script setup lang="ts">
// Dossier d'un élève : identité, scolarité, acte de naissance et situations particulières ; déclaration d'un abandon et demande
// de transfert. La consultation d'un dossier est journalisée par le serveur. Un élève radié ou transféré est en lecture seule.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, type PupilFile } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import { P } from './access';
import { useAction, usePermissions } from './usePs';

const props = defineProps<{ id: string }>();
const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const pupil = ref<PupilFile | null>(null);
const saved = ref(false);
const message = ref<'dropout' | 'transfer' | null>(null);
const profile = reactive({ actRef: '', verified: false, language: '', displaced: false, refugee: false, orphan: false, accommodation: '' });
const dropoutNote = ref('');
const targetId = ref('');
const canWrite = computed(() => can(...P.pupilsWrite) && pupil.value?.read_only === false);

function fill(p: PupilFile): void {
  const s = (p.special_situations ?? p) as Record<string, unknown>;
  profile.actRef = p.birth_act_ref ?? '';
  profile.verified = p.birth_act_verified;
  profile.language = typeof s.home_language === 'string' ? s.home_language : '';
  profile.displaced = s.is_displaced === true;
  profile.refugee = s.is_refugee === true;
  profile.orphan = s.is_orphan === true;
  profile.accommodation = typeof s.accommodation_type === 'string' ? s.accommodation_type : '';
}

async function load(): Promise<void> {
  const file = await run(() => publicSchools.pupil(props.id));
  if (file) {
    pupil.value = file;
    fill(file);
  }
}

async function save(): Promise<void> {
  saved.value = false;
  const updated = await run(() =>
    publicSchools.updatePupil(props.id, {
      birth_act_ref: profile.actRef.trim() || null,
      birth_act_verified: profile.verified,
      home_language: profile.language.trim() || null,
      is_displaced: profile.displaced,
      is_refugee: profile.refugee,
      is_orphan: profile.orphan,
      accommodation_type: profile.accommodation.trim() || null,
    }),
  );
  if (updated) {
    pupil.value = updated;
    saved.value = true;
  }
}

async function declareDropout(): Promise<void> {
  if (!window.confirm(t('ps.pupil.confirmDropout'))) return;
  if (await run(() => publicSchools.declareDropout(props.id, dropoutNote.value.trim() || undefined))) {
    message.value = 'dropout';
    dropoutNote.value = '';
  }
}

async function requestTransfer(): Promise<void> {
  if (await run(() => publicSchools.requestTransfer(props.id, targetId.value.trim()))) {
    message.value = 'transfer';
    targetId.value = '';
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.pupil.eyebrow') }}</p>
      <h1 v-if="pupil">{{ pupil.last_name.toUpperCase() }} {{ pupil.first_name }}</h1>
      <h1 v-else>{{ t('ps.pupil.title') }}</h1>
    </header>
    <ErrorNotice :error="error" />

    <template v-if="pupil">
      <section class="card" aria-labelledby="pf-id">
        <h2 id="pf-id">{{ t('ps.pupil.identity') }}</h2>
        <dl class="facts">
          <dt>{{ t('ps.pupils.number') }}</dt><dd>{{ pupil.learner_number ?? '—' }}</dd>
          <dt>{{ t('ps.enrol.birthDate') }}</dt><dd>{{ formatDate(pupil.birth_date) }}</dd>
          <dt>{{ t('ps.enrol.sex') }}</dt><dd>{{ pupil.sex === 'F' ? t('ps.enrol.female') : t('ps.enrol.male') }}</dd>
          <dt>{{ t('ps.pupils.registeredOn') }}</dt><dd>{{ formatDate(pupil.registered_on) }}</dd>
          <dt>{{ t('ps.pupil.status') }}</dt>
          <dd><span class="tag" :class="pupil.read_only ? 'tag--neutral' : 'tag--success'">{{ pupil.read_only ? t('ps.pupil.closed') : t('ps.pupil.active') }}</span></dd>
        </dl>
        <p v-if="pupil.read_only" class="muted">{{ t('ps.pupil.readOnly') }}</p>
      </section>

      <form v-if="canWrite" class="card form" @submit.prevent="save">
        <h2>{{ t('ps.pupil.profile') }}</h2>
        <div class="field">
          <label for="pf-act">{{ t('ps.enrol.actRef') }}</label>
          <input id="pf-act" v-model="profile.actRef" type="text" maxlength="80" />
        </div>
        <label class="check"><input v-model="profile.verified" type="checkbox" />{{ t('ps.pupil.actVerified') }}</label>
        <div class="field">
          <label for="pf-lang">{{ t('ps.pupil.language') }}</label>
          <input id="pf-lang" v-model="profile.language" type="text" maxlength="80" />
        </div>
        <fieldset class="field">
          <legend class="label">{{ t('ps.pupil.situations') }}</legend>
          <label class="check"><input v-model="profile.displaced" type="checkbox" />{{ t('ps.pupil.displaced') }}</label>
          <label class="check"><input v-model="profile.refugee" type="checkbox" />{{ t('ps.pupil.refugee') }}</label>
          <label class="check"><input v-model="profile.orphan" type="checkbox" />{{ t('ps.pupil.orphan') }}</label>
        </fieldset>
        <div class="field">
          <label for="pf-acc">{{ t('ps.pupil.accommodation') }}</label>
          <input id="pf-acc" v-model="profile.accommodation" type="text" maxlength="80" />
        </div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('ps.pupil.save') }}</button>
        <p v-if="saved" class="notice notice--success" role="status">{{ t('ps.pupil.saved') }}</p>
      </form>

      <section v-if="canWrite" class="card stack" aria-labelledby="pf-transfer">
        <h2 id="pf-transfer">{{ t('ps.pupil.transferTitle') }}</h2>
        <form class="form" @submit.prevent="requestTransfer">
          <div class="field">
            <label for="pf-target">{{ t('ps.pupil.targetSchool') }}</label>
            <input id="pf-target" v-model="targetId" type="text" autocomplete="off" aria-describedby="pf-target-hint" />
            <span id="pf-target-hint" class="hint">{{ t('ps.pupil.targetHint') }}</span>
          </div>
          <button type="submit" class="btn" :disabled="busy || targetId.trim() === ''">{{ t('ps.pupil.requestTransfer') }}</button>
        </form>
        <p v-if="message === 'transfer'" class="notice notice--success" role="status">{{ t('ps.pupil.transferRequested') }}</p>
      </section>

      <section v-if="canWrite" class="card stack" aria-labelledby="pf-dropout">
        <h2 id="pf-dropout">{{ t('ps.pupil.dropoutTitle') }}</h2>
        <form class="form" @submit.prevent="declareDropout">
          <div class="field">
            <label for="pf-note">{{ t('ps.pupil.dropoutNote') }}</label>
            <input id="pf-note" v-model="dropoutNote" type="text" maxlength="255" />
          </div>
          <button type="submit" class="btn" :disabled="busy">{{ t('ps.pupil.declareDropout') }}</button>
        </form>
        <p v-if="message === 'dropout'" class="notice notice--success" role="status">{{ t('ps.pupil.dropoutDeclared') }}</p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.facts { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
.facts dt { font-weight: 700; }
.facts dd { margin: 0; }
.form { display: grid; gap: 10px; max-width: 40rem; }
fieldset { border: 0; padding: 0; margin: 0; }
</style>
