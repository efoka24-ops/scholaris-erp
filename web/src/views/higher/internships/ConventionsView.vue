<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CONVENTION_STATUSES, internships, type Company, type ConventionStatus, type Internship, type InternshipInput } from '@/api/higher-internships';
import { useContextStore } from '@/stores/context';
import { formatDate } from '@/lib/format';
import HigherFrame from '../HigherFrame.vue';
import { toneOf } from '../display';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import InternshipsNav from './InternshipsNav.vue';
import { S } from './perms';
import { ik, useInternshipsI18n } from './i18n';

// Conventions de stage du périmètre (EX-STG-02) : liste filtrée par état, création d'une convention en brouillon (R06, R16).
const { ts, locale } = useInternshipsI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Internship[]>([]);
const companies = ref<Company[]>([]);
const status = ref<ConventionStatus | ''>('');
const showForm = ref(false);
const tried = ref(false);
const form = reactive<InternshipInput>({ enrolment_id: '', company_id: '', subject: '', starts_on: '', ends_on: '', company_tutor_name: '', supervisor_person_id: '' });
const canManage = computed(() => ctx.can(...S.manage) && !higher.readOnly);
const missing = computed(() => (Object.keys(form) as Array<keyof InternshipInput>).filter((k) => !String(form[k] ?? '').trim()));
const companyName = (id: string): string => companies.value.find((c) => c.id === id)?.name ?? id;

async function load(): Promise<void> {
  const res = await list.run(() => internships.list(status.value ? { 'filter[status]': status.value } : {}));
  if (res) items.value = res;
}

async function create(): Promise<void> {
  tried.value = true;
  if (missing.value.length > 0) return;
  const res = await act.run(() => internships.create({ ...form }));
  if (!res) return;
  showForm.value = false;
  tried.value = false;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  const found = await list.run(() => internships.companies({ 'filter[active]': '1' }));
  if (found) companies.value = found;
  await load();
});
</script>

<template>
  <HigherFrame :title="ts('hs.c.title')" :lede="ts('hs.c.lede')" section="portal">
    <InternshipsNav />
    <div class="hi-form">
      <div class="field">
        <label for="cf-st">{{ ts('hs.status') }}</label>
        <select id="cf-st" v-model="status" @change="load"><option value="">{{ ts('hs.all') }}</option><option v-for="s in CONVENTION_STATUSES" :key="s" :value="s">{{ ts(ik('hs.state.', s)) }}</option></select>
      </div>
      <button v-if="canManage" type="button" class="btn btn--primary" data-test="new-convention" @click="showForm = !showForm">{{ ts('hs.c.new') }}</button>
    </div>
    <ErrorNotice :error="list.error.value" />
    <form v-if="showForm" class="card hi-form" data-test="convention-form" @submit.prevent="create">
      <div class="field"><label for="cv-enr">{{ ts('hs.c.enrolment') }}</label><input id="cv-enr" v-model="form.enrolment_id" maxlength="40"></div>
      <div class="field">
        <label for="cv-co">{{ ts('hs.c.company') }}</label>
        <select id="cv-co" v-model="form.company_id"><option value="" /><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </div>
      <div class="field"><label for="cv-sub">{{ ts('hs.c.subject') }}</label><input id="cv-sub" v-model="form.subject" maxlength="255"></div>
      <div class="field"><label for="cv-from">{{ ts('hs.c.starts') }}</label><input id="cv-from" v-model="form.starts_on" type="date"></div>
      <div class="field"><label for="cv-to">{{ ts('hs.c.ends') }}</label><input id="cv-to" v-model="form.ends_on" type="date"></div>
      <div class="field"><label for="cv-tut">{{ ts('hs.c.tutor') }}</label><input id="cv-tut" v-model="form.company_tutor_name" maxlength="160"></div>
      <div class="field"><label for="cv-sup">{{ ts('hs.c.supervisor') }}</label><input id="cv-sup" v-model="form.supervisor_person_id" maxlength="40"></div>
      <p v-if="tried && missing.length" class="error" data-test="form-missing">{{ ts('hs.required') }}</p>
      <ErrorNotice :error="act.error.value" />
      <div class="hi-actions"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ ts('hs.c.create') }}</button></div>
    </form>
    <ul class="hi-list card">
      <li v-for="i in items" :key="i.id" data-test="convention-row">
        <div class="hi-grow">
          <strong>{{ i.subject }}</strong>
          <p class="muted hi-small">{{ companyName(i.company_id) }} · {{ ts('hs.c.period', { from: formatDate(i.starts_on, locale), to: formatDate(i.ends_on, locale) }) }}</p>
        </div>
        <span class="tag" :class="`tag--${toneOf(i.status) ?? 'neutral'}`">{{ ts(ik('hs.state.', i.status)) }}</span>
        <router-link :to="{ name: 'higher-internship', params: { id: i.id } }" class="btn btn--quiet">{{ ts('hs.c.open') }}</router-link>
      </li>
      <li v-if="items.length === 0" class="muted">{{ ts('hs.none') }}</li>
    </ul>
  </HigherFrame>
</template>
