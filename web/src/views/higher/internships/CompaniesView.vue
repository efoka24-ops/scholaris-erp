<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { internships, type Company } from '@/api/higher-internships';
import { useContextStore } from '@/stores/context';
import HigherFrame from '../HigherFrame.vue';
import { useHigherStore } from '../store';
import { useHigherAction } from '../useHigherAction';
import InternshipsNav from './InternshipsNav.vue';
import { S } from './perms';
import { useInternshipsI18n } from './i18n';

// Répertoire des entreprises d'accueil (EX-STG-01) : consultation, ajout et désactivation par les responsables des stages.
const { ts } = useInternshipsI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Company[]>([]);
const form = reactive({ name: '', city: '', sector: '' });
const tried = ref(false);
const canManage = computed(() => ctx.can(...S.manage) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => internships.companies());
  if (res) items.value = res;
}

async function add(): Promise<void> {
  tried.value = true;
  if (!form.name.trim()) return;
  const body = { name: form.name.trim(), ...(form.city.trim() ? { city: form.city.trim() } : {}), ...(form.sector.trim() ? { sector: form.sector.trim() } : {}) };
  if ((await act.run(() => internships.createCompany(body))) === undefined) return;
  Object.assign(form, { name: '', city: '', sector: '' });
  tried.value = false;
  await load();
}

async function toggle(c: Company): Promise<void> {
  if ((await act.run(() => internships.updateCompany(c.id, { active: !c.active }))) !== undefined) await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="ts('hs.co.title')" :lede="ts('hs.co.lede')" section="portal" :selector="false">
    <InternshipsNav />
    <ErrorNotice :error="list.error.value" />
    <ErrorNotice :error="act.error.value" />
    <form v-if="canManage" class="card hi-form" data-test="company-form" @submit.prevent="add">
      <div class="field">
        <label for="co-n">{{ ts('hs.co.name') }}</label>
        <input id="co-n" v-model="form.name" maxlength="160" :aria-invalid="tried && !form.name.trim()">
        <span v-if="tried && !form.name.trim()" class="error">{{ ts('hs.required') }}</span>
      </div>
      <div class="field"><label for="co-c">{{ ts('hs.co.city') }}</label><input id="co-c" v-model="form.city" maxlength="120"></div>
      <div class="field"><label for="co-s">{{ ts('hs.co.sector') }}</label><input id="co-s" v-model="form.sector" maxlength="120"></div>
      <button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ ts('hs.co.add') }}</button>
    </form>
    <ul class="hi-list card">
      <li v-for="c in items" :key="c.id" data-test="company-row">
        <div class="hi-grow"><strong>{{ c.name }}</strong><p class="muted hi-small">{{ [c.city, c.sector].filter(Boolean).join(' · ') }}</p></div>
        <span v-if="!c.active" class="tag tag--neutral">{{ ts('hs.co.inactive') }}</span>
        <button v-if="canManage" type="button" class="btn btn--quiet" @click="toggle(c)">{{ c.active ? '⏸' : '▶' }}</button>
      </li>
      <li v-if="items.length === 0" class="muted">{{ ts('hs.none') }}</li>
    </ul>
  </HigherFrame>
</template>
