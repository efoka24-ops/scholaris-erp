<script setup lang="ts">
// Bulletins d'un conseil validé : aperçu avant émission, émission en lot (numéro, QR, contenu figé), publication aux familles
// (portail et SMS « bulletin disponible »), consultation de la copie figée et rectificatif motivé (nouvelle révision).
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { primaryP2, type CouncilSummary, type ReportCard, type ReportSnapshot } from '@/api/primary';
import { useI18n, type MessageKey } from '@/i18n';
import { P } from './access';
import BulletinCard from './BulletinCard.vue';
import { usePicker } from './usePicker';
import { useAction, usePermissions } from './usePri';

const { t } = useI18n();
const { can } = usePermissions();
const { error, busy, run } = useAction();
const route = useRoute();
const picker = usePicker();
const councils = ref<CouncilSummary[]>([]);
const councilId = ref('');
const cards = ref<ReportCard[]>([]);
const learners = ref<{ person_id: string; name: string }[]>([]);
const previewPerson = ref('');
const previewKind = ref<'term' | 'annual'>('term');
const preview = ref<ReportSnapshot | null>(null);
const shown = ref<ReportCard | null>(null);
const result = ref<string | null>(null);
const rectifyReason = ref('');
const rectifyAppraisal = ref('');
const canSign = computed(() => can(...P.reportCardSign));
const council = computed(() => councils.value.find((c) => c.id === councilId.value) ?? null);
const current = computed(() => cards.value.filter((c) => c.status !== 'superseded'));
const issuedCount = computed(() => current.value.filter((c) => c.status === 'issued').length);
const councilName = (c: CouncilSummary): string => `${picker.className(c.class_structure_id) || t('pri.dash.classUnknown')} · ${c.period_label}`;
const statusTone: Record<string, string> = { issued: 'warning', published: 'success', failed: 'danger', superseded: 'neutral' };

async function loadCouncils(): Promise<void> {
  councils.value = (await primaryP2.councils({ academic_year_id: picker.year.value?.id })).filter((c) => c.status === 'validated');
  const wanted = typeof route.query.council === 'string' ? route.query.council : '';
  councilId.value = councils.value.some((c) => c.id === wanted) ? wanted : (councils.value[0]?.id ?? '');
}

async function loadCards(): Promise<void> {
  cards.value = [];
  preview.value = null;
  shown.value = null;
  if (councilId.value === '') return;
  await run(async () => {
    cards.value = await primaryP2.reportCards(councilId.value);
    const detail = await primaryP2.council(councilId.value);
    learners.value = detail.lines.map((l) => ({ person_id: l.person_id, name: l.name }));
    previewPerson.value = learners.value[0]?.person_id ?? '';
  });
}

async function showPreview(): Promise<void> {
  shown.value = null;
  const loaded = await run(() => primaryP2.preview(councilId.value, previewPerson.value, previewKind.value));
  preview.value = loaded?.snapshot ?? null;
}

async function issue(): Promise<void> {
  const done = await run(() => primaryP2.issueCards(councilId.value));
  if (done) {
    cards.value = done.items;
    result.value = t('pri.cards.issuedResult', { issued: done.issued, failed: done.failed, skipped: done.skipped });
  }
}

async function publish(): Promise<void> {
  const done = await run(() => primaryP2.publishCards(councilId.value));
  if (done) {
    cards.value = done.items;
    result.value = t('pri.cards.publishedResult', { published: done.published, notified: done.notified });
  }
}

async function open(card: ReportCard): Promise<void> {
  preview.value = null;
  shown.value = (await run(() => primaryP2.reportCard(card.id))) ?? null;
  rectifyAppraisal.value = shown.value?.snapshot?.appraisal ?? '';
  rectifyReason.value = '';
}

async function rectify(): Promise<void> {
  const card = shown.value;
  if (!card) return;
  const done = await run(() => primaryP2.rectify(card.id, { reason: rectifyReason.value.trim(), appraisal: rectifyAppraisal.value.trim() === '' ? null : rectifyAppraisal.value.trim() }));
  if (done) {
    shown.value = done;
    cards.value = await primaryP2.reportCards(councilId.value);
    result.value = t('pri.cards.rectified', { revision: done.revision });
  }
}

