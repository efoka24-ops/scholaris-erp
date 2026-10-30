<script setup lang="ts">
// Guichet des inscriptions : pour chaque inscription en cours, profil de l'élève (redoublant calculé), facture d'inscription
// (inscription + 1re tranche, remise de fratrie appliquée par le serveur), documents administratifs avec QR et transfert sortant.
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { http } from '@/api/http';
import type { Enrollment, Person } from '@/api/types';
import { DOCUMENT_KINDS, primary, type DocumentKind, type EnrolmentInvoice, type IssuedDocument, type LearnerProfile } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { formatXaf, todayIso } from '@/lib/format';
import { P } from './access';
import { currentYear, structureIndex, useAction, usePermissions } from './usePri';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();

const rows = ref<Enrollment[]>([]);
const people = ref<Record<string, Person>>({});
const classes = ref<Record<string, string>>({});
const query = ref('');
const opened = ref<string | null>(null);
const profile = ref<LearnerProfile | null>(null);
const invoice = ref<EnrolmentInvoice | null>(null);
const issued = ref<IssuedDocument[]>([]);
const transfer = reactive({ destination: '', reason: '', effectiveOn: todayIso() });
const canInvoice = computed(() => can(...P.enrol));
const canIssue = computed(() => can(...P.documentsIssue));

const fullName = (id: string): string => {
  const p = people.value[id];
  return p ? `${p.last_name.toUpperCase()} ${p.first_name}` : id;
};
const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q === '' ? rows.value : rows.value.filter((r) => fullName(r.person_id).toLowerCase().includes(q) || (r.internal_number ?? '').toLowerCase().includes(q));
});

async function load(): Promise<void> {
  await run(async () => {
    const year = await currentYear();
    const index = await structureIndex();
    classes.value = Object.fromEntries(index.list.map((s) => [s.id, s.name]));
    const { items } = await http.page<Enrollment>('/enrollments', { 'filter[status]': 'active', ...(year ? { 'filter[academic_year_id]': year.id } : {}) });
    rows.value = items;
    await Promise.all(
      items.slice(0, 50).map(async (e) => {
        const person = (await http.get<{ data: Person }>(`/people/${e.person_id}`)).data;
        people.value = { ...people.value, [e.person_id]: person };
      }),
    );
  });
}

async function toggle(e: Enrollment): Promise<void> {
  if (opened.value === e.id) {
    opened.value = null;
    return;
  }
  opened.value = e.id;
  profile.value = null;
  invoice.value = null;
  issued.value = [];
  profile.value = (await run(() => primary.learnerProfile(e.person_id, e.academic_year_id ?? undefined))) ?? null;
}

async function bill(e: Enrollment): Promise<void> {
  const done = await run(() => primary.invoiceEnrolment(e.id));
  if (done) invoice.value = done;
}

async function issue(e: Enrollment, kind: DocumentKind): Promise<void> {
  const done = await run(() => primary.issueDocument(kind, e.id));
  if (done) issued.value.push(done);
}

async function leave(e: Enrollment): Promise<void> {
  const done = await run(() => primary.transfer(e.id, { destination: transfer.destination.trim(), reason: transfer.reason.trim(), effective_on: transfer.effectiveOn }));
  if (done) {
    issued.value.push(done);
    rows.value = rows.value.filter((r) => r.id !== e.id);
    opened.value = null;
  }
}

onMounted(() => void load());
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.desk.title') }}</h1>
      <p class="lede">{{ t('pri.desk.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <div class="field">
        <label for="dk-q">{{ t('pri.desk.search') }}</label>
        <input id="dk-q" v-model="query" type="search" autocomplete="off" />
      </div>
      <p v-if="visible.length === 0" class="muted">{{ t('pri.desk.none') }}</p>
      <ul class="list">
        <li v-for="e in visible" :key="e.id">
          <div class="row row--between">
            <div class="grow">
              <strong>{{ fullName(e.person_id) }}</strong>
              <div class="muted">{{ e.structure_id ? (classes[e.structure_id] ?? e.structure_id) : t('pri.desk.noClass') }}<span v-if="e.internal_number"> · {{ e.internal_number }}</span></div>
            </div>
            <button type="button" class="btn btn--quiet" :aria-expanded="opened === e.id" @click="toggle(e)">{{ opened === e.id ? t('pri.desk.close') : t('pri.desk.open') }}</button>
          </div>

          <div v-if="opened === e.id" class="detail">
            <p v-if="profile">
              <span class="tag" :class="profile.repeater ? 'tag--warning' : 'tag--neutral'">{{ profile.repeater ? t('pri.desk.repeater') : t('pri.desk.notRepeater') }}</span>
              <span v-if="profile.is_exam_level" class="tag tag--neutral">{{ t('pri.desk.examLevel') }}</span>
              <span v-if="profile.is_final_level" class="tag tag--neutral">{{ t('pri.desk.finalLevel') }}</span>
            </p>

            <div v-if="canInvoice">
              <button type="button" class="btn" :disabled="busy" @click="bill(e)">{{ t('pri.desk.invoice') }}</button>
              <p v-if="invoice" class="notice" role="status">
                {{ t('pri.desk.invoiced', { net: formatXaf(invoice.net_xaf), discount: formatXaf(invoice.discount_xaf), due: formatXaf(invoice.due_at_enrolment_xaf) }) }}
              </p>
            </div>

            <div v-if="canIssue">
              <h3>{{ t('pri.desk.documents') }}</h3>
              <p class="row">
                <button v-for="k in DOCUMENT_KINDS" :key="k" type="button" class="btn btn--quiet" :disabled="busy" @click="issue(e, k)">{{ t(`pri.desk.kind.${k}` as MessageKey) }}</button>
              </p>
              <ul v-if="issued.length > 0" class="list">
                <li v-for="d in issued" :key="d.id">
                  {{ t(`pri.desk.kind.${d.kind}` as MessageKey) }} · <code>{{ d.number }}</code>
                  <router-link :to="{ name: 'verify', params: { token: d.verification_token } }">{{ t('pri.desk.verify') }}</router-link>
                </li>
              </ul>
            </div>

            <form v-if="canIssue" class="grid2" @submit.prevent="leave(e)">
              <h3 class="span2">{{ t('pri.desk.transfer') }}</h3>
              <div class="field"><label for="tr-dest">{{ t('pri.desk.destination') }}</label><input id="tr-dest" v-model="transfer.destination" type="text" maxlength="160" required /></div>
              <div class="field"><label for="tr-reason">{{ t('pri.desk.reason') }}</label><input id="tr-reason" v-model="transfer.reason" type="text" maxlength="255" required /></div>
              <div class="field"><label for="tr-date">{{ t('pri.desk.effectiveOn') }}</label><input id="tr-date" v-model="transfer.effectiveOn" type="date" required /></div>
              <div><button type="submit" class="btn" :disabled="busy">{{ t('pri.desk.transferDo') }}</button></div>
            </form>
          </div>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
.list > li { padding-top: 12px; border-top: 1px solid var(--border, #dcdcd8); }
.grow { flex: 1; }
.detail { display: grid; gap: 12px; padding: 12px 0 0; }
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.span2 { grid-column: 1 / -1; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
