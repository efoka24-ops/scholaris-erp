<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { academic, isSecondFactorRequired, REGIMES, type Regime, type Regulation, type RegulationRevision } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import YearField from './YearField.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useConfirmation } from '@/views/account/useConfirmation';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Règlement des études (EX-ACA-05, RG-ACA-3) : brouillon modifiable, gel, modification d'un règlement figé avec confirmation,
// motif et double authentification récente (403 second-factor-required : ressaisie du code, puis nouvel envoi par la personne).
type ParamValue = number | boolean | string | null;
const { t, locale } = useI18n();
const confirmation = useConfirmation();
const ctx = useContextStore();
const higher = useHigherStore();
const router = useRouter();
const route = useRoute();
const list = useHigherAction();
const save = useHigherAction();

const items = ref<Regulation[]>([]);
const selected = ref<Regulation | null>(null);
const revisions = ref<RegulationRevision[]>([]);
const draft = ref<Record<string, string>>({});
const reason = ref('');
const confirmed = ref(false);
const tried = ref(false);
const needsTwoFactor = ref(false);
const touched = ref(false);
const form = reactive({ school_id: '', regime: 'lmd' as Regime, academic_year_id: '' });

const canManage = computed(() => ctx.can(...P.settingsManage) && !higher.readOnly);
const canAmend = computed(() => ctx.can(...P.regulationAmend) && !higher.readOnly);
const frozen = computed(() => selected.value?.status === 'frozen');
const editable = computed(() => (frozen.value ? canAmend.value : canManage.value));

const show = (v: ParamValue | undefined): string => (v === true ? t('hi.yes') : v === false ? t('hi.no') : v === null || v === undefined ? '—' : String(v));
const toInput = (v: ParamValue | undefined): string => (typeof v === 'boolean' ? (v ? 'true' : 'false') : v === null || v === undefined ? '' : String(v));

function parse(raw: string, original: ParamValue | undefined): ParamValue | undefined {
  const s = raw.trim();
  if (typeof original === 'boolean') return s === 'true' ? true : s === 'false' ? false : undefined;
  if (s === '') return null;
  if (typeof original === 'number' || /^-?\d+(\.\d+)?$/.test(s)) {
    const n = Number(s.replace(',', '.'));
    return Number.isFinite(n) ? n : undefined;
  }
  return s;
}
const changes = computed<Record<string, ParamValue> | null>(() => {
  if (!selected.value) return null;
  const out: Record<string, ParamValue> = {};
  for (const [key, raw] of Object.entries(draft.value)) {
    const original = selected.value.parameters[key];
    const v = parse(raw, original);
    if (v === undefined) return null;
    if (v !== original) out[key] = v;
  }
  return out;
});

async function load(): Promise<void> {
  await higher.loadRefs('schools', 'years');
  const res = await list.run(() => academic.regulations(higher.scope()));
  if (res) items.value = res;
  if (!form.school_id && higher.schools[0]) form.school_id = higher.schools[0].id;
}

async function select(r: Regulation): Promise<void> {
  selected.value = r;
  draft.value = Object.fromEntries(Object.entries(r.parameters).map(([key, v]) => [key, toInput(v)]));
  reason.value = '';
  confirmed.value = false;
  tried.value = false;
  needsTwoFactor.value = false;
  revisions.value = [];
  if (r.status === 'frozen') {
    const res = await list.run(() => academic.revisions(r.id));
    if (res) revisions.value = res;
  }
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.school_id || !form.academic_year_id) return;
  const res = await save.run(() => academic.createRegulation({ ...form }));
  if (!res) return;
  touched.value = false;
  await load();
  await select(res);
}

async function saveDraft(): Promise<void> {
  const r = selected.value;
  const c = changes.value;
  if (!r || !c) return;
  const res = await save.run(() => academic.updateRegulation(r.id, { parameters: c }));
  if (res) await refresh(res);
}

async function freeze(): Promise<void> {
  const r = selected.value;
  if (!r || (typeof window !== 'undefined' && !window.confirm(t('hi.reg.freezeConfirm')))) return;
  const res = await save.run(() => academic.freezeRegulation(r.id));
  if (res) await refresh(res);
}

async function amend(): Promise<void> {
  const r = selected.value;
  const c = changes.value;
  tried.value = true;
  needsTwoFactor.value = false;
  if (!r || !c || !confirmed.value || reason.value.trim() === '') return;
  const res = await save.run(() => academic.amendRegulation(r.id, { parameters: c, reason: reason.value.trim() }));
  if (res) {
    await refresh(res);
    return;
  }
  if (isSecondFactorRequired(save.error.value)) {
    needsTwoFactor.value = true;
    save.error.value = null;
  }
}

async function revalidate(): Promise<void> {
  await router.push({ name: 'twofactor', query: { redirect: route.fullPath } });
}

