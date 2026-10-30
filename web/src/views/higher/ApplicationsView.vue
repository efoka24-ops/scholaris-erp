<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { admissions, APPLICATION_STATUSES, DIPLOMAS, MENTIONS, type Application, type Campaign, type Diploma, type Mention } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatDate } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import PersonPicker from './PersonPicker.vue';
import { P } from './access';
import { k, toneOf } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Liste des candidatures avec statuts et filtres, création au guichet (EX-ADM-02) — design : Inscription.html (identité) et Frais.html (journal).
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const route = useRoute();
const router = useRouter();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Application[]>([]);
const campaigns = ref<Campaign[]>([]);
const filters = reactive({ campaign_id: typeof route.query.campaign === 'string' ? route.query.campaign : '', status: '', channel: '' });
const touched = ref(false);
const mode = ref<'existing' | 'new'>('new');
const form = reactive({
  campaign_id: '', person_id: null as string | null, first_name: '', last_name: '', phone: '', email: '', application_kind: 'new' as 'new' | 'transfer',
  origin_institution: '', diploma: 'bac' as Diploma, diploma_series: '', diploma_year: '' as string | number, diploma_mention: '' as Mention | '',
});

const canManage = computed(() => ctx.can(...P.applicationManage) && !higher.readOnly);
const openCampaigns = computed(() => campaigns.value.filter((c) => c.status === 'open'));
const campaignName = (id: string): string => {
  const c = campaigns.value.find((x) => x.id === id);
  return c ? c.title || `${higher.programName(c.program_id)} · ${higher.levelName(c.level_id)}` : id;
};
const identityBad = computed(() => touched.value && mode.value === 'new' && (!form.first_name.trim() || !form.last_name.trim() || !form.phone.trim()));

async function load(): Promise<void> {
  await higher.loadRefs('programs', 'levels', 'years');
  const q: Record<string, string> = { ...(higher.scope() as Record<string, string>) };
  for (const [key, v] of Object.entries(filters)) if (v) q[`filter[${key}]`] = v;
  const res = await list.run(async () => ({ apps: await admissions.applications(q), camps: await admissions.campaigns(higher.scope()) }));
  if (!res) return;
  items.value = res.apps;
  campaigns.value = res.camps;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.campaign_id || identityBad.value || (mode.value === 'existing' && !form.person_id)) return;
  const body: Record<string, unknown> = { campaign_id: form.campaign_id, diploma: form.diploma, application_kind: form.application_kind };
  if (mode.value === 'existing') body.person_id = form.person_id;
  else body.identity = { first_name: form.first_name.trim(), last_name: form.last_name.trim(), phone: form.phone.trim(), ...(form.email.trim() ? { email: form.email.trim() } : {}), locale: locale.value };
  if (form.diploma_series.trim()) body.diploma_series = form.diploma_series.trim();
  if (form.diploma_year !== '') body.diploma_year = Number(form.diploma_year);
  if (form.diploma_mention) body.diploma_mention = form.diploma_mention;
  if (form.application_kind === 'transfer' && form.origin_institution.trim()) body.origin_institution = form.origin_institution.trim();
  const res = await act.run(() => admissions.createApplication(body));
  if (res) await router.push({ name: 'higher-application', params: { id: res.id } });
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.app.title')" :lede="t('hi.app.lede')" section="admissions" @school-change="load">
    <form class="hi-form" :aria-label="t('hi.filters')" @submit.prevent="load">
      <div class="field">
<label for="af-camp">{{ t('hi.adm.campaign') }}</label>
        <select id="af-camp" v-model="filters.campaign_id" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="c in campaigns" :key="c.id" :value="c.id">{{ campaignName(c.id) }}</option></select>
      </div>
      <div class="field">
<label for="af-status">{{ t('hi.status') }}</label>
        <select id="af-status" v-model="filters.status" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option v-for="s in APPLICATION_STATUSES" :key="s" :value="s">{{ t(k('hi.app.st.', s)) }}</option></select>
      </div>
      <div class="field">
