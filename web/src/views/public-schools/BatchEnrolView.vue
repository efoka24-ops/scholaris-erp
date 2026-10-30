<script setup lang="ts">
// Inscription groupée depuis un registre papier (EX-ELV-07) : le directeur ou l'agent de saisie de l'inspection recopie les lignes du
// registre (photo du registre jointe par sa référence) ; chaque ligne suit l'inscription normale (doublons, identifiant, acte
// facultatif) et une ligne refusée n'empêche pas les autres. Le lot est envoyé une seule fois (identifiant client conservé).
import { onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { publicSchools, publicSchoolsP2, type ClassRow, type RegistrationBatch } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { ulid } from '@/lib/ulid';
import { currentYear, useAction } from './usePs';

const { t } = useI18n();
const { error, busy, run } = useAction();

interface Line {
  last_name: string;
  first_name: string;
  birth_date: string;
  sex: 'F' | 'M' | '';
  birth_act_ref: string;
  guardian: string;
  phone: string;
}
const blank = (): Line => ({ last_name: '', first_name: '', birth_date: '', sex: '', birth_act_ref: '', guardian: '', phone: '' });
const classes = ref<ClassRow[]>([]);
const classId = ref('');
const photoRef = ref('');
const registerLabel = ref('');
const lines = ref<Line[]>([blank()]);
const clientUuid = ref(ulid());
const result = ref<RegistrationBatch | null>(null);
const history = ref<RegistrationBatch[]>([]);

onMounted(async () => {
  await run(async () => {
    const year = await currentYear();
    if (year) classes.value = await publicSchools.classes(year.id);
    history.value = await publicSchoolsP2.registrationBatches();
  });
});

function add(): void {
  lines.value = [...lines.value, blank()];
}
function remove(index: number): void {
  lines.value = lines.value.filter((_, i) => i !== index);
}

async function send(): Promise<void> {
  const rows = lines.value
    .filter((l) => l.last_name.trim() !== '')
    .map((l) => ({
      last_name: l.last_name.trim(),
      first_name: l.first_name.trim(),
      birth_date: l.birth_date,
      sex: l.sex,
      ...(l.birth_act_ref.trim() !== '' ? { birth_act_ref: l.birth_act_ref.trim() } : {}),
      guardians: l.guardian.trim() !== '' ? [{ kind: 'parent', identity: { first_name: l.guardian.trim(), last_name: l.last_name.trim(), phone: l.phone.trim() } }] : [],
    }));
  await run(async () => {
    result.value = await publicSchoolsP2.registerBatch({
      client_uuid: clientUuid.value,
      register_photo_ref: photoRef.value || undefined,
      register_label: registerLabel.value || undefined,
      class_id: classId.value || undefined,
      rows,
    });
    clientUuid.value = ulid();
    lines.value = [blank()];
    history.value = await publicSchoolsP2.registrationBatches();
  });
}

const statusLabel = (s: string): string => t(s === 'created' ? 'pp.batch.created' : s === 'replayed' ? 'pp.batch.replayed' : s === 'duplicate' ? 'pp.batch.duplicate' : 'pp.batch.rejected');
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.batch.title') }}</h1>
      <p class="lede">{{ t('pp.batch.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <form class="card controls" @submit.prevent="send">
      <div class="field">
        <label for="be-class">{{ t('pp.common.class') }}</label>
        <select id="be-class" v-model="classId"><option value="">—</option><option v-for="c in classes" :key="c.structure_id" :value="c.structure_id">{{ c.label }}</option></select>
      </div>
      <div class="field"><label for="be-photo">{{ t('pp.batch.photo') }}</label><input id="be-photo" v-model="photoRef" type="text" maxlength="255" /></div>
      <div class="field"><label for="be-label">{{ t('pp.batch.label') }}</label><input id="be-label" v-model="registerLabel" type="text" maxlength="160" /></div>
      <fieldset v-for="(l, i) in lines" :key="i" class="line">
        <legend>{{ t('pp.batch.line', { n: i + 1 }) }}</legend>
        <div class="field"><label :for="`bl-${i}`">{{ t('ps.enrol.lastName') }}</label><input :id="`bl-${i}`" v-model="l.last_name" type="text" maxlength="120" /></div>
        <div class="field"><label :for="`bf-${i}`">{{ t('ps.enrol.firstName') }}</label><input :id="`bf-${i}`" v-model="l.first_name" type="text" maxlength="120" /></div>
        <div class="field"><label :for="`bd-${i}`">{{ t('ps.enrol.birthDate') }}</label><input :id="`bd-${i}`" v-model="l.birth_date" type="date" /></div>
        <div class="field">
          <label :for="`bs-${i}`">{{ t('ps.enrol.sex') }}</label>
          <select :id="`bs-${i}`" v-model="l.sex"><option value="">—</option><option value="F">F</option><option value="M">M</option></select>
        </div>
        <div class="field"><label :for="`ba-${i}`">{{ t('ps.enrol.actRef') }}</label><input :id="`ba-${i}`" v-model="l.birth_act_ref" type="text" maxlength="80" /></div>
        <div class="field"><label :for="`bg-${i}`">{{ t('pp.batch.guardian') }}</label><input :id="`bg-${i}`" v-model="l.guardian" type="text" maxlength="120" /></div>
        <div class="field"><label :for="`bp-${i}`">{{ t('pp.batch.phone') }}</label><input :id="`bp-${i}`" v-model="l.phone" type="tel" maxlength="20" /></div>
        <button v-if="lines.length > 1" type="button" class="btn btn--small" @click="remove(i)">{{ t('pp.batch.remove') }}</button>
      </fieldset>
      <button type="button" class="btn" @click="add">{{ t('pp.batch.add') }}</button>
      <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.batch.send') }}</button>
    </form>

    <section v-if="result" class="card" aria-labelledby="be-res" role="status">
      <h2 id="be-res">{{ t('pp.batch.result', { created: result.created_count, total: result.rows_count }) }}</h2>
      <ul>
        <li v-for="r in result.results" :key="r.row">
          {{ t('pp.batch.line', { n: r.row + 1 }) }} : {{ statusLabel(r.status) }}
          <span v-if="r.learner_number"> · {{ r.learner_number }}</span>
          <span v-if="r.message && r.status !== 'created'" class="muted"> — {{ r.message }}</span>
        </li>
      </ul>
    </section>

    <section v-if="history.length > 0" class="card" aria-labelledby="be-hist">
      <h2 id="be-hist">{{ t('pp.batch.history') }}</h2>
      <ul><li v-for="h in history" :key="h.id">{{ h.register_label ?? h.client_uuid }} · {{ h.created_count }} / {{ h.rows_count }}<span v-if="h.entered_by_proxy"> · {{ t('pp.census.proxy') }}</span></li></ul>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.line { border: 1px solid var(--input-border); padding: 8px; display: grid; gap: 6px; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); }
</style>
