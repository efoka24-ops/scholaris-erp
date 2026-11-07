<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useI18n, type MessageKey } from '@/i18n';
import { useAction, usePermissions } from '../useSec';
import { internshipsApi, type Company, type Internship } from './workshops';
import { workshopMessages } from './workshopMessages';
import { workshopMessagesEn } from './workshopMessagesEn';

const { t: translate, locale } = useI18n();
function t(key: string): string {
  if (key in workshopMessages) {
    const localKey = key as keyof typeof workshopMessages;
    return locale.value === 'en' ? workshopMessagesEn[localKey] : workshopMessages[localKey];
  }
  return translate(key as MessageKey);
}
const { error, busy, run } = useAction();
const { can } = usePermissions();
const allowed = computed(() => can('secondary.internships.manage'));
const companies = ref<Company[]>([]);
const placements = ref<Internship[]>([]);
const done = ref('');
const company = reactive({ label: '', sector: '' });
const placement = reactive({ person_id: '', structure_id: '', company_id: '', tutor_name: '', starts_on: '', ends_on: '', score_coefficient: '' });

async function load(): Promise<void> {
  companies.value = (await run(() => internshipsApi.companies())) ?? [];
  placements.value = (await run(() => internshipsApi.internships())) ?? [];
  const firstCompany = companies.value[0];
  if (!placement.company_id && firstCompany) placement.company_id = firstCompany.id;
}
async function addCompany(): Promise<void> {
  const created = await run(() => internshipsApi.createCompany({ label: company.label.trim(), sector: company.sector.trim() || null }));
  if (created) { companies.value.push(created); placement.company_id = created.id; company.label = ''; done.value = t('secw.saved'); }
}
async function addPlacement(): Promise<void> {
  const created = await run(() => internshipsApi.create({ ...placement, score_coefficient: placement.score_coefficient === '' ? undefined : Number(placement.score_coefficient) }));
  if (created) { placements.value.unshift(created); done.value = t('secw.saved'); }
}
async function recordEvaluation(item: Internship): Promise<void> {
  const value = window.prompt(t('secw.score'), item.score === null ? '' : String(item.score));
  if (value === null || value.trim() === '' || !Number.isFinite(Number(value))) return;
  const updated = await run(() => internshipsApi.update(item.id, { score: Number(value), status: 'completed' }));
  if (updated) placements.value = placements.value.map((x) => x.id === updated.id ? updated : x);
}
onMounted(load);
</script>

<template>
  <div class="page">
    <header class="page-head"><p class="eyebrow">{{ t('sec.home.eyebrow') }}</p><h1>{{ t('secw.internshipsTitle') }}</h1><p class="lede">{{ t('sec.home.internshipsText') }}</p></header>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>
    <section v-if="allowed" class="card"><h2>{{ t('secw.company') }}</h2><ul class="rows"><li v-for="entry in companies" :key="entry.id">{{ entry.label }}<template v-if="entry.sector"> · {{ entry.sector }}</template></li></ul><form class="grid" @submit.prevent="addCompany"><div class="field"><label for="company-name">{{ t('secw.company') }}</label><input id="company-name" v-model="company.label" required /></div><div class="field"><label for="company-sector">{{ t('secw.sector') }}</label><input id="company-sector" v-model="company.sector" /></div><button class="btn btn--primary" :disabled="busy">{{ t('secw.addCompany') }}</button></form></section>
    <section v-if="allowed" class="card"><h2>{{ t('secw.internshipsTitle') }}</h2><p v-if="!placements.length" class="muted">{{ t('secw.noInternships') }}</p><ul class="rows"><li v-for="entry in placements" :key="entry.id"><strong>{{ entry.company.label }}</strong> · {{ entry.person_id }} · {{ entry.starts_on }} – {{ entry.ends_on }} · {{ entry.status }}<template v-if="entry.score !== null"> · {{ entry.score }}</template><button class="btn" type="button" @click="recordEvaluation(entry)">{{ t('secw.score') }}</button></li></ul><form class="grid" @submit.prevent="addPlacement"><div class="field"><label for="intern-person">{{ t('secw.person') }}</label><input id="intern-person" v-model="placement.person_id" required /></div><div class="field"><label for="intern-class">{{ t('secw.class') }}</label><input id="intern-class" v-model="placement.structure_id" required /></div><div class="field"><label for="intern-company">{{ t('secw.company') }}</label><select id="intern-company" v-model="placement.company_id" required><option v-for="entry in companies" :key="entry.id" :value="entry.id">{{ entry.label }}</option></select></div><div class="field"><label for="intern-tutor">{{ t('secw.tutor') }}</label><input id="intern-tutor" v-model="placement.tutor_name" required /></div><div class="field"><label for="intern-start">{{ t('secw.starts') }}</label><input id="intern-start" v-model="placement.starts_on" type="date" required /></div><div class="field"><label for="intern-end">{{ t('secw.ends') }}</label><input id="intern-end" v-model="placement.ends_on" type="date" required /></div><div class="field"><label for="intern-coef">{{ t('secw.coefficient') }}</label><input id="intern-coef" v-model="placement.score_coefficient" type="number" min="0.01" step="0.01" /></div><button class="btn btn--primary" :disabled="busy || !companies.length">{{ t('secw.createInternship') }}</button></form></section>
  </div>
</template>
