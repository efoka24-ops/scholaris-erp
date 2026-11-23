<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { academic, type Authorization, type Program } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { expiryThreshold, k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Registre des autorisations MINESUP (EX-ACA-03, RG-ACA-1) avec alertes d'expiration à 90, 30 et 7 jours — design : DocRegistre.html.
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<Authorization[]>([]);
const expiring = ref<Authorization[]>([]);
const touched = ref(false);
const form = reactive({ program_id: '', decree_number: '', decree_date: '', authority: 'MINESUP', valid_from: '', valid_to: '', level_ids: [] as string[], document_id: '' });

const canManage = computed(() => ctx.can(...P.settingsManage) && !higher.readOnly);
const programLevels = computed(() => higher.levels.filter((l) => l.program_id === form.program_id).sort((a, b) => a.ordinal - b.ordinal));
const datesBad = computed(() => touched.value && (!form.valid_from || !form.valid_to || form.valid_to < form.valid_from));
const programs = computed<Program[]>(() => higher.programs);

const levelCodes = (ids: string[] | null | undefined): string => (ids && ids.length ? ids.map((id) => higher.levelName(id)).join(', ') : t('hi.auth.levelsAll'));

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'levels');
  const res = await list.run(async () => ({ all: await academic.authorizations(higher.scope()), soon: await academic.expiring(90) }));
  if (!res) return;
  items.value = res.all;
  const scoped = higher.scopedSchoolId;
  expiring.value = res.soon.filter((a) => !scoped || a.school_id === scoped).sort((a, b) => (a.days_left ?? 0) - (b.days_left ?? 0));
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.program_id || !form.decree_number.trim() || datesBad.value) return;
  const res = await save.run(() =>
    academic.createAuthorization({
      program_id: form.program_id,
      decree_number: form.decree_number.trim(),
      decree_date: form.decree_date || null,
      authority: form.authority.trim() || 'MINESUP',
      valid_from: form.valid_from,
      valid_to: form.valid_to,
      level_ids: form.level_ids.length ? [...form.level_ids] : null,
      document_id: form.document_id.trim() || null,
    }),
  );
  if (!res) return;
  Object.assign(form, { decree_number: '', decree_date: '', valid_from: '', valid_to: '', level_ids: [], document_id: '' });
  touched.value = false;
  await load();
}

async function remove(a: Authorization): Promise<void> {
  if (typeof window !== 'undefined' && !window.confirm(t('hi.auth.deleteConfirm'))) return;
  const res = await save.run(() => academic.deleteAuthorization(a.id).then(() => true));
  if (res) await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.auth.title')" :lede="t('hi.auth.lede')" section="academic" @school-change="load">
    <ErrorNotice :error="list.error.value" />

    <section class="card card--strong" aria-labelledby="alerts-title" data-test="expiry-alerts">
      <h2 id="alerts-title">{{ t('hi.auth.alerts') }}</h2>
      <p v-if="expiring.length === 0" class="muted">{{ t('hi.auth.alertsNone') }}</p>
      <ul v-else class="hi-list">
        <li v-for="a in expiring" :key="a.id">
          <span class="tag" :class="expiryThreshold(a.days_left) === 90 ? 'tag--neutral' : 'tag--warning'">{{ t(k('hi.auth.threshold.', String(expiryThreshold(a.days_left)))) }}</span>
          <span class="hi-grow">{{ t('hi.auth.alertLine', { program: higher.programName(a.program_id), decree: a.decree_number ?? '', date: formatDate(a.valid_to, locale), days: a.days_left ?? 0 }) }}</span>
        </li>
      </ul>
    </section>

    <section class="hi-section">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th scope="col">{{ t('hi.auth.decree') }}</th><th scope="col">{{ t('hi.program') }}</th><th scope="col">{{ t('hi.auth.levels') }}</th>
              <th scope="col">{{ t('hi.auth.validFrom') }}</th><th scope="col">{{ t('hi.auth.validTo') }}</th><th scope="col">{{ t('hi.status') }}</th><th v-if="canManage" scope="col">{{ t('hi.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in items" :key="a.id">
              <th scope="row">{{ a.decree_number ?? '—' }}<span v-if="a.authority" class="muted hi-small"> · {{ a.authority }}</span></th>
              <td>{{ higher.programName(a.program_id) }}</td>
              <td>{{ levelCodes(a.level_ids) }}</td>
              <td>{{ formatDate(a.valid_from, locale) }}</td>
              <td>{{ formatDate(a.valid_to, locale) }} <span v-if="a.status === 'valid' && expiryThreshold(a.days_left)" class="tag tag--warning">{{ t('hi.auth.daysLeft', { days: a.days_left ?? 0 }) }}</span></td>
              <td><span class="tag" :class="toneOf(a.status) ? `tag--${toneOf(a.status)}` : ''">{{ t(k('hi.auth.state.', a.status)) }}</span></td>
              <td v-if="canManage"><button type="button" class="hi-linkbtn" @click="remove(a)">{{ t('hi.delete') }}</button></td>
            </tr>
            <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <ErrorNotice :error="save.error.value" />
    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.auth.new') }}</h2>
      <div class="field">
<label for="a-prog">{{ t('hi.program') }}</label>
        <select id="a-prog" v-model="form.program_id" :aria-invalid="touched && !form.program_id"><option value="" disabled>{{ t('hi.programPick') }}</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <div class="field"><label for="a-decree">{{ t('hi.auth.decree') }}</label><input id="a-decree" v-model="form.decree_number" type="text" :aria-invalid="touched && !form.decree_number.trim()" /></div>
      <div class="field"><label for="a-ddate">{{ t('hi.auth.decreeDate') }}</label><input id="a-ddate" v-model="form.decree_date" type="date" /></div>
      <div class="field"><label for="a-auth">{{ t('hi.auth.authority') }}</label><input id="a-auth" v-model="form.authority" type="text" /></div>
      <div class="field"><label for="a-from">{{ t('hi.auth.validFrom') }}</label><input id="a-from" v-model="form.valid_from" type="date" :aria-invalid="datesBad" /></div>
      <div class="field"><label for="a-to">{{ t('hi.auth.validTo') }}</label><input id="a-to" v-model="form.valid_to" type="date" :aria-invalid="datesBad" /><span v-if="datesBad" class="error">{{ t('hi.datesBad') }}</span></div>
      <fieldset v-if="programLevels.length" class="field hi-wide">
        <legend class="label">{{ t('hi.auth.levels') }}</legend>
        <label v-for="l in programLevels" :key="l.id" class="check"><input v-model="form.level_ids" type="checkbox" :value="l.id" /> {{ l.code }} · {{ l.name }}</label>
        <span class="hint">{{ t('hi.auth.levelsNoneHint') }}</span>
      </fieldset>
      <div class="field hi-wide"><label for="a-doc">{{ t('hi.auth.document') }}</label><input id="a-doc" v-model="form.document_id" type="text" aria-describedby="a-doc-hint" /><span id="a-doc-hint" class="hint">{{ t('hi.auth.documentHint') }}</span></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.save') }}</button></div>
    </form>
  </HigherFrame>
</template>
