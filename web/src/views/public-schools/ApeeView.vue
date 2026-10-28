<script setup lang="ts">
// APEE de l'école : association des parents, cotisation VOLONTAIRE, comptes séparés de la scolarité. Aucune inscription, présence,
// bulletin ni document d'élève n'en dépend (RG-APE-1). Le bureau encaisse et dépense (reçus numérotés DOC-24), le directeur consulte
// (RG-APE-4) ; la liste des familles non contributrices n'est montrée qu'au bureau (RG-APE-2). État financier DOC-25.
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { openDocument, publicSchoolsP2, type ApeeEntry, type ApeeStatement, type ApeeYear } from '@/api/publicSchools';
import { useI18n } from '@/i18n';
import { formatDate, todayIso } from '@/lib/format';
import { ulid } from '@/lib/ulid';
import { useContextStore } from '@/stores/context';
import { useAction } from './usePs';

const { t } = useI18n();
const ctx = useContextStore();
const { error, busy, run } = useAction();

const INCOME = ['contribution', 'donation', 'other_income'] as const;
const EXPENSE = ['parents_teacher', 'maintenance', 'supplies', 'mock_exam', 'other'] as const;
const years = ref<ApeeYear[]>([]);
const yearId = ref('');
const entries = ref<ApeeEntry[]>([]);
const statement = ref<ApeeStatement | null>(null);
const nonPayers = ref<{ person_id: string; name: string }[] | null>(null);
const vote = ref({ year_label: '', amount_per_pupil: 0, voted_on: todayIso(), minutes_ref: '' });
const entry = ref({ direction: 'income' as 'income' | 'expense', category: 'contribution', amount: 0, method: 'cash', operator_ref: '', family_ref: '', label: '', proof_ref: '' });
const familySearch = ref('');
const families = ref<{ person_id: string; name: string }[]>([]);
const done = ref('');

const canWrite = computed(() => ctx.can('public_schools.apee.write'));
const canSeeNames = computed(() => ctx.can('public_schools.apee.non_payers.read'));
const categories = computed(() => (entry.value.direction === 'income' ? INCOME : EXPENSE));
const money = (n: number): string => new Intl.NumberFormat('fr-FR').format(n) + ' FCFA';
const categoryLabel = (c: string): string => t(`pp.apee.cat.${c}` as Parameters<typeof t>[0]);

onMounted(load);

async function load(): Promise<void> {
  await run(async () => {
    years.value = await publicSchoolsP2.apeeYears();
    if (!yearId.value && years.value.length > 0) yearId.value = years.value[years.value.length - 1]?.id ?? '';
    await openYear();
  });
}

async function openYear(): Promise<void> {
  if (!yearId.value) return;
  entries.value = await publicSchoolsP2.apeeEntries(yearId.value);
  statement.value = await publicSchoolsP2.apeeStatement(yearId.value);
  nonPayers.value = canSeeNames.value ? await publicSchoolsP2.nonContributors(yearId.value) : null;
}

async function sendVote(): Promise<void> {
  await run(async () => {
    const y = await publicSchoolsP2.voteApee({ ...vote.value, amount_per_pupil: Number(vote.value.amount_per_pupil), minutes_ref: vote.value.minutes_ref || undefined });
    yearId.value = y.id;
    done.value = t('pp.apee.voted');
    await load();
  });
}

async function findFamilies(): Promise<void> {
  if (familySearch.value.trim().length < 2) return;
  await run(async () => {
    families.value = await publicSchoolsP2.families(familySearch.value.trim());
  });
}

async function record(): Promise<void> {
  const e = entry.value;
  await run(async () => {
    const res = await publicSchoolsP2.recordApee({
      apee_year_id: yearId.value,
      direction: e.direction,
      category: e.category,
      amount: Number(e.amount),
      method: e.method,
      operator_ref: e.operator_ref || undefined,
      family_ref: e.family_ref || undefined,
      label: e.label || undefined,
      proof_ref: e.proof_ref || undefined,
      client_uuid: ulid(),
    });
    done.value = res.receipt_number ? t('pp.apee.receipt', { number: res.receipt_number }) : res.state === 'pending' ? t('pp.apee.pending') : t('pp.apee.recorded');
    entry.value = { ...entry.value, amount: 0, family_ref: '', label: '', proof_ref: '', operator_ref: '' };
    await openYear();
  });
}

