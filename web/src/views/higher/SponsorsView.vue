<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { billing, type Sponsor, type SponsorStatement } from '@/api/higher';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import { formatXaf } from '@/lib/format';
import HigherFrame from './HigherFrame.vue';
import { P } from './access';
import { k } from './display';
import { useHigherStore } from './store';
import { useHigherAction } from './useHigherAction';

// Tiers payeurs (EX-FIN-04, S13) : entreprises, bailleurs, parrains ; relevé des comptes pris en charge — design : Frais.html.
const { t, locale } = useI18n();
const ctx = useContextStore();
const higher = useHigherStore();
const list = useHigherAction();
const act = useHigherAction();

const items = ref<Sponsor[]>([]);
const statement = ref<SponsorStatement | null>(null);
const touched = ref(false);
const form = reactive({ sponsor_kind: 'organisation' as 'person' | 'organisation', name: '', reference: '', contact: '' });
const canManage = computed(() => ctx.can(...P.billingManage) && !higher.readOnly);

async function load(): Promise<void> {
  const res = await list.run(() => billing.sponsors());
  if (res) items.value = res;
}

async function show(s: Sponsor): Promise<void> {
  const res = await act.run(() => billing.sponsor(s.id));
  if (res) statement.value = res;
}

async function create(): Promise<void> {
  touched.value = true;
  if (!form.name.trim()) return;
  const res = await act.run(() => billing.createSponsor({ sponsor_kind: form.sponsor_kind, name: form.name.trim(), ...(form.reference.trim() ? { reference: form.reference.trim() } : {}), ...(form.contact.trim() ? { contact: form.contact.trim() } : {}) }));
  if (!res) return;
  Object.assign(form, { name: '', reference: '', contact: '' });
  touched.value = false;
  await load();
}

onMounted(async () => {
  await higher.ensure();
  await load();
});
</script>

<template>
  <HigherFrame :title="t('hi.fin.sponsorsTitle')" :lede="t('hi.fin.sponsorsLede')" section="finance" :selector="false">
    <ErrorNotice :error="list.error.value" />
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">{{ t('hi.name') }}</th><th scope="col">{{ t('hi.fin.sponsorKind') }}</th><th scope="col">{{ t('hi.fin.reference') }}</th><th scope="col">{{ t('hi.fin.contact') }}</th><th scope="col">{{ t('hi.actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in items" :key="s.id">
            <th scope="row">{{ s.name }}</th><td>{{ t(k('hi.fin.sponsorKind.', s.sponsor_kind)) }}</td><td>{{ s.reference ?? '—' }}</td><td>{{ s.contact ?? '—' }}</td>
            <td><button type="button" class="hi-linkbtn" @click="show(s)">{{ t('hi.fin.statement') }}</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="5" class="muted">{{ t('hi.none') }}</td></tr>
        </tbody>
      </table>
    </div>
    <ErrorNotice :error="act.error.value" />

    <section v-if="statement" class="card card--strong hi-section" aria-labelledby="st-title">
      <h2 id="st-title">{{ t('hi.fin.statement') }} · {{ statement.name }}</h2>
      <p>{{ t('hi.fin.sponsorAccounts', { count: statement.accounts.length, amount: formatXaf(statement.balance_xaf, locale) }) }}</p>
      <ul class="hi-list">
        <li v-for="a in statement.accounts" :key="a.account_id"><router-link :to="{ name: 'higher-account', params: { id: a.account_id } }" class="hi-grow hi-mono">{{ a.person_id }}</router-link><span>{{ formatXaf(a.net_xaf, locale) }}</span><strong>{{ formatXaf(a.balance_xaf, locale) }}</strong></li>
      </ul>
    </section>

    <form v-if="canManage" class="card hi-section hi-form" novalidate @submit.prevent="create">
      <h2 class="hi-wide">{{ t('hi.fin.sponsorNew') }}</h2>
      <div class="field"><label for="sp-kind">{{ t('hi.fin.sponsorKind') }}</label><select id="sp-kind" v-model="form.sponsor_kind"><option value="organisation">{{ t('hi.fin.sponsorKind.organisation') }}</option><option value="person">{{ t('hi.fin.sponsorKind.person') }}</option></select></div>
      <div class="field"><label for="sp-name">{{ t('hi.name') }}</label><input id="sp-name" v-model="form.name" type="text" :aria-invalid="touched && !form.name.trim()" /><span v-if="touched && !form.name.trim()" class="error">{{ t('hi.required') }}</span></div>
      <div class="field"><label for="sp-ref">{{ t('hi.fin.reference') }}</label><input id="sp-ref" v-model="form.reference" type="text" /></div>
      <div class="field"><label for="sp-contact">{{ t('hi.fin.contact') }}</label><input id="sp-contact" v-model="form.contact" type="text" /></div>
      <div class="field"><button type="submit" class="btn btn--primary" :disabled="act.busy.value">{{ t('hi.create') }}</button></div>
    </form>
  </HigherFrame>
</template>
