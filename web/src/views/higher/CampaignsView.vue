<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { admissions, parseXaf, SELECTION_MODES, type Campaign, type SelectionMode } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Campagnes d'admission (EX-ADM-01) : créer, ouvrir, clore, annuler — design : InscriptionPieces.html (places, état « Autorisée »).
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Campaign[]>([]);
const statusFilter = ref('');
const cancelling = ref<Campaign | null>(null);
const cancelReason = ref('');
const cancelTried = ref(false);
const touched = ref(false);
const form = reactive({ program_id: '', level_id: '', academic_year_id: '', title: '', opens_on: '', closes_on: '', seats: 60, fee: '10000', selection_mode: 'file' as SelectionMode });

const canManage = computed(() => ctx.can(...P.applicationManage) && !higher.readOnly);
const formLevels = computed(() => higher.levels.filter((l) => l.program_id === form.program_id).sort((a, b) => a.ordinal - b.ordinal));
const datesBad = computed(() => touched.value && (!form.opens_on || !form.closes_on || form.closes_on < form.opens_on));
const feeBad = computed(() => touched.value && form.fee.trim() !== '0' && parseXaf(form.fee) === null);

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  if (statusFilter.value) q['filter[status]'] = statusFilter.value;
  const res = await list.run(() => admissions.campaigns(q));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.level_id || !form.academic_year_id || datesBad.value || feeBad.value || !(Number(form.seats) >= 1)) return;
  const res = await act.run(() =>
    admissions.createCampaign({
      level_id: form.level_id,
      academic_year_id: form.academic_year_id,
      ...(form.title.trim() ? { title: form.title.trim() } : {}),
      opens_on: form.opens_on,
      closes_on: form.closes_on,
      seats: Number(form.seats),
      application_fee_xaf: form.fee.trim() === '0' ? 0 : (parseXaf(form.fee) ?? 0),
      selection_mode: form.selection_mode,
    }),
  );
  if (!res) return;
  touched.value = false;
  Object.assign(form, { title: '', opens_on: '', closes_on: '' });
  await load();
}

async function transition(c: Campaign, kind: 'open' | 'close'): Promise<void> {
  const res = await act.run(() => (kind === 'open' ? admissions.openCampaign(c.id) : admissions.closeCampaign(c.id)));
  if (res) await load();
}

