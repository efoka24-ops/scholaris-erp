<script setup lang="ts">
// Inscription d'un élève (RG-ELV) : identité, acte de naissance, classe, garants. Un doublon probable (409) arrête l'inscription :
// la personne reprend la fiche existante ou confirme, motif à l'appui, qu'il s'agit d'un autre enfant.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { ApiError } from '@/api/http';
import { publicSchools, type ClassRow, type Guardian, type PupilRegistered } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { todayIso } from '@/lib/format';
import { currentYear, useAction } from './usePs';

const { t } = useI18n();
const { error, busy, run } = useAction();

const classes = ref<ClassRow[]>([]);
const duplicates = ref<string[]>([]);
const confirmDifferent = ref(false);
const registered = ref<PupilRegistered | null>(null);
const clientUuid = ref(crypto.randomUUID());
const form = reactive({
  lastName: '',
  firstName: '',
  birthDate: '',
  sex: '' as '' | 'F' | 'M',
  actRef: '',
  classId: '',
  guardianKind: 'parent' as Guardian['kind'],
  guardianFirst: '',
  guardianLast: '',
  guardianPhone: '',
  reason: '',
});

const today = todayIso();
const valid = computed(
  () =>
    form.lastName.trim() !== '' &&
    form.firstName.trim() !== '' &&
    form.birthDate !== '' &&
    form.birthDate <= today &&
    form.sex !== '' &&
    (duplicates.value.length === 0 || (confirmDifferent.value && form.reason.trim() !== '')),
);

const warningKeys: Record<string, MessageKey> = {
  possible_duplicate: 'ps.enrol.warn.possible_duplicate',
  birth_act_missing: 'ps.enrol.warn.birth_act_missing',
  class_size_alert: 'ps.enrol.warn.class_size_alert',
  age_out_of_range: 'ps.enrol.warn.age_out_of_range',
  guardian_notification_failed: 'ps.enrol.warn.guardian_notification_failed',
};
const warningText = (code: string): string => (warningKeys[code] ? t(warningKeys[code]) : code);

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (year) classes.value = await publicSchools.classes(year.id);
  });
});

async function submit(): Promise<void> {
  error.value = null;
  const guardians: Guardian[] =
    form.guardianLast.trim() !== '' && form.guardianFirst.trim() !== ''
      ? [
          {
            kind: form.guardianKind,
            identity: {
              first_name: form.guardianFirst.trim(),
              last_name: form.guardianLast.trim(),
              ...(form.guardianPhone.trim() ? { phone: form.guardianPhone.trim() } : {}),
            },
          },
        ]
      : [];
  busy.value = true;
  try {
    registered.value = await publicSchools.registerPupil({
      last_name: form.lastName.trim(),
      first_name: form.firstName.trim(),
      birth_date: form.birthDate,
      sex: form.sex as 'F' | 'M',
      birth_act_ref: form.actRef.trim() || null,
      class_id: form.classId || null,
      guardians,
      client_uuid: clientUuid.value,
      ...(duplicates.value.length > 0 && confirmDifferent.value ? { confirm_different: true, override_reason: form.reason.trim() } : {}),
    });
    duplicates.value = [];
  } catch (e) {
    if (e instanceof ApiError && e.status === 409 && Array.isArray(e.problem?.possible_duplicates)) {
      duplicates.value = (e.problem.possible_duplicates as unknown[]).map(String);
    } else {
      error.value = e;
    }
  } finally {
    busy.value = false;
  }
}