async function proof(row: ApeeEntry): Promise<void> {
  const ref = window.prompt(t('pp.apee.proofPrompt')) ?? '';
  if (ref.trim() === '') return;
  await run(async () => {
    await publicSchoolsP2.attachProof(row.id, ref.trim());
    await openYear();
  });
}

async function issueStatement(): Promise<void> {
  await run(async () => {
    const res = await publicSchoolsP2.issueStatement(yearId.value);
    await openDocument(res.document.id);
  });
}

async function receipt(row: ApeeEntry): Promise<void> {
  if (row.receipt_document_id) await run(() => openDocument(row.receipt_document_id as string));
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('ps.home.eyebrow') }}</p>
      <h1>{{ t('pp.apee.title') }}</h1>
      <p class="lede">{{ t('pp.apee.intro') }}</p>
    </header>
    <p class="notice" role="note">{{ t('pp.apee.voluntary') }}</p>
    <ErrorNotice :error="error" />
    <p v-if="done" class="notice notice--success" role="status">{{ done }}</p>

    <section class="card controls">
      <div class="field">
        <label for="ap-year">{{ t('pp.apee.year') }}</label>
        <select id="ap-year" v-model="yearId" @change="run(openYear)">
          <option value="">—</option>
          <option v-for="y in years" :key="y.id" :value="y.id">{{ y.year_label }} · {{ money(y.amount_per_pupil) }}</option>
        </select>
      </div>
    </section>

    <section v-if="canWrite" class="card" aria-labelledby="ap-vote">
      <h2 id="ap-vote">{{ t('pp.apee.voteTitle') }}</h2>
      <form class="controls" @submit.prevent="sendVote">
        <div class="field"><label for="av-y">{{ t('pp.apee.yearLabel') }}</label><input id="av-y" v-model="vote.year_label" type="text" placeholder="2026-2027" pattern="\d{4}-\d{4}" required /></div>
        <div class="field"><label for="av-a">{{ t('pp.apee.amount') }}</label><input id="av-a" v-model.number="vote.amount_per_pupil" type="number" min="0" required /></div>
        <div class="field"><label for="av-d">{{ t('pp.apee.votedOn') }}</label><input id="av-d" v-model="vote.voted_on" type="date" required /></div>
        <div class="field"><label for="av-m">{{ t('pp.apee.minutes') }}</label><input id="av-m" v-model="vote.minutes_ref" type="text" maxlength="255" /></div>
        <button type="submit" class="btn" :disabled="busy">{{ t('pp.apee.vote') }}</button>
      </form>
    </section>

    <section v-if="statement" class="card" aria-labelledby="ap-st">
      <h2 id="ap-st">{{ t('pp.apee.statement') }}</h2>
      <dl class="kv">
        <dt>{{ t('pp.apee.expected') }}</dt><dd>{{ money(statement.expected) }}</dd>
        <dt>{{ t('pp.apee.collected') }}</dt><dd>{{ money(statement.collected) }} ({{ statement.collection_rate ?? '—' }} %)</dd>
        <dt>{{ t('pp.apee.expenses') }}</dt><dd>{{ money(statement.expenses_total) }}</dd>
        <dt>{{ t('pp.apee.pendingTotal') }}</dt><dd>{{ money(statement.pending_total) }}</dd>
        <dt>{{ t('pp.apee.balance') }}</dt><dd><strong>{{ money(statement.balance) }}</strong></dd>
        <dt>{{ t('pp.apee.families') }}</dt><dd>{{ statement.contributing_families }} / {{ statement.non_contributing_families }} / {{ statement.exempted_families }}</dd>
      </dl>
      <button v-if="canWrite" type="button" class="btn" :disabled="busy" @click="issueStatement">{{ t('pp.apee.issueStatement') }}</button>
    </section>

    <section v-if="canWrite && yearId" class="card" aria-labelledby="ap-entry">
      <h2 id="ap-entry">{{ t('pp.apee.entryTitle') }}</h2>
      <form class="controls" @submit.prevent="record">
        <fieldset class="field">
          <legend class="label">{{ t('pp.apee.direction') }}</legend>
          <label class="check"><input v-model="entry.direction" type="radio" value="income" @change="entry.category = 'contribution'" />{{ t('pp.apee.income') }}</label>
          <label class="check"><input v-model="entry.direction" type="radio" value="expense" @change="entry.category = 'parents_teacher'" />{{ t('pp.apee.expense') }}</label>
        </fieldset>
        <div class="field">
          <label for="ae-cat">{{ t('pp.apee.category') }}</label>
          <select id="ae-cat" v-model="entry.category"><option v-for="c in categories" :key="c" :value="c">{{ categoryLabel(c) }}</option></select>
        </div>
        <div class="field"><label for="ae-amt">{{ t('pp.apee.amountPaid') }}</label><input id="ae-amt" v-model.number="entry.amount" type="number" min="1" required /></div>
        <template v-if="entry.category === 'contribution'">
          <div class="field">
            <label for="ae-fam">{{ t('pp.apee.pupilSearch') }}</label>
            <input id="ae-fam" v-model="familySearch" type="search" @change="findFamilies" />
            <select v-model="entry.family_ref" :aria-label="t('pp.apee.pupil')" required>
              <option value="">—</option>
              <option v-for="f in families" :key="f.person_id" :value="f.person_id">{{ f.name }}</option>
            </select>
          </div>
        </template>
        <div class="field">
          <label for="ae-met">{{ t('pp.apee.method') }}</label>
          <select id="ae-met" v-model="entry.method"><option value="cash">{{ t('pp.apee.cash') }}</option><option value="mobile_money">{{ t('pp.apee.mobileMoney') }}</option><option value="bank">{{ t('pp.apee.bank') }}</option></select>
        </div>
        <div v-if="entry.method === 'mobile_money'" class="field"><label for="ae-op">{{ t('pp.apee.operatorRef') }}</label><input id="ae-op" v-model="entry.operator_ref" type="text" maxlength="80" required /></div>
        <div class="field"><label for="ae-lbl">{{ t('pp.apee.label') }}</label><input id="ae-lbl" v-model="entry.label" type="text" maxlength="200" /></div>
        <div v-if="entry.direction === 'expense'" class="field"><label for="ae-pj">{{ t('pp.apee.proof') }}</label><input id="ae-pj" v-model="entry.proof_ref" type="text" maxlength="255" /></div>
        <button type="submit" class="btn btn--primary" :disabled="busy">{{ t('pp.apee.record') }}</button>
      </form>
    </section>

    <section v-if="entries.length > 0" class="card" aria-labelledby="ap-list">
      <h2 id="ap-list">{{ t('pp.apee.entries') }}</h2>
      <div class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('pp.apee.entries') }}</caption>
          <thead><tr><th scope="col">{{ t('pp.common.date') }}</th><th scope="col">{{ t('pp.apee.category') }}</th><th scope="col">{{ t('pp.apee.amountPaid') }}</th><th scope="col">{{ t('pp.apee.state') }}</th><th scope="col"><span class="sr-only">{{ t('pp.common.actions') }}</span></th></tr></thead>
          <tbody>
            <tr v-for="e in entries" :key="e.id">
              <td>{{ formatDate(e.entered_on) }}</td>
              <td>{{ categoryLabel(e.category) }}</td>
              <td>{{ e.direction === 'expense' ? '−' : '' }}{{ money(e.amount) }}</td>
              <td>{{ e.state === 'pending' ? t('pp.apee.statePending') : t('pp.apee.stateRecorded') }}</td>
              <td>
                <button v-if="e.receipt_document_id" type="button" class="btn btn--small" @click="receipt(e)">{{ t('pp.apee.receiptPdf') }}</button>
                <button v-if="canWrite && e.state === 'pending'" type="button" class="btn btn--small" @click="proof(e)">{{ t('pp.apee.attachProof') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="nonPayers" class="card" aria-labelledby="ap-np">
      <h2 id="ap-np">{{ t('pp.apee.nonPayers') }}</h2>
      <p class="muted">{{ t('pp.apee.nonPayersNote') }}</p>
      <ul><li v-for="n in nonPayers" :key="n.person_id">{{ n.name }}</li></ul>
    </section>
  </div>
</template>

<style scoped>
.controls { display: grid; gap: 8px; }
.check { display: inline-flex; margin-right: 1rem; }
.kv { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; }
.kv dd { margin: 0; }
</style>