<label for="af-channel">{{ t('hi.app.channel') }}</label>
        <select id="af-channel" v-model="filters.channel" @change="load"><option value="">{{ t('hi.filterAll') }}</option><option value="online">{{ t('hi.app.channel.online') }}</option><option value="counter">{{ t('hi.app.channel.counter') }}</option></select>
      </div>
    </form>
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th scope="col">{{ t('hi.app.reference') }}</th><th scope="col">{{ t('hi.adm.campaign') }}</th><th scope="col">{{ t('hi.app.channel') }}</th><th scope="col">{{ t('hi.app.diploma') }}</th><th scope="col">{{ t('hi.app.fees') }}</th><th scope="col">{{ t('hi.status') }}</th><th scope="col">{{ t('hi.date') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in items" :key="a.id">
            <th scope="row"><router-link :to="{ name: 'higher-application', params: { id: a.id } }">{{ a.reference }}</router-link></th>
            <td>{{ campaignName(a.campaign_id) }}</td>
            <td>{{ t(k('hi.app.channel.', a.channel)) }}</td>
            <td>{{ t(k('hi.app.diploma.', a.diploma)) }}</td>
            <td><span class="tag" :class="`tag--${toneOf(a.fee.status) ?? 'neutral'}`">{{ t(k('hi.app.fee.', a.fee.status)) }}</span></td>
            <td><span class="tag" :class="`tag--${toneOf(a.status) ?? 'neutral'}`">{{ t(k('hi.app.st.', a.status)) }}</span></td>
            <td>{{ formatDate(a.submitted_at ?? a.created_at, locale) }}</td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="7" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>

    <ErrorNotice :error="act.error.value" />
    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.app.new') }}</h2>
      <div class="field">
<label for="an-camp">{{ t('hi.adm.campaign') }}</label>
        <select id="an-camp" v-model="form.campaign_id" :aria-invalid="touched && !form.campaign_id"><option value="" disabled>—</option><option v-for="c in openCampaigns" :key="c.id" :value="c.id">{{ campaignName(c.id) }}</option></select>
        <span v-if="touched && !form.campaign_id" class="error">{{ t('hi.required') }}</span>
      </div>
      <fieldset class="field hi-wide">
        <legend class="label">{{ t('hi.app.candidate') }}</legend>
        <label class="check"><input v-model="mode" type="radio" value="new" /> {{ t('hi.app.newPerson') }}</label>
        <label class="check"><input v-model="mode" type="radio" value="existing" /> {{ t('hi.app.existing') }}</label>
      </fieldset>
      <PersonPicker v-if="mode === 'existing'" id="an-person" v-model="form.person_id" :label="t('hi.person')" class="hi-wide" />
      <template v-else>
        <div class="field"><label for="an-last">{{ t('hi.app.lastName') }}</label><input id="an-last" v-model="form.last_name" type="text" autocomplete="family-name" :aria-invalid="identityBad && !form.last_name.trim()" /></div>
        <div class="field"><label for="an-first">{{ t('hi.app.firstName') }}</label><input id="an-first" v-model="form.first_name" type="text" autocomplete="given-name" :aria-invalid="identityBad && !form.first_name.trim()" /></div>
        <div class="field"><label for="an-phone">{{ t('hi.app.phone') }}</label><input id="an-phone" v-model="form.phone" type="tel" autocomplete="tel" :aria-invalid="identityBad && !form.phone.trim()" /></div>
        <div class="field"><label for="an-email">{{ t('hi.app.email') }}</label><input id="an-email" v-model="form.email" type="email" autocomplete="email" /></div>
        <p v-if="identityBad" class="error hi-wide">{{ t('hi.required') }}</p>
      </template>
      <div class="field"><label for="an-kind">{{ t('hi.app.kind') }}</label><select id="an-kind" v-model="form.application_kind"><option value="new">{{ t('hi.app.kind.new') }}</option><option value="transfer">{{ t('hi.app.kind.transfer') }}</option></select></div>
      <div v-if="form.application_kind === 'transfer'" class="field"><label for="an-origin">{{ t('hi.app.origin') }}</label><input id="an-origin" v-model="form.origin_institution" type="text" /></div>
      <div class="field"><label for="an-dipl">{{ t('hi.app.diploma') }}</label><select id="an-dipl" v-model="form.diploma"><option v-for="d in DIPLOMAS" :key="d" :value="d">{{ t(k('hi.app.diploma.', d)) }}</option></select></div>
      <div class="field"><label for="an-series">{{ t('hi.app.series') }}</label><input id="an-series" v-model="form.diploma_series" type="text" /></div>
      <div class="field"><label for="an-year">{{ t('hi.app.diplomaYear') }}</label><input id="an-year" v-model="form.diploma_year" type="number" min="1980" max="2100" /></div>
      <div class="field"><label for="an-mention">{{ t('hi.app.mention') }}</label><select id="an-mention" v-model="form.diploma_mention"><option value="">—</option><option v-for="m in MENTIONS" :key="m" :value="m">{{ t(k('hi.app.mention.', m)) }}</option></select></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