function another(): void {
  registered.value = null;
  duplicates.value = [];
  confirmDifferent.value = false;
  clientUuid.value = crypto.randomUUID();
  Object.assign(form, { lastName: '', firstName: '', birthDate: '', sex: '', actRef: '', guardianFirst: '', guardianLast: '', guardianPhone: '', reason: '' });
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('ps.enrol.title') }}</h1>
      <p class="lede">{{ t('ps.enrol.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section v-if="registered" class="card stack" aria-labelledby="enrol-done" data-test="enrol-done">
      <h2 id="enrol-done">{{ t('ps.enrol.done') }}</h2>
      <p>
        <strong>{{ registered.last_name.toUpperCase() }} {{ registered.first_name }}</strong> — {{ t('ps.enrol.number') }} <code>{{ registered.learner_number }}</code>
      </p>
      <p v-if="registered.replayed" class="muted">{{ t('ps.enrol.replayed') }}</p>
      <ul v-if="registered.warnings.length > 0" class="notice notice--warning" role="status">
        <li v-for="w in registered.warnings" :key="w">{{ warningText(w) }}</li>
      </ul>
      <div class="row">
        <router-link class="btn btn--primary" :to="{ name: 'ps-pupil', params: { id: registered.person_id } }">{{ t('ps.enrol.openFile') }}</router-link>
        <button type="button" class="btn" @click="another">{{ t('ps.enrol.another') }}</button>
      </div>
    </section>

    <form v-else class="card form" novalidate @submit.prevent="submit">
      <fieldset :disabled="busy">
        <legend>{{ t('ps.enrol.pupil') }}</legend>
        <div class="field">
          <label for="en-last">{{ t('ps.enrol.lastName') }}</label>
          <input id="en-last" v-model="form.lastName" type="text" maxlength="120" required autocomplete="off" />
        </div>
        <div class="field">
          <label for="en-first">{{ t('ps.enrol.firstName') }}</label>
          <input id="en-first" v-model="form.firstName" type="text" maxlength="120" required autocomplete="off" />
        </div>
        <div class="field">
          <label for="en-birth">{{ t('ps.enrol.birthDate') }}</label>
          <input id="en-birth" v-model="form.birthDate" type="date" :max="today" required />
        </div>
        <fieldset class="field sex">
          <legend class="label">{{ t('ps.enrol.sex') }}</legend>
          <label class="check"><input v-model="form.sex" type="radio" name="en-sex" value="F" />{{ t('ps.enrol.female') }}</label>
          <label class="check"><input v-model="form.sex" type="radio" name="en-sex" value="M" />{{ t('ps.enrol.male') }}</label>
        </fieldset>
        <div class="field">
          <label for="en-act">{{ t('ps.enrol.actRef') }}</label>
          <input id="en-act" v-model="form.actRef" type="text" maxlength="80" aria-describedby="en-act-hint" />
          <span id="en-act-hint" class="hint">{{ t('ps.enrol.actHint') }}</span>
        </div>
        <div class="field">
          <label for="en-class">{{ t('ps.enrol.class') }}</label>
          <select id="en-class" v-model="form.classId">
            <option value="">{{ t('ps.enrol.noClass') }}</option>
            <option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option>
          </select>
        </div>
      </fieldset>

      <fieldset :disabled="busy">
        <legend>{{ t('ps.enrol.guardian') }}</legend>
        <div class="field">
          <label for="en-gkind">{{ t('ps.enrol.guardianKind') }}</label>
          <select id="en-gkind" v-model="form.guardianKind">
            <option value="parent">{{ t('ps.enrol.kind.parent') }}</option>
            <option value="guardian">{{ t('ps.enrol.kind.guardian') }}</option>
            <option value="emergency">{{ t('ps.enrol.kind.emergency') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="en-glast">{{ t('ps.enrol.lastName') }}</label>
          <input id="en-glast" v-model="form.guardianLast" type="text" autocomplete="off" />
        </div>
        <div class="field">
          <label for="en-gfirst">{{ t('ps.enrol.firstName') }}</label>
          <input id="en-gfirst" v-model="form.guardianFirst" type="text" autocomplete="off" />
        </div>
        <div class="field">
          <label for="en-gphone">{{ t('ps.enrol.phone') }}</label>
          <input id="en-gphone" v-model="form.guardianPhone" type="tel" inputmode="tel" autocomplete="off" />
        </div>
      </fieldset>

      <div v-if="duplicates.length > 0" class="notice notice--warning" role="alert" data-test="duplicates">
        <p><strong>{{ t('ps.enrol.duplicateTitle') }}</strong> {{ t('ps.enrol.duplicateText') }}</p>
        <ul>
          <li v-for="id in duplicates" :key="id">
            <router-link :to="{ name: 'ps-pupil', params: { id } }">{{ t('ps.enrol.duplicateOpen', { id }) }}</router-link>
          </li>
        </ul>
        <label class="check"><input v-model="confirmDifferent" type="checkbox" />{{ t('ps.enrol.confirmDifferent') }}</label>
        <div class="field">
          <label for="en-reason">{{ t('ps.enrol.overrideReason') }}</label>
          <input id="en-reason" v-model="form.reason" type="text" maxlength="500" :disabled="!confirmDifferent" />
        </div>
      </div>

      <button type="submit" class="btn btn--primary btn--large" :disabled="busy || !valid">{{ t('ps.enrol.submit') }}</button>
    </form>
  </div>
</template>

<style scoped>
.form { display: grid; gap: 16px; max-width: 40rem; }
fieldset { border: 0; padding: 0; margin: 0; }
legend { font-weight: 800; margin-bottom: 8px; }
.sex .check { display: inline-flex; margin-right: 1.5rem; }
</style>