async function confirmCancel(): Promise<void> {
  cancelTried.value = true;
  const c = cancelling.value;
  if (!c || !cancelReason.value.trim()) return;
  const res = await act.run(() => admissions.cancelCampaign(c.id, cancelReason.value.trim()));
  if (!res) return;
  cancelling.value = null;
  cancelReason.value = '';
  cancelTried.value = false;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.adm.title')" :lede="t('hi.adm.lede')" section="admissions" @school-change="load">
    <div class="hi-form">
      <div class="field">
<label for="cp-status">{{ t('hi.status') }}</label>
        <select id="cp-status" v-model="statusFilter" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in ['draft', 'open', 'closed', 'cancelled']" :key="s" :value="s">{{ t(k('hi.adm.state.', s)) }}</option></select>
      </div>
    </div>
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ t('hi.adm.campaign') }}</th><th scope="col">{{ t('hi.level') }}</th><th scope="col">{{ t('hi.adm.opensOn') }}</th><th scope="col">{{ t('hi.adm.closesOn') }}</th>
            <th scope="col" class="num">{{ t('hi.adm.seats') }}</th><th scope="col" class="num">{{ t('hi.adm.fee') }}</th><th scope="col">{{ t('hi.adm.mode') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <th scope="row"><router-link :to="{ name: 'higher-campaign', params: { id: c.id } }">{{ c.title || higher.programName(c.program_id) }}</router-link></th>
            <td>{{ higher.levelName(c.level_id) }} · {{ higher.yearName(c.academic_year_id) }}</td>
            <td>{{ formatDate(c.opens_on, locale) }}</td><td>{{ formatDate(c.closes_on, locale) }}</td>
            <td class="num">{{ c.seats }}</td><td class="num">{{ formatXaf(c.application_fee_xaf, locale) }}</td>
            <td>{{ t(k('hi.adm.mode.', c.selection_mode)) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(c.status) ?? 'neutral'}`">{{ t(k('hi.adm.state.', c.status)) }}</span></td>
            <td class="hi-actions">
              <template v-if="canManage">
                <button v-if="c.status === 'draft' || c.status === 'closed'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="transition(c, 'open')">{{ t('hi.adm.openAction') }}</button>
                <button v-if="c.status === 'open'" type="button" class="hi-linkbtn" :disabled="act.busy.value" @click="transition(c, 'close')">{{ t('hi.adm.closeAction') }}</button>
                <button v-if="c.status !== 'cancelled'" type="button" class="hi-linkbtn" @click="cancelling = c">{{ t('hi.adm.cancelAction') }}</button>
              </template>
            </td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="9" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="cancelling" class="card card--strong hi-section" role="dialog" aria-labelledby="cancel-title" data-test="cancel-campaign">
      <h2 id="cancel-title">{{ t('hi.adm.cancelAction') }} · {{ cancelling.title || higher.programName(cancelling.program_id) }}</h2>
      <p>{{ t('hi.adm.cancelText') }}</p>
      <div class="field"><label for="cancel-reason">{{ t('hi.reason') }}</label><textarea id="cancel-reason" v-model="cancelReason" :aria-invalid="cancelTried && !cancelReason.trim()" /><span v-if="cancelTried && !cancelReason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
      <div class="hi-actions"><button type="button" class="btn btn--ink" :disabled="act.busy.value" @click="confirmCancel">{{ t('hi.confirm') }}</button><button type="button" class="btn btn--quiet" @click="cancelling = null">{{ t('hi.back') }}</button></div>
    </section>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.adm.new') }}</h2>
      <p class="muted hi-small hi-wide">{{ t('hi.adm.docsHint') }}</p>
      <div class="field">
<label for="cn-prog">{{ t('hi.program') }}</label>
        <select id="cn-prog" v-model="form.program_id" @change="form.level_id = ''"><option value="" disabled>{{ t('hi.programPick') }}</option><option v-for="p in higher.programs" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option></select>
      </div>
      <div class="field">
<label for="cn-level">{{ t('hi.level') }}</label>
        <select id="cn-level" v-model="form.level_id" :aria-invalid="touched && !form.level_id"><option value="" disabled>{{ t('hi.levelPick') }}</option><option v-for="l in formLevels" :key="l.id" :value="l.id">{{ l.code }} · {{ l.name }}</option></select>
        <span v-if="touched && !form.level_id" class="error">{{ t('hi.required') }}</span>
      </div>
      <YearField id="cn-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><label for="cn-title">{{ t('hi.cur.titleField') }}</label><input id="cn-title" v-model="form.title" type="text" /></div>
      <div class="field"><label for="cn-open">{{ t('hi.adm.opensOn') }}</label><input id="cn-open" v-model="form.opens_on" type="date" :aria-invalid="datesBad" /></div>
      <div class="field"><label for="cn-close">{{ t('hi.adm.closesOn') }}</label><input id="cn-close" v-model="form.closes_on" type="date" :aria-invalid="datesBad" /><span v-if="datesBad" class="error">{{ t('hi.datesBad') }}</span></div>
      <div class="field"><label for="cn-seats">{{ t('hi.adm.seats') }}</label><input id="cn-seats" v-model.number="form.seats" type="number" min="1" /></div>
      <div class="field"><label for="cn-fee">{{ t('hi.adm.fee') }}</label><input id="cn-fee" v-model="form.fee" type="text" inputmode="numeric" :aria-invalid="feeBad" aria-describedby="cn-fee-hint" /><span id="cn-fee-hint" class="hint">{{ t('hi.adm.feeHint') }}</span><span v-if="feeBad" class="error">{{ t('hi.amountBad') }}</span></div>
      <div class="field"><label for="cn-mode">{{ t('hi.adm.mode') }}</label><select id="cn-mode" v-model="form.selection_mode"><option v-for="m in SELECTION_MODES" :key="m" :value="m">{{ t(k('hi.adm.mode.', m)) }}</option></select></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