async function refresh(r: Regulation): Promise<void> {
  await load();
  await select(items.value.find((x) => x.id === r.id) ?? r);
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.reg.title')" :lede="t('hi.reg.lede')" section="academic" @school-change="load">
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.school.label') }}</th><th scope="col">{{ t('hi.aca.regime') }}</th><th scope="col">{{ t('hi.year') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in items" :key="r.id" :aria-current="selected?.id === r.id ? 'true' : undefined">
            <th scope="row">{{ higher.schoolName(r.school_id) }}</th>
            <td>{{ t(k('hi.regime.', r.regime)) }}</td>
            <td>{{ higher.yearName(r.academic_year_id) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(r.status) ?? 'neutral'}`">{{ t(k('hi.reg.state.', r.status)) }}</span> {{ t('hi.reg.version', { version: r.version }) }}</td>
            <td><button type="button" class="hi-linkbtn" @click="select(r)">{{ t('hi.open') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="5" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <section v-if="selected" class="card hi-section" data-test="regulation">
      <div class="row row--between">
        <h2>{{ higher.schoolName(selected.school_id) }} · {{ t(k('hi.regime.', selected.regime)) }} · {{ higher.yearName(selected.academic_year_id) }}</h2>
        <span class="tag" :class="`tag--${toneOf(selected.status) ?? 'neutral'}`">{{ t(k('hi.reg.state.', selected.status)) }} · {{ t('hi.reg.version', { version: selected.version }) }}</span>
      </div>
      <h3>{{ t('hi.reg.parameters') }}</h3>
      <div class="hi-form">
        <div v-for="(value, key) in selected.parameters" :key="key" class="field">
          <label :for="`param-${key}`" class="hi-mono">{{ key }}</label>
          <select v-if="typeof value === 'boolean'" :id="`param-${key}`" v-model="draft[key]" :disabled="!editable">
            <option value="true">{{ t('hi.yes') }}</option>
            <option value="false">{{ t('hi.no') }}</option>
          </select>
          <input v-else :id="`param-${key}`" v-model="draft[key]" type="text" inputmode="decimal" :disabled="!editable" :aria-invalid="parse(draft[key] ?? '', value) === undefined" />
          <span v-if="!editable" class="hint">{{ show(value) }}</span>
        </div>
      </div>
      <p v-if="changes === null" class="error">{{ t('hi.reg.paramBad') }}</p>

      <div v-if="!frozen" class="hi-actions">
        <button v-if="canManage" type="button" class="btn btn--primary" :disabled="save.busy.value || !changes || Object.keys(changes).length === 0" @click="saveDraft">{{ t('hi.save') }}</button>
        <button v-if="canManage" type="button" class="btn btn--ink" :disabled="save.busy.value" @click="freeze">{{ t('hi.reg.freeze') }}</button>
      </div>

      <div v-else-if="canAmend" class="card card--strong hi-section" data-test="amend">
        <h3>{{ t('hi.reg.amendTitle') }}</h3>
        <p>{{ t('hi.reg.amendText', { version: selected.version + 1 }) }}</p>
        <div class="field"><label for="amend-reason">{{ t('hi.reason') }}</label><textarea id="amend-reason" v-model="reason" maxlength="500" :aria-invalid="tried && !reason.trim()" aria-describedby="amend-reason-hint" /><span id="amend-reason-hint" class="hint">{{ t('hi.reasonHint') }}</span><span v-if="tried && !reason.trim()" class="error">{{ t('hi.reasonRequired') }}</span></div>
        <label class="check"><input v-model="confirmed" type="checkbox" /> {{ t('hi.reg.amendConfirm') }}</label>
        <p v-if="tried && !confirmed" class="error">{{ t('hi.reg.amendConfirmRequired') }}</p>
        <div v-if="needsTwoFactor" class="notice notice--warning" role="alert" data-test="tfa">
          <p><strong>{{ confirmation.title(t('hi.reg.tfaTitle')) }}</strong></p>
          <p>{{ confirmation.text(t('hi.reg.tfaText')) }}</p>
          <button type="button" class="btn btn--ink" @click="revalidate">{{ confirmation.go(t('hi.reg.tfaGo')) }}</button>
        </div>
        <button type="button" class="btn btn--primary" :disabled="save.busy.value || !changes || Object.keys(changes).length === 0" @click="amend">{{ t('hi.reg.amend') }}</button>
      </div>
      <ErrorNotice :error="save.error.value" />

      <template v-if="frozen">
        <h3 class="hi-section">{{ t('hi.reg.revisions') }}</h3>
        <p v-if="selected.frozen_at" class="muted hi-small">{{ t('hi.reg.state.frozen') }} · {{ formatDateTime(selected.frozen_at, locale) }}</p>
        <p v-if="revisions.length === 0" class="muted">{{ t('hi.reg.noRevision') }}</p>
        <ul v-else class="hi-list">
          <li v-for="rv in revisions" :key="rv.id">{{ t('hi.reg.revisionLine', { version: rv.version, date: formatDate(rv.created_at, locale), reason: rv.reason }) }}</li>
        </ul>
      </template>
    </section>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.reg.new') }}</h2>
      <div class="field">
<label for="r-school">{{ t('hi.school.label') }}</label>
        <select id="r-school" v-model="form.school_id" :aria-invalid="touched && !form.school_id"><option value="" disabled>{{ t('hi.schoolPick') }}</option><option v-for="s in higher.schools" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option></select>
      </div>
      <div class="field"><label for="r-regime">{{ t('hi.aca.regime') }}</label><select id="r-regime" v-model="form.regime"><option v-for="r in REGIMES" :key="r" :value="r">{{ t(k('hi.regime.', r)) }}</option></select></div>
      <YearField id="r-year" v-model="form.academic_year_id" :invalid="touched && !form.academic_year_id" />
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="save.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