watch(councilId, () => void loadCards());
onMounted(async () => {
  await run(async () => {
    await picker.load();
    await loadCouncils();
  });
  await loadCards();
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('pri.home.eyebrow') }}</p>
      <h1>{{ t('pri.cards.title') }}</h1>
      <p class="lede">{{ t('pri.cards.intro') }}</p>
    </header>
    <ErrorNotice :error="error" />

    <section class="card">
      <p v-if="councils.length === 0" class="muted">{{ t('pri.cards.noCouncil') }}</p>
      <div v-else class="field">
        <label for="rc-council">{{ t('pri.cards.council') }}</label>
        <select id="rc-council" v-model="councilId"><option v-for="c in councils" :key="c.id" :value="c.id">{{ councilName(c) }}</option></select>
      </div>
      <div v-if="council && canSign" class="actions">
        <button type="button" class="btn btn--primary" :disabled="busy" @click="issue">{{ t('pri.cards.issue') }}</button>
        <button type="button" class="btn" :disabled="busy || issuedCount === 0" @click="publish">{{ t('pri.cards.publish', { count: issuedCount }) }}</button>
      </div>
      <p v-if="result" class="notice" role="status">{{ result }}</p>
    </section>

    <section v-if="council" class="card" aria-labelledby="rc-list">
      <h2 id="rc-list">{{ t('pri.cards.list') }}</h2>
      <p v-if="current.length === 0" class="muted">{{ t('pri.cards.none') }}</p>
      <ul class="cards">
        <li v-for="c in current" :key="c.id" class="cards__row">
          <span><strong>{{ c.name }}</strong> · {{ t(c.kind === 'annual' ? 'pri.card.annualTitle' : 'pri.card.termTitle') }}<template v-if="c.revision > 1"> · {{ t('pri.cards.revision', { n: c.revision }) }}</template></span>
          <span class="tag" :class="`tag--${statusTone[c.status]}`">{{ t(`pri.cards.status.${c.status}` as MessageKey) }}</span>
          <span v-if="c.number" class="muted">{{ c.number }}</span>
          <span v-if="c.error_message" class="muted">{{ c.error_message }}</span>
          <button v-if="c.status !== 'failed'" type="button" class="btn btn--quiet" @click="open(c)">{{ t('pri.cards.view') }}</button>
        </li>
      </ul>
    </section>

    <section v-if="council" class="card" aria-labelledby="rc-preview">
      <h2 id="rc-preview">{{ t('pri.cards.preview') }}</h2>
      <div class="grid2">
        <div class="field">
          <label for="rc-person">{{ t('pri.cards.learner') }}</label>
          <select id="rc-person" v-model="previewPerson"><option v-for="l in learners" :key="l.person_id" :value="l.person_id">{{ l.name }}</option></select>
        </div>
        <div v-if="council.is_final_term" class="field">
          <label for="rc-kind">{{ t('pri.cards.kind') }}</label>
          <select id="rc-kind" v-model="previewKind">
            <option value="term">{{ t('pri.card.termTitle') }}</option>
            <option value="annual">{{ t('pri.card.annualTitle') }}</option>
          </select>
        </div>
      </div>
      <button type="button" class="btn" :disabled="busy || previewPerson === ''" @click="showPreview">{{ t('pri.cards.showPreview') }}</button>
      <div v-if="preview" class="sheet"><p class="notice">{{ t('pri.cards.previewNote') }}</p><BulletinCard :snapshot="preview" /></div>
    </section>

    <section v-if="shown && shown.snapshot" class="card" aria-labelledby="rc-shown">
      <h2 id="rc-shown">{{ t('pri.cards.frozen') }}</h2>
      <BulletinCard :snapshot="shown.snapshot" :number="shown.number" />
      <form v-if="canSign && (shown.status === 'issued' || shown.status === 'published')" class="rectify" @submit.prevent="rectify">
        <h3>{{ t('pri.cards.rectifyTitle') }}</h3>
        <label for="rc-ra">{{ t('pri.card.appraisal') }}</label>
        <textarea id="rc-ra" v-model="rectifyAppraisal" rows="2" maxlength="500" />
        <label for="rc-rr">{{ t('pri.cards.rectifyReason') }}</label>
        <input id="rc-rr" v-model="rectifyReason" type="text" maxlength="500" required />
        <button type="submit" class="btn" :disabled="busy || rectifyReason.trim() === ''">{{ t('pri.cards.rectify') }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: 1fr; gap: 0 16px; align-items: end; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.cards { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.cards__row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--border, #dcdcd8); }
.sheet { margin-top: 12px; }
.rectify { display: grid; gap: 6px; margin-top: 16px; max-width: 36rem; }
@media (min-width: 701px) { .grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
